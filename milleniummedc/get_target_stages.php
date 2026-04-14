#!/usr/bin/env php
<?php
/**
 * Output target portal stages as JSON. Run on box via SSH.
 * Usage: php get_target_stages.php
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (empty($docRoot)) {
    foreach (['/home/bitrix/www', '/var/www/bitrix', '/var/www/html'] as $d) {
        if (is_dir($d . '/bitrix/modules/main/include')) {
            $docRoot = $d;
            break;
        }
    }
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

$result = [
    'target_url' => 'https://bitrix.milleniummedc.ru',
    'scanned_at' => date('c'),
    'lead_stages' => [],
    'lead_sources' => [],
    'lead_honorific' => [],
    'deal_categories' => [],
];

$res = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'STATUS']);
while ($row = $res->Fetch()) {
    $result['lead_stages'][] = [
        'status_id' => $row['STATUS_ID'] ?? '',
        'name' => $row['NAME'] ?? '',
    ];
}

$res = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'SOURCE']);
while ($row = $res->Fetch()) {
    $result['lead_sources'][] = [
        'status_id' => $row['STATUS_ID'] ?? '',
        'name' => $row['NAME'] ?? '',
    ];
}

$res = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'HONORIFIC']);
while ($row = $res->Fetch()) {
    $result['lead_honorific'][] = [
        'status_id' => $row['STATUS_ID'] ?? '',
        'name' => $row['NAME'] ?? '',
    ];
}

$result['deal_categories'][] = ['id' => 0, 'name' => 'Первичная продажа', 'stages' => []];
$r = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'DEAL_STAGE']);
while ($row = $r->Fetch()) {
    $result['deal_categories'][0]['stages'][] = [
        'status_id' => $row['STATUS_ID'] ?? '',
        'name' => $row['NAME'] ?? '',
    ];
}

$catRes = \Bitrix\Crm\Category\DealCategory::getList(['select' => ['ID', 'NAME'], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']]);
while ($cat = $catRes->fetch()) {
    $catId = (int)$cat['ID'];
    if ($catId === 0) continue;
    $entityId = 'DEAL_STAGE_' . $catId;
    $stages = [];
    $res = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => $entityId]);
    while ($row = $res->Fetch()) {
        $stages[] = [
            'status_id' => $row['STATUS_ID'] ?? '',
            'name' => $row['NAME'] ?? '',
        ];
    }
    $result['deal_categories'][] = [
        'id' => $catId,
        'name' => $cat['NAME'] ?? '',
        'stages' => $stages,
    ];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
