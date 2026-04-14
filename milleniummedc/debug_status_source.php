<?php
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

$r = \Bitrix\Crm\StatusTable::getList([
    'filter' => ['=ENTITY_ID' => 'SOURCE'],
    'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
    'cache' => ['ttl' => 0],
]);
$n = 0;
while ($row = $r->fetch()) {
    $n++;
    echo $row['STATUS_ID'] . " | CAT=" . var_export($row['CATEGORY_ID'], true) . "\n";
}
echo "TOTAL=$n\n";
