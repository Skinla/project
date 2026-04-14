#!/usr/bin/env php
<?php
/**
 * Migrate leads from cloud with ID >= given value (and optionally <= --to).
 * Уже выгруженные лиды обновляются по lead_sync_log.
 *
 * Usage: php migrate_leads_from_id.php [--from=ID] [--to=ID] | [--created-from=YYYY-MM-DD --created-to=YYYY-MM-DD] ...
 *   --from=1        минимальный ID в облаке (по умолчанию 67562; не используется с --created-from/to)
 *   --to=67790      максимальный ID включительно
 *   --created-from=2026-03-18  дата создания лида в облаке, с 00:00:00
 *   --created-to=2026-03-20    по 23:59:59 этого дня включительно
 *   --no-date-check не проверять DATE_MODIFY на коробке
 *   --dry-run       только счётчик (из API total) и пример ID, без миграции
 *   --limit=N       не больше N лидов всего
 *
 * Все лиды до 67790:
 *   php migrate_leads_from_id.php --from=1 --to=67790 --no-date-check
 *
 * По дате создания в облаке:
 *   php migrate_leads_from_id.php --created-from=2026-03-18 --created-to=2026-03-20 --no-date-check
 *
 * Выгрузка идёт страницами по 50 ID (как в Bitrix24), на каждую страницу — отдельный запуск на коробке + подтягивание lead_sync_log.
 */
$projectRoot = __DIR__;
$options = getopt('', ['from:', 'to:', 'created-from:', 'created-to:', 'no-date-check', 'dry-run', 'limit:']);
$fromId = isset($options['from']) ? (int)$options['from'] : 67562;
$toId = isset($options['to']) ? (int)$options['to'] : 0;
$createdFrom = isset($options['created-from']) ? trim((string)$options['created-from']) : '';
$createdTo = isset($options['created-to']) ? trim((string)$options['created-to']) : '';
$checkDateModify = !isset($options['no-date-check']);
$dryRun = isset($options['dry-run']);
$limit = isset($options['limit']) ? (int)$options['limit'] : 0;

$useDateCreate = ($createdFrom !== '' || $createdTo !== '');
if ($useDateCreate) {
    if ($createdFrom === '' || $createdTo === '') {
        fwrite(STDERR, "Error: укажите оба параметра --created-from и --created-to (YYYY-MM-DD)\n");
        exit(1);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdTo)) {
        fwrite(STDERR, "Error: даты должны быть в формате YYYY-MM-DD\n");
        exit(1);
    }
    if ($createdFrom > $createdTo) {
        fwrite(STDERR, "Error: --created-from ($createdFrom) must be <= --created-to ($createdTo)\n");
        exit(1);
    }
}

if (!$useDateCreate && $toId > 0 && $fromId > $toId) {
    fwrite(STDERR, "Error: --from ($fromId) must be <= --to ($toId)\n");
    exit(1);
}

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';
require_once $projectRoot . '/lib/LeadSync.php';

