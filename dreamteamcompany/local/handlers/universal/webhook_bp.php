<?php
// webhook_bp.php
// Приём входящих заявок → создание элемента в списке 49 (внутренний API) → запуск БП 775.
// Использует Bitrix D7/Legacy в CLI-режиме (BX_CRONTAB), без шаблонов и авторизации.
//
// ВАЖНО: На сервере нужно добавить этот файл в исключения авторизации Intranet.
// Варианты:
//   1) urlrewrite.php — добавить правило по аналогии с webhook.php
//   2) .htaccess в /local/handlers/universal/ — SetEnvIf + allow
//   3) Разместить в /bitrix/tools/webhook_bp.php (эта папка не блокируется)

error_reporting(E_ALL);
ini_set('display_errors', 0);

// --- Тестовый запрос от Тильды (до подключения Bitrix) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['test']) && $_POST['test'] === 'test') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'message' => 'webhook_available']);
        exit;
    }
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $jsonData = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['test']) && $jsonData['test'] === 'test') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ok', 'message' => 'webhook_available']);
            exit;
        }
    }
} else {
    $rawBody = '';
}

// --- Подключаем ядро Bitrix (CLI-режим, без шаблонов и визуальной части) ---
$prologIncluded = defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true;
if (!$prologIncluded) {
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);
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

    $includePath = $documentRoot . '/bitrix/modules/main/include.php';
    if (!file_exists($includePath)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'bitrix_include_not_found', 'path' => $includePath]);
        exit;
    }
    require_once $includePath;

    global $USER;
    if (!is_object($USER)) {
        $USER = new \CUser();
    }
    if (!$USER->IsAuthorized()) {
        $USER->Authorize(1);
    }
}

if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'iblock_module_failed']);
    exit;
}
if (!\Bitrix\Main\Loader::includeModule('bizproc')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'bizproc_module_failed']);
    exit;
}

// --- Считываем/парсим входные данные ---
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

// --- Определяем домен (source_domain) ---
$domain = 'unknown.domain';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $u = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($u['host'])) $domain = $u['host'];
    } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
        $u = parse_url($_SERVER['HTTP_ORIGIN']);
        if (!empty($u['host'])) $domain = $u['host'];
    }
    if (!empty($inputData['domain'])) {
        $domain = $inputData['domain'];
    }
} else {
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $u = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($u['host'])) $domain = $u['host'];
    } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
        $u = parse_url($_SERVER['HTTP_ORIGIN']);
        if (!empty($u['host'])) $domain = $u['host'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['extra']['href'])) {
        $p = parse_url($inputData['extra']['href']);
        if (!empty($p['host'])) $domain = $p['host'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['ASSIGNED_BY_ID'])) {
        $domain = $inputData['ASSIGNED_BY_ID'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['source_domain'])) {
        $domain = $inputData['source_domain'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['__submission']['source_url'])) {
        $p = parse_url($inputData['__submission']['source_url']);
        if (!empty($p['host'])) $domain = $p['host'];
    }

    if (($domain === 'unknown.domain' || $domain === 'mrqz.me') && !empty($inputData['extra']['referrer'])) {
        $p = parse_url($inputData['extra']['referrer']);
        if (!empty($p['host'])) $domain = $p['host'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['subPoolName'])) {
        $domain = $inputData['subPoolName'];
    }

    if ($domain === 'unknown.domain' && !empty($inputData['url'])) {
        $p = parse_url($inputData['url']);
        if (!empty($p['host'])) $domain = $p['host'];
    }
}

$inputData['source_domain'] = $domain;

// --- Извлекаем телефон ---
$phoneValue = '';
foreach ($inputData as $key => $value) {
    if (is_string($value) && !empty($value) && preg_match('/^phone/i', $key)) {
        $phoneValue = $value;
        break;
    }
}
if (!$phoneValue && isset($inputData['contacts']['phone'])) {
    $phoneValue = $inputData['contacts']['phone'];
}
if (!$phoneValue && isset($inputData['callerphone'])) {
    $phoneValue = $inputData['callerphone'];
}

$qs = $_SERVER['QUERY_STRING'] ?? '';
$hasBitrixPhoneInQuery = (
    strpos($qs, 'fields%5BPHONE%5D') !== false ||
    strpos(urldecode($qs), 'fields[PHONE]') !== false
);

if (empty(trim($phoneValue)) && !$hasBitrixPhoneInQuery) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'message' => 'empty_phone']);
    exit;
}

// --- Формируем JSON сырых данных ---
$rawPayload = json_encode([
    'raw_body'    => $rawBody,
    'raw_headers' => [
        'CONTENT_TYPE'   => $_SERVER['CONTENT_TYPE'] ?? '',
        'HTTP_REFERER'   => $_SERVER['HTTP_REFERER'] ?? '',
        'HTTP_ORIGIN'    => $_SERVER['HTTP_ORIGIN'] ?? '',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
        'QUERY_STRING'   => $_SERVER['QUERY_STRING'] ?? '',
    ],
    'parsed_data'   => $inputData,
    'source_domain' => $domain,
    'phone'         => $phoneValue,
    'timestamp'     => date('Y-m-d H:i:s'),
], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

// --- Создаём элемент в IBlock 49 через внутренний API ---
$iblockId = 49;
$bpTemplateId = 776;

$el = new \CIBlockElement();
$elementId = $el->Add([
    'IBLOCK_ID'    => $iblockId,
    'NAME'         => 'Заявка ' . date('d.m.Y H:i:s'),
    'ACTIVE'       => 'Y',
    'CODE'         => 'lead_' . time() . '_' . substr(md5($rawBody . microtime(true)), 0, 8),
    'PROPERTY_VALUES' => [
        'RAW_DATA'      => $rawPayload,
        'STATUS'        => 'new',
        'SOURCE_DOMAIN' => $domain,
        'PHONE'         => $phoneValue,
    ],
]);

if (!$elementId) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'element_add_failed',
        'detail'  => $el->LAST_ERROR,
    ]);
    exit;
}

// --- Запускаем БП 775 через CBPDocument::StartWorkflow ---
$documentId = [
    'lists',
    'Bitrix\\Lists\\BizprocDocumentLists',
    $elementId,
];

$errors = [];
$workflowId = \CBPDocument::StartWorkflow(
    $bpTemplateId,
    $documentId,
    [],
    $errors
);

header('Content-Type: application/json; charset=utf-8');

if (!empty($errors)) {
    $errorMessages = [];
    foreach ($errors as $err) {
        if (is_array($err) && isset($err['message'])) {
            $errorMessages[] = $err['message'];
        } elseif (is_string($err)) {
            $errorMessages[] = $err;
        } else {
            $errorMessages[] = json_encode($err, JSON_UNESCAPED_UNICODE);
        }
    }
    echo json_encode([
        'status'     => 'error',
        'message'    => 'bp_start_failed',
        'element_id' => $elementId,
        'errors'     => $errorMessages,
    ]);
    exit;
}

echo json_encode([
    'status'      => 'ok',
    'element_id'  => $elementId,
    'workflow_id' => $workflowId,
]);
