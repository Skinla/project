<?php
/**
 * Тест: коды свойств списка 22 для GetListsDocumentActivity.
 */
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__ . '/../../../..');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: text/plain; charset=utf-8');

\Bitrix\Main\Loader::includeModule('iblock');

$res = \CIBlockProperty::GetList(
    ['SORT' => 'ASC'],
    ['IBLOCK_ID' => 22, 'ACTIVE' => 'Y']
);
echo "=== Свойства списка 22 ===\n\n";
while ($row = $res->Fetch()) {
    $code = $row['CODE'] ?: '(no code)';
    $id = $row['ID'];
    echo "ID={$id}  CODE={$code}  NAME={$row['NAME']}  TYPE={$row['PROPERTY_TYPE']}  USER_TYPE={$row['USER_TYPE']}\n";
    echo "  -> BP field: PROPERTY_PROPERTY_{$id}\n\n";
}
