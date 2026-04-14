<?php
/**
 * Create/update contacts on box from JSON on stdin.
 * Input: JSON array of {cloud_id, NAME, LAST_NAME, PHONE, EMAIL, DATE_CREATE?, DATE_MODIFY?, ...}
 *   or object {items: [...], existing_mapping: {cloud_id: box_id}} for update mode.
 * Output: JSON {mapping: {cloud_id: box_id}, created: N, updated: N}
 */
ob_start();
$json = stream_get_contents(STDIN);
$input = json_decode($json, true);
$existingMapping = [];
if (isset($input['items']) && is_array($input['items'])) {
    $items = $input['items'];
    $existingMapping = $input['existing_mapping'] ?? [];
} elseif (is_array($input)) {
    $items = $input;
} else {
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

global $DB;
$mapping = [];
$created = 0;
$updated = 0;
foreach ($items as $item) {
    $cloudId = (int)($item['cloud_id'] ?? 0);
    $dateCreate = $item['DATE_CREATE'] ?? null;
    $dateModify = $item['DATE_MODIFY'] ?? null;
    unset($item['cloud_id'], $item['DATE_CREATE'], $item['DATE_MODIFY']);
    if ($cloudId <= 0) continue;

    $fm = [];
    if (!empty($item['PHONE']) && is_array($item['PHONE'])) {
        foreach ($item['PHONE'] as $i => $p) {
            $v = $p['VALUE'] ?? '';
            if ($v) $fm['PHONE']['n' . $i] = ['VALUE' => $v, 'VALUE_TYPE' => $p['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    if (!empty($item['EMAIL']) && is_array($item['EMAIL'])) {
        foreach ($item['EMAIL'] as $i => $e) {
            $v = $e['VALUE'] ?? '';
            if ($v) $fm['EMAIL']['n' . $i] = ['VALUE' => $v, 'VALUE_TYPE' => $e['VALUE_TYPE'] ?? 'WORK'];
        }
    }
    unset($item['PHONE'], $item['EMAIL']);
    if (!empty($fm)) $item['FM'] = $fm;

    $boxId = isset($existingMapping[(string)$cloudId]) ? (int)$existingMapping[(string)$cloudId] : null;
    if ($boxId > 0) {
        $skipUpdate = false;
        if ($dateModify) {
            $row = $DB->Query("SELECT DATE_MODIFY FROM b_crm_contact WHERE ID = " . (int)$boxId)->Fetch();
            if ($row && !empty($row['DATE_MODIFY']) && strtotime($dateModify) <= strtotime($row['DATE_MODIFY'])) {
                $skipUpdate = true;
            }
        }
        if (!$skipUpdate) {
            $contact = new \CCrmContact(false);
            $contact->Update($boxId, $item);
            if ($dateModify) {
                $DB->Query("UPDATE b_crm_contact SET DATE_MODIFY = '" . $DB->ForSql($dateModify) . "' WHERE ID = " . (int)$boxId);
            }
            $updated++;
        }
        $mapping[(string)$cloudId] = $boxId;
    } else {
        $contact = new \CCrmContact(false);
        $boxId = $contact->Add($item);
        if ($boxId) {
            $mapping[(string)$cloudId] = $boxId;
            $created++;
            if ($dateCreate || $dateModify) {
                $upd = [];
                if ($dateCreate) $upd[] = "DATE_CREATE = '" . $DB->ForSql($dateCreate) . "'";
                if ($dateModify) $upd[] = "DATE_MODIFY = '" . $DB->ForSql($dateModify) . "'";
                if (!empty($upd)) {
                    $DB->Query('UPDATE b_crm_contact SET ' . implode(', ', $upd) . ' WHERE ID = ' . (int)$boxId);
                }
            }
        }
    }
}

ob_end_clean();
echo json_encode(['mapping' => $mapping, 'created' => $created, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
