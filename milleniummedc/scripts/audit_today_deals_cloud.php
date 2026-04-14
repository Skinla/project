<?php
/**
 * Выгрузка и аудит сделок облака за день: сделки, стадии, поля, ответственные,
 * активности (в т.ч. задачи, звонки, онлайн-запись), комментарии таймлайна, товары.
 * Проверка покрытия stage_mapping, category_mapping, user_mapping, field_mapping (null UF).
 *
 * Запуск из корня проекта:
 *   php scripts/audit_today_deals_cloud.php
 *   php scripts/audit_today_deals_cloud.php --date=2026-03-21
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib/BitrixRestClient.php';

$opts = getopt('', ['date::']);
$tz = new DateTimeZone('Europe/Moscow');
$dateStr = isset($opts['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts['date'])
    ? (string)$opts['date']
    : (new DateTimeImmutable('now', $tz))->format('Y-m-d');

$cfg = require $root . '/config/webhook.php';
$client = new BitrixRestClient($cfg['url']);

$stageMapping = json_decode((string)file_get_contents($root . '/data/stage_mapping.json'), true) ?: [];
$fieldMapping = json_decode((string)file_get_contents($root . '/data/field_mapping.json'), true) ?: [];
$userMapping = json_decode((string)file_get_contents($root . '/data/user_mapping.json'), true) ?: [];
$dealFields = $fieldMapping['deal_fields'] ?? [];
$dealStages = $stageMapping['deal_stages'] ?? [];
$categoryMap = $stageMapping['category_mapping'] ?? [];
$users = $userMapping['users'] ?? [];

$filter = [
    '>=DATE_CREATE' => $dateStr . ' 00:00:00',
    '<=DATE_CREATE' => $dateStr . ' 23:59:59',
];

$list = $client->call('crm.deal.list', [
    'filter' => $filter,
    'select' => ['ID', 'TITLE', 'CATEGORY_ID', 'STAGE_ID', 'ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID', 'DATE_CREATE', 'OPPORTUNITY', 'CURRENCY_ID', 'CONTACT_ID', 'COMPANY_ID', 'SOURCE_ID'],
    'order' => ['ID' => 'ASC'],
]);
if (!empty($list['error'])) {
    fwrite(STDERR, $list['error'] . ': ' . ($list['error_description'] ?? '') . "\n");
    exit(1);
}

$dealIds = array_map(static fn($r) => (int)($r['ID'] ?? 0), $list['result'] ?? []);
$dealIds = array_values(array_filter($dealIds, static fn($id) => $id > 0));
$totalList = (int)($list['total'] ?? count($dealIds));

if (count($dealIds) !== $totalList) {
    fwrite(STDERR, "Warning: list total={$totalList} but got " . count($dealIds) . " ids (pagination?).\n");
}

$usleepBetween = 120000; // ~8 req/s max burst mitigation

$deals = [];
$globalActivityByProvider = [];
$issues = [
    'unmapped_stage' => [],
    'unmapped_category' => [],
    'unmapped_user_assigned' => [],
    'unmapped_user_created' => [],
    'unmapped_user_modify' => [],
    'uf_unmapped_in_mapping_file' => [],
];

foreach ($dealIds as $did) {
    usleep($usleepBetween);
    $g = $client->call('crm.deal.get', ['id' => $did]);
    if (!empty($g['error']) || empty($g['result'])) {
        $issues['deal_get_failed'][] = ['id' => $did, 'error' => $g['error'] ?? 'empty'];
        continue;
    }
    $deal = $g['result'];
    $cat = (string)($deal['CATEGORY_ID'] ?? '0');
    $stage = (string)($deal['STAGE_ID'] ?? '');
    $ass = (string)($deal['ASSIGNED_BY_ID'] ?? '');
    $cr = (string)($deal['CREATED_BY_ID'] ?? '');
    $mod = (string)($deal['MODIFY_BY_ID'] ?? '');

    if (!array_key_exists($cat, $categoryMap)) {
        $issues['unmapped_category'][] = ['deal_id' => $did, 'CATEGORY_ID' => $cat];
    }
    if ($stage !== '' && !array_key_exists($stage, $dealStages)) {
        $issues['unmapped_stage'][] = ['deal_id' => $did, 'STAGE_ID' => $stage];
    }
    if ($ass !== '' && !array_key_exists($ass, $users)) {
        $issues['unmapped_user_assigned'][] = ['deal_id' => $did, 'ASSIGNED_BY_ID' => $ass];
    }
    if ($cr !== '' && !array_key_exists($cr, $users)) {
        $issues['unmapped_user_created'][] = ['deal_id' => $did, 'CREATED_BY_ID' => $cr];
    }
    if ($mod !== '' && !array_key_exists($mod, $users)) {
        $issues['unmapped_user_modify'][] = ['deal_id' => $did, 'MODIFY_BY_ID' => $mod];
    }

    $ufNull = [];
    foreach ($deal as $k => $v) {
        if (strpos((string)$k, 'UF_') !== 0 || $v === '' || $v === null) {
            continue;
        }
        if (array_key_exists($k, $dealFields) && $dealFields[$k] === null) {
            $ufNull[] = $k;
        }
        if (!array_key_exists($k, $dealFields)) {
            $ufNull[] = $k . ' (not in deal_fields)';
        }
    }
    if ($ufNull !== []) {
        $issues['uf_unmapped_in_mapping_file'][] = ['deal_id' => $did, 'fields' => array_values(array_unique($ufNull))];
    }

    $activities = [];
    $start = 0;
    do {
        usleep($usleepBetween);
        $ar = $client->call('crm.activity.list', [
            'filter' => ['OWNER_TYPE_ID' => 2, 'OWNER_ID' => $did],
            'select' => ['ID', 'TYPE_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SUBJECT', 'START_TIME', 'END_TIME', 'RESPONSIBLE_ID', 'COMPLETED', 'DIRECTION', 'ORIGIN_ID'],
            'start' => $start,
        ]);
        if (!empty($ar['error'])) {
            $issues['activity_list_failed'][] = ['deal_id' => $did, 'start' => $start, 'error' => $ar['error']];
            break;
        }
        $chunk = $ar['result'] ?? [];
        foreach ($chunk as $row) {
            $activities[] = $row;
            $p = (string)($row['PROVIDER_ID'] ?? '');
            $globalActivityByProvider[$p] = ($globalActivityByProvider[$p] ?? 0) + 1;
        }
        $start += count($chunk);
    } while (count($chunk) >= 50);

    $comments = [];
    $cstart = 0;
    do {
        usleep($usleepBetween);
        $cr = $client->call('crm.timeline.comment.list', [
            'filter' => ['ENTITY_ID' => $did, 'ENTITY_TYPE' => 'deal'],
            'select' => ['ID', 'CREATED', 'AUTHOR_ID'],
            'start' => $cstart,
        ]);
        if (!empty($cr['error'])) {
            $issues['comment_list_failed'][] = ['deal_id' => $did, 'error' => $cr['error']];
            break;
        }
        $cchunk = $cr['result'] ?? [];
        foreach ($cchunk as $row) {
            $comments[] = $row;
        }
        $cstart += count($cchunk);
    } while (count($cchunk) >= 50);

    usleep($usleepBetween);
    $pr = $client->call('crm.deal.productrows.get', ['id' => $did]);
    $products = (!empty($pr['error'])) ? [] : ($pr['result'] ?? []);

    $activitySummary = [];
    foreach ($activities as $row) {
        $pid = (string)($row['PROVIDER_ID'] ?? '');
        $activitySummary[$pid] = ($activitySummary[$pid] ?? 0) + 1;
    }

    $deals[] = [
        'deal' => $deal,
        'activities' => $activities,
        'activity_summary_by_provider' => $activitySummary,
        'comments' => $comments,
        'products' => $products,
    ];
}

$out = [
    'audit_date' => $dateStr,
    'timezone' => 'Europe/Moscow',
    'deal_count' => count($deals),
    'list_total_from_api' => $totalList,
    'global_activity_by_provider' => $globalActivityByProvider,
    'mapping_issues' => $issues,
    'deals' => $deals,
];

$outPath = $root . '/data/audit_deals_cloud_' . $dateStr . '.json';
file_put_contents($outPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Console summary
echo "Date: {$dateStr} (MSK)\n";
echo "Deals exported: " . count($deals) . " (API total: {$totalList})\n";
echo "File: {$outPath}\n";
echo "\nActivities by PROVIDER_ID (all deals):\n";
ksort($globalActivityByProvider);
foreach ($globalActivityByProvider as $p => $n) {
    echo "  {$p}: {$n}\n";
}
echo "\nMapping issues (non-empty):\n";
foreach ($issues as $k => $v) {
    if ($v !== []) {
        echo "  {$k}: " . count($v) . " record(s)\n";
    }
}

$bookingDeals = [];
foreach ($deals as $block) {
    $id = (int)($block['deal']['ID'] ?? 0);
    $n = (int)($block['activity_summary_by_provider']['CRM_BOOKING'] ?? 0);
    if ($n > 0) {
        $bookingDeals[] = ['deal_id' => $id, 'crm_booking_activities' => $n];
    }
}
echo "\nDeals with CRM_BOOKING activities: " . count($bookingDeals) . "\n";
if ($bookingDeals !== []) {
    echo json_encode($bookingDeals, JSON_UNESCAPED_UNICODE) . "\n";
}

exit(0);
