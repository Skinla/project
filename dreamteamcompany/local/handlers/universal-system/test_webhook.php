<?php
declare(strict_types=1);

$logsDir = __DIR__ . '/logs';
$logFile = $logsDir . '/test_webhook.log';

if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    $rawBody = '';
}

$headers = [];
if (function_exists('getallheaders')) {
    $tmpHeaders = getallheaders();
    if (is_array($tmpHeaders)) {
        $headers = $tmpHeaders;
    }
}

if ($headers === []) {
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0 || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'REQUEST_METHOD', 'REQUEST_URI', 'QUERY_STRING'], true)) {
            $headers[$key] = $value;
        }
    }
}

$entry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'get' => $_GET,
    'post' => $_POST,
    'headers' => $headers,
    'raw_body' => $rawBody,
    'is_empty_request' => ($_GET === [] && $_POST === [] && trim($rawBody) === ''),
];

$encodedEntry = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($encodedEntry === false) {
    $encodedEntry = json_encode([
        'timestamp' => date('Y-m-d H:i:s'),
        'error' => 'json_encode_failed',
        'json_error' => json_last_error_msg(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

@file_put_contents($logFile, $encodedEntry . PHP_EOL, FILE_APPEND);

header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

echo json_encode([
    'status' => 'ok',
    'message' => 'logged',
    'log_file' => 'logs/test_webhook.log',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
