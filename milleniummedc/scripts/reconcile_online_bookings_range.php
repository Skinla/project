#!/usr/bin/env php
<?php
/**
 * Сверка онлайн-записей (CRM_BOOKING) облако ↔ коробка за диапазон дат по START_TIME активности (Europe/Moscow).
 * Тянет deal_booking_sync_log.json с коробки, ищет в облаке активности без маппинга cloud_booking_id → box id,
 * перевыгружает затронутые сделки через migrate_deals_from_id.php до совпадения или --max-rounds.
 *
 * Запуск из корня проекта:
 *   php scripts/reconcile_online_bookings_range.php --from=2026-03-25 --to=2026-03-30
 *   php scripts/reconcile_online_bookings_range.php --from=2026-03-25 --to=2026-03-30 --max-rounds=30 --sleep=3
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/lib/BitrixRestClient.php';

$opts = getopt('', ['from:', 'to:', 'max-rounds::', 'sleep::', 'dry-run']);
$fromStr = isset($opts['from']) ? trim((string)$opts['from']) : '';
$toStr = isset($opts['to']) ? trim((string)$opts['to']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr)) {
    fwrite(STDERR, "Usage: php scripts/reconcile_online_bookings_range.php --from=Y-m-d --to=Y-m-d [--max-rounds=25] [--sleep=2] [--dry-run]\n");
    exit(1);
}
$maxRounds = isset($opts['max-rounds']) ? max(1, (int)$opts['max-rounds']) : 25;
$sleepSec = isset($opts['sleep']) ? max(0, (int)$opts['sleep']) : 2;
$dryRun = isset($opts['dry-run']);

$tz = new DateTimeZone('Europe/Moscow');
$dFrom = DateTimeImmutable::createFromFormat('Y-m-d', $fromStr, $tz);
$dTo = DateTimeImmutable::createFromFormat('Y-m-d', $toStr, $tz);
if (!$dFrom || !$dTo || $dFrom > $dTo) {
    fwrite(STDERR, "Invalid from/to dates\n");
    exit(1);
}

$fromTs = $dFrom->setTime(0, 0, 0)->getTimestamp();
$toTs = $dTo->setTime(23, 59, 59)->getTimestamp();

$config = require $root . '/config/webhook.php';
$ssh = file_exists($root . '/config/ssh.php') ? require $root . '/config/ssh.php' : [];
$host = $ssh['host'] ?? '185.51.60.122';
$port = (int)($ssh['port'] ?? 2226);
$user = $ssh['user'] ?? 'root';
$pass = (string)($ssh['password'] ?? '');

$dataDir = $root . '/local/handlers/dealsync/data';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0755, true);
}

$bookingLogPath = $dataDir . '/deal_booking_sync_log.json';
$failuresPath = $dataDir . '/deal_booking_sync_failures.json';

$pullFromBox = static function () use ($pass, $port, $user, $host, $dataDir): void {
    $base = $pass !== ''
        ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d', escapeshellarg($pass), $port)
        : sprintf('scp -o StrictHostKeyChecking=no -P %d', $port);
    $remote = sprintf('%s@%s:/home/bitrix/www/local/handlers/dealsync/data/', $user, $host);
    @exec($base . ' ' . $remote . 'deal_booking_sync_log.json ' . escapeshellarg($dataDir . '/') . ' 2>/dev/null');
    @exec($base . ' ' . $remote . 'deal_booking_sync_failures.json ' . escapeshellarg($dataDir . '/') . ' 2>/dev/null');
};

/**
 * Отбор по пересечению слота SETTINGS.FIELDS.datePeriod (unix from/to) с [fromTs, toTs] — у CRM_BOOKING в списке START_TIME часто пустой.
 *
 * @return array<int, array{activity_id: int, cloud_deal_id: int, slot_from: int, cloud_booking_id: int}>
 */
function collectCloudBookingRows(BitrixRestClient $client, int $fromTs, int $toTs): array
{
    $byBookingId = [];
    $start = 0;
    do {
        usleep(80000);
        $r = $client->call('crm.activity.list', [
            'filter' => [
                'PROVIDER_ID' => 'CRM_BOOKING',
                'OWNER_TYPE_ID' => 2,
            ],
            'select' => ['ID', 'OWNER_ID', 'SETTINGS', 'PROVIDER_ID'],
            'start' => $start,
        ]);
        if (!empty($r['error'])) {
            fwrite(STDERR, 'crm.activity.list error: ' . ($r['error_description'] ?? $r['error']) . "\n");
            break;
        }
        $chunk = $r['result'] ?? [];
        foreach ($chunk as $row) {
            if (!is_array($row)) {
                continue;
            }
            $actId = (int)($row['ID'] ?? 0);
            $dealId = (int)($row['OWNER_ID'] ?? 0);
            $settings = $row['SETTINGS'] ?? null;
            if (is_string($settings)) {
                $settings = json_decode($settings, true);
            }
            if (!is_array($settings)) {
                $settings = [];
            }
            $fields = $settings['FIELDS'] ?? [];
            if (!is_array($fields)) {
                $fields = [];
            }
            $dp = $fields['datePeriod'] ?? null;
            if (!is_array($dp)) {
                continue;
            }
            $slotFrom = (int)($dp['from'] ?? 0);
            $slotTo = (int)($dp['to'] ?? $slotFrom);
            if ($slotTo < $slotFrom) {
                $slotTo = $slotFrom;
            }
            if ($slotTo < $fromTs || $slotFrom > $toTs) {
                continue;
            }
            $bid = (int)($fields['id'] ?? 0);
            if ($bid <= 0 || $dealId <= 0) {
                continue;
            }
            $byBookingId[$bid] = [
                'activity_id' => $actId,
                'cloud_deal_id' => $dealId,
                'slot_from' => $slotFrom,
                'cloud_booking_id' => $bid,
            ];
        }
        $n = count($chunk);
        $start += $n;
    } while ($n >= 50);

    return $byBookingId;
}

