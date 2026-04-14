#!/usr/bin/env php
<?php
/**
 * Проверка: для UF по CRM_LEAD вывести EDIT_FORM_LABEL из UserFieldLangTable.
 * Запуск на коробке: cd /home/bitrix/www && php /path/to/verify_uf_lang_sample_on_box.php
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (empty($docRoot)) {
    foreach (['/home/bitrix/www', '/var/www/bitrix', '/var/www/html'] as $d) {
        if (is_dir($d . '/bitrix/modules/main/include')) {
            $docRoot = $d;
            break;
        }
    }
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('main');

$sample = ['UF_CRM_T_VASEIMA', 'UF_CRM_1744791016816'];
$out = ['sample_fields' => []];

foreach ($sample as $fieldName) {
    $r = CUserTypeEntity::GetList([], ['ENTITY_ID' => 'CRM_LEAD', 'FIELD_NAME' => $fieldName]);
    $row = $r->Fetch();
    if (!$row) {
        $out['sample_fields'][$fieldName] = ['error' => 'no_uf'];
        continue;
    }
    $id = (int) $row['ID'];
    $langs = [];
    if (class_exists(\Bitrix\Main\UserFieldLangTable::class)) {
        $lr = \Bitrix\Main\UserFieldLangTable::getList([
            'filter' => ['=USER_FIELD_ID' => $id],
            'select' => ['LANGUAGE_ID', 'EDIT_FORM_LABEL', 'LIST_COLUMN_LABEL'],
        ]);
        while ($l = $lr->fetch()) {
            $langs[] = $l;
        }
    }
    $out['sample_fields'][$fieldName] = [
        'USER_FIELD_ID' => $id,
        'CUserTypeEntity_LIST_COLUMN' => $row['LIST_COLUMN_LABEL'] ?? '',
        'CUserTypeEntity_EDIT_FORM' => $row['EDIT_FORM_LABEL'] ?? '',
        'lang_rows' => $langs,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
