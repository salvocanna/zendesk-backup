<?php

require_once './vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

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
$concurrency = $config['concurrency'];
$subdomain = $config['subdomain'];

$ticketAuditUrl = function ($ticketId) {
    global $subdomain;
    return 'https://'.$subdomain.'.zendesk.com/api/v2/tickets/'.$ticketId.'/audits.json?include=users,tickets';
};

$client = new Client();

$requests = function ($from, $to) use ($ticketAuditUrl) {
    for ($i = $from; $i <= $to; ++$i) {
        yield new Request('GET', $ticketAuditUrl($i), [
            'x-ticket-id' => $i,
        ]);
    }
};

$rateLimitTotal = -INF;
$rateLimitRemaining = +INF;

$updateRateLimit = function ($remaining) {
    global $rateLimitRemaining, $rateLimitTotal;
    if ($remaining > $rateLimitTotal) {
        $rateLimitTotal = $remaining;
    }

    $rateLimitRemaining = $remaining;
};

$rejected = [];

$fromTicket = 150132;
$toTicket = 150132;

do {
    $pool = new Pool($client, $requests($fromTicket, $toTicket), [
        'options' => [
            'auth' => [
                $username,
                $password,
            ],
        ],
        'concurrency' => $concurrency,
        'fulfilled' => function (Response $response, $index, $promise) {

            $contents = json_decode($response->getBody()->getContents(), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                saveTicketDocument($contents);
            } else {
                throw new \Exception('Got a JSON decode issue');
            }
            echo 'Got response'.get_class($response)." - $index\n\n";
        },
        'rejected' => function (RequestException $reason, $index) {
            global $rejected;

            var_dump($reason);

            $bodyResponseContents = $reason->getResponse()->getBody()->getContents();
            $r = json_decode($bodyResponseContents, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $error = null;
                if (isset($r['error'])) {
                    $error = $r['error'];
                }
                $rejected[] = [
                    'ticketId' => $reason->getRequest()->getHeader('x-ticket-id')[0],
                    'error' => $error,
                ];
            }
        },
    ]);

    // Initiate the transfers and create a promise
    $promise = $pool->promise();

    // Force the pool of requests to complete.
    $promise->wait();

    var_dump("Rejected: ", $rejected);

} while (false);


function saveTicketDocument($originalDocument)
{
    $ticketData = $originalDocument['tickets'][0];
    $ticketId = $ticketData['id'];

    foreach ($originalDocument['audits'] as $audit) {
        if (isset($audit['events'])) {
            foreach ($audit['events'] as $event) {
                if (isset($event['data']) && isset($event['data']['recording_url'])) {
                    $recordingURL = $event['data']['recording_url'];
                    $callId = $event['data']['call_id'];
                    $media = saveMedia($recordingURL, $ticketId, $callId, true);

                    // Now save the results back to the original structure.
                    // So we will be able to match them later on if needed.
                    $event['data']['downloaded_media'] = [
                        'filename' => $media['originFilename'],
                        'saved_filename' => $media['destinationFilename'],
                        'type' => $media['type']
                    ];
                }

                if (isset($event['attachments'])) {
                    foreach ($event['attachments'] as $attachment) {
                        echo 'Found attachment: '.$attachment['content_url'];
                        $attachmentURL = $attachment['content_url'];
                        $attachmentId = $attachment['id'];
                        $media = saveMedia($attachmentURL, $ticketId, $attachmentId, false);

                        $attachment['downloaded_media'] = [
                            'filename' => $media['originFilename'],
                            'saved_filename' => $media['destinationFilename'],
                            'type' => $media['type']
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
            'created_at' => $item['created_at'],
            'phone' => $item['phone'],
            'organization_id' => $item['organization_id'],
            'role' => $item['role'],
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
    ]);

    $matchContentType = preg_match('/([\w\/]+)(;\s+charset=([^\s"]+))/', $response->getHeaderLine('Content-Type'), $contentTypeMatches);
    $fileType = null;

    if ($matchContentType) {
        $fileType = $contentTypeMatches[1];
    }

    $filename = $ticketId.'_'.$mediaId; //uniqid();
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
