<?php
/**
 * Webhook: sync one cloud lead to box.
 *
 * - Outgoing webhook / robot (JSON or query): cloud_lead_id (or lead_id, ID) + optional check_date_modify
 * - Classic Bitrix24 app event: ONCRMLEADADD + data.FIELDS.ID
 *
 * URL: https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$baseDir = __DIR__;
$config = @require $baseDir . '/config/webhook.php';
if (!$config || empty($config['url'])) {
    echo json_encode(['ok' => false, 'error' => 'Config missing']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = [];
}

/**
 * Cloud lead ID: query cloud_lead_id|lead_id|ID, then same keys in JSON, then data.FIELDS.ID (classic).
 */
$resolveCloudLeadId = static function (array $in): int {
    $keys = ['cloud_lead_id', 'lead_id', 'ID'];
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '' && (int)$_GET[$k] > 0) {
            return (int)$_GET[$k];
        }
    }
    foreach ($keys as $k) {
        if (isset($in[$k]) && (int)$in[$k] > 0) {
            return (int)$in[$k];
        }
    }
    $classic = (int)($in['data']['FIELDS']['ID'] ?? 0);
    if ($classic > 0) {
        return $classic;
    }

    return 0;
};

$hasExplicitLeadId = static function (array $in): bool {
    $keys = ['cloud_lead_id', 'lead_id', 'ID'];
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && (int)$_GET[$k] > 0) {
            return true;
        }
    }
    foreach ($keys as $k) {
        if (isset($in[$k]) && (int)$in[$k] > 0) {
            return true;
        }
    }

    return false;
};

/**
 * Default true (skip full overwrite unless caller sets false).
 */
$parseCheckDateModify = static function (array $in): bool {
    $val = null;
    if (array_key_exists('check_date_modify', $in)) {
        $val = $in['check_date_modify'];
    } elseif (isset($_GET['check_date_modify'])) {
        $val = $_GET['check_date_modify'];
    } elseif (isset($_GET['checkDateModify'])) {
        $val = $_GET['checkDateModify'];
    }
    if ($val === null) {
        return true;
    }
    if (is_bool($val)) {
        return $val;
    }
    if (is_int($val)) {
        return $val !== 0;
    }
    $s = strtolower(trim((string)$val));
    if (in_array($s, ['0', 'false', 'n', 'no', 'off'], true)) {
        return false;
    }
    if (in_array($s, ['1', 'true', 'y', 'yes', 'on'], true)) {
        return true;
    }

    return (bool)$val;
};

$leadId = $resolveCloudLeadId($input);
if ($leadId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No lead ID in payload']);
    exit;
}

if (!$hasExplicitLeadId($input) && ($input['event'] ?? '') !== 'ONCRMLEADADD') {
    echo json_encode(['ok' => false, 'error' => 'Wrong event']);
    exit;
}

$checkDateModify = $parseCheckDateModify($input);

require_once $baseDir . '/lib/BitrixRestClient.php';
require_once $baseDir . '/lib/LeadSync.php';
require_once $baseDir . '/lib/LeadSyncLog.php';
require_once $baseDir . '/lib/EnsureCloudContact.php';

$log = new LeadSyncLog($baseDir);
$existingBoxId = $log->getBoxId($leadId);

