<?php
/**
 * Check b_crm_act table structure for date columns.
 * Run on box: php check_activity_schema.php
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? (getcwd() ?: '/home/bitrix/www');
if (!is_dir($docRoot . '/bitrix')) $docRoot = '/home/bitrix/www';
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
chdir($docRoot);
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';

global $DB;
$res = $DB->Query("SHOW COLUMNS FROM b_crm_act");
$cols = [];
while ($row = $res->Fetch()) {
    $cols[] = $row['Field'];
}
echo "b_crm_act columns: " . implode(', ', $cols) . "\n";

$dateCols = array_filter($cols, function ($c) {
    return stripos($c, 'date') !== false || stripos($c, 'created') !== false || stripos($c, 'updated') !== false;
});
echo "Date-related columns: " . implode(', ', $dateCols) . "\n";
