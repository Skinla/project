<?php
/**
 * Run ON box: php box_inspect_lead.php BOX_LEAD_ID
 */
$lid = isset($argv[1]) ? (int)$argv[1] : 0;
if ($lid <= 0) {
    fwrite(STDERR, "Usage: php box_inspect_lead.php BOX_LEAD_ID\n");
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
$lead = $DB->Query(
    'SELECT ID, TITLE, DATE_CREATE, STATUS_ID, ASSIGNED_BY_ID, CONTACT_ID, COMPANY_ID, DATE_CLOSED FROM b_crm_lead WHERE ID = ' . $lid
)->Fetch();
$out = ['lead' => $lead ?: 'NOT_FOUND'];

$dealRows = [];
$rDeal = $DB->Query('SELECT ID, TITLE, STAGE_ID, ASSIGNED_BY_ID FROM b_crm_deal WHERE LEAD_ID = ' . $lid . ' ORDER BY ID DESC LIMIT 5');
while ($row = $rDeal->Fetch()) {
    $dealRows[] = $row;
}
$out['deals_by_LEAD_ID'] = $dealRows;

$cid = (int)($lead['CONTACT_ID'] ?? 0);
if ($cid > 0) {
    $dealByContact = [];
    $rDc = $DB->Query(
        'SELECT ID, TITLE, STAGE_ID, ASSIGNED_BY_ID, DATE_CREATE FROM b_crm_deal WHERE CONTACT_ID = ' . $cid . ' ORDER BY ID DESC LIMIT 8'
    );
    while ($row = $rDc->Fetch()) {
        $dealByContact[] = $row;
    }
    $out['deals_by_CONTACT_ID'] = $dealByContact;
}

$rActResp = $DB->Query('SELECT ID, RESPONSIBLE_ID, AUTHOR_ID FROM b_crm_act WHERE OWNER_TYPE_ID = 1 AND OWNER_ID = ' . $lid);
$resp = [];
while ($row = $rActResp->Fetch()) {
    $resp[] = $row;
}
$out['activity_responsibles'] = $resp;

$r = $DB->Query(
    'SELECT ID, OWNER_TYPE_ID, OWNER_ID, TYPE_ID, PROVIDER_ID, LEFT(SUBJECT, 80) AS SUBJECT, COMPLETED FROM b_crm_act '
    . 'WHERE OWNER_TYPE_ID = 1 AND OWNER_ID = ' . $lid . ' ORDER BY ID'
);
$rows = [];
while ($row = $r->Fetch()) {
    $rows[] = $row;
}
$out['b_crm_act_by_owner_columns'] = $rows;

$r2 = $DB->Query('SELECT ACTIVITY_ID, OWNER_TYPE_ID, OWNER_ID FROM b_crm_act_bind WHERE OWNER_TYPE_ID = 1 AND OWNER_ID = ' . $lid);
$binds = [];
while ($row = $r2->Fetch()) {
    $binds[] = $row;
}
$out['b_crm_act_bind'] = $binds;

$r3 = $DB->Query(
    'SELECT ID, TYPE_ID, CREATED, ASSOCIATED_ENTITY_TYPE_ID, ASSOCIATED_ENTITY_ID FROM b_crm_timeline '
    . 'WHERE ASSOCIATED_ENTITY_TYPE_ID = 1 AND ASSOCIATED_ENTITY_ID = ' . $lid . ' ORDER BY ID DESC LIMIT 20'
);
$t = [];
while ($row = $r3->Fetch()) {
    $t[] = $row;
}
$out['b_crm_timeline'] = $t;

$dbRes = CCrmActivity::GetList([], ['OWNER_ID' => $lid, 'OWNER_TYPE_ID' => 1], false, false, ['ID', 'TYPE_ID', 'PROVIDER_ID', 'SUBJECT', 'OWNER_ID', 'OWNER_TYPE_ID']);
$api = [];
while ($row = $dbRes->Fetch()) {
    $api[] = $row;
}
$out['CCrmActivity_GetList'] = $api;

$dbResEq = CCrmActivity::GetList([], ['=OWNER_ID' => $lid, '=OWNER_TYPE_ID' => 1], false, false, ['ID', 'SUBJECT']);
$apiEq = [];
while ($row = $dbResEq->Fetch()) {
    $apiEq[] = $row;
}
$out['CCrmActivity_GetList_eq_filter'] = $apiEq;

$sampleActId = null;
if (!empty($binds[0]['ACTIVITY_ID'])) {
    $sampleActId = (int)$binds[0]['ACTIVITY_ID'];
}
if ($sampleActId) {
    $byId = CCrmActivity::GetByID($sampleActId, false);
    $out['CCrmActivity_GetByID_' . $sampleActId] = $byId ? [
        'ID' => $byId['ID'] ?? null,
        'OWNER_ID' => $byId['OWNER_ID'] ?? null,
        'OWNER_TYPE_ID' => $byId['OWNER_TYPE_ID'] ?? null,
        'SUBJECT' => isset($byId['SUBJECT']) ? mb_substr((string)$byId['SUBJECT'], 0, 60) : null,
    ] : null;
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
