<?php
/**
 * Webhook handler for Bitrix24 ONCRMLEADADD event.
 * Receives POST from Bitrix24 when a lead is created, syncs it to box.
 *
 * Deploy this script to a publicly accessible HTTPS URL and register via:
 *   php register_lead_webhook.php
 *
 * Bitrix24 sends: { "event": "ONCRMLEADADD", "data": { "FIELDS": { "ID": "123" } }, ... }
 */
header('Content-Type: application/json; charset=utf-8');
http_response_code(200);

$projectRoot = __DIR__;
$config = @require $projectRoot . '/config/webhook.php';
if (!$config || empty($config['url'])) {
    echo json_encode(['ok' => false, 'error' => 'Config missing']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

$leadId = (int)($input['data']['FIELDS']['ID'] ?? 0);
if ($leadId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'No lead ID in payload']);
    exit;
}

if (($input['event'] ?? '') !== 'ONCRMLEADADD') {
    echo json_encode(['ok' => false, 'error' => 'Wrong event']);
    exit;
}

require_once $projectRoot . '/lib/BitrixRestClient.php';
require_once $projectRoot . '/lib/LeadSync.php';

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];

$client = new BitrixRestClient($config['url']);
$payload = LeadSync::buildLeadPayload($client, $leadId, $stageMapping, $fieldMapping, $userMapping);

if (!$payload) {
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch lead', 'lead_id' => $leadId]);
    exit;
}

$tmpFile = sys_get_temp_dir() . '/lead_sync_' . $leadId . '_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode([$payload], JSON_UNESCAPED_UNICODE));

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$boxScript = $projectRoot . '/migrate_leads_from_json.php';
$scpCmd = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/ 2>/dev/null', escapeshellarg($pass), $port, escapeshellarg($boxScript), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/ 2>/dev/null', $port, escapeshellarg($boxScript), $user, $host);
@exec($scpCmd);

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

$result = json_decode(trim($output ?? ''), true);
$boxLeadId = $result['results'][0]['lead_id'] ?? null;

if ($boxLeadId) {
    echo json_encode(['ok' => true, 'cloud_lead_id' => $leadId, 'box_lead_id' => $boxLeadId]);
} else {
    echo json_encode(['ok' => false, 'cloud_lead_id' => $leadId, 'output' => $output]);
}
