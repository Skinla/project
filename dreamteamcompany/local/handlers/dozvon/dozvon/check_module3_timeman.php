<?php
/**
 * Проверка статуса рабочего дня операторов через REST webhook (timeman.status).
 * Запуск: php check_module3_timeman.php [uid1,uid2,...]
 * Пример: php check_module3_timeman.php 40,45,46,47,50,49219
 *
 * Требование: webhook должен иметь scope timeman (Учёт времени).
 * Если получаете "higher privileges" — добавьте scope или используйте check_module3_timeman_local.php на сервере.
 */

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) {
    die("config.php not found\n");
}
$config = require $configPath;
$webhookUrl = (string)($config['MODULE3_REST_WEBHOOK_URL'] ?? '');
if ($webhookUrl === '') {
    die("MODULE3_REST_WEBHOOK_URL not configured\n");
}

$baseUrl = rtrim($webhookUrl, '/');
$userIds = [];
if (!empty($argv[1])) {
    $userIds = array_map('intval', array_filter(explode(',', $argv[1])));
}
if ($userIds === []) {
    $userIds = [40, 45, 46, 47, 50, 49219];
}

$callRest = function (string $method, array $params = []) use ($baseUrl): array {
    $url = $baseUrl . '/' . $method;
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($response === false) {
        return ['error' => 'request_failed: ' . $curlErr];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['error' => 'invalid_json', '_raw' => substr($response, 0, 200)];
    }
    if (!empty($decoded['error'])) {
        return ['error' => (string)($decoded['error_description'] ?? $decoded['error'])];
    }
    return $decoded;
};

$getUserName = function (int $uid) use ($callRest): string {
    $r = $callRest('user.get', ['ID' => $uid]);
    if (!empty($r['error']) || empty($r['result'][0])) {
        return '';
    }
    $u = $r['result'][0];
    return trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? ''));
};

echo "=== Проверка рабочего дня операторов (timeman.status) ===\n";
echo "Webhook: {$baseUrl}\n";
echo "Проверяем: " . implode(', ', $userIds) . "\n\n";

foreach ($userIds as $uid) {
    $name = $getUserName($uid);
    $label = $name !== '' ? "{$uid} ({$name})" : (string)$uid;

    $r = $callRest('timeman.status', ['USER_ID' => $uid]);
    if (!empty($r['error'])) {
        echo "{$label}: ОШИБКА — {$r['error']}\n";
        continue;
    }

    $result = $r['result'] ?? null;
    if ($result === null || $result === false) {
        echo "{$label}: нет данных (рабочий день не начат или закрыт)\n";
        continue;
    }

    $status = $result['STATUS'] ?? '?';
    $timeStart = $result['TIME_START'] ?? '?';
    $timeFinish = $result['TIME_FINISH'] ?? '?';
    $active = $result['ACTIVE'] ?? '?';

    $ok = in_array($status, ['OPENED', 'PAUSED'], true) ? 'OK' : 'НЕ НАЧАТ';
    echo "{$label}: {$status} ({$ok}) | START={$timeStart} FINISH={$timeFinish} ACTIVE={$active}\n";
}

echo "\n=== Конец проверки ===\n";
echo "Модуль 3 принимает только OPENED и PAUSED. Остальные — workday_not_started.\n";
