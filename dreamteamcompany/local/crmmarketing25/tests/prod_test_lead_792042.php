<?php
declare(strict_types=1);

/**
 * Manual production test:
 * Reprocess deal 792042 through the live webhook handler URL.
 *
 * Usage:
 *   php tests/prod_test_lead_792042.php
 *   php tests/prod_test_lead_792042.php --format=json
 */

function testLeadOutError(string $message): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, $message . PHP_EOL);
        return;
    }
    echo $message . PHP_EOL;
}

$argvList = [];
if (isset($argv) && is_array($argv)) {
    $argvList = $argv;
} elseif (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $argvList = $_SERVER['argv'];
}
$isJsonOutput = in_array('--format=json', $argvList, true)
    || (isset($_GET['format']) && $_GET['format'] === 'json');

$baseDir = dirname(__DIR__);
$configPath = $baseDir . '/deal_webhook_config.php';
if (!is_file($configPath)) {
    testLeadOutError("Config file not found: {$configPath}");
    exit(1);
}

$config = require $configPath;
if (!is_array($config)) {
    testLeadOutError('Invalid config structure');
    exit(1);
}

$handlerBaseUrl = (string)($config['notifications']['reprocess_url_base'] ?? '');
if ($handlerBaseUrl === '') {
    testLeadOutError('notifications.reprocess_url_base is empty in config');
    exit(1);
}

$dealId = 792042;
$url = $handlerBaseUrl . (strpos($handlerBaseUrl, '?') === false ? '?' : '&') . http_build_query(['deal_id' => $dealId]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
if ($response === false) {
    $error = curl_error($ch);
    curl_close($ch);
    testLeadOutError("HTTP request failed: {$error}");
    exit(1);
}

$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$decoded = json_decode($response, true);
if (!is_array($decoded)) {
    testLeadOutError('Response is not valid JSON');
    exit(1);
}

$status = (string)($decoded['status'] ?? 'unknown');
if (!in_array($status, ['success', 'already_processed'], true)) {
    testLeadOutError("Unexpected status: {$status}");
    exit(1);
}

if ($isJsonOutput) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'deal_id' => $dealId,
        'request_url' => $url,
        'http_code' => $httpCode,
        'status' => $status,
        'response' => $decoded,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}

echo "Request URL: {$url}\n";
echo "HTTP code: {$httpCode}\n";
echo "Raw response: {$response}\n";
echo "Test completed with status: {$status}\n";
exit(0);
