<?php
/**
 * Запуск на коробке: php /path/to/list_box_booking_resources.php
 * Выводит ID и NAME ресурсов модуля «Онлайн-запись» для заполнения data/booking_resource_mapping.json
 */
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
$_SERVER['SERVER_NAME'] = 'localhost';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
if (!\Bitrix\Main\Loader::includeModule('booking')) {
    fwrite(STDERR, "booking module not loaded\n");
    exit(1);
}
use Bitrix\Booking\Internals\Model\ResourceTable;
$rs = ResourceTable::getList([
    'select' => ['ID', 'TYPE_ID', 'NAME' => 'DATA.NAME', 'IS_DELETED' => 'DATA.IS_DELETED'],
    'order' => ['ID' => 'ASC'],
]);
$n = 0;
while ($row = $rs->fetch()) {
    if (($row['IS_DELETED'] ?? 'N') === 'Y') {
        continue;
    }
    $n++;
    echo (int)$row['ID'] . "\t" . (string)($row['NAME'] ?? '') . "\tTYPE_ID=" . (int)($row['TYPE_ID'] ?? 0) . "\n";
}
if ($n === 0) {
    fwrite(STDERR, "Нет активных ресурсов: таблица b_booking_resource пуста или все помечены удалёнными.\n");
}
