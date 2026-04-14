#!/usr/bin/env php
<?php
/**
 * Create contacts on box from cloud contacts linked to migrated leads.
 * 1. Get CONTACT_ID from each lead in lead_sync_log
 * 2. Fetch contacts from cloud
 * 3. Create on box via SSH
 * 4. Update contact_mapping.json
 * 5. Sync leads with CONTACT_ID
 *
 * Usage: php migrate_contacts_from_leads.php [--dry-run]
 */
$projectRoot = __DIR__;
$dryRun = in_array('--dry-run', $argv ?? []);

$logPath = $projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$log = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
if (empty($log)) {
    echo "No leads in log.\n";
    exit(0);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];

$contactMappingPath = $projectRoot . '/data/contact_mapping.json';
$contactMappingFile = file_exists($contactMappingPath) ? json_decode(file_get_contents($contactMappingPath), true) : [];
if (!is_array($contactMappingFile)) {
    $contactMappingFile = [];
}
$existingContactMap = $contactMappingFile['contacts'] ?? [];
if (!is_array($existingContactMap) || isset($existingContactMap[0])) {
    $existingContactMap = [];
}

$contactIds = [];
foreach (array_keys($log) as $cloudLeadId) {
    $resp = $client->call('crm.lead.get', ['id' => $cloudLeadId, 'select' => ['CONTACT_ID']]);
    if (isset($resp['error']) || empty($resp['result'])) continue;
    $cid = (int)($resp['result']['CONTACT_ID'] ?? 0);
    if ($cid > 0) $contactIds[$cid] = true;
}
$contactIds = array_keys($contactIds);
if (empty($contactIds)) {
    echo "No contacts linked to leads.\n";
    exit(0);
}

$contactIdsToCreate = [];
foreach ($contactIds as $cid) {
    if (!isset($existingContactMap[(string)$cid])) {
        $contactIdsToCreate[] = $cid;
    }
}
$alreadyMapped = count($contactIds) - count($contactIdsToCreate);
echo "Found " . count($contactIds) . " unique cloud contacts on leads ($alreadyMapped already in contact_mapping.json, " . count($contactIdsToCreate) . " to create).\n";

$payload = [];
foreach ($contactIdsToCreate as $cloudContactId) {
    $resp = $client->call('crm.contact.get', ['id' => $cloudContactId]);
    if (isset($resp['error']) || empty($resp['result'])) {
        echo "  Skip contact $cloudContactId: not found\n";
        continue;
    }
    $c = $resp['result'];
    $assignedById = $userMapping['users'][(string)($c['ASSIGNED_BY_ID'] ?? 1)] ?? 1;
    $payload[] = [
        'cloud_id' => $cloudContactId,
        'NAME' => $c['NAME'] ?? '',
        'LAST_NAME' => $c['LAST_NAME'] ?? '',
        'SECOND_NAME' => $c['SECOND_NAME'] ?? '',
        'POST' => $c['POST'] ?? '',
        'COMMENTS' => $c['COMMENTS'] ?? '',
        'ASSIGNED_BY_ID' => $assignedById,
        'PHONE' => $c['PHONE'] ?? [],
        'EMAIL' => $c['EMAIL'] ?? [],
        'DATE_CREATE' => $c['DATE_CREATE'] ?? null,
        'DATE_MODIFY' => $c['DATE_MODIFY'] ?? null,
    ];
}

if (empty($payload)) {
    if (!empty($contactIdsToCreate)) {
        echo "ERROR: Could not load any contact from cloud (crm.contact.get failed for all IDs). Nothing to create.\n";
        exit(1);
    }
    echo "No new contacts to create (all are already mapped).\n";
    if ($dryRun) {
        echo "[DRY-RUN] Would run sync_existing_leads.php --no-date-check to set CONTACT_ID on leads.\n";
        exit(0);
    }
    echo "\nRunning sync_existing_leads --no-date-check to attach contacts to leads...\n";
    passthru('php ' . escapeshellarg($projectRoot . '/sync_existing_leads.php') . ' --no-date-check', $code);
    exit($code);
}

if ($dryRun) {
    echo "[DRY-RUN] Would create " . count($payload) . " contacts, then sync leads with --no-date-check.\n";
    exit(0);
}

$tmpFile = sys_get_temp_dir() . '/migrate_contacts_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$boxScript = $projectRoot . '/create_contacts_on_box.php';
$scpCmd = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', escapeshellarg($pass), $port, escapeshellarg($boxScript), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', $port, escapeshellarg($boxScript), $user, $host);
exec($scpCmd . ' 2>&1');

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php create_contacts_on_box.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php create_contacts_on_box.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

$result = json_decode(trim($output ?? ''), true);
if (!$result || isset($result['error'])) {
    echo "Error: " . ($output ?? 'no output') . "\n";
    exit(1);
}

$newMapping = $result['mapping'] ?? [];
echo "Created " . ($result['created'] ?? 0) . " contacts on box.\n";

$contactMapping = file_exists($contactMappingPath) ? json_decode(file_get_contents($contactMappingPath), true) : [];
if (!is_array($contactMapping)) $contactMapping = [];
$existing = $contactMapping['contacts'] ?? [];
if (!is_array($existing) || isset($existing[0])) $existing = [];
$contactMapping['contacts'] = $existing + $newMapping;
$contactMapping['created_at'] = date('c');
file_put_contents($contactMappingPath, json_encode($contactMapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Updated contact_mapping.json: " . count($contactMapping['contacts']) . " mapped.\n";

$handlerDataDir = $projectRoot . '/local/handlers/leadsync/data';
if (!is_dir($handlerDataDir)) @mkdir($handlerDataDir, 0755, true);
copy($contactMappingPath, $handlerDataDir . '/contact_mapping.json');
echo "Copied to handler data.\n";

$scpData = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/local/handlers/leadsync/data/', escapeshellarg($pass), $port, escapeshellarg($contactMappingPath), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/local/handlers/leadsync/data/', $port, escapeshellarg($contactMappingPath), $user, $host);
exec($scpData . ' 2>&1');
echo "Deployed contact_mapping to box.\n";

echo "\nRunning sync_existing_leads --no-date-check to attach contacts to leads...\n";
passthru('php ' . escapeshellarg($projectRoot . '/sync_existing_leads.php') . ' --no-date-check', $code);
exit($code);
