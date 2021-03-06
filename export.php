<?php

ini_set('memory_limit', '1G');
require_once './vendor/autoload.php';
require_once './GuzzleRequestMetaMiddleware.php';

use Concat\Http\Middleware\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

$lastRequestTime = null;
$requestAllowance = null;

if (!file_exists('credentials.json')) {
    echo "credentials.json doesn't exists! Unable to load config parameters\n\n";
    exit(1);
}

$config = json_decode(file_get_contents('credentials.json'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "credentials.json doesn't contains valid JSON!\n\n";
    exit(1);
}

$username = $config['username'];
$password = $config['password'];
$apiWindowLimit = $config['rateLimitPerMinute'];
$subdomain = $config['subdomain'];

$fromTicket = 145130;
$toTicket = 150140;

//$apiWindowLimit = 690; //690
$quantityToProcess = $apiWindowLimit;

$skipSavedTickets = true;

$ticketAuditUrl = function ($ticketId) {
    return '/api/v2/tickets/'.$ticketId.'/audits.json?include=users,tickets';
};

$stack = new HandlerStack();
$stack->setHandler(new CurlMultiHandler());

$stack->push(Middleware::redirect());

$stack->push(GuzzleRequestMetaMiddleware::middleware());

$stack->push(
    Middleware::retry(function ($retries, $request, $response, $exception) {
        if ($response) {
            if ($response->getStatusCode() < 1 || ($response->getStatusCode() >= 500 && ($retries < 3))) {
                echo "Retrying ... \n";

                return true;
            }
        }

        return false;
    },
    function ($delay) {
        echo "Delaying $delay * 1000...\n";

        return $delay * 1;
    })
);

$loggerMiddleware = new Logger(function ($level, $message, array $context) {
    if ($level === 'debug') {
        echo $message."\n";
    }
});

// Change it to `true` for some debug juice
$loggerMiddleware->setRequestLoggingEnabled(false);
$loggerMiddleware->setLogLevel(\Psr\Log\LogLevel::DEBUG);

$stack->push($loggerMiddleware);

$client = new Client([
    'base_uri' => 'https://'.$subdomain.'.zendesk.com/',
    'handler' => $stack,
    'debug' => false,
    'cookies' => true,
    'curl' => [
        CURLOPT_FORBID_REUSE => false,
        CURLOPT_FRESH_CONNECT => false,
    ],
]);

$tickets404 = get404TicketIds();

$requests = function ($from, $to) use ($ticketAuditUrl, $skipSavedTickets, $tickets404) {
    for ($i = $from; $i <= $to; ++$i) {
        if ($skipSavedTickets && (file_exists('./tickets/'.$i.'.json') || in_array($i, $tickets404))) {
            continue;
        }

        yield new Request('GET', $ticketAuditUrl($i), [
            'x-ticket-id' => $i,
            'curl' => [
                CURLOPT_FORBID_REUSE => false,
                CURLOPT_FRESH_CONNECT => false,
            ],
            'Extra' => [
                'Ticket-Id' => $i,
            ],
            'timeout' => 5,
        ]);
    }
};

$lastProcessed = $fromTicket;

$requestProcessed = [];

$timeKey = function () {
    return date('Hi');
};

do {
    if (!isset($requestProcessed[$timeKey()])) {
        $requestProcessed[$timeKey()] = 0;
    }

    if ($requestProcessed[$timeKey()] >= $apiWindowLimit) {
        $interval = (60 - date('s')) + 1;
        echo "Sleeping till next window ($interval s)...\n";
        sleep($interval);
    }

    $quantityToProcess = $apiWindowLimit - $requestProcessed[$timeKey()];

    $batchFrom = $lastProcessed;
    $batchTo = min($batchFrom + $quantityToProcess, $toTicket);

    echo 'Starting at '.date('H:i:s')." with tickets from $batchFrom to $batchTo\n\n";

    $pool = new Pool($client, $requests($batchFrom, $batchTo), [
        'options' => [
            'auth' => [
                $username,
                $password,
            ],
        ],
        'concurrency' => 100,
        'fulfilled' => function (Response $response, $index, $promise) use ($timeKey) {

            global $requestProcessed;
            ++$requestProcessed[$timeKey()];

            if ($response->getStatusCode() === 200) {
                $contents = json_decode($response->getBody()->getContents(), true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    if ($response->getStatusCode() === 200) {
                        saveTicketDocument($contents);
                    }
                } else {
                    var_dump($response->getBody()->getContents());
                    throw new \Exception('Got a JSON decode issue');
                }
            } elseif ($response->getStatusCode() === 404) {
                addTicketTo404($response->getHeaderLine('x-ticket-id'));
            }
        },
        'rejected' => function (RequestException $reason, $index) use ($timeKey) {

            global $requestProcessed;
            ++$requestProcessed[$timeKey()];

            if ($reason->getResponse() && $reason->getResponse()->getStatusCode() === 404) {
                //This shouldn't even happen.
                addTicketTo404($reason->getRequest()->getHeaderLine('x-ticket-id'));

                return;
            }

            //We get this weird cases every once in a while - TODO needs more details on why
            if (!$reason->getResponse()) {
                var_dump('Reason for no response:', $reason->getCode());

                return;
            }

            echo $reason->getResponse()->getStatusCode().' => '.json_encode($reason->getResponse()->getHeaders()).' =>'.$reason->getResponse()->getBody()->getContents()."\n";

            $bodyResponseContents = $reason->getResponse()->getBody()->getContents();
            $error = null;

            $r = json_decode($bodyResponseContents, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                if (isset($r['error'])) {
                    $error = $r['error'];
                }
            }
        },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    $lastProcessed = $batchTo;

    echo 'Finishing at '.date('H:i:s')."\n";

    unset($pool, $promise);

    gc_collect_cycles();
} while (($batchTo + $quantityToProcess) < $toTicket);

function saveTicketDocument($originalDocument)
{
    if (!isset($originalDocument['tickets'])) {
        throw new \Exception('Document without ticket: '.json_encode($originalDocument));
    }

    $ticketData = $originalDocument['tickets'][0];
    $ticketId = $ticketData['id'];

    foreach ($originalDocument['audits'] as $audit) {
        if (isset($audit['events'])) {
            foreach ($audit['events'] as $event) {
                if (isset($event['data']) && isset($event['data']['recording_url'])) {
                    $recordingURL = $event['data']['recording_url'];
                    $callId = $event['data']['call_id'];

                    //sometimes you get "" as 'recording_url' as there's no recording available
                    if (strlen($recordingURL) > 0) {
                        $media = saveMedia($recordingURL, $ticketId, $callId, true);

                        // Now save the results back to the original structure.
                        // So we will be able to match them later on if needed.
                        $event['data']['downloaded_media'] = [
                            'filename' => $media['originFilename'],
                            'saved_filename' => $media['destinationFilename'],
                            'type' => $media['type'],
                        ];
                    }
                }

                if (isset($event['attachments'])) {
                    foreach ($event['attachments'] as $attachment) {
                        $attachmentURL = $attachment['content_url'];
                        $attachmentId = $attachment['id'];

                        $media = saveMedia($attachmentURL, $ticketId, $attachmentId, false);

                        $attachment['downloaded_media'] = [
                            'filename' => $media['originFilename'],
                            'saved_filename' => $media['destinationFilename'],
                            'type' => $media['type'],
                        ];
                    }
                }
            }
        }
    }

    //Shuffle some stuff around.
    $ticketData = $originalDocument['tickets'][0];
    $ticketId = $ticketData['id'];

    $newDocument = $ticketData;
    $newDocument['audits'] = $originalDocument['audits'];

    $newDocument['users'] = array_map(function ($item) {
        return [
            'id' => $item['id'],
            'url' => $item['url'],
            'name' => $item['name'],
            'email' => $item['email'],
            'phone' => $item['phone'],
        ];
    }, $originalDocument['users']);

    file_put_contents('./tickets/'.$ticketId.'.json', json_encode($newDocument, JSON_PRETTY_PRINT));
}

function saveMedia($url, $ticketId, $mediaId, $isCall)
{
    global $client, $username, $password;

    $response = $client->request('GET', $url, [
        'auth' => [
            $username,
            $password,
        ],
        'curl' => [
            CURLOPT_FORBID_REUSE => false,
            CURLOPT_FRESH_CONNECT => false,
        ],
        'timeout' => 15,
    ]);

    if ($response->getHeaderLine('Location') || $response->getStatusCode() === 302) {
        echo 'Got redirect response!';
        exit;
    }
    $matchContentType = preg_match('/([\w\/]+)(;\s+charset=([^\s"]+))/', $response->getHeaderLine('Content-Type'), $contentTypeMatches);
    $fileType = null;

    if ($matchContentType) {
        $fileType = $contentTypeMatches[1];
    }

    $filename = $ticketId.'_'.$mediaId;
    $originFilename = null;

    $matchFilename = preg_match('/.*filename=[\'\"]?([^\"]+)/', $response->getHeaderLine('Content-Disposition'), $matches);

    if ($matchFilename) {
        $originFilename = $matches[1];
        $filename .= '_'.$originFilename;
    }

    $destinationPath = ($isCall ? './calls/' : './attachments/').$filename;

    if (file_exists($destinationPath)) {
        unlink($destinationPath);
    }

    file_put_contents($destinationPath, $response->getBody()->getContents());

    unset($response);

    return [
        'type' => $fileType,
        'originFilename' => $originFilename,
        'destinationFilename' => $filename,
    ];
}
function get404TicketIds()
{
    $list = [];
    if (file_exists('./404.json')) {
        $list = json_decode(file_get_contents('./404.json'), true);
    }

    return $list;
}
function addTicketTo404($ticketId)
{
    $ticketId = (int) $ticketId;

    if ($ticketId < 1) {
        return false;
    }

    $list = get404TicketIds();
    $list[] = (int) $ticketId;

    file_put_contents('./404.json', json_encode($list));

    return true;
}
