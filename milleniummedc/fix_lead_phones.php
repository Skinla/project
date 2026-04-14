#!/usr/bin/env php
<?php
/**
 * Fix phone/email in existing migrated leads. Updates leads from log with FM from cloud.
 * Usage: php fix_lead_phones.php
 */
$projectRoot = __DIR__;
$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$logPath = $projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$log = file_exists($logPath) ? json_decode(file_get_contents($logPath), true) : [];
if (empty($log)) {
    echo "No pairs in log.\n";
    exit(0);
}

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];

$client = new BitrixRestClient($config['url']);
$contactMapping = file_exists($projectRoot . '/data/contact_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/contact_mapping.json'), true) ?: [] : [];

$payload = [];
foreach ($log as $cloudId => $boxId) {
    $cloudId = (int)$cloudId;
    $boxId = (int)$boxId;
    echo "Fetching cloud lead $cloudId (box $boxId)... ";
    $leadResp = $client->call('crm.lead.get', ['id' => $cloudId]);
    if (isset($leadResp['error'])) {
        echo "Error\n";
        continue;
    }
    $lead = $leadResp['result'] ?? null;
    if (!$lead) {
        echo "Not found\n";
        continue;
    }
    $fm = [];
    foreach ($lead['PHONE'] ?? [] as $i => $item) {
        if (!empty($item['VALUE'])) {
            $fm['PHONE']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    foreach ($lead['EMAIL'] ?? [] as $i => $item) {
        if (!empty($item['VALUE'])) {
            $fm['EMAIL']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    if (!empty($lead['CONTACT_ID']) && (empty($fm['PHONE']) || empty($fm['EMAIL']))) {
        $contactResp = $client->call('crm.contact.get', ['id' => $lead['CONTACT_ID']]);
        if (!isset($contactResp['error']) && !empty($contactResp['result'])) {
            $contact = $contactResp['result'];
            if (empty($fm['PHONE']) && !empty($contact['PHONE']) && is_array($contact['PHONE'])) {
                foreach ($contact['PHONE'] as $i => $item) {
                    if (!empty($item['VALUE'])) {
                        $fm['PHONE']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                    }
                }
            }
            if (empty($fm['EMAIL']) && !empty($contact['EMAIL']) && is_array($contact['EMAIL'])) {
                foreach ($contact['EMAIL'] as $i => $item) {
                    if (!empty($item['VALUE'])) {
                        $fm['EMAIL']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                    }
                }
            }
        }
    }
    if (empty($fm)) {
        echo "No phone/email\n";
        continue;
    }
    $leadFields = ['FM' => $fm];
    if (!empty($contactMapping['contacts'][(string)$lead['CONTACT_ID']])) {
        $leadFields['CONTACT_ID'] = $contactMapping['contacts'][(string)$lead['CONTACT_ID']];
    }
    $payload[] = [
        'cloud_lead_id' => $cloudId,
        'box_lead_id' => $boxId,
        'update_only' => true,
        'lead' => $leadFields,
        'activities' => [],
        'comments' => [],
        'products' => [],
    ];
    echo "OK (" . count($fm['PHONE'] ?? []) . " phones, " . count($fm['EMAIL'] ?? []) . " emails)\n";
}

if (empty($payload)) {
    echo "Nothing to fix.\n";
    exit(0);
}

$tmpFile = sys_get_temp_dir() . '/fix_phones_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode($payload, JSON_UNESCAPED_UNICODE));

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

echo "\n" . ($output ?? 'SSH failed') . "\n";
