<?php
// v3/webhook_handler.php
// Единый синхронный обработчик входящих заявок.
// Принимает POST/GET, парсит данные, ищет настройки в ИБ54,
// создает лид через crm.lead.add, при ошибке API сохраняет в retry/.
//
// Подключается из production webhook.php:
//   require_once __DIR__ . '/universal-system/v3/webhook_handler.php';

error_reporting(E_ALL);
ini_set('display_errors', 0);

$v3StartTime = microtime(true);
$v3Config = require __DIR__ . '/config.php';

// =========================================================================
// 1. Тест Тильды (до загрузки Bitrix -- быстрый ответ)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['test']) && $_POST['test'] === 'test') {
        v3_jsonResponse(['status' => 'ok', 'message' => 'webhook_available']);
    }
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $dec = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($dec['test']) && $dec['test'] === 'test') {
            v3_jsonResponse(['status' => 'ok', 'message' => 'webhook_available']);
        }
    }
} else {
    $rawBody = '';
}

// =========================================================================
// 2. Парсинг входных данных
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $inputData = $_GET;
    $rawBody = http_build_query($_GET);
} else {
    if (empty($rawBody)) {
        $rawBody = file_get_contents('php://input');
    }
    $inputData = [];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $dec = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($dec) && !empty($dec)) {
            $inputData = $dec;
        }
    }
    if (empty($inputData) && stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
        parse_str(urldecode($rawBody), $parsed);
        if (!empty($parsed) && is_array($parsed)) {
            $inputData = $parsed;
        }
    }
    if (empty($inputData) && !empty($_POST)) {
        $inputData = $_POST;
    }
    if (empty($inputData)) {
        $inputData = ['rawBody' => $rawBody];
    }
}

// =========================================================================
// 3. Определение домена (source_domain)
// =========================================================================
$domain = 'unknown.domain';
$refererHost = !empty($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) : null;
$originHost = !empty($_SERVER['HTTP_ORIGIN']) ? parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST) : null;
$extraHref = $inputData['extra']['href'] ?? '';
$extraRef = $inputData['extra']['referrer'] ?? '';
$submUrl = $inputData['__submission']['source_url'] ?? '';

$domainCandidates = array_filter([
    $refererHost,
    $originHost,
    $extraHref ? parse_url($extraHref, PHP_URL_HOST) : null,
    $inputData['ASSIGNED_BY_ID'] ?? null,
    $inputData['source_domain'] ?? null,
    $submUrl ? parse_url($submUrl, PHP_URL_HOST) : null,
    $extraRef ? parse_url($extraRef, PHP_URL_HOST) : null,
    $inputData['subPoolName'] ?? null,
    !empty($inputData['url']) ? parse_url($inputData['url'], PHP_URL_HOST) : null,
]);

if (!empty($_GET['domain'])) {
    $domain = $_GET['domain'];
} else {
    foreach ($domainCandidates as $val) {
        if ($val === 'mrqz.me' && $extraRef) {
            $ref = parse_url($extraRef, PHP_URL_HOST);
            if ($ref) { $val = $ref; }
        }
        $domain = $val;
        break;
    }
}

// Bitrix24 webhook: SOURCE_DESCRIPTION в QUERY_STRING перезаписывает домен
$queryString = $_SERVER['QUERY_STRING'] ?? '';
$decodedQs = urldecode($queryString);
if ($domain === 'unknown.domain' && preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', $decodedQs, $m)) {
    $domain = $m[1];
}

$inputData['source_domain'] = $domain;

// =========================================================================
// 4. Извлечение телефона
// =========================================================================
$phone = v3_extractPhone($inputData, $decodedQs);

$hasBitrixPhoneInQuery = (
    strpos($queryString, 'fields%5BPHONE%5D') !== false ||
    strpos($decodedQs, 'fields[PHONE]') !== false
);
if (empty(trim($phone)) && !$hasBitrixPhoneInQuery) {
    v3_jsonResponse(['status' => 'ok', 'message' => 'empty_phone']);
}

