<?php
/**
 * Проверка полей списка «Недозвон»: типы свойств и значения списков (enum).
 * Запуск: php inspect_list.php [DOCUMENT_ROOT]   или в браузере под админом.
 * Вывод: JSON с перечнем свойств и для списков — ID, VALUE, XML_ID каждого варианта.
 */

$isCli = (php_sapi_name() === 'cli');
if ($isCli && (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT']))) {
    $docRoot = $argv[1] ?? __DIR__ . '/../../../..';
    if (is_dir($docRoot)) {
        $_SERVER['DOCUMENT_ROOT'] = realpath($docRoot);
    }
}

if (empty($_SERVER['DOCUMENT_ROOT']) || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DOCUMENT_ROOT not set or Bitrix not found'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';

$config = $GLOBALS['DOZVON_CONFIG'] ?? [];
$listId = (int)($config['DOZVON_LIST_ID'] ?? 0);
if ($listId <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'DOZVON_LIST_ID not set'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

if (!\Bitrix\Main\Loader::includeModule('iblock')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Module iblock not loaded'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit(1);
}

$out = [
    'list_id' => $listId,
    'properties' => [],
];

$res = CIBlockProperty::GetList(['SORT' => 'ASC', 'NAME' => 'ASC'], ['IBLOCK_ID' => $listId]);
while ($prop = $res->Fetch()) {
    $code = $prop['CODE'] ?? '';
    $type = $prop['PROPERTY_TYPE'] ?? '';
    $userType = $prop['USER_TYPE'] ?? '';
    $item = [
        'code' => $code,
        'name' => $prop['NAME'] ?? '',
        'property_type' => $type,
        'user_type' => $userType,
        'id' => (int)($prop['ID'] ?? 0),
    ];
    if ($type === 'L') {
        $item['enum'] = [];
        $resEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $prop['ID']]);
        while ($enum = $resEnum->Fetch()) {
            $item['enum'][] = [
                'id' => (int)($enum['ID'] ?? 0),
                'value' => $enum['VALUE'] ?? '',
                'xml_id' => $enum['XML_ID'] ?? '',
            ];
        }
    }
    $out['properties'][] = $item;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
