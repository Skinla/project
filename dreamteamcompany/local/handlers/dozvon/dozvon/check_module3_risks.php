<?php
/**
 * Диагностика рисков модуля 3: структура таблиц voximplant и списков 22/128.
 *
 * Запуск: php check_module3_risks.php [DOCUMENT_ROOT]
 *   или в браузере под админом: /local/handlers/dozvon/check_module3_risks.php
 *
 * Проверяет:
 * - b_voximplant_call, b_voximplant_call_user — колонки (LAST_PING, INSERTED)
 * - Список 22 — поля, наличие поля для SIP-линий
 * - Список 128 — поля OPERATOR, STATUS, enum free/busy
 * - REST: voximplant.line.get (если задан MODULE3_REST_WEBHOOK_URL)
 */

$isCli = (php_sapi_name() === 'cli');
if ($isCli && (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT']))) {
    $docRoot = $argv[1] ?? __DIR__ . '/../../../..';
    if (is_dir($docRoot)) {
        $_SERVER['DOCUMENT_ROOT'] = realpath($docRoot);
    }
}

if (empty($_SERVER['DOCUMENT_ROOT']) || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DOCUMENT_ROOT not set or Bitrix not found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';

$config = $GLOBALS['DOZVON_CONFIG'] ?? [];
$out = [
    'timestamp' => date('Y-m-d H:i:s'),
    'tables' => [],
    'list_22' => [],
    'list_128' => [],
    'rest_voximplant_lines' => null,
    'risks' => [],
];

// --- 1. Структура таблиц voximplant ---
$tables = ['b_voximplant_call', 'b_voximplant_call_user'];
$requiredColumns = [
    'b_voximplant_call' => ['CALL_ID', 'USER_ID', 'STATUS', 'LAST_PING'],
    'b_voximplant_call_user' => ['CALL_ID', 'USER_ID', 'STATUS', 'INSERTED'],
];

global $DB;
if (is_object($DB)) {
    foreach ($tables as $table) {
        $cols = [];
        $res = $DB->Query("SHOW COLUMNS FROM `" . preg_replace('/[^a-zA-Z0-9_]/', '', $table) . "`");
        if ($res) {
            while ($row = $res->Fetch()) {
                $cols[] = $row['Field'] ?? $row['field'] ?? '';
            }
        }
        $out['tables'][$table] = [
            'columns' => $cols,
            'has_last_ping' => in_array('LAST_PING', $cols, true),
            'has_inserted' => in_array('INSERTED', $cols, true),
        ];
        $required = $requiredColumns[$table] ?? [];
        foreach ($required as $rc) {
            if (!in_array($rc, $cols, true)) {
                $out['risks'][] = "Таблица {$table}: отсутствует колонка {$rc}";
            }
        }
    }
} else {
    $out['risks'][] = 'Глобальный $DB недоступен, проверка таблиц пропущена';
}

// --- 2. Список 22 (города) ---
$list22Id = 22;
if (\Bitrix\Main\Loader::includeModule('iblock')) {
    $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $list22Id]);
    $props22 = [];
    $hasSipField = false;
    $sipFieldCandidates = ['SIP_LINE', 'SIP_LINES', 'SIP_NUMBER', 'LINE_ID', 'CITY_LINE', 'GORODSKAYA_LINIYA'];
    while ($p = $res->Fetch()) {
        $code = $p['CODE'] ?? '';
        $props22[] = [
            'code' => $code,
            'name' => $p['NAME'] ?? '',
            'type' => $p['PROPERTY_TYPE'] ?? '',
        ];
        if (in_array(mb_strtoupper($code), array_map('mb_strtoupper', $sipFieldCandidates), true)) {
            $hasSipField = true;
        }
    }
    $out['list_22'] = [
        'iblock_id' => $list22Id,
        'properties' => $props22,
        'has_sip_field' => $hasSipField,
    ];
    if (!$hasSipField) {
        $out['risks'][] = 'Список 22: не найдено поле для SIP-линий. Добавьте поле (например SIP_LINE или LINE_ID) или укажите код существующего.';
    }
} else {
    $out['risks'][] = 'Модуль iblock не загружен, проверка списка 22 пропущена';
}

// --- 3. Список 128 (операторы) ---
$list128Id = 128;
if (\Bitrix\Main\Loader::includeModule('iblock')) {
    $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $list128Id]);
    $props128 = [];
    $hasOperator = false;
    $hasStatus = false;
    $statusFreeId = null;
    $statusBusyId = null;
    while ($p = $res->Fetch()) {
        $code = $p['CODE'] ?? '';
        $item = [
            'code' => $code,
            'name' => $p['NAME'] ?? '',
            'type' => $p['PROPERTY_TYPE'] ?? '',
        ];
        if ($code === 'OPERATOR') {
            $hasOperator = true;
        }
        if ($code === 'STATUS' && ($p['PROPERTY_TYPE'] ?? '') === 'L') {
            $hasStatus = true;
            $enumRes = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $p['ID']]);
            while ($e = $enumRes->Fetch()) {
                $v = mb_strtolower($e['VALUE'] ?? $e['XML_ID'] ?? '');
                if (strpos($v, 'free') !== false || strpos($v, 'свобод') !== false) {
                    $statusFreeId = (int)($e['ID'] ?? 0);
                }
                if (strpos($v, 'busy') !== false || strpos($v, 'занят') !== false) {
                    $statusBusyId = (int)($e['ID'] ?? 0);
                }
            }
        }
        $props128[] = $item;
    }
    $out['list_128'] = [
        'iblock_id' => $list128Id,
        'properties' => $props128,
        'has_operator' => $hasOperator,
        'has_status' => $hasStatus,
        'status_free_enum_id' => $statusFreeId,
        'status_busy_enum_id' => $statusBusyId,
    ];
    if (!$hasOperator) {
        $out['risks'][] = 'Список 128: не найдено поле OPERATOR';
    }
    if (!$hasStatus) {
        $out['risks'][] = 'Список 128: не найдено поле STATUS (список)';
    }
}

// --- 4. REST: voximplant.line.get (если задан webhook) ---
$webhookUrl = $config['MODULE3_REST_WEBHOOK_URL'] ?? $config['MODULE2_LISTS_WEBHOOK_BASE_URL'] ?? '';
if ($webhookUrl !== '') {
    $restUrl = rtrim($webhookUrl, '/') . '/voximplant.line.get';
    $ch = curl_init($restUrl);
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false) {
            $data = json_decode($resp, true);
            $out['rest_voximplant_lines'] = [
                'http_code' => $httpCode,
                'result' => $data['result'] ?? null,
                'error' => $data['error'] ?? null,
            ];
            if (!empty($data['error'])) {
                $out['risks'][] = 'REST voximplant.line.get: ' . ($data['error_description'] ?? $data['error']);
            }
        } else {
            $out['rest_voximplant_lines'] = ['error' => 'curl failed'];
            $out['risks'][] = 'REST voximplant.line.get: не удалось выполнить запрос';
        }
    }
} else {
    $out['rest_voximplant_lines'] = 'MODULE3_REST_WEBHOOK_URL или MODULE2_LISTS_WEBHOOK_BASE_URL не задан — проверка REST пропущена';
}

// --- Сводка ---
$out['summary'] = [
    'risks_count' => count($out['risks']),
    'ok' => empty($out['risks']),
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