// =========================================================================
// 5. Загрузка ядра Bitrix (BX_CRONTAB -- без шаблонов и авторизации)
// =========================================================================
$v3T = ['parse' => round(microtime(true) - $v3StartTime, 3)];
$v3Ts = microtime(true);
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    $documentRoot = $_SERVER['DOCUMENT_ROOT']
        ?? (is_dir(dirname(__DIR__, 5) . '/bitrix') ? dirname(__DIR__, 5) : dirname(__DIR__, 4));
    $_SERVER['DOCUMENT_ROOT'] = $documentRoot;

    define('NOT_CHECK_PERMISSIONS', true);
    define('NO_KEEP_STATISTIC', 'Y');
    define('NO_AGENT_STATISTIC', 'Y');
    define('NO_AGENT_CHECK', true);
    define('DisableEventsCheck', true);
    define('BX_NO_ACCELERATOR_RESET', true);
    define('STOP_STATISTICS', true);
    define('BX_CRONTAB', true);
    define('BX_CRONTAB_SUPPORT', true);

    $prologPath = $documentRoot . '/bitrix/modules/main/include.php';
    if (!file_exists($prologPath)) {
        v3_log('ERR', "bitrix_prolog_not_found path=$prologPath", $v3Config);
        v3_jsonResponse(['status' => 'error', 'message' => 'bitrix_include_not_found']);
    }
    require_once $prologPath;

    global $USER;
    if (!is_object($USER)) { $USER = new \CUser(); }
    if (!$USER->IsAuthorized()) { $USER->Authorize(1); }
}

if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    v3_log('ERR', 'iblock_module_failed', $v3Config);
    v3_jsonResponse(['status' => 'error', 'message' => 'iblock_module_failed']);
}

$v3T['bitrix'] = round(microtime(true) - $v3Ts, 3);
// =========================================================================
// 6. Поиск элемента в ИБ54 + построение полей лида
// =========================================================================
$v3Ts = microtime(true);
$isCallTouch = !empty($inputData['subPoolName']) && !empty($inputData['siteId']);
$calltouchSiteId = $inputData['siteId'] ?? ($inputData['PROPERTY_199'] ?? '');

$cacheKey = $isCallTouch ? ($inputData['subPoolName'] . '|' . $calltouchSiteId) : $domain;
$ib54 = v3_getCachedIb54($cacheKey, $isCallTouch, $inputData, $calltouchSiteId, $domain, $v3Config);

if (!$ib54) {
    $errMsg = $isCallTouch
        ? "domain_not_found subPool={$inputData['subPoolName']} siteId=$calltouchSiteId"
        : "domain_not_found domain=$domain";
    v3_log('ERR', "$errMsg phone=$phone", $v3Config);

    v3_saveToDir($v3Config['errors_dir'], [
        'error' => 'domain_not_found',
        'domain' => $domain,
        'phone' => $phone,
        'input' => $inputData,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);

    v3_sendChat(v3_buildErrorChatMessage('domain_not_found', [
        'domain' => $domain,
        'phone'  => $phone,
        'reason' => $isCallTouch
            ? "Не найден элемент в ИБ54 (subPool={$inputData['subPoolName']}, siteId=$calltouchSiteId)"
            : "Домен '$domain' не найден в инфоблоке 54",
    ]), $v3Config);

    v3_jsonResponse(['status' => 'error', 'message' => 'domain_not_found', 'domain' => $domain]);
}

$v3T['ib54'] = round(microtime(true) - $v3Ts, 3);
// =========================================================================
// 7. Нормализация (inline) + построение полей лида
// =========================================================================
$v3Ts = microtime(true);
$normalized = v3_normalize($inputData, $decodedQs, $phone);
$elementName = $ib54['NAME'] ?? $domain;

$leadFields = [
    'TITLE' => ($isCallTouch ? "Звонок с сайта [$elementName]" : "Лид с сайта [$elementName]"),
    'ASSIGNED_BY_ID' => $v3Config['assigned_by_id_default'],
    'CREATED_BY_ID' => $v3Config['assigned_by_id_default'],
    'PHONE' => [['VALUE' => $normalized['phone'], 'VALUE_TYPE' => 'WORK']],
    'COMMENTS' => $normalized['comment'],
    'SOURCE_DESCRIPTION' => $isCallTouch
        ? "CallTouch (siteId=$calltouchSiteId)"
        : "Сайт: $domain",
];

// Имя / фамилия
if (!empty($normalized['name'])) {
    $leadFields['NAME'] = $normalized['name'];
    if (!empty($normalized['last_name'])) {
        $leadFields['LAST_NAME'] = $normalized['last_name'];
    }
} else {
    $leadFields['NAME'] = 'Имя';
    $leadFields['LAST_NAME'] = 'Фамилия';
}

// UTM
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $u) {
    if (!empty($normalized[$u])) {
        $leadFields[strtoupper($u)] = $normalized[$u];
    }
}

