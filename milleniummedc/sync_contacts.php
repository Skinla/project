#!/usr/bin/env php
<?php
/**
 * Sync existing contacts (from contact_mapping) with cloud.
 * Fetches contacts from cloud, sends to create_contacts_on_box with existing_mapping.
 * Only updates contacts where source DATE_MODIFY > target DATE_MODIFY.
 *
 * Usage: php sync_contacts.php [--dry-run]
 */
$projectRoot = __DIR__;
$dryRun = in_array('--dry-run', $argv ?? []);

$contactMappingPath = $projectRoot . '/data/contact_mapping.json';
$contactMapping = file_exists($contactMappingPath) ? json_decode(file_get_contents($contactMappingPath), true) : [];
$existing = $contactMapping['contacts'] ?? [];
if (empty($existing)) {
    echo "No contacts in mapping.\n";
    exit(0);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];

$payload = [];
foreach ($existing as $cloudId => $boxId) {
    $cloudId = (int)$cloudId;
    if ($cloudId <= 0) continue;

    $resp = $client->call('crm.contact.get', ['id' => $cloudId]);
    if (isset($resp['error']) || empty($resp['result'])) {
        echo "  Skip contact $cloudId: not found\n";
        continue;
    }
    $c = $resp['result'];
    $assignedById = $userMapping['users'][(string)($c['ASSIGNED_BY_ID'] ?? 1)] ?? 1;
    $payload[] = [
        'cloud_id' => $cloudId,
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
    echo "Nothing to sync.\n";
    exit(0);
}

if ($dryRun) {
    echo "[DRY-RUN] Would sync " . count($payload) . " contacts.\n";
    exit(0);
}

$input = [
    'items' => $payload,
    'existing_mapping' => $existing,
];
$tmpFile = sys_get_temp_dir() . '/sync_contacts_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode($input, JSON_UNESCAPED_UNICODE));

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

echo "Synced contacts: " . ($result['updated'] ?? 0) . " updated (skipped if target newer).\n";
