<?php

function benchmarkFunc(int $tics, string $funcName, array $args = []) {
    $start = hrtime(true);
    $result = null;
    for ($i = 1; $i <= $tics; $i++) {
        $result = $funcName(...$args);
    }
    $end = hrtime(true);

    echo PHP_EOL . '-----------------------------------------------------' . PHP_EOL;
    echo 'Function: ' . $funcName . PHP_EOL;
    echo 'Start time: ' . $start . PHP_EOL;
    echo 'End time: ' . $end . PHP_EOL;
    echo 'Total duration: ' . ($end - $start) . PHP_EOL;
    echo 'Result: ' . $result . PHP_EOL;
    echo '-----------------------------------------------------' . PHP_EOL;
}

function extractTimestampCalc(string $iso): int
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

function extractTimestampSttrToTime(string $iso): int
{
    return (int)(strtotime($iso) * 1000);
}

function extractTimestampDatetimeImutable(string $iso): int
{
    return (new DateTimeImmutable($iso))->format('Uv');
}

// testa funcoes de conversão de iso para timestamp com ms

$testTimestamp = "2025-08-07T00:23:11.715Z";

benchmarkFunc(1000, 'extractTimestampCalc', [$testTimestamp]);
benchmarkFunc(1000, 'extractTimestampSttrToTime', [$testTimestamp]);
benchmarkFunc(1000, 'extractTimestampDatetimeImutable', [$testTimestamp]);