// Свойства из ИБ54
$props = $ib54['properties'] ?? [];

if (!empty($props['PROPERTY_192']['VALUE'])) {
    $sourceId = v3_getLinkedProperty($props['PROPERTY_192']['VALUE'], 'PROPERTY_73');
    if ($sourceId) { $leadFields['SOURCE_ID'] = $sourceId; }
}
if (!empty($props['PROPERTY_191']['VALUE'])) {
    $assignedById = v3_getLinkedProperty($props['PROPERTY_191']['VALUE'], 'PROPERTY_185');
    if ($assignedById) {
        $leadFields['ASSIGNED_BY_ID'] = $assignedById;
        $leadFields['CREATED_BY_ID'] = $assignedById;
    }
    $leadFields['UF_CRM_1744362815'] = $props['PROPERTY_191']['VALUE'];
}
if (!empty($props['PROPERTY_193']['VALUE'])) {
    $leadFields['UF_CRM_1745957138'] = $props['PROPERTY_193']['VALUE'];
}
if (!empty($props['PROPERTY_194']['VALUE'])) {
    $leadFields['UF_CRM_5FC49F7DA5470'] = (string)$props['PROPERTY_194']['VALUE'];
}

// Наблюдатели (PROPERTY_195 -- может быть массивом)
if (!empty($props['PROPERTY_195']['VALUE'])) {
    $raw195 = $props['PROPERTY_195']['VALUE'];
    $observerIds = is_array($raw195) ? $raw195 : [$raw195];
    $observerIds = array_values(array_filter(array_map('intval', $observerIds), function($v) { return $v > 0; }));
    if (!empty($observerIds)) {
        $leadFields['OBSERVER_IDS'] = $observerIds;
    }
}

$v3T['normalize'] = round(microtime(true) - $v3Ts, 3);
// =========================================================================
// 8. Создание лида (D7 напрямую, fallback на REST API)
// =========================================================================
$v3Ts = microtime(true);
$apiResult = v3_createLeadD7($leadFields, $v3Config);
$v3T['lead_add'] = round(microtime(true) - $v3Ts, 3);
$elapsed = round(microtime(true) - $v3StartTime, 2);

if ($apiResult && isset($apiResult['result'])) {
    $leadId = $apiResult['result'];
    $usedD7 = !isset($apiResult['_via_rest']);
    $automationUserId = (int)($leadFields['CREATED_BY_ID'] ?? $v3Config['assigned_by_id_default']);
    $v3T['total'] = $elapsed;
    $timings = implode(' ', array_map(function($k, $v) { return "$k={$v}s"; }, array_keys($v3T), $v3T));
    $method = $usedD7 ? 'D7' : 'REST';
    v3_log('OK', "lead=$leadId phone={$normalized['phone']} domain=$domain [$method] $timings", $v3Config);

    v3_saveToDir($v3Config['processed_dir'], [
        'lead_id' => $leadId,
        'phone' => $normalized['phone'],
        'domain' => $domain,
        'timestamp' => date('Y-m-d H:i:s'),
    ]);
    v3_cleanupProcessed($v3Config);

    // Отправляем ответ клиенту и освобождаем воркер.
    // Автоматизация запускается ПОСЛЕ ответа, чтобы не блокировать
    // PHP-FPM воркеры на 7-10 секунд при пакетной нагрузке.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'lead_id' => $leadId], JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    if ($usedD7) {
        v3_runAutomation($leadId, $automationUserId, $v3Config);
    }
    exit;
}

// API вернул ошибку -- сохраняем для retry
$v3T['total'] = $elapsed;
$timings = implode(' ', array_map(function($k, $v) { return "$k={$v}s"; }, array_keys($v3T), $v3T));
v3_log('RETRY', "api_error phone={$normalized['phone']} domain=$domain $timings", $v3Config);

v3_saveToDir($v3Config['retry_dir'], [
    'lead_fields' => $leadFields,
    'phone' => $normalized['phone'],
    'domain' => $domain,
    'retry_count' => 0,
    'last_error' => json_encode($apiResult, JSON_UNESCAPED_UNICODE),
    'created_at' => date('Y-m-d H:i:s'),
]);