$stageMapping = json_decode(file_get_contents($baseDir . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($baseDir . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($baseDir . '/data/user_mapping.json'), true) ?: [];
$contactMappingPath = $baseDir . '/data/contact_mapping.json';
$contactMapping = file_exists($contactMappingPath) ? (json_decode(file_get_contents($contactMappingPath), true) ?: []) : [];
$sourceMapping = file_exists($baseDir . '/data/source_mapping.json') ? (json_decode(file_get_contents($baseDir . '/data/source_mapping.json'), true) ?: []) : [];
$honorificMapping = file_exists($baseDir . '/data/honorific_mapping.json') ? (json_decode(file_get_contents($baseDir . '/data/honorific_mapping.json'), true) ?: []) : [];

$client = new BitrixRestClient($config['url']);

$leadPeek = $client->call('crm.lead.get', ['id' => $leadId, 'select' => ['ID', 'CONTACT_ID']]);
if (isset($leadPeek['error']) || empty($leadPeek['result'])) {
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch lead', 'lead_id' => $leadId, 'detail' => $leadPeek['error'] ?? null]);
    exit;
}
$cloudContactId = (int)($leadPeek['result']['CONTACT_ID'] ?? 0);

if ($cloudContactId > 0) {
    $contactResp = $client->call('crm.contact.get', ['id' => $cloudContactId]);
    if (isset($contactResp['error']) || empty($contactResp['result'])) {
        echo json_encode([
            'ok' => false,
            'error' => 'crm.contact.get failed',
            'lead_id' => $leadId,
            'cloud_contact_id' => $cloudContactId,
            'detail' => $contactResp['error'] ?? null,
        ]);
        exit;
    }

    $docRoot = realpath($baseDir . '/../../..') ?: '/home/bitrix/www';
    $_SERVER['DOCUMENT_ROOT'] = $docRoot;
    if (!defined('NO_KEEP_STATISTIC')) {
        define('NO_KEEP_STATISTIC', true);
    }
    if (!defined('NOT_CHECK_PERMISSIONS')) {
        define('NOT_CHECK_PERMISSIONS', true);
    }
    require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
    \Bitrix\Main\Loader::includeModule('crm');

    if (!EnsureCloudContact::mappingHasCloudContact($contactMapping, $cloudContactId)) {
        $createdBoxContactId = EnsureCloudContact::ensureMapped(
            $contactMappingPath,
            $cloudContactId,
            $contactResp['result'],
            $userMapping
        );
        if (!$createdBoxContactId) {
            echo json_encode([
                'ok' => false,
                'error' => 'Failed to create box contact or update contact_mapping',
                'lead_id' => $leadId,
                'cloud_contact_id' => $cloudContactId,
            ]);
            exit;
        }
    }

    $contactMapping = file_exists($contactMappingPath) ? (json_decode(file_get_contents($contactMappingPath), true) ?: []) : [];
    $boxContactId = (int)($contactMapping['contacts'][(string)$cloudContactId] ?? 0);
    if ($boxContactId > 0) {
        EnsureCloudContact::syncPersonFieldsFromCloud($boxContactId, $contactResp['result']);
    }
    $contactMapping = file_exists($contactMappingPath) ? (json_decode(file_get_contents($contactMappingPath), true) ?: []) : [];
}

$payload = LeadSync::buildLeadPayload(
    $client,
    $leadId,
    $stageMapping,
    $fieldMapping,
    $userMapping,
    $contactMapping,
    $sourceMapping,
    $honorificMapping
);

if (!$payload) {
    echo json_encode(['ok' => false, 'error' => 'Failed to build lead payload', 'lead_id' => $leadId]);
    exit;
}

$payload['cloud_lead_id'] = $leadId;
if ($existingBoxId) {
    $payload['box_lead_id'] = $existingBoxId;
    $payload['update_only'] = true;
}

$stdinPayload = json_encode(
    [
        'items' => [$payload],
        'check_date_modify' => $checkDateModify,
    ],
    JSON_UNESCAPED_UNICODE
);

$scriptPath = $baseDir . '/migrate_leads_from_json.php';
$docRoot = realpath($baseDir . '/../../..') ?: '/home/bitrix/www';

$descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
$env = array_merge(getenv() ?: [], ['DOCUMENT_ROOT' => $docRoot]);
$proc = proc_open(
    'php -d display_errors=0 ' . escapeshellarg($scriptPath),
    $descriptors,
    $pipes,
    $docRoot,
    $env
);

if (!is_resource($proc)) {
    echo json_encode(['ok' => false, 'error' => 'Failed to run migrate script', 'lead_id' => $leadId]);
    exit;
}

fwrite($pipes[0], $stdinPayload);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$result = json_decode(trim($output ?? ''), true);
$boxLeadId = $result['results'][0]['lead_id'] ?? null;

if ($boxLeadId) {
    if (!$existingBoxId) {
        $log->set($leadId, $boxLeadId);
    }
    echo json_encode([
        'ok' => true,
        'cloud_lead_id' => $leadId,
        'box_lead_id' => $boxLeadId,
        'updated' => (bool)$existingBoxId,
        'check_date_modify' => $checkDateModify,
    ]);
} else {
    echo json_encode(['ok' => false, 'cloud_lead_id' => $leadId, 'output' => $output]);
}
