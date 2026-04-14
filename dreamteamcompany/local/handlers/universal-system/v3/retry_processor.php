<?php
// v3/retry_processor.php
// Cron-скрипт для повторной отправки заявок, которые не удалось создать через API.
// Запуск: php retry_processor.php
// Cron:   */2 * * * * php /path/to/v3/retry_processor.php

if (PHP_SAPI !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

$config = require __DIR__ . '/config.php';
$retryDir = $config['retry_dir'];

if (!is_dir($retryDir)) {
    echo "retry/ пуста\n";
    exit(0);
}

$files = glob("$retryDir/*.json");
if (empty($files)) {
    echo "Нет файлов для retry\n";
    exit(0);
}

$maxRetries = 10;
$processed = 0;
$failed = 0;

foreach ($files as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['lead_fields'])) {
        @rename($file, $config['errors_dir'] . '/' . basename($file));
        $failed++;
        continue;
    }

    $retryCount = $data['retry_count'] ?? 0;
    if ($retryCount >= $maxRetries) {
        if (!is_dir($config['errors_dir'])) { @mkdir($config['errors_dir'], 0777, true); }
        $data['error'] = 'max_retries_exceeded';
        @file_put_contents(
            $config['errors_dir'] . '/' . basename($file),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        @unlink($file);
        v3r_log('ERR', "max_retries phone={$data['phone']} domain={$data['domain']}", $config);
        $failed++;
        continue;
    }

    $result = v3r_createLead($data['lead_fields'], $config);

    if ($result && isset($result['result'])) {
        $leadId = $result['result'];
        v3r_log('OK', "retry_ok lead=$leadId phone={$data['phone']} domain={$data['domain']} attempt=" . ($retryCount + 1), $config);

        if (!is_dir($config['processed_dir'])) { @mkdir($config['processed_dir'], 0777, true); }
        $processedData = [
            'lead_id' => $leadId,
            'phone' => $data['phone'],
            'domain' => $data['domain'],
            'retried_at' => date('Y-m-d H:i:s'),
            'attempts' => $retryCount + 1,
        ];
        @file_put_contents(
            $config['processed_dir'] . '/processed_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.json',
            json_encode($processedData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        @unlink($file);
        $processed++;
    } else {
        $data['retry_count'] = $retryCount + 1;
        $data['last_error'] = json_encode($result, JSON_UNESCAPED_UNICODE);
        $data['last_retry'] = date('Y-m-d H:i:s');
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        v3r_log('RETRY', "still_failing phone={$data['phone']} domain={$data['domain']} attempt=" . ($retryCount + 1), $config);
        $failed++;
    }
}

echo "Retry: processed=$processed failed=$failed\n";

// --- Функции ---

function v3r_createLead(array $fields, array $config): ?array {
    $url = $config['webhook_url'] . 'crm.lead.add.json';
    $postData = http_build_query([
        'fields' => $fields,
        'params' => ['REGISTER_SONET_EVENT' => 'Y'],
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => $config['curl_timeout'] ?? 30,
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err || $httpCode !== 200) { return null; }
    $response = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) { return null; }
    return $response;
}

function v3r_log(string $level, string $message, array $config): void {
    $dir = dirname($config['log_file']);
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $line = '[' . date('Y-m-d H:i:s') . "] $level $message\n";
    @file_put_contents($config['log_file'], $line, FILE_APPEND | LOCK_EX);
}