v3_sendChat(v3_buildErrorChatMessage('api_error', [
    'domain' => $domain,
    'phone'  => $normalized['phone'],
    'reason' => 'crm.lead.add вернул ошибку, заявка сохранена в retry',
    'extra'  => json_encode($apiResult, JSON_UNESCAPED_UNICODE),
]), $v3Config);

v3_jsonResponse(['status' => 'ok', 'message' => 'queued_for_retry']);


// =========================================================================
// Вспомогательные функции
// =========================================================================

function v3_jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --------------- Логирование ---------------

function v3_log(string $level, string $message, array $config): void {
    $dir = dirname($config['log_file']);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        error_log('v3_log mkdir failed: ' . $dir);
        return;
    }

    $path = $config['log_file'];
    if (file_exists($path) && filesize($path) > $config['max_log_size']) {
        $archivePath = $path . '.' . date('Ymd_His');
        if (!rename($path, $archivePath)) {
            error_log('v3_log rotate failed: ' . $path);
        }
    }

    $line = '[' . date('Y-m-d H:i:s') . "] $level $message\n";
    $written = file_put_contents($path, $line, FILE_APPEND);
    if ($written === false) {
        error_log('v3_log write failed: ' . $path . ' | ' . $line);
    }
}

// --------------- Извлечение телефона ---------------

function v3_extractPhone(array $data, string $decodedQs): string {
    // Bitrix24 webhook: fields[PHONE][0][VALUE] в QUERY_STRING
    if (preg_match('/fields\[PHONE\]\[0\]\[VALUE\]=([^&]*)/', $decodedQs, $m)) {
        return v3_normalizePhone($m[1]);
    }

    // Прямые поля: phone, Phone, PHONE
    foreach (['Phone', 'phone', 'PHONE'] as $f) {
        if (!empty($data[$f]) && is_string($data[$f])) {
            return v3_normalizePhone($data[$f]);
        }
    }

    // contacts.phone
    if (!empty($data['contacts']['phone'])) {
        return v3_normalizePhone($data['contacts']['phone']);
    }

    // CallTouch: callerphone
    if (!empty($data['callerphone'])) {
        return v3_normalizePhone($data['callerphone']);
    }

    // Phone_N (Phone_1, Phone_2, ...)
    foreach ($data as $key => $val) {
        if (is_string($val) && preg_match('/^(phone|Phone|PHONE)_\d+$/i', $key)) {
            $clean = preg_replace('/\D+/', '', $val);
            if (strlen($clean) >= 10) {
                return v3_normalizePhone($val);
            }
        }
    }

    return '';
}

function v3_normalizePhone(string $phone): string {
    $clean = preg_replace('/\D+/', '', $phone);
    if (empty($clean)) { return ''; }

    if (strlen($clean) === 11 && $clean[0] === '8') {
        $clean = '7' . substr($clean, 1);
    }
    if (strlen($clean) === 10) {
        return '+7' . $clean;
    }
    if (strlen($clean) >= 10 && $clean[0] === '7') {
        return '+' . $clean;
    }
    if (strlen($clean) >= 10) {
        return '+7' . $clean;
    }
    return $clean;
}

// --------------- Нормализация (inline, без классов) ---------------

function v3_normalize(array $data, string $decodedQs, string $fallbackPhone): array {
    $result = [
        'phone' => $fallbackPhone,
        'name' => '',
        'last_name' => '',
        'comment' => '',
        'source_domain' => $data['source_domain'] ?? 'unknown.domain',
        'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '',
        'utm_content' => '', 'utm_term' => '',
    ];

    $parsedData = $data['parsed_data'] ?? $data;

    // Bitrix24 webhook: поля из QUERY_STRING
    if (preg_match_all('/fields\[([^\]]+)\]=([^&]*)/', $decodedQs, $matches, PREG_SET_ORDER)) {
        $bxFields = [];
        foreach ($matches as $m) { $bxFields[$m[1]] = $m[2]; }

        if (!empty($bxFields['PHONE[0][VALUE]'])) {
            $result['phone'] = v3_normalizePhone($bxFields['PHONE[0][VALUE]']);
        }
        if (!empty($bxFields['NAME'])) { $result['name'] = $bxFields['NAME']; }
    }

    // Имя
    if (empty($result['name'])) {
        $result['name'] = v3_extractField($parsedData, ['name', 'Name', 'NAME', 'fio', 'FIO', 'fullname']);
    }

    // Фамилия
    $result['last_name'] = v3_extractField($parsedData, ['last_name', 'surname', 'lastName', 'LAST_NAME']);

    // UTM
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $u) {
        if (!empty($parsedData[$u])) { $result[$u] = $parsedData[$u]; }
    }

    // Комментарий
    $result['comment'] = v3_buildComment($parsedData);

    return $result;
}

