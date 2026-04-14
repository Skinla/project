#!/usr/bin/env php
<?php
/**
 * Run ON box: php box_check_lead_timeline.php LEAD_ID
 */
$leadId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($leadId <= 0) {
    fwrite(STDERR, "Usage: php box_check_lead_timeline.php LEAD_ID\n");
    exit(1);
}
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

global $USER;
if (is_object($USER) && method_exists($USER, 'Authorize')) {
    $USER->Authorize(1);
}

global $DB;

$out = ['lead_id' => $leadId];

$r = $DB->Query(
    'SELECT ID, TYPE_ID, PROVIDER_ID, SUBJECT, STATUS, COMPLETED, OWNER_TYPE_ID, OWNER_ID, ORIGIN_ID '
    . 'FROM b_crm_act WHERE OWNER_TYPE_ID = 1 AND OWNER_ID = ' . $leadId . ' ORDER BY ID'
);
$acts = [];
while ($row = $r->Fetch()) {
    $acts[] = $row;
}
$out['b_crm_act_on_lead'] = $acts;

$actIds = array_column($acts, 'ID');
$timelineByAct = [];
if ($actIds !== []) {
    $in = implode(',', array_map('intval', $actIds));
    $r2 = $DB->Query(
        'SELECT ID, TYPE_ID, TYPE_CATEGORY_ID, ASSOCIATED_ENTITY_TYPE_ID, ASSOCIATED_ENTITY_ID, CREATED, AUTHOR_ID '
        . 'FROM b_crm_timeline WHERE ASSOCIATED_ENTITY_TYPE_ID = 6 AND ASSOCIATED_ENTITY_ID IN (' . $in . ') ORDER BY ID'
    );
    while ($row = $r2->Fetch()) {
        $timelineByAct[] = $row;
    }
}
$out['timeline_rows_for_activities'] = $timelineByAct;

// Привязки таймлайна к лиду (OWNER в b_crm_timeline_bind — это ID записи таймлайна в новых версиях?)
$r3 = $DB->Query('SHOW TABLES LIKE "b_crm_timeline_bind"');
if ($r3 && $r3->Fetch()) {
    $r4 = $DB->Query(
        'SELECT tb.OWNER_ID AS timeline_id, tb.ENTITY_TYPE_ID, tb.ENTITY_ID, t.TYPE_ID, t.ASSOCIATED_ENTITY_ID AS act_id '
        . 'FROM b_crm_timeline_bind tb '
        . 'INNER JOIN b_crm_timeline t ON t.ID = tb.OWNER_ID '
        . 'WHERE tb.ENTITY_TYPE_ID = 1 AND tb.ENTITY_ID = ' . $leadId
        . ' ORDER BY tb.OWNER_ID DESC LIMIT 30'
    );
    $binds = [];
    while ($row = $r4->Fetch()) {
        $binds[] = $row;
    }
    $out['timeline_bind_to_lead_sample'] = $binds;
}

// Альтернативное имя таблицы привязок
$r5 = $DB->Query('SHOW TABLES LIKE "%timeline%bind%"');
$tbls = [];
while ($row = $r5->Fetch()) {
    $tbls[] = array_values($row)[0];
}
$out['timeline_bind_tables'] = $tbls;

// Как в выборке CRM: таймлайн, привязанный к лиду (inner join bindings)
if (class_exists('\Bitrix\Crm\Timeline\Entity\TimelineTable')) {
    global $USER;
    $USER->Authorize(9);
    $q = new \Bitrix\Main\Entity\Query(\Bitrix\Crm\Timeline\Entity\TimelineTable::getEntity());
    $q->registerRuntimeField('B', [
        'data_type' => \Bitrix\Crm\Timeline\Entity\TimelineBindingTable::getEntity(),
        'reference' => ['=this.ID' => 'ref.OWNER_ID'],
        ['join_type' => 'inner'],
    ]);
    $q->addFilter('=B.ENTITY_TYPE_ID', CCrmOwnerType::Lead);
    $q->addFilter('=B.ENTITY_ID', $leadId);
    $q->addSelect('ID');
    $q->addSelect('TYPE_ID');
    $q->addSelect('TYPE_CATEGORY_ID');
    $q->addSelect('ASSOCIATED_ENTITY_TYPE_ID');
    $q->addSelect('ASSOCIATED_ENTITY_ID');
    $q->addOrder('ID', 'DESC');
    $q->setLimit(25);
    $res = $q->exec();
    $d7 = [];
    while ($row = $res->fetch()) {
        $d7[] = $row;
    }
    $out['d7_timeline_for_lead_as_user_9'] = $d7;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
