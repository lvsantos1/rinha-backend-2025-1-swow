<?php

use Swow\Buffer;

// Externo
define('PAYMENT_PROCESSOR_URL_DEFAULT', 'payment-processor-default');
define('PAYMENT_PROCESSOR_PORT_DEFAULT', (int) '8080');
define('PAYMENT_PROCESSOR_URL_FALLBACK', 'payment-processor-fallback');
define('PAYMENT_PROCESSOR_PORT_FALLBACK', (int) '8080');

// HTTP Parse
define('API_HTTP_BACKLOG', 1024);
define('HTTP_PARSER_BUFFER_SIZE', Buffer::COMMON_SIZE * 2 * 2);

// IPC
define('WORKERS_IPC_SOCKET_PATTERN', '/ipc/workers_%s.sock');
define('WORKERS_IPC_SOCKET', sprintf(WORKERS_IPC_SOCKET_PATTERN, getenv("INTERNAL_ID")));
define('API_IPC_SOCKET', sprintf('/ipc/api_%s.sock', getenv("INTERNAL_ID")));
define('API_HTTP_SOCKET', sprintf('/http/api_%s.sock', getenv("INTERNAL_ID")));

// Balanceamento
define('API_PAYMENTS_CACHE_CAPACITY', 2500);
define('WORKERS_NUM_SOCKETS', 3);
define('WORKERS_NUM', 25);
define('ERRORS_WORKERS_NUM', 25);
define('WORKERS_PAYMENTS_CACHE_CAPACITY', 5000);
define('WORKERS_PAYMENTS_CACHE_CAPACITY_ERRORS', 6000);
define('SUCCESSFULLY_INSERTED_PAYMENTS', 20000);
