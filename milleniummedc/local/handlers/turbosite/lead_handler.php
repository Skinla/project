<?php
/**
 * Turbosite / исходящий вебхук Bitrix24: лог + JSON + создание лида на коробке.
 * Источник лида по умолчанию: UC_QUY0M1 («Арбитражник 2») — как в create_box_sources.php и data/source_mapping.json.
 * Ответственный по умолчанию: ASSIGNED_BY_ID = 8 (всегда, поле из формы не используется).
 * Опционально: data/source_mapping.json рядом со скриптом — { "box_source_id": "UC_..." }.
 *
 * URL: /local/handlers/turbosite/lead_handler.php
 */
header('Content-Type: text/plain; charset=utf-8');
http_response_code(200);

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true)) {
    http_response_code(500);
    echo 'fail';
    exit;
}

$rawBody = file_get_contents('php://input');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

$filesMeta = [];
foreach ($_FILES as $key => $info) {
    if (!is_array($info['name'] ?? null)) {
        $filesMeta[$key] = [
            'name' => $info['name'] ?? '',
            'type' => $info['type'] ?? '',
            'size' => (int)($info['size'] ?? 0),
            'error' => (int)($info['error'] ?? 0),
        ];
        continue;
    }
    $filesMeta[$key] = [];
    $n = count($info['name']);
    for ($i = 0; $i < $n; $i++) {
        $filesMeta[$key][] = [
            'name' => $info['name'][$i] ?? '',
            'type' => $info['type'][$i] ?? '',
            'size' => (int)($info['size'][$i] ?? 0),
            'error' => (int)($info['error'][$i] ?? 0),
        ];
    }
}

$payload = [
    'time' => gmdate('c'),
    'method' => $method,
    'uri' => $uri,
    'content_type' => $contentType,
    'get' => $_GET,
    'post' => $_POST,
    'files_meta' => $filesMeta,
    'raw_body' => $rawBody,
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
];

/** По умолчанию STATUS_ID источника «Арбитражник 2» на коробке (b_crm_status ENTITY_ID=SOURCE). */
$defaultBoxSourceId = 'UC_QUY0M1';
$sourceMapPath = $dataDir . '/source_mapping.json';
if (is_readable($sourceMapPath)) {
    $sm = json_decode((string)file_get_contents($sourceMapPath), true) ?: [];
    if (!empty($sm['box_source_id']) && is_string($sm['box_source_id'])) {
        $defaultBoxSourceId = $sm['box_source_id'];
    }
}

$payload['crm'] = turbosite_try_create_lead($_GET, $_POST, $defaultBoxSourceId);