/**
 * @param array<string, mixed> $log
 * @return list<int>
 */
function missingCloudBookingIds(array $expectedByBid, array $log): array
{
    $missing = [];
    foreach ($expectedByBid as $bid => $_meta) {
        $key = (string)$bid;
        $v = $log[$key] ?? null;
        $boxId = is_array($v) ? ($v['box_id'] ?? $v[0] ?? null) : $v;
        if (!is_numeric($boxId) || (int)$boxId <= 0) {
            $missing[] = $bid;
        }
    }

    return $missing;
}

$client = new BitrixRestClient($config['url']);

echo 'Range slot datePeriod (MSK): ' . date('Y-m-d H:i:s', $fromTs) . ' .. ' . date('Y-m-d H:i:s', $toTs) . "\n";
echo "Max rounds: {$maxRounds}, sleep between: {$sleepSec}s\n\n";

for ($round = 1; $round <= $maxRounds; $round++) {
    $pullFromBox();
    $bookingLog = file_exists($bookingLogPath) ? (json_decode((string)file_get_contents($bookingLogPath), true) ?: []) : [];

    $cloudRows = collectCloudBookingRows($client, $fromTs, $toTs);
    $expectedCount = count($cloudRows);
    $mappedCount = 0;
    foreach ($cloudRows as $bid => $_) {
        $key = (string)$bid;
        $v = $bookingLog[$key] ?? null;
        $boxId = is_array($v) ? ($v['box_id'] ?? null) : $v;
        if (is_numeric($boxId) && (int)$boxId > 0) {
            $mappedCount++;
        }
    }

    echo "--- Round {$round}/{$maxRounds} ---\n";
    echo "Cloud CRM_BOOKING in range (unique booking id): {$expectedCount}, mapped in deal_booking_sync_log: {$mappedCount}\n";

    $missingBids = missingCloudBookingIds($cloudRows, $bookingLog);
    if ($missingBids === []) {
        echo "OK: все онлайн-записи из облака за период имеют пару в deal_booking_sync_log.json на коробке.\n";
        exit(0);
    }

    $dealIds = [];
    foreach ($missingBids as $bid) {
        $dealIds[] = (int)$cloudRows[$bid]['cloud_deal_id'];
    }
    $dealIds = array_values(array_unique(array_filter($dealIds, static fn (int $x): bool => $x > 0)));
    sort($dealIds);

    echo 'Missing cloud booking ids (' . count($missingBids) . '): ' . implode(', ', array_slice($missingBids, 0, 40)) . (count($missingBids) > 40 ? ' ...' : '') . "\n";
    echo 'Cloud deal ids to re-sync (' . count($dealIds) . '): ' . implode(', ', array_slice($dealIds, 0, 40)) . (count($dealIds) > 40 ? ' ...' : '') . "\n";

    if ($dryRun) {
        exit(0);
    }

    $chunks = array_chunk($dealIds, 25);
    $phpBin = PHP_BINARY ?: 'php';
    foreach ($chunks as $ci => $chunk) {
        $spec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $cmdline = [$phpBin, $root . '/migrate_deals_from_id.php', '--deal-ids=' . implode(',', $chunk), '--no-date-check'];
        $proc = proc_open($cmdline, $spec, $pipes, $root);
        if (!is_resource($proc)) {
            fwrite(STDERR, "proc_open failed for chunk " . ($ci + 1) . "\n");
            continue;
        }
        fclose($pipes[0]);
        stream_copy_to_stream($pipes[1], STDOUT);
        stream_copy_to_stream($pipes[2], STDERR);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if ($code !== 0) {
            fwrite(STDERR, "migrate_deals_from_id exited {$code} for chunk " . ($ci + 1) . "\n");
        }
        if ($sleepSec > 0) {
            sleep($sleepSec);
        }
    }

    if ($sleepSec > 0) {
        sleep($sleepSec);
    }
}

fwrite(STDERR, "Не сошлось за {$maxRounds} раундов. Проверьте deal_booking_sync_failures.json на коробке и права webhook на booking.v1.*\n");
exit(1);
