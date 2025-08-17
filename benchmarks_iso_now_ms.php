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
    echo '-----------------------------------------------------' . PHP_EOL;
}

function isoNowMsCalc(): array
{
    $microtime = microtime(true);
    $seconds   = (int) $microtime;
    $ms        = (int)(($microtime - $seconds) * 1000);

    $dt = getdate($seconds);

    $Y = (string)$dt['year'];
    $m = ($dt['mon']     < 10 ? '0'.$dt['mon']     : (string)$dt['mon']);
    $d = ($dt['mday']    < 10 ? '0'.$dt['mday']    : (string)$dt['mday']);
    $H = ($dt['hours']   < 10 ? '0'.$dt['hours']   : (string)$dt['hours']);
    $i = ($dt['minutes'] < 10 ? '0'.$dt['minutes'] : (string)$dt['minutes']);
    $s = ($dt['seconds'] < 10 ? '0'.$dt['seconds'] : (string)$dt['seconds']);

    // milissegundos
    $M = ($ms < 10 ? '00'.$ms : ($ms < 100 ? '0'.$ms : (string)$ms));

    $iso = $Y.'-'.$m.'-'.$d.'T'.$H.':'.$i.':'.$s.'.'.$M.'Z';

    return [$iso, $seconds * 1000 + $ms];
}

function isoNowMsGmDate(): array
{
    $microtime = microtime(true);
    $seconds   = (int)$microtime;
    $ms        = (int)(($microtime - $seconds) * 1000);
    $ms        = str_pad((string)$ms, 3, '0', STR_PAD_LEFT);

    return [gmdate('Y-m-d\TH:i:s', $seconds) . '.' . $ms . 'Z', $seconds . $ms];
}

function isoNowMsArit(): array {
    $micro = microtime(true);
    $ts    = (int)$micro;
    $ms    = (int)(($micro - $ts) * 1000);

    // calcular data a partir do timestamp UTC
    $days = intdiv($ts, 86400);   // dias desde epoch
    $secs = $ts % 86400;          // segundos do dia

    // cálculo do ano / mês / dia via algoritmo de calendário (Gregorian)
    $l = $days + 68569 + 2440588;
    $n = intdiv(4 * $l, 146097);
    $l = $l - intdiv((146097 * $n + 3), 4);
    $i = intdiv(4000 * ($l + 1), 1461001);
    $l = $l - intdiv(1461 * $i, 4) + 31;
    $j = intdiv(80 * $l, 2447);
    $day = $l - intdiv(2447 * $j, 80);
    $l = intdiv($j, 11);
    $month = $j + 2 - 12 * $l;
    $year = 100 * ($n - 49) + $i + $l;

    // hora / minuto / segundo
    $hour   = intdiv($secs, 3600);
    $minute = intdiv($secs % 3600, 60);
    $second = $secs % 60;

    // montar ISO sem sprintf, com ternários
    $iso =
        ($year < 1000 ? '0' : '') . ($year < 100 ? '0' : '') . ($year < 10 ? '0' : '') . $year
        . '-' . ($month < 10 ? '0' : '') . $month
        . '-' . ($day < 10 ? '0' : '') . $day
        . 'T' . ($hour < 10 ? '0' : '') . $hour
        . ':' . ($minute < 10 ? '0' : '') . $minute
        . ':' . ($second < 10 ? '0' : '') . $second
        . '.' . ($ms < 100 ? ($ms < 10 ? '00' : '0') : '') . $ms
        . 'Z';

    return [$iso, $ts * 1000 + $ms];
}

benchmarkFunc(1000, 'isoNowMsGmDate', []);
benchmarkFunc(1000, 'isoNowMsCalc', []);
benchmarkFunc(1000, 'isoNowMsArit', []);
