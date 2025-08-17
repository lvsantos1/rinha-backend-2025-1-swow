<?php

gc_disable();

require './config.php';

use Swow\Buffer;
use Swow\Channel;
use Swow\Coroutine;
use Swow\Http\Parser;
use Swow\Http\ParserException;
use Swow\Socket;
use Swow\SocketException;

const RESPONSE_HEADERS = [
    'text/plain' => [
        0 => "HTTP/1.1 200 OK\r\nConnection: Closed\r\nContent-Type: text/plain\r\nContent-Length: ",
        1 => "HTTP/1.1 200 OK\r\nConnection: Keep-Alive\r\nContent-Type: text/plain\r\nContent-Length: ",
    ],
    'application/json' => [
        0 => "HTTP/1.1 200 OK\r\nConnection: Closed\r\nContent-Type: application/json\r\nContent-Length: ",
        1 => "HTTP/1.1 200 OK\r\nConnection: Keep-Alive\r\nContent-Type: application/json\r\nContent-Length: ",
    ],
];

function buildResponse(string $body, bool $keepAlive, string $contentType): string
{
    $header = RESPONSE_HEADERS[$contentType][(int)$keepAlive];
    return $header . strlen($body) . "\r\n\r\n" . $body;
}

const STATIC_BODIES = [
    'health' => 'PHP is the KING, MTF!',
    '404'    => 'Not Found!',
];

static $STATIC_RESPONSES = [
    'health' => [
        0 => buildResponse(STATIC_BODIES['health'], false, 'text/plain'),
        1 => buildResponse(STATIC_BODIES['health'], true, 'text/plain'),
    ],
    '404' => [
        0 => buildResponse(STATIC_BODIES['404'], false, 'text/plain'),
        1 => buildResponse(STATIC_BODIES['404'], true, 'text/plain'),
    ],
];

function healthHandler(bool $keepAlive): string
{
    global $STATIC_RESPONSES;
    return $STATIC_RESPONSES['health'][(int)$keepAlive];
}

function notFoundHandler(bool $keepAlive): string
{
    global $STATIC_RESPONSES;
    return $STATIC_RESPONSES['404'][(int)$keepAlive];
}

function paymentHandler(Channel $channel, Buffer $data, bool $keepAlive): string
{
    $channel->push('a' . $data->toString());
    return buildResponse('Payment registered!', $keepAlive, 'text/plain');
}

function paymentsSummaryHandler(Channel $channel, ?string $queryParams, Socket $ipc, bool $keepAlive): string
{
    usleep(200);
    $channel->push('b' . $queryParams ?? '');

    $data = '{}';

    try {
        $recv = $ipc->recvString(512, 5);
        if ($recv !== null) {
            $data = $recv;
        }
    } catch (SocketException $e) {
    }

    return buildResponse($data, $keepAlive, 'application/json');
}

function onEventNone(int &$parsedOffset, Buffer $buffer): void
{
    $buffer->truncateFrom($parsedOffset);
    $parsedOffset = 0;
}

function onEventUrl(?string &$url, Buffer $buffer, Parser $parser): void
{
    $url = $buffer->read($parser->getDataOffset(), $parser->getDataLength());
}

function onEventBody(Buffer $body, Buffer $buffer, Parser $parser): void
{
    $body->write(0, $buffer, $parser->getDataOffset(), $parser->getDataLength());
}

function dispatchHandler(string $url, Channel $ch, Buffer $body, ?string $query, Socket $ipc, bool $keepAlive): string
{
    if ($url === '/payments') {
        return paymentHandler($ch, $body, $keepAlive);
    }

    if ($url === '/health') {
        return healthHandler($keepAlive);
    }

    if ($url === '/payments-summary') {
        return paymentsSummaryHandler($ch, $query, $ipc, $keepAlive);
    }

    return notFoundHandler($keepAlive);
}

Coroutine::run(function () {
    $paymentCache = new Channel(API_PAYMENTS_CACHE_CAPACITY);

    // IPC
    @unlink(API_IPC_SOCKET);
    $ipc = new Socket(Socket::TYPE_UDG);
    $ipc->bind(API_IPC_SOCKET);

    // WORKER IPC
    Coroutine::run(static function () use ($paymentCache, $ipc) {
        while (true) {
            try {
                $data = $paymentCache->pop(5);
                $ipc->sendTo($data, 0, strlen($data), WORKERS_IPC_SOCKET);
            } catch (Swow\ChannelException | Throwable) {
            }
        }
    });

    // HTTP SERVER (Isso aqui precisa muito de uma revisão)
    Coroutine::run(static function () use ($paymentCache, $ipc) {
        @unlink(API_HTTP_SOCKET);

        $server = new Socket(Socket::TYPE_UNIX);
        $server->bind(API_HTTP_SOCKET)->listen(API_HTTP_BACKLOG);

        while (true) {
            try {
                $client = $server->accept();
            } catch (SocketException) {
                break;
            }

            Coroutine::run(static function () use ($client, $paymentCache, $ipc): void {
                $buffer = new Buffer(HTTP_PARSER_BUFFER_SIZE);
                $parser = (new Parser())
                    ->setType(Parser::TYPE_REQUEST)
                    ->setEvents(Parser::EVENT_BODY | Parser::EVENT_URL);

                $parsedOffset = 0;
                $url = null;
                $body = new Buffer(512);

                try {
                    while (true) {
                        if ($client->recv($buffer, $buffer->getLength()) === 0) {
                            break;
                        }

                        while (true) {
                            $parsedOffset += $parser->execute($buffer, $parsedOffset);

                            if ($parser->getEvent() == Parser::EVENT_NONE) {
                                onEventNone($parsedOffset, $buffer);
                                break;
                            } elseif ($parser->getEvent() == Parser::EVENT_URL) {
                                onEventUrl($url, $buffer, $parser);
                            } elseif ($parser->getEvent() == Parser::EVENT_BODY) {
                                onEventBody($body, $buffer, $parser);
                            }

                            if ($parser->isCompleted()) {
                                $queryParams = null;
                                $queryStart = strpos($url, '?');
                                if ($queryStart !== false) {
                                    $queryParams = substr($url, $queryStart + 1);
                                    $url = substr($url, 0, $queryStart);
                                }

                                $client->send(
                                    dispatchHandler($url, $paymentCache, $body, $queryParams, $ipc, $parser->shouldKeepAlive())
                                );

                                $body->clear();

                                break;
                            }
                        }

                        if (!$parser->shouldKeepAlive()) {
                            break;
                        }
                    }
                } catch (SocketException | ParserException) {
                } finally {
                    $client->close();
                }
            });
        }
    });
});

\Swow\Sync\waitAll();