function v3_extractField(array $data, array $candidates): string {
    foreach ($candidates as $f) {
        if (!empty($data[$f]) && is_string($data[$f]) && strlen($data[$f]) < 200) {
            $val = trim($data[$f]);
            if (!preg_match('/^\d+$/', $val)) { return $val; }
        }
    }
    if (isset($data['contacts']) && is_array($data['contacts'])) {
        foreach ($candidates as $f) {
            if (!empty($data['contacts'][$f]) && is_string($data['contacts'][$f])) {
                return trim($data['contacts'][$f]);
            }
        }
    }
    return '';
}

function v3_buildComment(array $data): string {
    // Quiz-формы с массивом answers (Koltach и др.)
    if (!empty($data['answers']) && is_array($data['answers'])) {
        $lines = [];
        foreach ($data['answers'] as $item) {
            $q = strip_tags(trim($item['q'] ?? ''));
            $a = $item['a'] ?? '';
            if (is_array($a)) { $a = implode(', ', $a); }
            $a = strip_tags(trim($a));
            if ($q !== '' && $a !== '') { $lines[] = "$q: $a"; }
        }
        if (!empty($lines)) { return implode("\n", $lines); }
    }

    $skip = [
        'phone', 'Phone', 'PHONE', 'name', 'Name', 'NAME',
        'source_domain', 'utm_source', 'utm_medium', 'utm_campaign',
        'utm_content', 'utm_term', 'formname', 'form_name', 'formid',
        'tranid', 'raw_body', 'raw_headers', 'parsed_data',
        'answers', 'raw', 'contacts', 'extra', 'ASSIGNED_BY_ID',
        'test', 'subPoolName', 'siteId', 'callerphone', 'rawBody',
    ];
    $lines = [];
    foreach ($data as $key => $val) {
        if (in_array($key, $skip, true)) { continue; }
        if (is_string($val) && $val !== '' && strlen($val) < 500) {
            $lines[] = str_replace('_', ' ', $key) . ': ' . strip_tags(trim($val));
        }
    }
    return implode("\n", $lines);
}

// --------------- Кэш ИБ54 ---------------

function v3_getCachedIb54(string $cacheKey, bool $isCallTouch, array $inputData, string $siteId, string $domain, array $config): ?array {
    $cacheFile = $config['cache_file'] ?? '';
    $refreshEvery = $config['cache_refresh_every'] ?? 100;
    if (empty($cacheFile)) {
        return $isCallTouch
            ? v3_getIb54ByCallTouch($inputData['subPoolName'], $siteId)
            : v3_getIb54ByDomain($domain);
    }

    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }

    $cache = [];
    $counter = 0;
    if (file_exists($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $decoded = $raw ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $cache = $decoded['entries'] ?? [];
            $counter = (int)($decoded['counter'] ?? 0);
        }
    }

    $counter++;
    $forceRefresh = ($counter % $refreshEvery === 0);

    if (!$forceRefresh && isset($cache[$cacheKey])) {
        return $cache[$cacheKey] ?: null;
    }

    $result = $isCallTouch
        ? v3_getIb54ByCallTouch($inputData['subPoolName'], $siteId)
        : v3_getIb54ByDomain($domain);

    if ($forceRefresh) {
        $cache = [];
    }
    $cache[$cacheKey] = $result ?: false;

    @file_put_contents($cacheFile, json_encode([
        'counter' => $counter,
        'updated_at' => date('Y-m-d H:i:s'),
        'entries' => $cache,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

    return $result;
}

// --------------- ИБ54: Bitrix D7 (CIBlockElement) ---------------

function v3_getIb54ByDomain(string $domain): ?array {
    $res = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 54, 'NAME' => $domain, 'ACTIVE' => 'Y'],
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME', 'IBLOCK_ID']
    );
    $obj = $res->GetNextElement();
    if (!$obj) { return null; }

    $fields = $obj->GetFields();
    $properties = $obj->GetProperties();
    return ['ID' => $fields['ID'], 'NAME' => $fields['NAME'], 'properties' => $properties];
}

