<?php
/**
 * Create lead from JSON on stdin. Run on box via SSH.
 * Input: JSON object with lead fields (already mapped)
 */
$json = stream_get_contents(STDIN);
$fields = json_decode($json, true);
if (!$fields) {
    echo json_encode(['error' => 'Invalid JSON']);
    exit(1);
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if (empty($docRoot) || !is_dir($docRoot . '/bitrix')) {
    $docRoot = '/home/bitrix/www';
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

$id = CCrmLead::Add($fields);
if ($id) {
    echo json_encode(['lead_id' => $id, 'success' => true]);
} else {
    $ex = $GLOBALS['APPLICATION']->GetException();
    echo json_encode(['error' => $ex ? $ex->GetString() : 'Unknown']);
    exit(1);
}
