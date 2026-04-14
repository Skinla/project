#!/usr/bin/env php
<?php
/**
 * Разовая чистка дублей активностей миграции на сделке (одинаковый ORIGIN_ID + ORIGINATOR_ID=migration).
 * Учитывает владельца строки b_crm_act (сделка), без GetBindings — в CLI привязки часто не видны.
 *
 * Запуск на коробке:
 *   php /home/bitrix/www/scripts/box_repair_deal_activity_dupes.php DEAL_ID
 */
declare(strict_types=1);

$dealId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($dealId <= 0) {
    fwrite(STDERR, "Usage: php box_repair_deal_activity_dupes.php BOX_DEAL_ID\n");
    exit(1);
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docRoot === '' || !is_dir($docRoot . '/bitrix')) {
    $docRoot = '/home/bitrix/www';
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
if (empty($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

global $DB;
$ownerDeal = (int)CCrmOwnerType::Deal;
$sql = 'SELECT DISTINCT ORIGIN_ID FROM b_crm_act WHERE OWNER_TYPE_ID=' . $ownerDeal . ' AND OWNER_ID=' . (int)$dealId
    . " AND ORIGINATOR_ID='migration' AND ORIGIN_ID IS NOT NULL AND ORIGIN_ID<>''";
$res = $DB->Query($sql);
$origins = [];
while ($row = $res->Fetch()) {
    $o = trim((string)($row['ORIGIN_ID'] ?? ''));
    if ($o !== '') {
        $origins[] = $o;
    }
}

$deleted = 0;
foreach ($origins as $cloudOriginId) {
    $q = 'SELECT ID FROM b_crm_act WHERE ORIGINATOR_ID = \'migration\' AND ORIGIN_ID = \'' . $DB->ForSql($cloudOriginId) . '\''
        . ' AND OWNER_TYPE_ID = ' . $ownerDeal . ' AND OWNER_ID = ' . (int)$dealId
        . ' ORDER BY ID ASC';
    $r2 = $DB->Query($q);
    $ids = [];
    while ($row = $r2->Fetch()) {
        $ids[] = (int)($row['ID'] ?? 0);
    }
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if (count($ids) < 2) {
        continue;
    }
    $keep = $ids[0];
    for ($i = 1, $n = count($ids); $i < $n; $i++) {
        if (CCrmActivity::Delete($ids[$i], false)) {
            $deleted++;
        }
    }
}

echo "deal_id={$dealId} distinct_origin_ids=" . count($origins) . " deleted_duplicates={$deleted}\n";
