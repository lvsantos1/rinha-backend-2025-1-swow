<?php

gc_disable();

require './config.php';

use Swow\Coroutine;
use Swow\Socket;
use Swow\Channel;
use Swow\Http\Parser;
use Swow\Buffer;

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
                $query = $paymentsSummaryTasksChannel->pop();

                // Coloquei isso aqui para evitar inconsistência. Vale a pena pensar num jeito melhor de fazer
                $counter = 0;
                while ($successfullyInsertedPayments->getLength() > 0 && $counter <= 1000) {
                    $item = $successfullyInsertedPayments->pop();
                    $successArray[] = $item;
                    $counter++;
                }

                $sum = '0.0';
                $count = 0;

                if (!isset($query['from'])) {
                    foreach ($successArray as $item) {
                        $sum = bcadd($sum, $item['amount'], 10);
                        $count++;
                    }
                } else {
                    foreach ($successArray as $item) {
                        if ($item['timestamp'] >= $query['from'] && $item['timestamp'] <= $query['to']) {
                            $sum = bcadd($sum, $item['amount'], 10);
                            $count++;
                        }
                    }
                }

                $result = '{"default": {"totalRequests": ' . $count . ', "totalAmount": ' . $sum . '}, "fallback": {"totalRequests": 0, "totalAmount": 0.0}}';

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
                    $paymentsCacheChannel->push($data);
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

    Coroutine::run(static function () use ($errorChannel, $paymentsCacheChannel) {
        while (true) {
            try {
                while (true) {
                    $payload = $errorChannel->pop(0);
                    $paymentsCacheChannel->push($payload);
                }
            } catch (Swow\ChannelException $e) {
            } catch (Throwable $t) {
            }
        }
    });

    // WORKERS PAYMENTS
    foreach (range(1, WORKERS_NUM) as $workerNum) {
        Coroutine::run(static function () use ($paymentsCacheChannel, $errorChannel, $successfullyInsertedPayments) {
            $client = new Socket(Socket::TYPE_TCP);
            $client->connect(PAYMENT_PROCESSOR_URL_DEFAULT, PAYMENT_PROCESSOR_PORT_DEFAULT);

            $parser   = new Parser();
            $parser->setType(Parser::TYPE_RESPONSE);
            $buffer   = new Buffer(HTTP_PARSER_BUFFER_SIZE);

            while (true) {
                try {
                    $payload = $paymentsCacheChannel->pop();

                    if ($payload === null) {
                        Coroutine::yield();
                        continue;
                    }

                    [$nowApi, $nowPersist] = isoNowMs();
                    $payloadApi = substr($payload, 1, -1) . ',"requestedAt":"' . $nowApi . '"}';

                    $client->send(
                        request(
                            PAYMENT_PROCESSOR_URL_DEFAULT,
                            '/payments',
                            $payloadApi
                        )
                    );

                    $parsedOffset = 0;

                    do {
                        $client->recv($buffer, $buffer->getLength());
                        $parsedOffset += $parser->execute($buffer, $parsedOffset);

                        if ($parser->getEvent() === Parser::EVENT_CHUNK_COMPLETE) {
                            $buffer->truncateFrom($parsedOffset);
                            $parsedOffset = 0;
                            continue;
                        }

                        if ($parser->getEvent() === Parser::EVENT_MESSAGE_COMPLETE) {
                            $buffer->truncateFrom($parsedOffset);
                            break;
                        }
                    } while (true);

                    if ($parser->getStatusCode() == 200) {
                        $successfullyInsertedPayments->push(['timestamp' => $nowPersist, 'amount' => extractAmount($payloadApi)]);
                        continue;
                    }

                    $errorChannel->push($payload);
                } catch (Swow\Http\ParserException $e) {
                    $errorChannel->push($payload);
                } catch (Throwable $t) {
                    $errorChannel->push($payload);
                }
            }
        });
    }
});

\Swow\Sync\waitAll();
