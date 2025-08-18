<?php

gc_disable();

require './config.php';

use Swow\Coroutine;
use Swow\Socket;
use Swow\Channel;
use Swow\Http\Parser;
use Swow\Buffer;

const DEFAULT_SERVICE_NAME = 'default';
const FALLBACK_SERVICE_NAME = 'fallback';

function parseQueryString(string $query): array
{
    $params = [];
    $length = strlen($query);
    $key = '';
    $value = '';
    $isKey = true;

    for ($i = 0; $i < $length; $i++) {
        $ch = $query[$i];

        if ($ch === '=') {
            $isKey = false;
            $value = '';
        } elseif ($ch === '&') {
            $params[$key] = $value;
            $key = '';
            $isKey = true;
        } else {
            if ($isKey) {
                $key .= $ch;
            } else {
                $value .= $ch;
            }
        }
    }

    if ($key !== '') {
        $params[$key] = $value;
    }

    return $params;
}

function extractTimestamp(string $iso): int
{
    $year   = (int) substr($iso, 0, 4);
    $month  = (int) substr($iso, 5, 2);
    $day    = (int) substr($iso, 8, 2);
    $hour   = (int) substr($iso, 11, 2);
    $minute = (int) substr($iso, 14, 2);
    $second = (int) substr($iso, 17, 2);
    $millis = (int) substr($iso, 20, 3);

    $a = intdiv(14 - $month, 12);
    $y = $year + 4800 - $a;
    $m = $month + 12 * $a - 3;
    $julianDay = $day + intdiv(153 * $m + 2, 5) + 365 * $y + intdiv($y, 4) - intdiv($y, 100) + intdiv($y, 400) - 32045;
    $unixDays = $julianDay - 2440588;
    $seconds = $unixDays * 86400 + $hour * 3600 + $minute * 60 + $second;

    return $seconds * 1000 + $millis;
}

function extractAmount(string $payload): string
{
    static $reference = '"amount":';
    $referencePos = strpos($payload, $reference);
    $referencePos += 9;

    $numEnd = strpos($payload, ',', $referencePos);
    if ($payload[$referencePos] == " ") {
        $referencePos += 1;
    }

    return rtrim(substr($payload, $referencePos, $numEnd - $referencePos));
}

function extractCorrelationId(string $payload): string
{
    static $reference = '"correlationId":';
    $referencePos = strpos($payload, $reference);
    $referencePos += 16;

    if ($payload[$referencePos] == " ") {
        $referencePos += 1;
    }
    $referencePos += 1;

    return rtrim(substr($payload, $referencePos, 36));
}

function getRequest(string $host, string $path): string
{
    return "GET {$path} HTTP/1.1\r\n" .
        "Host: {$host}\r\n" .
        "User-Agent: swow\r\n" .
        "Accept: */*\r\n" .
        "Connection: keep-alive\r\n" .
        "\r\n";
}

function request(string $host, string $path, string $data): string
{
    $contentLength = strlen($data);

    return "POST {$path} HTTP/1.1\r\n" .
        "Host: {$host}\r\n" .
        "User-Agent: swow\r\n" .
        "Connection: keep-alive\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: {$contentLength}\r\n" .
        "\r\n" .
        $data;
}

function isoNowMs(): array
{
    $microtime = microtime(true);
    $seconds   = (int)$microtime;
    $ms        = (int)(($microtime - $seconds) * 1000);
    $ms        = str_pad((string)$ms, 3, '0', STR_PAD_LEFT);

    return [gmdate('Y-m-d\TH:i:s', $seconds) . '.' . $ms . 'Z', $seconds . $ms];
}