function v3_getIb54ByCallTouch(string $subPoolName, string $siteId): ?array {
    $res = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 54, 'NAME' => $subPoolName, 'ACTIVE' => 'Y'],
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_ID']
    );
    while ($obj = $res->GetNextElement()) {
        $fields = $obj->GetFields();
        $properties = $obj->GetProperties();
        $propVal = $properties['PROPERTY_199']['VALUE'] ?? null;
        if ($propVal === $siteId || $propVal === (string)$siteId) {
            return ['ID' => $fields['ID'], 'NAME' => $fields['NAME'], 'properties' => $properties];
        }
    }
    return null;
}

function v3_getLinkedProperty(string $elementId, string $propertyCode): ?string {
    $res = \CIBlockElement::GetList(
        [],
        ['ID' => (int)$elementId],
        false,
        ['nTopCount' => 1],
        ['ID', 'IBLOCK_ID']
    );
    $obj = $res->GetNextElement();
    if (!$obj) { return null; }

    $props = $obj->GetProperties();
    return $props[$propertyCode]['VALUE'] ?? null;
}

// --------------- Автозапуск БП и роботов ---------------

function v3_runAutomation(int $leadId, int $userId, array $config): void {
    $aStart = microtime(true);

    try {
        // CRM-роботы (Automation Rules)
        $robotsOk = false;
        if (class_exists('\Bitrix\Crm\Automation\Starter')) {
            $starter = new \Bitrix\Crm\Automation\Starter(\CCrmOwnerType::Lead, $leadId);
            if ($userId > 0) {
                $starter->setUserId($userId);
            }
            $starter->runOnAdd();
            $robotsOk = true;
        } else {
            v3_log('WARN', "automation_starter_class_missing lead=$leadId", $config);
        }

        // БП с автозапуском «при создании»
        $bpOk = false;
        if (\Bitrix\Main\Loader::includeModule('bizproc')) {
            $bpErrors = [];
            \CCrmBizProcHelper::AutoStartWorkflows(
                \CCrmOwnerType::LeadName,
                $leadId,
                \CCrmBizProcEventType::Create,
                $bpErrors
            );
            $bpOk = empty($bpErrors);
            if (!$bpOk) {
                v3_log('WARN', "bp_autostart_errors lead=$leadId: " . json_encode($bpErrors, JSON_UNESCAPED_UNICODE), $config);
            }
        } else {
            v3_log('WARN', "bp_module_not_loaded lead=$leadId", $config);
        }

        $aElapsed = round(microtime(true) - $aStart, 3);
        v3_log('AUTO', "lead=$leadId user=$userId bp=" . ($bpOk ? 'ok' : 'fail') . " robots=" . ($robotsOk ? 'ok' : 'fail') . " {$aElapsed}s", $config);

    } catch (\Throwable $e) {
        $aElapsed = round(microtime(true) - $aStart, 3);
        v3_log('ERR', "automation_exception lead=$leadId: " . $e->getMessage() . " {$aElapsed}s", $config);
    }
}

// --------------- Создание лида: D7 напрямую ---------------

function v3_createLeadD7(array $fields, array $config): ?array {
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        v3_log('WARN', 'crm_module_failed, fallback to REST', $config);
        return v3_createLeadRest($fields, $config);
    }

    $lead = new \CCrmLead(false);

    $phoneData = $fields['PHONE'] ?? [];
    $fm = [];
    foreach ($phoneData as $p) {
        $fm['PHONE'][] = [
            'VALUE' => $p['VALUE'],
            'VALUE_TYPE' => $p['VALUE_TYPE'] ?? 'WORK',
        ];
    }
    unset($fields['PHONE']);

    $observerIds = $fields['OBSERVER_IDS'] ?? [];
    unset($fields['OBSERVER_IDS']);

    $fields['FM'] = $fm;

    $leadId = $lead->Add($fields, true, [
        'REGISTER_SONET_EVENT' => true,
        'CURRENT_USER' => $fields['CREATED_BY_ID'] ?? $config['assigned_by_id_default'],
    ]);

    if (!$leadId) {
        $errMsg = $lead->LAST_ERROR ?? 'unknown';
        v3_log('WARN', "d7_lead_add_failed: $errMsg, fallback to REST", $config);
        $fields['PHONE'] = $phoneData;
        $fields['OBSERVER_IDS'] = $observerIds;
        unset($fields['FM']);
        return v3_createLeadRest($fields, $config);
    }

    if (!empty($observerIds) && class_exists('\Bitrix\Crm\Observer\ObserverManager')) {
        foreach ($observerIds as $observerId) {
            \Bitrix\Crm\Observer\ObserverManager::registerBulk(
                (int)$leadId,
                \CCrmOwnerType::Lead,
                [(int)$observerId]
            );
        }
    }

    return ['result' => (int)$leadId];
}