$jsonName = sprintf(
    'lead_%s_%s.json',
    gmdate('Ymd_His'),
    substr(str_replace('.', '', (string)microtime(true)), -6) . '_' . bin2hex(random_bytes(3))
);
$jsonPath = $dataDir . '/' . $jsonName;
file_put_contents($jsonPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$logPath = $dataDir . '/incoming.log';
$logBlock = str_repeat('-', 72) . ' ' . gmdate('c') . "\n"
    . "METHOD: {$method}\n"
    . "REQUEST_URI: {$uri}\n"
    . "Content-Type: {$contentType}\n"
    . "--- body (php://input) ---\n";

if ($rawBody === '' && $contentType !== '' && stripos($contentType, 'multipart/form-data') !== false) {
    $logBlock .= "(empty: multipart/form-data; see POST below)\n"
        . "--- POST ---\n"
        . print_r($_POST, true)
        . "--- FILES (names/sizes) ---\n"
        . print_r($filesMeta, true);
} else {
    $logBlock .= $rawBody !== '' ? $rawBody : "(empty)\n";
}

$logBlock .= "\n--- crm ---\n" . print_r($payload['crm'] ?? [], true) . "\n\n";
file_put_contents($logPath, $logBlock, FILE_APPEND | LOCK_EX);

if (!empty($payload['crm']['error'])) {
    http_response_code(500);
    echo 'fail';
    exit;
}

echo 'ok';

/**
 * @return array{skipped?:true, box_lead_id?:int, source_id?:string, automation?:array<string, mixed>, warnings?:array<int, string>, error?:string}
 */
function turbosite_try_create_lead(array $get, array $post, string $boxSourceId): array
{
    $fieldsIn = $get['FIELDS'] ?? null;
    if (!is_array($fieldsIn) || $fieldsIn === []) {
        return ['skipped' => true];
    }

    $docRoot = realpath(__DIR__ . '/../../..') ?: '/home/bitrix/www';
    if (!is_dir($docRoot . '/bitrix')) {
        return ['error' => 'document_root'];
    }

    $_SERVER['DOCUMENT_ROOT'] = $docRoot;
    if (!defined('NO_KEEP_STATISTIC')) {
        define('NO_KEEP_STATISTIC', true);
    }
    if (!defined('NOT_CHECK_PERMISSIONS')) {
        define('NOT_CHECK_PERMISSIONS', true);
    }

    require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        return ['error' => 'crm_module'];
    }
    \Bitrix\Main\Loader::includeModule('bizproc');

    global $USER;
    $title = trim((string)($fieldsIn['TITLE'] ?? ''));
    if ($title === '') {
        $title = 'Без названия';
    }

    $comments = trim((string)($fieldsIn['COMMENTS'] ?? ''));
    $dealId = isset($get['deal_id']) ? (int)$get['deal_id'] : 0;
    if ($dealId > 0) {
        $comments = trim($comments . "\n\n[cloud deal_id={$dealId}]");
    }
    if (!empty($post['document_id']) && is_array($post['document_id'])) {
        $comments = trim($comments . "\n[document_id: " . implode(', ', $post['document_id']) . ']');
    }
    if (!empty($post['auth']['domain'])) {
        $comments = trim($comments . "\n[auth.domain: " . (string)$post['auth']['domain'] . ']');
    }

    $fm = [];
    $phoneRaw = $fieldsIn['PHONE'] ?? null;
    if (is_array($phoneRaw)) {
        $i = 0;
        foreach ($phoneRaw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $val = trim((string)($row['VALUE'] ?? ''));
            if ($val !== '') {
                $fm['PHONE']['n' . $i] = [
                    'VALUE' => $val,
                    'VALUE_TYPE' => !empty($row['VALUE_TYPE']) ? (string)$row['VALUE_TYPE'] : 'WORK',
                ];
                $i++;
            }
        }
    }

    $leadFields = [
        'TITLE' => $title,
        'NAME' => trim((string)($fieldsIn['NAME'] ?? '')),
        'LAST_NAME' => trim((string)($fieldsIn['LAST_NAME'] ?? '')),
        'SOURCE_ID' => $boxSourceId,
        'STATUS_ID' => 'NEW',
        'OPENED' => 'Y',
        'COMMENTS' => $comments,
        'ASSIGNED_BY_ID' => 8,
    ];
    $currentUserId = (int)$leadFields['ASSIGNED_BY_ID'];
    if ($currentUserId <= 0) {
        $currentUserId = 1;
    }

    if (is_object($USER) && method_exists($USER, 'Authorize')) {
        if (!$USER->IsAuthorized() || (int)$USER->GetID() !== $currentUserId) {
            $USER->Authorize($currentUserId);
        }
    }

    if ($fm !== []) {
        $leadFields['FM'] = $fm;
    }

    $lead = new \CCrmLead(false);
    $leadId = (int)$lead->Add($leadFields, true, [
        'REGISTER_SONET_EVENT' => 'Y',
        'CURRENT_USER' => $currentUserId,
    ]);
    if ($leadId <= 0) {
        $ex = $GLOBALS['APPLICATION']->GetException();

        return ['error' => $ex ? $ex->GetString() : 'CCrmLead::Add failed'];
    }

    $result = [
        'box_lead_id' => $leadId,
        'source_id' => $boxSourceId,
    ];
    $automation = turbosite_run_lead_automation($leadId, $currentUserId);
    if ($automation !== []) {
        $result['automation'] = $automation;
    }

    $warnings = [];
    if (!empty($automation['crm_error'])) {
        $warnings[] = 'crm_automation: ' . (string)$automation['crm_error'];
    }
    if (!empty($automation['bizproc_error'])) {
        $warnings[] = 'bizproc: ' . (string)$automation['bizproc_error'];
    }
    if (!empty($automation['bizproc_errors']) && is_array($automation['bizproc_errors'])) {
        $warnings[] = 'bizproc_errors: ' . json_encode($automation['bizproc_errors'], JSON_UNESCAPED_UNICODE);
    }
    if ($warnings !== []) {
        $result['warnings'] = $warnings;
    }

    return $result;
}

/**
 * @return array<string, mixed>
 */
function turbosite_run_lead_automation(int $leadId, int $userId): array
{
    $result = [];

    try {
        if (class_exists(\Bitrix\Crm\Automation\Starter::class) && class_exists(\CCrmOwnerType::class)) {
            $starter = new \Bitrix\Crm\Automation\Starter(\CCrmOwnerType::Lead, $leadId);
            if ($userId > 0) {
                $starter->setUserId($userId);
            }
            $starter->runOnAdd();
            $result['crm'] = 'started';
        }
    } catch (\Throwable $e) {
        $result['crm_error'] = $e->getMessage();
    }

    try {
        if (class_exists(\CCrmBizProcHelper::class) && class_exists(\CCrmBizProcEventType::class) && class_exists(\CCrmOwnerType::class)) {
            $errors = [];
            \CCrmBizProcHelper::AutoStartWorkflows(\CCrmOwnerType::LeadName, $leadId, \CCrmBizProcEventType::Create, $errors);
            $result['bizproc'] = 'started';
            if ($errors !== []) {
                $result['bizproc_errors'] = $errors;
            }
        }
    } catch (\Throwable $e) {
        $result['bizproc_error'] = $e->getMessage();
    }

    return $result;
}