Coroutine::run(static function () {
    $paymentsCacheChannel = new Channel(API_PAYMENTS_CACHE_CAPACITY);
    $errorChannel         = new Channel(WORKERS_PAYMENTS_CACHE_CAPACITY_ERRORS);
    $successfullyInsertedPayments = new Channel(SUCCESSFULLY_INSERTED_PAYMENTS);

    // PAYMENTS SUMMARY
    $paymentsSummaryTasksChannel = new Channel(3);

    Coroutine::run(static function () use ($paymentsSummaryTasksChannel, $successfullyInsertedPayments) {
        $successArray = [];

        while (true) {
            while ($successfullyInsertedPayments->getLength() > 0) {
                $item = $successfullyInsertedPayments->pop();
                $successArray[] = $item;
            }

            if ($paymentsSummaryTasksChannel->getLength() > 0) {
                usleep(1000);
                $query = $paymentsSummaryTasksChannel->pop();

                $defaultSum = '0.0';
                $defaultCount = 0;
                $fallbackSum = '0.0';
                $fallbackCount = 0;

                if (!isset($query['from'])) {
                    foreach ($successArray as $item) {
                        if ($item['service'] == DEFAULT_SERVICE_NAME) {
                            $defaultSum = bcadd($defaultSum, $item['amount'], 10);
                            $defaultCount++;
                            continue;
                        }

                        $fallbackSum = bcadd($fallbackSum, $item['amount'], 10);
                        $fallbackCount++;
                    }
                } else {
                    foreach ($successArray as $item) {
                        if ($item['timestamp'] >= $query['from'] && $item['timestamp'] <= $query['to']) {
                            if ($item['service'] == DEFAULT_SERVICE_NAME) {
                                $defaultSum = bcadd($defaultSum, $item['amount'], 10);
                                $defaultCount++;
                                continue;
                            }

                            $fallbackSum = bcadd($fallbackSum, $item['amount'], 10);
                            $fallbackCount++;
                        }
                    }
                }

                $result = '{"default": {"totalRequests": ' . $defaultCount . ', "totalAmount": ' . $defaultSum . '}, "fallback": {"totalRequests": ' . $fallbackCount . ', "totalAmount": ' . $fallbackSum . '}}';

                $query['server']->sendTo($result, 0, strlen($result), $query['peer']);
            }
            usleep(1);
        }
    });

    foreach (range(1, WORKERS_NUM_SOCKETS) as $socketNum) {
        Coroutine::run(static function () use ($paymentsCacheChannel, $paymentsSummaryTasksChannel, $socketNum) {
            $socketPath = sprintf(WORKERS_IPC_SOCKET_PATTERN, $socketNum);
            @unlink($socketPath);

            $server = new Socket(Socket::TYPE_UDG);
            $server->bind($socketPath);

            $recvBuffer = new Buffer(512);

            $handlers = [
                'a' => fn(string $data, array $config) => static function () use ($data, $paymentsCacheChannel) {
                    $paymentsCacheChannel->push(['target' => DEFAULT_SERVICE_NAME, 'payload' => $data]);
                },
                'b' => fn(string $data, array $config) => static function () use ($paymentsSummaryTasksChannel, $data, $config, &$server) {
                    $query = [];
                    if (strlen($data) > 1) {
                        $query = parseQueryString(substr($data, 1));
                        $query['from'] = extractTimestamp($query['from']);
                        $query['to'] = extractTimestamp($query['to']);
                    }
                    $query['peer'] = $config['peer'];
                    $query['server'] = &$server;
                    $paymentsSummaryTasksChannel->push($query);
                },
            ];

            while (true) {
                $recvBuffer->clear();
                $address = null;
                $server->recvFrom($recvBuffer, 0, $recvBuffer->getSize(), $address);
                $data = $recvBuffer->toString();

                $handler = $handlers[$data[0]];
                $config = ['peer' => $address];

                Coroutine::run($handler($data, $config));
            }
        });
    }

    foreach (range(1, ERRORS_WORKERS_NUM) as $workerNum) {
        Coroutine::run(static function () use ($errorChannel, $paymentsCacheChannel, $successfullyInsertedPayments) {

            $defaultClient = new Socket(Socket::TYPE_TCP);
            $defaultClient->connect(PAYMENT_PROCESSOR_URL_DEFAULT, PAYMENT_PROCESSOR_PORT_DEFAULT);

            $defaultParser   = new Parser();
            $defaultParser->setType(Parser::TYPE_RESPONSE);
            $defaultBuffer   = new Buffer(HTTP_PARSER_BUFFER_SIZE);

            $fallbackClient = new Socket(Socket::TYPE_TCP);
            $fallbackClient->connect(PAYMENT_PROCESSOR_URL_FALLBACK, PAYMENT_PROCESSOR_PORT_FALLBACK);

            $fallbackParser   = new Parser();
            $fallbackParser->setType(Parser::TYPE_RESPONSE);
            $fallbackBuffer   = new Buffer(HTTP_PARSER_BUFFER_SIZE);

            while (true) {
                $data = null;
                try {
                    $data = $errorChannel->pop(0);
                } catch (Throwable $t) {
                }

                if ($data === null) {
                    continue;
                    usleep(1);
                }

                $correlationId = extractCorrelationId($data['payload']);

                // tenta recuperar do default
                if ($data['lastTarget'] == DEFAULT_SERVICE_NAME) {

                    $defaultClient->send(
                        getRequest(
                            PAYMENT_PROCESSOR_URL_DEFAULT,
                            '/payments/' . $correlationId
                        )
                    );

                    $parsedOffset = 0;

                    do {
                        $defaultClient->recv($defaultBuffer, $defaultBuffer->getLength());
                        $parsedOffset += $defaultParser->execute($defaultBuffer, $parsedOffset);

                        if ($defaultParser->getEvent() === Parser::EVENT_CHUNK_COMPLETE) {
                            $defaultBuffer->truncateFrom($parsedOffset);
                            $parsedOffset = 0;
                            continue;
                        }

                        if ($defaultParser->getEvent() === Parser::EVENT_MESSAGE_COMPLETE) {
                            $defaultBuffer->truncateFrom($parsedOffset);
                            break;
                        }
                    } while (true);

                    if ($defaultParser->getStatusCode() == 200) {
                        $successfullyInsertedPayments->push([
                            'service' => DEFAULT_SERVICE_NAME,
                            'timestamp' => $data['requestedAt'],
                            'amount' => extractAmount($data['payloadApi'])
                        ]);
                        continue;
                    }

                    $paymentsCacheChannel->push(['payload' => $data['payload'], 'target' => FALLBACK_SERVICE_NAME]);
                    continue;
                }

                // tenta recuperar do fallback

                $fallbackClient->send(
                    getRequest(
                        PAYMENT_PROCESSOR_URL_FALLBACK,
                        '/payments/' . $correlationId
                    )
                );

                $parsedOffset = 0;

                do {
                    $fallbackClient->recv($fallbackBuffer, $fallbackBuffer->getLength());
                    $parsedOffset += $fallbackParser->execute($fallbackBuffer, $parsedOffset);

                    if ($fallbackParser->getEvent() === Parser::EVENT_CHUNK_COMPLETE) {
                        $fallbackBuffer->truncateFrom($parsedOffset);
                        $parsedOffset = 0;
                        continue;
                    }

                    if ($fallbackParser->getEvent() === Parser::EVENT_MESSAGE_COMPLETE) {
                        $fallbackBuffer->truncateFrom($parsedOffset);
                        break;
                    }
                } while (true);

                if ($fallbackParser->getStatusCode() == 200) {
                    $successfullyInsertedPayments->push([
                        'service' => FALLBACK_SERVICE_NAME,
                        'timestamp' => $data['requestedAt'],
                        'amount' => extractAmount($data['payloadApi'])
                    ]);
                    continue;
                }

                $paymentsCacheChannel->push(['payload' => $data['payload'], 'target' => DEFAULT_SERVICE_NAME]);
            }
        });
    }

    // WORKERS PAYMENTS
    foreach (range(1, WORKERS_NUM) as $workerNum) {
        Coroutine::run(static function () use ($paymentsCacheChannel, $errorChannel, $successfullyInsertedPayments) {
            $defaultClient = new Socket(Socket::TYPE_TCP);
            $defaultClient->connect(PAYMENT_PROCESSOR_URL_DEFAULT, PAYMENT_PROCESSOR_PORT_DEFAULT);

            $defaultParser   = new Parser();
            $defaultParser->setType(Parser::TYPE_RESPONSE);
            $defaultBuffer   = new Buffer(HTTP_PARSER_BUFFER_SIZE);

            $fallbackClient = new Socket(Socket::TYPE_TCP);
            $fallbackClient->connect(PAYMENT_PROCESSOR_URL_FALLBACK, PAYMENT_PROCESSOR_PORT_FALLBACK);

            $fallbackParser   = new Parser();
            $fallbackParser->setType(Parser::TYPE_RESPONSE);
            $fallbackBuffer   = new Buffer(HTTP_PARSER_BUFFER_SIZE);

            while (true) {
                try {
                    $data = $paymentsCacheChannel->pop();

                    if ($data === null) {
                        Coroutine::yield();
                        continue;
                    }

                    [$nowApi, $nowPersist] = isoNowMs();
                    $payloadApi = substr($data['payload'], 1, -1) . ',"requestedAt":"' . $nowApi . '"}';
                    $amount = extractAmount($payloadApi);

                    if ($data['target'] == DEFAULT_SERVICE_NAME) {

                        $defaultClient->send(
                            request(
                                PAYMENT_PROCESSOR_URL_DEFAULT,
                                '/payments',
                                $payloadApi
                            )
                        );

                        $parsedOffset = 0;

                        do {
                            $defaultClient->recv($defaultBuffer, $defaultBuffer->getLength());
                            $parsedOffset += $defaultParser->execute($defaultBuffer, $parsedOffset);

                            if ($defaultParser->getEvent() === Parser::EVENT_CHUNK_COMPLETE) {
                                $defaultBuffer->truncateFrom($parsedOffset);
                                $parsedOffset = 0;
                                continue;
                            }

                            if ($defaultParser->getEvent() === Parser::EVENT_MESSAGE_COMPLETE) {
                                $defaultBuffer->truncateFrom($parsedOffset);
                                break;
                            }
                        } while (true);

                        if ($defaultParser->getStatusCode() == 200) {
                            $successfullyInsertedPayments->push(['service' => DEFAULT_SERVICE_NAME, 'timestamp' => $nowPersist, 'amount' => $amount]);
                            continue;
                        }

                        $errorChannel->push(['payload' => $data['payload'], 'payloadApi' => $payloadApi, 'requestedAt' => $nowPersist, 'lastTarget' => DEFAULT_SERVICE_NAME]);

                        continue;
                    }

                    $fallbackClient->send(
                        request(
                            PAYMENT_PROCESSOR_URL_FALLBACK,
                            '/payments',
                            $payloadApi
                        )
                    );

                    $parsedOffset = 0;

                    do {
                        $fallbackClient->recv($fallbackBuffer, $fallbackBuffer->getLength());
                        $parsedOffset += $fallbackParser->execute($fallbackBuffer, $parsedOffset);

                        if ($fallbackParser->getEvent() === Parser::EVENT_CHUNK_COMPLETE) {
                            $fallbackBuffer->truncateFrom($parsedOffset);
                            $parsedOffset = 0;
                            continue;
                        }

                        if ($fallbackParser->getEvent() === Parser::EVENT_MESSAGE_COMPLETE) {
                            $fallbackBuffer->truncateFrom($parsedOffset);
                            break;
                        }
                    } while (true);

                    if ($fallbackParser->getStatusCode() == 200) {
                        $successfullyInsertedPayments->push(['service' => FALLBACK_SERVICE_NAME, 'timestamp' => $nowPersist, 'amount' => $amount]);
                        continue;
                    }

                    $errorChannel->push(['payload' => $data['payload'], 'payloadApi' => $payloadApi, 'requestedAt' => $nowPersist, 'lastTarget' => FALLBACK_SERVICE_NAME]);
                } catch (Throwable) {
                    $errorChannel->push(['payload' => $data['payload'], 'payloadApi' => $payloadApi, 'requestedAt' => $nowPersist, 'lastTarget' => $data['target']]);
                }
            }
        });
    }
});

\Swow\Sync\waitAll();