// --------------- Создание лида: REST API (fallback) ---------------

function v3_createLeadRest(array $fields, array $config): ?array {
    $url = $config['webhook_url'] . 'crm.lead.add.json';
    $postData = http_build_query([
        'fields' => $fields,
        'params' => ['REGISTER_SONET_EVENT' => 'Y'],
    ]);

    $maxRetries = $config['max_retries'] ?? 3;
    $delay = $config['retry_delay'] ?? 1000000;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
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

        if ($err || $httpCode !== 200) {
            if ($attempt < $maxRetries) { usleep($delay); continue; }
            return null;
        }

        $response = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !isset($response['result'])) {
            if ($attempt < $maxRetries) { usleep($delay); continue; }
            if (is_array($response)) { $response['_via_rest'] = true; }
            return $response;
        }

        $response['_via_rest'] = true;
        return $response;
    }
    return null;
}

// --------------- Файловые операции ---------------

function v3_saveToDir(string $dir, array $data): ?string {
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
    $ts = date('Ymd_His');
    $rand = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
    $path = "$dir/{$ts}_{$rand}.json";
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($path, $json, LOCK_EX) === false) { return null; }
    return $path;
}

function v3_cleanupProcessed(array $config): void {
    $dir = $config['processed_dir'];
    if (!is_dir($dir)) { return; }
    $marker = "$dir/.cleanup_ts";
    if (file_exists($marker) && (time() - filemtime($marker)) < 300) { return; }
    @file_put_contents($marker, '');

    $files = glob("$dir/*.json");
    if (!$files) { return; }
    $max = $config['max_processed_files'] ?? 200;
    if (count($files) <= $max) { return; }

    usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
    $toDelete = count($files) - $max;
    for ($i = 0; $i < $toDelete; $i++) { @unlink($files[$i]); }
}

// --------------- Уведомления в чат ---------------

function v3_sendChat(string $message, array $config): void {
    $chatId = $config['error_chat_id'] ?? '';
    if (empty($chatId)) { return; }

    $url = $config['webhook_url'] . 'im.message.add.json';
    $postData = http_build_query([
        'DIALOG_ID' => $chatId,
        'MESSAGE'   => $message,
        'SYSTEM'    => 'Y',
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        v3_log('ERR', "chat_curl_error: $err", $config);
        return;
    }
    if ($httpCode !== 200) {
        v3_log('ERR', "chat_http_error: $httpCode", $config);
        return;
    }
    $resp = json_decode($result, true);
    if (isset($resp['error'])) {
        v3_log('ERR', "chat_api_error: " . ($resp['error_description'] ?? $resp['error']), $config);
    }
}

function v3_buildErrorChatMessage(string $errorType, array $details): string {
    $baseUrl = 'https://bitrix.dreamteamcompany.ru/local/handlers/universal/universal-system/v3';
    $timestamp = date('d.m.Y H:i:s');

    $msg = "🚨 Ошибка по рекламе ($errorType)\n\n";

    if (!empty($details['domain'])) {
        $msg .= "🌐 Домен: {$details['domain']}\n";
    }
    if (!empty($details['phone'])) {
        $msg .= "📱 Телефон: {$details['phone']}\n";
    }
    if (!empty($details['reason'])) {
        $msg .= "❌ Причина: {$details['reason']}\n";
    }
    if (!empty($details['lead_id'])) {
        $msg .= "📋 Лид: {$details['lead_id']}\n";
    }
    if (!empty($details['extra'])) {
        $msg .= "ℹ️ Детали: {$details['extra']}\n";
    }

    $msg .= "⏰ Время: $timestamp\n\n";
    $msg .= "[URL={$baseUrl}/status.php]📊 Статус очереди[/URL]";

    return $msg;
}
