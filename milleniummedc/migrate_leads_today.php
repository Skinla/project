#!/usr/bin/env php
<?php
/**
 * Migrate today's leads from cloud to box with timeline and products.
 * Usage: php migrate_leads_today.php [--dry-run] [--limit=N]
 */
$projectRoot = __DIR__;
$options = getopt('', ['dry-run', 'limit:', 'no-date-check']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$checkDateModify = !isset($options['no-date-check']);

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';
require_once $projectRoot . '/lib/CrmActivityCloudEnrich.php';

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true);
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true);
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true);
$contactMapping = file_exists($projectRoot . '/data/contact_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/contact_mapping.json'), true) ?: [] : [];
$sourceMapping = file_exists($projectRoot . '/data/source_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/source_mapping.json'), true) ?: [] : [];
$honorificMapping = file_exists($projectRoot . '/data/honorific_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/honorific_mapping.json'), true) ?: [] : [];

$client = new BitrixRestClient($config['url']);
$today = date('Y-m-d');

$leadResp = $client->call('crm.lead.list', [
    'filter' => [
        '>=DATE_CREATE' => $today . 'T00:00:00',
        '<=DATE_CREATE' => $today . 'T23:59:59',
    ],
    'select' => ['ID'],
    'order' => ['DATE_CREATE' => 'DESC'],
]);

$leadIds = array_column($leadResp['result'] ?? [], 'ID');
if ($limit > 0) {
    $leadIds = array_slice($leadIds, 0, $limit);
}

echo "Leads to migrate: " . count($leadIds) . "\n";

$payload = [];

foreach ($leadIds as $i => $leadId) {
    $leadId = (int)$leadId;
    echo "\n[$i] Lead $leadId... ";

    $leadResp = $client->call('crm.lead.get', ['id' => $leadId]);
    if (isset($leadResp['error'])) {
        echo "Error: " . ($leadResp['error_description'] ?? $leadResp['error']) . "\n";
        continue;
    }
    $lead = $leadResp['result'] ?? null;
    if (!$lead) continue;

    $mapped = [];
    $fm = [];
    foreach ($lead as $key => $value) {
        if ($value === null || $value === '') continue;
        $targetKey = $fieldMapping['lead_fields'][$key] ?? null;
        if ($targetKey === null || $key === 'ID') continue;
        if ($key === 'STATUS_ID') $value = $stageMapping['lead_stages'][$value] ?? $value;
        if ($key === 'SOURCE_ID' && !empty($sourceMapping['sources'])) $value = $sourceMapping['sources'][$value] ?? $value;
        if ($key === 'HONORIFIC' && !empty($honorificMapping['honorific'])) $value = $honorificMapping['honorific'][$value] ?? $value;
        if (in_array($key, ['ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID']) && is_numeric($value)) {
            $value = $userMapping['users'][(string)$value] ?? 1;
        }
        if ($key === 'CONTACT_ID' && is_numeric($value)) {
            $value = $contactMapping['contacts'][(string)$value] ?? null;
            if ($value === null) continue;
        }
        if ($key === 'PHONE' && is_array($value)) {
            foreach ($value as $i => $item) {
                if (!empty($item['VALUE'])) {
                    $fm['PHONE']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                }
            }
            continue;
        }
        if ($key === 'EMAIL' && is_array($value)) {
            foreach ($value as $i => $item) {
                if (!empty($item['VALUE'])) {
                    $fm['EMAIL']['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                }
            }
            continue;
        }
        if (in_array($key, ['WEB', 'IM']) && is_array($value)) {
            foreach ($value as $i => $item) {
                if (!empty($item['VALUE'])) {
                    $fm[$key]['n' . $i] = ['VALUE' => $item['VALUE'], 'VALUE_TYPE' => $item['VALUE_TYPE'] ?? 'WORK'];
                }
            }
            continue;
        }
        $mapped[$targetKey] = $value;
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
    if (!empty($fm)) {
        $mapped['FM'] = $fm;
    }

    $activities = [];
    $actResp = $client->call('crm.activity.list', [
        'filter' => ['OWNER_TYPE_ID' => 1, 'OWNER_ID' => $leadId],
        'select' => ['*', 'COMMUNICATIONS'],
    ]);
    foreach ($actResp['result'] ?? [] as $act) {
        if (is_array($act)) {
            $act = CrmActivityCloudEnrich::mergeFullFieldsFromGet($client, $act);
        }
        $cloudActId = $act['ID'] ?? null;
        $act['RESPONSIBLE_ID'] = $userMapping['users'][(string)($act['RESPONSIBLE_ID'] ?? '')] ?? 1;
        $act['AUTHOR_ID'] = $userMapping['users'][(string)($act['AUTHOR_ID'] ?? '')] ?? 1;
        $act['EDITOR_ID'] = $userMapping['users'][(string)($act['EDITOR_ID'] ?? '')] ?? 1;
        unset($act['ID'], $act['OWNER_ID']);
        $act['cloud_activity_id'] = $cloudActId;
        $activities[] = $act;
    }

    $comments = [];
    $comResp = $client->call('crm.timeline.comment.list', [
        'filter' => ['ENTITY_ID' => $leadId, 'ENTITY_TYPE' => 'lead'],
        'select' => ['ID', 'CREATED', 'AUTHOR_ID', 'COMMENT', 'FILES'],
    ]);
    foreach ($comResp['result'] ?? [] as $com) {
        $com['AUTHOR_ID'] = $userMapping['users'][(string)($com['AUTHOR_ID'] ?? '')] ?? 1;
        unset($com['ID']);
        $comments[] = $com;
    }

    $products = [];
    $prodResp = $client->call('crm.lead.productrows.get', ['id' => $leadId]);
    foreach ($prodResp['result'] ?? [] as $p) {
        unset($p['ID'], $p['OWNER_ID']);
        $products[] = $p;
    }

    $payload[] = [
        'cloud_lead_id' => $leadId,
        'lead' => $mapped,
        'activities' => $activities,
        'comments' => $comments,
        'products' => $products,
    ];

    echo "lead+activities:" . count($activities) . "+comments:" . count($comments) . "+products:" . count($products);
}

if ($dryRun) {
    echo "\n\n[DRY-RUN] Would migrate " . count($payload) . " leads.\n";
    exit(0);
}

if (empty($payload)) {
    echo "\nNo leads to migrate.\n";
    exit(0);
}

$input = ['items' => $payload, 'check_date_modify' => $checkDateModify];
$tmpFile = sys_get_temp_dir() . '/migrate_leads_' . getmypid() . '.json';
file_put_contents($tmpFile, json_encode($input, JSON_UNESCAPED_UNICODE));

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
if ($scpCode !== 0) {
    echo "\nWarning: could not upload migrate_leads_from_json.php: " . implode("\n", $scpOut) . "\n";
}

$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 -d log_errors=0 migrate_leads_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 -d log_errors=0 migrate_leads_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

$output = shell_exec($sshCmd);
@unlink($tmpFile);

echo "\n\n" . ($output ?? 'SSH failed') . "\n";
