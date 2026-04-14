#!/usr/bin/env php
<?php
/**
 * Удаляет англоязычные подписи пользовательских полей CRM в b_user_field_lang
 * (UserFieldLangTable), чтобы не дублировались названия на EN — интерфейс опирается на ru.
 *
 * Запуск на коробке (из document root):
 *   php remove_english_crm_uf_lang_on_box.php
 *   php remove_english_crm_uf_lang_on_box.php --dry-run
 *
 * По умолчанию удаляются строки LANGUAGE_ID = en для ENTITY_ID:
 * CRM_LEAD, CRM_DEAL, CRM_CONTACT, CRM_COMPANY.
 *
 * Другой код языка: --lang=de
 */

set_error_handler(function ($n, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $n, $file, $line);
});

$dryRun = in_array('--dry-run', $argv, true);
$removeLang = 'en';
foreach ($argv as $a) {
    if (str_starts_with($a, '--lang=')) {
        $removeLang = strtolower(substr($a, strlen('--lang=')));
    }
}
if (strlen($removeLang) !== 2) {
    fwrite(STDERR, "Invalid --lang (expect 2 letters, e.g. en)\n");
    exit(1);
}

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
define('BX_NO_ACCELERATOR_RESET', true);

require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('main')) {
    fwrite(STDERR, "main module failed\n");
    exit(1);
}

if (!class_exists(\Bitrix\Main\UserFieldLangTable::class)) {
    fwrite(STDERR, "UserFieldLangTable not available\n");
    exit(1);
}

$crmEntities = ['CRM_LEAD', 'CRM_DEAL', 'CRM_CONTACT', 'CRM_COMPANY'];
$ufIds = [];
foreach ($crmEntities as $entityId) {
    $res = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
    while ($row = $res->Fetch()) {
        $ufIds[] = (int) ($row['ID'] ?? 0);
    }
}
$ufIds = array_values(array_unique(array_filter($ufIds)));

if ($ufIds === []) {
    echo json_encode(['removed' => 0, 'dry_run' => $dryRun, 'message' => 'no_uf_ids'], JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$report = ['removed' => 0, 'dry_run' => $dryRun, 'language_id' => $removeLang, 'samples' => []];

$rows = \Bitrix\Main\UserFieldLangTable::getList([
    'filter' => [
        '@USER_FIELD_ID' => $ufIds,
        '=LANGUAGE_ID' => $removeLang,
    ],
    'select' => ['USER_FIELD_ID', 'LANGUAGE_ID', 'EDIT_FORM_LABEL'],
])->fetchAll();

foreach ($rows as $row) {
    $uid = (int) $row['USER_FIELD_ID'];
    if (count($report['samples']) < 5) {
        $report['samples'][] = [
            'USER_FIELD_ID' => $uid,
            'EDIT_FORM_LABEL' => (string) ($row['EDIT_FORM_LABEL'] ?? ''),
        ];
    }
    if ($dryRun) {
        $report['removed']++;

        continue;
    }
    $del = \Bitrix\Main\UserFieldLangTable::delete([
        'USER_FIELD_ID' => $uid,
        'LANGUAGE_ID' => $removeLang,
    ]);
    if ($del->isSuccess()) {
        $report['removed']++;
    } else {
        fwrite(STDERR, 'Delete failed USER_FIELD_ID=' . $uid . ': ' . implode('; ', $del->getErrorMessages()) . "\n");
    }
}

if (!$dryRun && $report['removed'] > 0 && isset($GLOBALS['USER_FIELD_MANAGER']) && is_object($GLOBALS['USER_FIELD_MANAGER'])) {
    $GLOBALS['USER_FIELD_MANAGER']->CleanCache();
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
