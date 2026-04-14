<?php
/**
 * Run on box via SSH. Output contacts for contact mapping.
 * Usage: cd /home/bitrix/www && php get_box_contacts.php
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? getcwd() ?: '/home/bitrix/www';
if (!is_dir($docRoot . '/bitrix')) {
    foreach (['/home/bitrix/www', '/var/www/bitrix'] as $d) {
        if (is_dir($d . '/bitrix')) { $docRoot = $d; break; }
    }
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

$res = CCrmContact::GetListEx([], [], ['ID', 'NAME', 'LAST_NAME'], false, [], []);
$contacts = [];
while ($row = $res->Fetch()) {
    $phones = [];
    $emails = [];
    $fmRes = CCrmFieldMulti::GetList(['ID' => 'ASC'], ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $row['ID']]);
    while ($fm = $fmRes->Fetch()) {
        if (($fm['TYPE_ID'] ?? '') === 'PHONE' && !empty($fm['VALUE'])) {
            $phones[] = preg_replace('/\D/', '', $fm['VALUE']);
        }
        if (($fm['TYPE_ID'] ?? '') === 'EMAIL' && !empty($fm['VALUE'])) {
            $emails[] = strtolower(trim($fm['VALUE']));
        }
    }
    $contacts[] = [
        'ID' => (int)$row['ID'],
        'NAME' => $row['NAME'] ?? '',
        'LAST_NAME' => $row['LAST_NAME'] ?? '',
        'PHONES' => array_unique($phones),
        'EMAILS' => array_unique($emails),
    ];
}
echo json_encode($contacts, JSON_UNESCAPED_UNICODE);
