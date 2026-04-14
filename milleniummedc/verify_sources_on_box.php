<?php
/**
 * Verify SOURCE values on box. Run on box: php verify_sources_on_box.php
 * Or via SSH from project: ssh ... "cd /home/bitrix/www && php verify_sources_on_box.php"
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '/home/bitrix/www';
if (!is_dir($docRoot . '/bitrix')) $docRoot = '/home/bitrix/www';
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
chdir($docRoot);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

echo "=== Источники (SOURCE) на коробке ===\n\n";

$res = CCrmStatus::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => 'SOURCE']);
$sources = [];
while ($row = $res->Fetch()) {
    $sources[] = [
        'id' => $row['STATUS_ID'] ?? '',
        'name' => $row['NAME'] ?? '',
    ];
}

foreach ($sources as $s) {
    echo sprintf("%-25s %s\n", $s['id'], $s['name']);
}

echo "\n=== Лиды с SOURCE_ID (топ-5) ===\n";
global $DB;
$r = $DB->Query("SELECT l.ID, l.TITLE, l.SOURCE_ID, s.NAME as SOURCE_NAME FROM b_crm_lead l LEFT JOIN b_crm_status s ON s.ENTITY_ID='SOURCE' AND s.STATUS_ID=l.SOURCE_ID WHERE l.SOURCE_ID != '' ORDER BY l.ID DESC LIMIT 5");
while ($row = $r->Fetch()) {
    echo sprintf("Lead %d: SOURCE_ID=%s => %s\n", $row['ID'], $row['SOURCE_ID'], $row['SOURCE_NAME'] ?? '(нет в справочнике)');
}

echo "\nГде смотреть в интерфейсе:\n";
echo "1. Карточка лида: CRM → Лиды → открыть лид → поле «Источник»\n";
echo "2. Справочники: CRM → Настройки → Справочники → Источники\n";
echo "   (URL: /crm/configs/ или /crm/configs/status/)\n";
