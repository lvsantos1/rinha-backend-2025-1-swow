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

$dataTest = '{
    "correlationId": "2304a691-edbc-4227-bf06-3fcdc576cf02",
    "amount": 19.99,
    "requestedAt": "2025-08-01T23:40:58.769Z"
}';

function jsonDecodeExtraction(string $data){
    $decoded = json_decode($data, true);
    return $decoded['amount'];
}

function manualExtractionForeach(string $data){
    static $reference = '"amount":';

    $referencePos = strpos($data, $reference)+9;

    $num = '';

    foreach(range($referencePos, strlen($data)) as $charIndex) {
        if($data[$charIndex] == ',') {
            return $num;
        }

        $num .= $data[$charIndex];
    }
}

function manualExtractionSubstr(string $data) {
    static $reference = '"amount":';
    $referencePos = strpos($data, $reference);
    if ($referencePos === false) {
        return null; // caso não encontre
    }
    $referencePos += 9;

    $len = strlen($data);
    $numEnd = strpos($data, ',', $referencePos);
    if ($numEnd === false) {
        $numEnd = $len;
    }

    return substr($data, $referencePos, $numEnd - $referencePos);
}

function manualExtractionStrpbrk(string $data) {
    static $reference = '"amount":';
    $referencePos = strpos($data, $reference);
    if ($referencePos === false) {
        return null;
    }
    $referencePos += 9;

    // Pega o pedaço da string a partir do número
    $numPart = substr($data, $referencePos);

    // Acha a primeira vírgula e corta ali
    $commaPart = strpbrk($numPart, ',');
    if ($commaPart !== false) {
        // Remove a vírgula e tudo que vem depois
        $numPart = substr($numPart, 0, strlen($numPart) - strlen($commaPart));
    }

    return $numPart;
}

function extractAmountWhile(string $payload): string
{
    static $needle = '"amount":';
    $lenNeedle = strlen($needle);
    $pos = strpos($payload, $needle);
    $pos += $lenNeedle;
    $char = $payload[$pos] ?? '';

    // pular espaço se houver
    $pos += ($char === ' ' ? 1 : 0);

    $amount = '';
    $c = $payload[$pos] ?? '';

    while ($c !== '' && $c !== ',') {
        $amount .= $c;
        $pos++;
        $c = $payload[$pos] ?? '';
    }

    return $amount;
}

benchmarkFunc(10000, 'manualExtractionStrpbrk', [$dataTest]);
benchmarkFunc(10000, 'manualExtractionForeach', [$dataTest]);
benchmarkFunc(10000, 'jsonDecodeExtraction', [$dataTest]);
benchmarkFunc(10000, 'manualExtractionSubstr', [$dataTest]);
benchmarkFunc(10000, 'extractAmountWhile', [$dataTest]);