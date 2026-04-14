<?php
/**
 * Проверка таблиц модуля 3 через REST webhook.
 * Запуск: php check_module3_tables_webhook.php
 *
 * Показывает:
 * - Попытки 131: planned/ready с SCHEDULED_AT <= now
 * - Попытки operator_calling с CALL_ID (для fix)
 * - Мастер-элементы 130: active, не completed
 * - Операторы 128: free по городам
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
$tz = 'Europe/Moscow';
date_default_timezone_set($tz);
$now = date('d.m.Y H:i:s');

$callRest = function (string $method, array $params = []) use ($baseUrl): array {
    $url = $baseUrl . '/' . $method;
    $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $payload,
            'timeout' => 15,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return ['error' => 'request_failed'];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return ['error' => 'invalid_json'];
    }
    if (!empty($decoded['error'])) {
        return ['error' => (string)($decoded['error_description'] ?? $decoded['error'])];
    }
    return $decoded;
};

$common = [
    'IBLOCK_TYPE_ID' => 'lists_socnet',
    'SOCNET_GROUP_ID' => 1,
];

echo "=== Проверка таблиц модуля 3 через webhook ===\n";
echo "Текущее время: {$now} ({$tz})\n";
echo "Webhook: {$baseUrl}\n\n";

// 1. Поля списка 131 (STATUS enum)
$fields131 = $callRest('lists.field.get', array_merge($common, ['IBLOCK_ID' => 131]));
echo "--- Список 131 (попытки): статусы ---\n";
if (!empty($fields131['error'])) {
    echo "Ошибка: " . $fields131['error'] . "\n";
} else {
    $statusField = $fields131['result']['PROPERTY_577'] ?? [];
    $statusEnums = $statusField['DISPLAY_VALUES_FORM'] ?? [];
    echo "STATUS enum: " . json_encode($statusEnums, JSON_UNESCAPED_UNICODE) . "\n";
}
echo "\n";

// 2. Попытки ready (1182)
$ready = $callRest('lists.element.get', array_merge($common, [
    'IBLOCK_ID' => 131,
    'FILTER' => ['PROPERTY_577' => 1182],
    'NAV_PARAMS' => ['nPageSize' => 20],
]));
echo "--- Попытки ready (STATUS=1182) ---\n";
if (!empty($ready['error'])) {
    echo "Ошибка: " . $ready['error'] . "\n";
} else {
    $items = $ready['result'] ?? [];
    echo "Найдено: " . count($items) . "\n";
    foreach (array_slice($items, 0, 5) as $el) {
        $sch = $el['PROPERTY_576'] ?? [];
        $schVal = $sch ? reset($sch) : '?';
        $mid = $el['PROPERTY_590'] ?? [];
        $midVal = $mid ? reset($mid) : '?';
        echo "  ID {$el['ID']} SCHEDULED_AT={$schVal} MASTER={$midVal}\n";
    }
}
echo "\n";

// 3. Попытки planned (1181) — для активации
$planned = $callRest('lists.element.get', array_merge($common, [
    'IBLOCK_ID' => 131,
    'FILTER' => ['PROPERTY_577' => 1181],
    'NAV_PARAMS' => ['nPageSize' => 50],
]));
echo "--- Попытки planned (STATUS=1181), для активации ---\n";
if (!empty($planned['error'])) {
    echo "Ошибка: " . $planned['error'] . "\n";
} else {
    $items = $planned['result'] ?? [];
    echo "Найдено: " . count($items) . "\n";
    $pastDue = 0;
    foreach ($items as $el) {
        $sch = $el['PROPERTY_576'] ?? [];
        $schVal = $sch ? reset($sch) : '';
        if ($schVal !== '' && $schVal <= $now) {
            $pastDue++;
        }
    }
    echo "С просроченным SCHEDULED_AT (<= {$now}): {$pastDue}\n";
    foreach (array_slice($items, 0, 5) as $el) {
        $sch = $el['PROPERTY_576'] ?? [];
        $schVal = $sch ? reset($sch) : '?';
        $mid = $el['PROPERTY_590'] ?? [];
        $midVal = $mid ? reset($mid) : '?';
        $pastDueMark = ($schVal !== '?' && $schVal <= $now) ? ' [ПРОСРОЧЕНО]' : '';
        echo "  ID {$el['ID']} SCHEDULED_AT={$schVal} MASTER={$midVal}{$pastDueMark}\n";
    }
}
echo "\n";

// 4. Попытки operator_calling (1187) с CALL_ID
$calling = $callRest('lists.element.get', array_merge($common, [
    'IBLOCK_ID' => 131,
    'FILTER' => ['PROPERTY_577' => 1187],
    'NAV_PARAMS' => ['nPageSize' => 20],
]));
echo "--- Попытки operator_calling (STATUS=1187) для fix ---\n";
if (!empty($calling['error'])) {
    echo "Ошибка: " . $calling['error'] . "\n";
} else {
    $items = $calling['result'] ?? [];
    $withCallId = 0;
    foreach ($items as $el) {
        $cid = $el['PROPERTY_581'] ?? [];
        $cidVal = $cid ? reset($cid) : '';
        if ($cidVal !== '') {
            $withCallId++;
        }
    }
    echo "Найдено: " . count($items) . ", с CALL_ID: {$withCallId}\n";
    foreach (array_slice($items, 0, 5) as $el) {
        $cid = $el['PROPERTY_581'] ?? [];
        $cidVal = $cid ? reset($cid) : '';
        echo "  ID {$el['ID']} CALL_ID=" . ($cidVal ?: '(пусто)') . "\n";
    }
}
echo "\n";

// 5. Мастер-элементы 130: active (1177), не completed
$masters = $callRest('lists.element.get', array_merge($common, [
    'IBLOCK_ID' => 130,
    'FILTER' => [
        'PROPERTY_546' => 1177,
        '!PROPERTY_545' => [1175, 1176],
    ],
    'NAV_PARAMS' => ['nPageSize' => 10],
]));
echo "--- Мастер 130: CALLING_CONTROL=active, STATUS не completed/cancelled ---\n";
if (!empty($masters['error'])) {
    echo "Ошибка (фильтр может не поддерживаться): " . $masters['error'] . "\n";
} else {
    $items = $masters['result'] ?? [];
    echo "Найдено: " . count($items) . "\n";
}
echo "\n";

// 6. Операторы 128: free (1163) по городам
$operators = $callRest('lists.element.get', array_merge($common, [
    'IBLOCK_ID' => 128,
    'FILTER' => ['PROPERTY_534' => 1163],
    'NAV_PARAMS' => ['nPageSize' => 20],
]));
echo "--- Операторы 128: STATUS=free (1163) ---\n";
if (!empty($operators['error'])) {
    echo "Ошибка: " . $operators['error'] . "\n";
} else {
    $items = $operators['result'] ?? [];
    echo "Найдено: " . count($items) . "\n";
    foreach (array_slice($items, 0, 5) as $el) {
        $op = $el['PROPERTY_533'] ?? [];
        $gorod = $el['PROPERTY_601'] ?? [];
        $opVal = $op ? reset($op) : '?';
        $gorodVal = $gorod ? reset($gorod) : '?';
        echo "  ID {$el['ID']} OPERATOR={$opVal} GOROD={$gorodVal}\n";
    }
}

echo "\n=== Конец проверки ===\n";
