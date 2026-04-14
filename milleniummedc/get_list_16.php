#!/usr/bin/env php
<?php
/**
 * Fetch list 16 (user mapping) from box workgroup 1. Run via SSH.
 * Output: JSON to stdout
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

\Bitrix\Main\Loader::includeModule('iblock');
\Bitrix\Main\Loader::includeModule('lists');

$groupId = 1;
$listId = 16;

$result = ['list_id' => $listId, 'group_id' => $groupId, 'elements' => [], 'error' => null];

$iblock = \Bitrix\Lists\Service\Container::getInstance()->getIblockService()->getIblockByCode([
    'IBLOCK_TYPE_ID' => 'lists_socnet',
    'SOCNET_GROUP_ID' => $groupId,
    'CODE' => (string)$listId,
]);
if (!$iblock) {
    $res = CIBlock::GetList([], [
        'TYPE' => 'lists_socnet',
        'ID' => $listId,
    ]);
    $iblock = $res->Fetch();
}
if (!$iblock) {
    $res = CIBlock::GetList([], [
        'TYPE' => 'lists_socnet',
        'CHECK_PERMISSIONS' => 'N',
    ]);
    while ($row = $res->Fetch()) {
        if (($row['ID'] ?? 0) == $listId || ($row['SOCNET_GROUP_ID'] ?? 0) == $groupId) {
            $iblock = $row;
            break;
        }
    }
}

if (!$iblock) {
    $result['error'] = 'List not found';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(0);
}

$iblockId = (int)($iblock['ID'] ?? $listId);
$props = [];
$res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $iblockId]);
while ($p = $res->Fetch()) {
    $props['PROPERTY_' . $p['CODE']] = $p['ID'];
}

$select = ['ID', 'NAME', 'DATE_CREATE'];
foreach (array_keys($props) as $k) {
    $select[] = $k;
}

$res = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    ['IBLOCK_ID' => $iblockId, 'CHECK_PERMISSIONS' => 'N'],
    false,
    false,
    $select
);

while ($el = $res->GetNextElement()) {
    $fields = $el->GetFields();
    $item = [
        'ID' => $fields['ID'],
        'NAME' => $fields['NAME'],
        'DATE_CREATE' => $fields['DATE_CREATE'],
    ];
    foreach ($el->GetProperties() as $code => $prop) {
        $item[$code] = $prop['VALUE'] ?? $prop['VALUE_ENUM'];
    }
    $result['elements'][] = $item;
}

$result['count'] = count($result['elements']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
