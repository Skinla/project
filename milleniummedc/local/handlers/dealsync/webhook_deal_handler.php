<?php
/**
 * Синхронизация одной сделки облако → коробка (как webhook_lead_handler для лидов).
 *
 * GET/POST: cloud_deal_id | deal_id | ID — ID сделки в облаке.
 * Опционально: check_date_modify (как у лидов), strict_booking (1/true — ok:false при сбое CRM_BOOKING), secret (если задан в config).
 *
 * Маппинги: local/handlers/dealsync/data/*.json
 * Контакты: единый local/handlers/leadsync/data/contact_mapping.json (EnsureCloudContact)
 *
 * Права: каталог data/ должен быть доступен на запись пользователю PHP (обычно bitrix:bitrix),
 * иначе deal_sync_log.json и прочие логи миграции не обновятся.
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$baseDir = __DIR__;
$leadsyncDir = dirname($baseDir) . '/leadsync';
$dataDir = $baseDir . '/data';
$docRoot = realpath($baseDir . '/../../..') ?: '/home/bitrix/www';

$config = @require $baseDir . '/config/webhook.php';
if (!$config || empty($config['url'])) {
    echo json_encode(['ok' => false, 'error' => 'Config missing: copy config/webhook.example.php to config/webhook.php']);
    exit;
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) {
    $input = [];
}

$secretRequired = isset($config['secret']) && is_string($config['secret']) && $config['secret'] !== '';
if ($secretRequired) {
    $got = '';
    if (isset($_GET['secret']) && $_GET['secret'] !== '') {
        $got = (string)$_GET['secret'];
    } elseif (isset($input['secret']) && $input['secret'] !== '') {
        $got = (string)$input['secret'];
    } elseif (!empty($_SERVER['HTTP_X_SYNC_SECRET'])) {
        $got = (string)$_SERVER['HTTP_X_SYNC_SECRET'];
    }
    if (!hash_equals($config['secret'], $got)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

$resolveCloudDealId = static function (array $in): int {
    $keys = ['cloud_deal_id', 'deal_id', 'ID'];
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

$cloudDealId = $resolveCloudDealId($input);
if ($cloudDealId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No deal ID (cloud_deal_id / deal_id / ID)']);
    exit;
}

$checkDateModify = $parseCheckDateModify($input);

$parseStrictBooking = static function (array $in): bool {
    $val = null;
    if (array_key_exists('strict_booking', $in)) {
        $val = $in['strict_booking'];
    } elseif (isset($_GET['strict_booking'])) {
        $val = $_GET['strict_booking'];
    }
    if ($val === null) {
        return false;
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

$strictBooking = $parseStrictBooking($input);

require_once $baseDir . '/lib/BitrixRestClient.php';
require_once $baseDir . '/lib/ContactBoxSync.php';
require_once $baseDir . '/lib/DealSync.php';
require_once $leadsyncDir . '/lib/EnsureCloudContact.php';

$client = new BitrixRestClient($config['url']);

$dealResp = $client->call('crm.deal.get', ['id' => $cloudDealId]);
if (isset($dealResp['error']) || empty($dealResp['result'])) {
    echo json_encode([
        'ok' => false,
        'error' => 'crm.deal.get failed',
        'cloud_deal_id' => $cloudDealId,
        'detail' => $dealResp['error'] ?? null,
    ]);
    exit;
}
$dealRow = $dealResp['result'];

$contactMappingPath = $leadsyncDir . '/data/contact_mapping.json';
$loadContactMapping = static function (string $path): array {
    if (!file_exists($path)) {
        return ['contacts' => []];
    }
    $j = json_decode((string)file_get_contents($path), true);

    return is_array($j) ? $j : ['contacts' => []];
};

$contactMapping = $loadContactMapping($contactMappingPath);
$userMapping = json_decode((string)file_get_contents($dataDir . '/user_mapping.json'), true) ?: [];

$contactIds = ContactBoxSync::collectContactIdsFromDeal($dealRow);
$crmBootstrapped = false;
foreach ($contactIds as $cloudContactId) {
    if ($cloudContactId <= 0) {
        continue;
    }
    if (EnsureCloudContact::mappingHasCloudContact($contactMapping, $cloudContactId)) {
        continue;
    }
    $contactResp = $client->call('crm.contact.get', ['id' => $cloudContactId]);
    if (isset($contactResp['error']) || empty($contactResp['result'])) {
        echo json_encode([
            'ok' => false,
            'error' => 'Cloud contact not in mapping and crm.contact.get failed',
            'cloud_deal_id' => $cloudDealId,
            'cloud_contact_id' => $cloudContactId,
            'detail' => $contactResp['error'] ?? null,
        ]);
        exit;
    }

    if (!$crmBootstrapped) {
        $_SERVER['DOCUMENT_ROOT'] = $docRoot;
        if (!defined('NO_KEEP_STATISTIC')) {
            define('NO_KEEP_STATISTIC', true);
        }
        if (!defined('NOT_CHECK_PERMISSIONS')) {
            define('NOT_CHECK_PERMISSIONS', true);
        }
        require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
        \Bitrix\Main\Loader::includeModule('crm');
        $crmBootstrapped = true;
    }

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
            'cloud_deal_id' => $cloudDealId,
            'cloud_contact_id' => $cloudContactId,
        ]);
        exit;
    }

    $contactMapping = $loadContactMapping($contactMappingPath);
}

$stageMapping = json_decode((string)file_get_contents($dataDir . '/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode((string)file_get_contents($dataDir . '/field_mapping.json'), true) ?: [];
$sourceMapping = file_exists($dataDir . '/source_mapping.json')
    ? (json_decode((string)file_get_contents($dataDir . '/source_mapping.json'), true) ?: [])
    : [];
$companyMapping = file_exists($dataDir . '/company_mapping.json')
    ? (json_decode((string)file_get_contents($dataDir . '/company_mapping.json'), true) ?: [])
    : ['companies' => []];

$leadLogPath = $leadsyncDir . '/data/lead_sync_log.json';
$leadMapping = ['leads' => []];
if (file_exists($leadLogPath)) {
    $rawLeads = json_decode((string)file_get_contents($leadLogPath), true);
    if (is_array($rawLeads)) {
        $leadMapping['leads'] = $rawLeads;
    }
}

$categoryMapping = $stageMapping['category_mapping'] ?? [];
$dealStages = $stageMapping['deal_stages'] ?? [];
$dealFields = $fieldMapping['deal_fields'] ?? [];

$dealLogPath = $dataDir . '/deal_sync_log.json';
$existingBoxDealId = null;
if (file_exists($dealLogPath)) {
    $dealLog = json_decode((string)file_get_contents($dealLogPath), true) ?: [];
    if (isset($dealLog[(string)$cloudDealId])) {
        $existingBoxDealId = (int)$dealLog[(string)$cloudDealId];
    }
}

$contactMapping = $loadContactMapping($contactMappingPath);

$item = DealSync::buildDealPayload(
    $client,
    $cloudDealId,
    $categoryMapping,
    $dealStages,
    $dealFields,
    $userMapping,
    $contactMapping,
    $sourceMapping,
    $companyMapping,
    $leadMapping,
    $existingBoxDealId > 0 ? $existingBoxDealId : null
);
if (!$item) {
    echo json_encode(['ok' => false, 'error' => 'Failed to build deal payload', 'cloud_deal_id' => $cloudDealId]);
    exit;
}

$stdinPayload = json_encode(
    [
        'items' => [[
            'cloud_deal_id' => $cloudDealId,
            'box_deal_id' => $existingBoxDealId > 0 ? $existingBoxDealId : null,
            'update_only' => $existingBoxDealId > 0,
            'deal' => $item['deal'],
            'activities' => $item['activities'],
            'comments' => $item['comments'],
            'products' => $item['products'],
        ]],
        'check_date_modify' => $checkDateModify,
        'strict_booking' => $strictBooking,
    ],
    JSON_UNESCAPED_UNICODE
);

$scriptPath = $docRoot . '/migrate_deals_from_json.php';
if (!is_readable($scriptPath)) {
    echo json_encode([
        'ok' => false,
        'error' => 'migrate_deals_from_json.php not found in DOCUMENT_ROOT',
        'expected' => $scriptPath,
    ]);
    exit;
}

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
    echo json_encode(['ok' => false, 'error' => 'Failed to run migrate script', 'cloud_deal_id' => $cloudDealId]);
    exit;
}

fwrite($pipes[0], $stdinPayload);
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
$stderrMigrate = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

$result = json_decode(trim($output ?? ''), true);
$boxDealId = $result['results'][0]['deal_id'] ?? null;
$row0 = is_array($result) && isset($result['results'][0]) && is_array($result['results'][0])
    ? $result['results'][0]
    : [];
$bookingInfo = $row0['booking'] ?? null;
$strictBookingFailed = !empty($row0['strict_booking_failed']) || !empty($result['strict_booking_failed']);
$syncOk = (bool)$boxDealId && (!$strictBooking || !$strictBookingFailed);

if ($boxDealId) {
    $payload = [
        'ok' => $syncOk,
        'cloud_deal_id' => $cloudDealId,
        'box_deal_id' => (int)$boxDealId,
        'updated' => $existingBoxDealId > 0,
        'check_date_modify' => $checkDateModify,
        'strict_booking' => $strictBooking,
        'strict_booking_failed' => $strictBookingFailed,
        'booking' => $bookingInfo,
        'counts' => [
            'activities' => $row0['activities'] ?? null,
            'comments' => $row0['comments'] ?? null,
            'products' => $row0['products'] ?? null,
        ],
    ];
    if (!$syncOk && $strictBooking) {
        $payload['error'] = 'strict_booking: not all CRM_BOOKING activities linked to box bookings';
    }
    if (is_string($stderrMigrate) && trim($stderrMigrate) !== '') {
        $payload['migrate_stderr'] = $stderrMigrate;
    }
    echo json_encode($payload);
} else {
    echo json_encode([
        'ok' => false,
        'cloud_deal_id' => $cloudDealId,
        'output' => $output,
        'parsed' => $result,
        'migrate_stderr' => (is_string($stderrMigrate) && trim($stderrMigrate) !== '') ? $stderrMigrate : null,
    ]);
}
