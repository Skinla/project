#!/usr/bin/env php
<?php
/**
 * Sync existing migrated leads (from lead_sync_log) with full fields, activities, comments, products.
 * Usage: php sync_existing_leads.php [--dry-run]
 */
$projectRoot = __DIR__;
$argv = $argv ?? [];
$dryRun = in_array('--dry-run', $argv);
$checkDateModify = !in_array('--no-date-check', $argv);

$logPath = $projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$log = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
if (empty($log)) {
    echo "No pairs in lead_sync_log.\n";
    exit(0);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';
require_once $projectRoot . '/lib/LeadSync.php';

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];
$contactMapping = file_exists($projectRoot . '/data/contact_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/contact_mapping.json'), true) ?: [] : [];
$sourceMapping = file_exists($projectRoot . '/data/source_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/source_mapping.json'), true) ?: [] : [];
$honorificMapping = file_exists($projectRoot . '/data/honorific_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/honorific_mapping.json'), true) ?: [] : [];

$client = new BitrixRestClient($config['url']);

$payload = [];
foreach ($log as $cloudId => $boxId) {
    $cloudId = (int)$cloudId;
    $boxId = (int)$boxId;
    echo "Building payload for cloud $cloudId -> box $boxId... ";
    $item = LeadSync::buildLeadPayload($client, $cloudId, $stageMapping, $fieldMapping, $userMapping, $contactMapping, $sourceMapping, $honorificMapping);
    if (!$item) {
        echo "Error\n";
        continue;
    }
    $payload[] = [
        'cloud_lead_id' => $cloudId,
        'box_lead_id' => $boxId,
        'update_only' => true,
        'lead' => $item['lead'],
        'activities' => $item['activities'],
        'comments' => $item['comments'],
        'products' => $item['products'],
    ];
    echo "OK (act:" . count($item['activities']) . " com:" . count($item['comments']) . " prod:" . count($item['products']) . ")\n";
}


if (empty($payload)) {
    echo "Nothing to sync.\n";
    exit(0);
}

$payload = ['items' => $payload, 'check_date_modify' => $checkDateModify];
$itemCount = count($payload['items']);

if ($dryRun) {
    echo "\n[DRY-RUN] Would sync $itemCount leads.\n";
    exit(0);
}

$tmpFile = sys_get_temp_dir() . '/sync_leads_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$boxScript = $projectRoot . '/migrate_leads_from_json.php';
$scpCmd = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', escapeshellarg($pass), $port, escapeshellarg($boxScript), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', $port, escapeshellarg($boxScript), $user, $host);
exec($scpCmd . ' 2>&1', $scpOut, $scpCode);

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

echo "\n" . ($output ?? 'SSH failed') . "\n";