$stageMapping = json_decode(file_get_contents($projectRoot . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode(file_get_contents($projectRoot . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode(file_get_contents($projectRoot . '/data/user_mapping.json'), true) ?: [];
$contactMapping = file_exists($projectRoot . '/data/contact_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/contact_mapping.json'), true) ?: [] : [];
$sourceMapping = file_exists($projectRoot . '/data/source_mapping.json') ? json_decode(file_get_contents($projectRoot . '/data/source_mapping.json'), true) ?: [] : [];
$honorificMapping = file_exists($projectRoot . '/data/honorific_mapping.json') ? (json_decode(file_get_contents($projectRoot . '/data/honorific_mapping.json'), true) ?: []) : [];

if (!isset($contactMapping['contacts']) || !is_array($contactMapping['contacts'])) {
    $contactMapping = ['contacts' => []];
}

$client = new BitrixRestClient($config['url']);

if ($useDateCreate) {
    $filter = [
        '>=DATE_CREATE' => $createdFrom . 'T00:00:00',
        '<=DATE_CREATE' => $createdTo . 'T23:59:59',
    ];
    $rangeLabel = "DATE_CREATE [$createdFrom .. $createdTo]";
} else {
    $filter = ['>=ID' => $fromId];
    if ($toId > 0) {
        $filter['<=ID'] = $toId;
    }
    $rangeLabel = $toId > 0 ? "ID in [$fromId .. $toId]" : "ID >= $fromId";
}

/** @return array{result: array, next?: int, total?: int, error?: string} */
function leadListPage(BitrixRestClient $client, array $filter, int $start): array {
    return $client->call('crm.lead.list', [
        'filter' => $filter,
        'select' => ['ID'],
        'order' => ['ID' => 'ASC'],
        'start' => $start,
    ]);
}

if ($dryRun) {
    $leadResp = leadListPage($client, $filter, 0);
    if (isset($leadResp['error'])) {
        fwrite(STDERR, 'crm.lead.list error: ' . ($leadResp['error_description'] ?? $leadResp['error']) . "\n");
        exit(1);
    }
    $total = (int)($leadResp['total'] ?? count($leadResp['result'] ?? []));
    $firstIds = array_column($leadResp['result'] ?? [], 'ID');
    $sample = array_slice($firstIds, 0, 15);
    echo "Leads ($rangeLabel): total по API = $total\n";
    echo "[DRY-RUN] Пример ID (первая страница): " . implode(', ', $sample) . (count($firstIds) > 15 ? ' ...' : '') . "\n";
    if ($limit > 0) {
        echo "[DRY-RUN] С --limit=$limit будет обработано не больше $limit лидов.\n";
    }
    echo "[DRY-RUN] check_date_modify=" . ($checkDateModify ? 'true' : 'false') . "\n";
    exit(0);
}

$logPath = $projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$readLog = static function () use ($logPath) {
    return file_exists($logPath) ? (json_decode(file_get_contents($logPath), true) ?: []) : [];
};

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
    echo "Warning: could not upload migrate_leads_from_json.php\n";
}

$dataDir = $projectRoot . '/local/handlers/leadsync/data';
$scpBack = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/leadsync/data/lead_sync_log.json %s/', escapeshellarg($pass), $port, $user, $host, escapeshellarg($dataDir))
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s@%s:/home/bitrix/www/local/handlers/leadsync/data/lead_sync_log.json %s/', $port, $user, $host, escapeshellarg($dataDir));

$sendBatch = static function (array $payload, bool $checkDateModify) use ($pass, $port, $user, $host, $scpBack): void {
    if ($payload === []) {
        return;
    }
    $input = [
        'items' => $payload,
        'check_date_modify' => $checkDateModify,
    ];
    $tmpFile = sys_get_temp_dir() . '/migrate_leads_' . getmypid() . '_' . mt_rand() . '.json';
    file_put_contents($tmpFile, json_encode($input, JSON_UNESCAPED_UNICODE));

    $sshCmd = $pass
        ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($tmpFile))
        : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "cd /home/bitrix/www && php -d display_errors=0 migrate_leads_from_json.php" < %s', $port, $user, $host, escapeshellarg($tmpFile));

    $output = shell_exec($sshCmd);
    @unlink($tmpFile);
    echo "\n--- batch result ---\n" . ($output ?? 'SSH failed') . "\n";

    exec($scpBack . ' 2>&1', $scpBackOut, $scpBackCode);
    if ($scpBackCode === 0) {
        echo "Synced lead_sync_log.json from box.\n";
    }
};

$log = $readLog();
$start = 0;
$pageIdx = 0;
$processedTotal = 0;
$globalIndex = 0;

while (true) {
    $leadResp = leadListPage($client, $filter, $start);
    if (isset($leadResp['error'])) {
        fwrite(STDERR, 'crm.lead.list error: ' . ($leadResp['error_description'] ?? $leadResp['error']) . "\n");
        exit(1);
    }
    $batch = array_column($leadResp['result'] ?? [], 'ID');
    if ($batch === []) {
        break;
    }

    echo "\n=== Page " . ($pageIdx + 1) . " (start=$start), " . count($batch) . " IDs ===\n";

    $payload = [];
    $stopAll = false;
    foreach ($batch as $cloudLeadId) {
        if ($limit > 0 && $processedTotal >= $limit) {
            $stopAll = true;
            break;
        }
        $cloudLeadId = (int)$cloudLeadId;
        $boxLeadId = isset($log[(string)$cloudLeadId]) ? (int)$log[(string)$cloudLeadId] : null;

        echo "\n[$globalIndex] Lead $cloudLeadId" . ($boxLeadId ? " -> box $boxLeadId (update)" : " (create)") . "... ";
        $globalIndex++;

        $item = LeadSync::buildLeadPayload($client, $cloudLeadId, $stageMapping, $fieldMapping, $userMapping, $contactMapping, $sourceMapping, $honorificMapping);
        if (!$item) {
            echo "Error\n";
            $processedTotal++;
            continue;
        }

        $payload[] = [
            'cloud_lead_id' => $cloudLeadId,
            'box_lead_id' => $boxLeadId,
            'update_only' => (bool)$boxLeadId,
            'lead' => $item['lead'],
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

    if (!isset($leadResp['next'])) {
        break;
    }
    $start = (int)$leadResp['next'];
    $pageIdx++;
}

echo "\n\nDone. Processed ~$processedTotal lead(s) in " . ($pageIdx + 1) . " list page(s).\n";
