<?php
/**
 * Тест: какой класс StatisticTable доступен на портале.
 * Запуск: браузер https://bitrix.dreamteamcompany.ru/local/handlers/dozvon/lead_callback/test_vox_statistic.php
 */

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__ . '/../../../..');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: text/plain; charset=utf-8');

\Bitrix\Main\Loader::includeModule('voximplant');

$classes = [
    '\Bitrix\Voximplant\StatisticTable',
    '\Bitrix\Voximplant\Model\StatisticTable',
];

$found = null;
foreach ($classes as $cls) {
    $exists = class_exists($cls);
    echo "$cls: " . ($exists ? "YES" : "no") . "\n";
    if ($exists && $found === null) $found = $cls;
}

if ($found === null) {
    echo "\nНи один класс не найден!\n";
    die();
}

echo "\nИспользуем: $found\n";

try {
    $res = $found::getList([
        'select' => ['ID', 'CALL_ID', 'PHONE_NUMBER', 'PORTAL_USER_ID', 'CALL_DURATION', 'CALL_FAILED_CODE', 'CALL_START_DATE'],
        'order' => ['ID' => 'DESC'],
        'limit' => 3,
    ]);
    echo "\nПоследние 3 записи:\n";
    while ($row = $res->fetch()) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n---\n";
    }
} catch (\Throwable $e) {
    echo "\nОшибка getList: " . $e->getMessage() . "\n";
}
