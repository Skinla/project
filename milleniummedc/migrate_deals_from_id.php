#!/usr/bin/env php
<?php
/**
 * Migrate deals from cloud by ID range or --last N (max ID first).
 * Лог пар cloud→box: local/handlers/dealsync/data/deal_sync_log.json (как лиды).
 *
 * Usage:
 *   php migrate_deals_from_id.php --last [--limit=1] [--no-date-check]
 *   php migrate_deals_from_id.php --id=12345 [--no-date-check]
 *   php migrate_deals_from_id.php --from=1 --to=999 [--no-date-check]
 *   php migrate_deals_from_id.php --today [--date=YYYY-MM-DD]  # DATE_CREATE за календарный день (Europe/Moscow), как audit_today_deals_cloud.php
 *   php migrate_deals_from_id.php --deal-ids=1,2,3 [--no-date-check] [--strict-booking]  # явный список cloud deal id (чанки по 25)
 *
 * check_date_modify по умолчанию true: обновление на коробке пропускается, если DATE_MODIFY облака <= коробки.
 */
$projectRoot = __DIR__;
$options = getopt('', ['last', 'id:', 'from:', 'to:', 'no-date-check', 'dry-run', 'limit:', 'today', 'date::', 'deal-ids:', 'strict-booking']);
$checkDateModify = !isset($options['no-date-check']);
$dryRun = isset($options['dry-run']);
$strictBookingMigrate = isset($options['strict-booking']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;
$singleId = isset($options['id']) ? (int)$options['id'] : 0;
$fromId = isset($options['from']) ? (int)$options['from'] : 0;
$toId = isset($options['to']) ? (int)$options['to'] : 0;
$useLast = isset($options['last']);
$todayOpt = isset($options['today']);
$dateCalendarStr = null;
if (isset($options['date']) && is_string($options['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['date'])) {
    $dateCalendarStr = $options['date'];
}
$explicitDealIds = [];
if (!empty($options['deal-ids']) && is_string($options['deal-ids'])) {
    foreach (explode(',', $options['deal-ids']) as $p) {
        $i = (int)trim($p);
        if ($i > 0) {
            $explicitDealIds[] = $i;
        }
    }
    $explicitDealIds = array_values(array_unique($explicitDealIds));
}

if ($explicitDealIds !== []) {
    $filter = [];
    $rangeLabel = 'deal-ids (' . count($explicitDealIds) . ' cloud deals)';
} elseif ($singleId > 0) {
    $filter = ['ID' => $singleId];
    $rangeLabel = "ID = $singleId";
} elseif ($useLast) {
    $filter = [];
    $rangeLabel = 'last by ID desc';
} elseif ($fromId > 0) {
    $filter = ['>=ID' => $fromId];
    if ($toId > 0) {
        if ($fromId > $toId) {
            fwrite(STDERR, "Error: --from must be <= --to\n");
            exit(1);
        }
        $filter['<=ID'] = $toId;
    }
    $rangeLabel = $toId > 0 ? "ID in [$fromId .. $toId]" : "ID >= $fromId";
} elseif ($todayOpt || $dateCalendarStr !== null) {
    $tz = new DateTimeZone('Europe/Moscow');
    $dateStr = $dateCalendarStr ?? (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $filter = [
        '>=DATE_CREATE' => $dateStr . ' 00:00:00',
        '<=DATE_CREATE' => $dateStr . ' 23:59:59',
    ];
    $rangeLabel = "DATE_CREATE day {$dateStr} (Europe/Moscow)";
} else {
    fwrite(STDERR, "Usage: php migrate_deals_from_id.php --last | --id=N | --from=A [--to=B] | --today [--date=Y-m-d] | --deal-ids=1,2,3 [--strict-booking] [--limit=N] [--no-date-check] [--dry-run]\n");
    exit(1);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';
require_once $projectRoot . '/lib/ContactBoxSync.php';
require_once $projectRoot . '/lib/DealSync.php';

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];
$contactMapping = file_exists($projectRoot . '/data/contact_mapping.json')
    ? (json_decode(file_get_contents($projectRoot . '/data/contact_mapping.json'), true) ?: [])
    : [];
$sourceMapping = file_exists($projectRoot . '/data/source_mapping.json')
    ? (json_decode(file_get_contents($projectRoot . '/data/source_mapping.json'), true) ?: [])
    : [];
$companyMapping = file_exists($projectRoot . '/data/company_mapping.json')
    ? (json_decode(file_get_contents($projectRoot . '/data/company_mapping.json'), true) ?: [])
    : [];

$leadLogPath = $projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$leadMapping = ['leads' => []];
if (file_exists($leadLogPath)) {
    $raw = json_decode(file_get_contents($leadLogPath), true);
    if (is_array($raw)) {
        $leadMapping['leads'] = $raw;
    }
}

if (!isset($contactMapping['contacts']) || !is_array($contactMapping['contacts'])) {
    $contactMapping = ['contacts' => []];
}
if (!isset($companyMapping['companies']) || !is_array($companyMapping['companies'])) {
    $companyMapping = ['companies' => $companyMapping['companies'] ?? []];
}

$categoryMapping = $stageMapping['category_mapping'] ?? [];
$dealStages = $stageMapping['deal_stages'] ?? [];
$dealFields = $fieldMapping['deal_fields'] ?? [];

$client = new BitrixRestClient($config['url']);

/** @return array{result: array, next?: int, total?: int, error?: string} */
function dealListPage(BitrixRestClient $client, array $filter, int $start, bool $orderDescId): array
{
    $params = [
        'filter' => $filter,
        'select' => ['ID'],
        'order' => $orderDescId ? ['ID' => 'DESC'] : ['ID' => 'ASC'],
        'start' => $start,
    ];
    if ($filter === []) {
        unset($params['filter']);
    }

    return $client->call('crm.deal.list', $params);
}

if ($dryRun) {
    if ($explicitDealIds !== []) {
        echo "Deals ($rangeLabel): " . implode(', ', array_slice($explicitDealIds, 0, 30)) . (count($explicitDealIds) > 30 ? ' ...' : '') . "\n";
        echo '[DRY-RUN] check_date_modify=' . ($checkDateModify ? 'true' : 'false') . ' strict_booking=' . ($strictBookingMigrate ? 'true' : 'false') . "\n";
        exit(0);
    }
    $resp = dealListPage($client, $filter, 0, $useLast && $singleId <= 0);
    if (isset($resp['error'])) {
        fwrite(STDERR, 'crm.deal.list error: ' . ($resp['error_description'] ?? $resp['error']) . "\n");
        exit(1);
    }
    $ids = array_column($resp['result'] ?? [], 'ID');
    $total = (int)($resp['total'] ?? count($ids));
    echo "Deals ($rangeLabel): total API ~ $total, sample IDs: " . implode(', ', array_slice($ids, 0, 10)) . "\n";
    echo '[DRY-RUN] check_date_modify=' . ($checkDateModify ? 'true' : 'false') . "\n";
    exit(0);
}

$logPath = $projectRoot . '/local/handlers/dealsync/data/deal_sync_log.json';
$readLog = static function () use ($logPath) {
    return file_exists($logPath) ? (json_decode(file_get_contents($logPath), true) ?: []) : [];
};

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$boxScript = $projectRoot . '/migrate_deals_from_json.php';
$scpCmd = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', escapeshellarg($pass), $port, escapeshellarg($boxScript), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/home/bitrix/www/', $port, escapeshellarg($boxScript), $user, $host);
exec($scpCmd . ' 2>&1', $scpOut, $scpCode);
if ($scpCode !== 0) {
    echo "Warning: could not upload migrate_deals_from_json.php\n";
}

$dataDir = $projectRoot . '/local/handlers/dealsync/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}
$scpEnsure = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg('mkdir -p /home/bitrix/www/local/handlers/dealsync/data'))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', $port, $user, $host, escapeshellarg('mkdir -p /home/bitrix/www/local/handlers/dealsync/data'));
exec($scpEnsure);

$scpBack = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_sync_log.json %s/', escapeshellarg($pass), $port, $user, $host, escapeshellarg($dataDir))
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_sync_log.json %s/', $port, $user, $host, escapeshellarg($dataDir));
$scpTaskLogBack = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_task_sync_log.json %s/deal_task_sync_log.json', escapeshellarg($pass), $port, $user, $host, escapeshellarg($dataDir))
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_task_sync_log.json %s/deal_task_sync_log.json', $port, $user, $host, escapeshellarg($dataDir));
$scpBookingLogBack = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_booking_sync_log.json %s/deal_booking_sync_log.json', escapeshellarg($pass), $port, $user, $host, escapeshellarg($dataDir))
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_booking_sync_log.json %s/deal_booking_sync_log.json', $port, $user, $host, escapeshellarg($dataDir));
$scpBookingFailuresBack = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_booking_sync_failures.json %s/deal_booking_sync_failures.json', escapeshellarg($pass), $port, $user, $host, escapeshellarg($dataDir))
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/dealsync/data/deal_booking_sync_failures.json %s/deal_booking_sync_failures.json', $port, $user, $host, escapeshellarg($dataDir));

$sendBatch = static function (array $payload, bool $checkDateModify) use ($pass, $port, $user, $host, $scpBack, $scpTaskLogBack, $scpBookingLogBack, $scpBookingFailuresBack, $strictBookingMigrate): void {
    if ($payload === []) {
        return;
    }
    $input = [
        'items' => $payload,
        'check_date_modify' => $checkDateModify,
        'strict_booking' => $strictBookingMigrate,
    ];
    $tmpFile = sys_get_temp_dir() . '/migrate_deals_' . getmypid() . '_' . mt_rand() . '.json';
    file_put_contents($tmpFile, json_encode($input, JSON_UNESCAPED_UNICODE));

    $sshCmd = $pass
        ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_deals_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
        : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_deals_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

    $output = shell_exec($sshCmd);
    @unlink($tmpFile);
    echo "\n--- batch result ---\n" . ($output ?? 'SSH failed') . "\n";

    exec($scpBack . ' 2>&1', $scpBackOut, $scpBackCode);
    if ($scpBackCode === 0) {
        echo "Synced deal_sync_log.json from box.\n";
    }
    exec($scpTaskLogBack . ' 2>&1', $scpTaskOut, $scpTaskCode);
    if ($scpTaskCode === 0) {
        echo "Synced deal_task_sync_log.json from box.\n";
    }
    exec($scpBookingLogBack . ' 2>&1', $scpBookOut, $scpBookCode);
    if ($scpBookCode === 0) {
        echo "Synced deal_booking_sync_log.json from box.\n";
    }
    exec($scpBookingFailuresBack . ' 2>&1', $scpFailOut, $scpFailCode);
    if ($scpFailCode === 0) {
        echo "Synced deal_booking_sync_failures.json from box.\n";
    }
};

$log = $readLog();
$start = 0;
$pageIdx = 0;
$processedTotal = 0;
$orderDesc = $useLast && $singleId <= 0;
if (($todayOpt || $dateCalendarStr !== null) && !$useLast) {
    $orderDesc = false;
}

$explicitChunks = $explicitDealIds !== [] ? array_chunk($explicitDealIds, 25) : [];
$chunkIdx = 0;

while (true) {
    if ($explicitDealIds !== []) {
        if ($chunkIdx >= count($explicitChunks)) {
            break;
        }
        $batch = $explicitChunks[$chunkIdx];
        $chunkIdx++;
        echo "\n=== Chunk $chunkIdx/" . count($explicitChunks) . ', ' . count($batch) . " deal IDs ===\n";
        $dealResp = ['next' => null];
    } else {
        $dealResp = dealListPage($client, $filter, $start, $orderDesc);
        if (isset($dealResp['error'])) {
            fwrite(STDERR, 'crm.deal.list error: ' . ($dealResp['error_description'] ?? $dealResp['error']) . "\n");
            exit(1);
        }
        $batch = array_column($dealResp['result'] ?? [], 'ID');
        if ($batch === []) {
            break;
        }

        echo "\n=== Page " . ($pageIdx + 1) . " (start=$start), " . count($batch) . " IDs ===\n";
    }

    $batchContactIds = [];
    foreach ($batch as $cloudDealId) {
        $sel = $client->call('crm.deal.get', ['id' => (int)$cloudDealId, 'select' => ['CONTACT_ID', 'CONTACT_IDS']]);
        if (!isset($sel['error']) && !empty($sel['result'])) {
            $batchContactIds = array_merge($batchContactIds, ContactBoxSync::collectContactIdsFromDeal($sel['result']));
        }
    }
    $batchContactIds = array_values(array_unique(array_filter(array_map('intval', $batchContactIds))));
    if ($batchContactIds !== []) {
        $before = count($contactMapping['contacts'] ?? []);
        $merged = ContactBoxSync::ensureCloudContactsMapped($client, $batchContactIds, $userMapping, $projectRoot, $sshConfig);
        $contactMapping['contacts'] = $merged;
        $after = count($merged);
        if ($after > $before) {
            echo 'Contacts: ensured on box, mapping entries ' . $before . ' -> ' . $after . "\n";
        }
    }

    $payload = [];
    $stopAll = false;
    foreach ($batch as $cloudDealId) {
        if ($limit > 0 && $processedTotal >= $limit) {
            $stopAll = true;
            break;
        }
        $cloudDealId = (int)$cloudDealId;
        $boxDealId = isset($log[(string)$cloudDealId]) ? (int)$log[(string)$cloudDealId] : null;

        echo "\nDeal cloud $cloudDealId" . ($boxDealId ? " -> box $boxDealId (update)" : ' (create)') . '... ';

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
            $boxDealId
        );
        if (!$item) {
            echo "Error (crm.deal.get)\n";
            $processedTotal++;
            continue;
        }

        $payload[] = [
            'cloud_deal_id' => $cloudDealId,
            'box_deal_id' => $boxDealId,
            'update_only' => (bool)$boxDealId,
            'deal' => $item['deal'],
            'activities' => $item['activities'],
            'comments' => $item['comments'],
            'products' => $item['products'],
        ];

        echo 'act:' . count($item['activities']) . ' com:' . count($item['comments']) . ' prod:' . count($item['products']);
        $processedTotal++;
    }

    $sendBatch($payload, $checkDateModify);
    $log = $readLog();

    if ($stopAll) {
        break;
    }

    if ($singleId > 0) {
        break;
    }

    if ($explicitDealIds !== []) {
        continue;
    }

    if (!isset($dealResp['next'])) {
        break;
    }
    $start = (int)$dealResp['next'];
    $pageIdx++;
}

echo "\n\nDone. Processed ~$processedTotal deal(s).\n";
