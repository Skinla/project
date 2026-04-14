#!/usr/bin/env php
<?php
/**
 * Create lead in box from cloud lead.
 * 1. Fetches lead from cloud via REST
 * 2. Applies stage/field/user mappings
 * 3. Creates lead on box via SSH + internal API
 *
 * Usage: php create_lead_in_box.php <cloud_lead_id>
 */
$projectRoot = __DIR__;
$leadId = $argv[1] ?? null;

$outputJson = ($argv[2] ?? '') === '--json';
if (!$leadId || !is_numeric($leadId)) {
    fwrite(STDERR, "Usage: php create_lead_in_box.php <cloud_lead_id> [--json]\n");
    fwrite(STDERR, "  --json: output mapped JSON only (pipe to ssh ... create_lead_from_json.php)\n");
    exit(1);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$resp = $client->call('crm.lead.get', ['id' => (int)$leadId]);
if (isset($resp['error'])) {
    fwrite(STDERR, "Error: " . ($resp['error_description'] ?? $resp['error']) . "\n");
    exit(1);
}
$leadData = $resp['result'] ?? null;
if (!$leadData) {
    fwrite(STDERR, "Lead not found\n");
    exit(1);
}

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true);
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true);
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true);

$mapped = [];
foreach ($leadData as $key => $value) {
    if ($value === null || $value === '') continue;
    $targetKey = $fieldMapping['lead_fields'][$key] ?? null;
    if ($targetKey === null) continue;

    if ($key === 'STATUS_ID') {
        $value = $stageMapping['lead_stages'][$value] ?? $value;
    }
    if (in_array($key, ['ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID']) && is_numeric($value)) {
        $value = $userMapping['users'][(string)$value] ?? 1;
    }
    if ($key === 'ID') continue;

    $mapped[$targetKey] = $value;
}

$json = json_encode($mapped, JSON_UNESCAPED_UNICODE);

if ($outputJson) {
    echo $json;
    exit(0);
}

$tmpFile = sys_get_temp_dir() . '/lead_' . $leadId . '_' . getmypid() . '.json';
file_put_contents($tmpFile, $json);

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -P %d %s@%s "cd /home/bitrix/www && php create_lead_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php create_lead_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

if ($output) {
    $result = json_decode(trim($output), true);
    if ($result && isset($result['lead_id'])) {
        echo "Lead created in box: ID=" . $result['lead_id'] . "\n";
    } else {
        echo $output;
    }
} else {
    fwrite(STDERR, "SSH failed\n");
    exit(1);
}
