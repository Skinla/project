<?php
/**
 * Run on box via SSH. Check if leads have linked contacts.
 * Usage: cd /home/bitrix/www && php check_leads_contacts_box.php
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

global $DB;

$r = $DB->Query("SELECT ID, TITLE, CONTACT_ID FROM b_crm_lead WHERE CONTACT_ID > 0 ORDER BY ID");
$leadsWithContact = [];
while ($row = $r->Fetch()) {
    $leadsWithContact[] = [
        'lead_id' => (int)$row['ID'],
        'title' => $row['TITLE'] ?? '',
        'contact_id' => (int)$row['CONTACT_ID'],
    ];
}

$totalLeads = (int)$DB->Query("SELECT COUNT(*) as c FROM b_crm_lead")->Fetch()['c'];
$totalContacts = (int)$DB->Query("SELECT COUNT(*) as c FROM b_crm_contact")->Fetch()['c'];

echo json_encode([
    'total_leads' => $totalLeads,
    'total_contacts' => $totalContacts,
    'leads_with_contact' => count($leadsWithContact),
    'sample' => array_slice($leadsWithContact, 0, 20),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
