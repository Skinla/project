#!/usr/bin/env php
<?php
/**
 * Собрать JSON для stdin migrate_deals_from_json.php (как webhook_deal_handler).
 *   php scripts/emit_deal_migrate_json.php CLOUD_DEAL_ID > /tmp/in.json
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$cloudDealId = isset($argv[1]) ? (int)$argv[1] : 0;
$overrideBoxDealId = isset($argv[2]) ? (int)$argv[2] : 0;
if ($cloudDealId <= 0) {
    fwrite(STDERR, "Usage: php emit_deal_migrate_json.php CLOUD_DEAL_ID [BOX_DEAL_ID]\n");
    exit(1);
}

require_once $root . '/lib/BitrixRestClient.php';
require_once $root . '/lib/ContactBoxSync.php';
require_once $root . '/lib/DealSync.php';

$dealsyncData = $root . '/local/handlers/dealsync/data';
$leadsyncData = $root . '/local/handlers/leadsync/data';

$config = require $root . '/local/handlers/dealsync/config/webhook.php';
if (empty($config['url'])) {
    fwrite(STDERR, "Missing dealsync config url\n");
    exit(1);
}

$client = new BitrixRestClient($config['url']);
$dealResp = $client->call('crm.deal.get', ['id' => $cloudDealId]);
if (!empty($dealResp['error']) || empty($dealResp['result'])) {
    fwrite(STDERR, "crm.deal.get failed\n");
    exit(1);
}

$contactMappingPath = $leadsyncData . '/contact_mapping.json';
$loadContactMapping = static function (string $path): array {
    if (!file_exists($path)) {
        return ['contacts' => []];
    }
    $j = json_decode((string)file_get_contents($path), true);

    return is_array($j) ? $j : ['contacts' => []];
};
$contactMapping = $loadContactMapping($contactMappingPath);
$userMapping = json_decode((string)file_get_contents($dealsyncData . '/user_mapping.json'), true) ?: [];
$stageMapping = json_decode((string)file_get_contents($dealsyncData . '/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode((string)file_get_contents($dealsyncData . '/field_mapping.json'), true) ?: [];
$sourceMapping = is_file($dealsyncData . '/source_mapping.json')
    ? (json_decode((string)file_get_contents($dealsyncData . '/source_mapping.json'), true) ?: [])
    : [];
$companyMapping = is_file($dealsyncData . '/company_mapping.json')
    ? (json_decode((string)file_get_contents($dealsyncData . '/company_mapping.json'), true) ?: [])
    : ['companies' => []];
$leadLogPath = $leadsyncData . '/lead_sync_log.json';
$leadMapping = ['leads' => []];
if (is_file($leadLogPath)) {
    $rawLeads = json_decode((string)file_get_contents($leadLogPath), true);
    if (is_array($rawLeads)) {
        $leadMapping['leads'] = $rawLeads;
    }
}

$dealLogPath = $dealsyncData . '/deal_sync_log.json';
$existingBoxDealId = null;
if ($overrideBoxDealId > 0) {
    $existingBoxDealId = $overrideBoxDealId;
} elseif (is_file($dealLogPath)) {
    $dealLog = json_decode((string)file_get_contents($dealLogPath), true) ?: [];
    if (isset($dealLog[(string)$cloudDealId])) {
        $existingBoxDealId = (int)$dealLog[(string)$cloudDealId];
    }
}

$item = DealSync::buildDealPayload(
    $client,
    $cloudDealId,
    $stageMapping['category_mapping'] ?? [],
    $stageMapping['deal_stages'] ?? [],
    $fieldMapping['deal_fields'] ?? [],
    $userMapping,
    $contactMapping,
    $sourceMapping,
    $companyMapping,
    $leadMapping,
    $existingBoxDealId > 0 ? $existingBoxDealId : null
);
if (!$item) {
    fwrite(STDERR, "buildDealPayload failed\n");
    exit(1);
}

$out = [
    'items' => [[
        'cloud_deal_id' => $cloudDealId,
        'box_deal_id' => $existingBoxDealId > 0 ? $existingBoxDealId : null,
        'update_only' => $existingBoxDealId > 0,
        'deal' => $item['deal'],
        'activities' => $item['activities'],
        'comments' => $item['comments'],
        'products' => $item['products'],
    ]],
    'check_date_modify' => false,
    'strict_booking' => true,
];
echo json_encode($out, JSON_UNESCAPED_UNICODE);
