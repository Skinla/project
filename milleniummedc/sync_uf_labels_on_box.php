#!/usr/bin/env php
<?php
/**
 * Apply user field labels on Bitrix BOX from JSON produced by scripts/export_cloud_uf_labels.php
 * (entities: CRM_LEAD, CRM_DEAL, CRM_CONTACT, CRM_COMPANY — на коробке обновляются только существующие UF).
 * Run on the server in Bitrix document root, e.g.:
 *   scp sync_uf_labels_on_box.php cloud_uf_labels.json root@box:/home/bitrix/www/
 *   ssh root@box "cd /home/bitrix/www && php sync_uf_labels_on_box.php cloud_uf_labels.json"
 *
 * Options:
 *   --dry-run   only print planned updates
 *
 * Важно: в коробке подписи для интерфейса (в т.ч. колонка «Название» в настройках полей)
 * хранятся в b_user_field_lang (UserFieldLangTable: EDIT_FORM_LABEL, LIST_*, …).
 * Один только CUserTypeEntity::Update часто не заполняет языковые строки — поэтому дублируем в UserFieldLangTable.
 */

set_error_handler(function ($n, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $n, $file, $line);
});

$dryRun = in_array('--dry-run', $argv, true);
$jsonPath = null;
foreach ($argv as $a) {
    if ($a === '--dry-run') {
        continue;
    }
    if (str_starts_with($a, '-')) {
        continue;
    }
    $jsonPath = $a;
}

if ($jsonPath === null || !is_readable($jsonPath)) {
    fwrite(STDERR, "Usage: php sync_uf_labels_on_box.php path/to/cloud_uf_labels.json [--dry-run]\n");
    exit(1);
}

$payload = json_decode(file_get_contents($jsonPath), true);
if (!$payload || empty($payload['entities'])) {
    fwrite(STDERR, "Invalid JSON\n");
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

/**
 * Если listLabel = код поля (как в REST облака), берём formLabel; «_» → пробел для читаемости.
 *
 * @return array{0: string, 1: string, 2: string}
 */
function uf_normalize_cloud_labels(string $fieldName, string $list, string $form, string $filter, bool $beautifyUnderscores = true): array
{
    $list = trim($list);
    $form = trim($form);
    $filter = trim($filter);
    if ($list === $fieldName) {
        $list = '';
    }
    if ($filter === $fieldName) {
        $filter = '';
    }
    if ($form === $fieldName) {
        $form = '';
    }
    if ($list === '') {
        $list = $form !== '' ? $form : $filter;
    }
    if ($form === '') {
        $form = $list !== '' ? $list : $filter;
    }
    if ($filter === '') {
        $filter = $list;
    }
    if ($beautifyUnderscores) {
        $list = str_replace('_', ' ', $list);
        $form = str_replace('_', ' ', $form);
        $filter = str_replace('_', ' ', $filter);
    }

    return [$list, $form, $filter];
}

/**
 * Заполняет языковые подписи UF (в т.ч. «Название» = EDIT_FORM_LABEL в админке).
 *
 * @return array{ok: bool, detail?: string, error?: string}
 */
function syncUserFieldLangLabels(int $userFieldId, string $list, string $form, string $filter, bool $dryRun): array
{
    if (!class_exists(\Bitrix\Main\UserFieldLangTable::class)) {
        return ['ok' => true, 'detail' => 'UserFieldLangTable_absent'];
    }

    $data = [
        'EDIT_FORM_LABEL' => $form,
        'LIST_COLUMN_LABEL' => $list,
        'LIST_FILTER_LABEL' => $filter,
    ];

    $rows = \Bitrix\Main\UserFieldLangTable::getList([
        'filter' => ['=USER_FIELD_ID' => $userFieldId],
        'select' => ['LANGUAGE_ID'],
    ])->fetchAll();

    if ($dryRun) {
        $n = count($rows);

        return ['ok' => true, 'detail' => 'dry_lang_rows_' . ($n > 0 ? $n : 'insert_ru')];
    }

    if ($rows === []) {
        $defaultLang = (string) \Bitrix\Main\Config\Option::get('main', 'default_site_language', 'ru');
        if (strlen($defaultLang) !== 2) {
            $defaultLang = 'ru';
        }
        $add = \Bitrix\Main\UserFieldLangTable::add(array_merge(
            ['USER_FIELD_ID' => $userFieldId, 'LANGUAGE_ID' => $defaultLang],
            $data
        ));
        if (!$add->isSuccess()) {
            return ['ok' => false, 'error' => implode('; ', $add->getErrorMessages())];
        }

        return ['ok' => true, 'detail' => 'inserted_' . $defaultLang];
    }

    foreach ($rows as $row) {
        $langId = (string) ($row['LANGUAGE_ID'] ?? '');
        if ($langId === '') {
            continue;
        }
        $upd = \Bitrix\Main\UserFieldLangTable::update(
            ['USER_FIELD_ID' => $userFieldId, 'LANGUAGE_ID' => $langId],
            $data
        );
        if (!$upd->isSuccess()) {
            return ['ok' => false, 'error' => implode('; ', $upd->getErrorMessages())];
        }
    }

    return ['ok' => true, 'detail' => 'updated_' . count($rows) . '_lang'];
}

$report = ['updated' => [], 'skipped' => [], 'errors' => []];

foreach ($payload['entities'] as $entityId => $fields) {
    if (!is_array($fields)) {
        continue;
    }
    foreach ($fields as $fieldName => $labels) {
        if (strpos((string) $fieldName, 'UF_') !== 0) {
            continue;
        }
        $list = trim((string) ($labels['listLabel'] ?? ''));
        $form = trim((string) ($labels['formLabel'] ?? ''));
        $filter = trim((string) ($labels['filterLabel'] ?? ''));
        [$list, $form, $filter] = uf_normalize_cloud_labels((string) $fieldName, $list, $form, $filter, true);

        if ($list === '' && $form === '' && $filter === '') {
            continue;
        }

        $dbRes = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId, 'FIELD_NAME' => $fieldName]);
        $field = $dbRes->Fetch();
        if (!$field) {
            $report['skipped'][] = ['entity' => $entityId, 'field' => $fieldName, 'reason' => 'not_found_on_box'];
            continue;
        }

        $id = (int) $field['ID'];
        $arUpdate = [
            'LIST_COLUMN_LABEL' => $list,
            'EDIT_FORM_LABEL' => $form,
            'LIST_FILTER_LABEL' => $filter,
        ];

        if ($dryRun) {
            $langHint = syncUserFieldLangLabels($id, $list, $form, $filter, true);
            $report['updated'][] = [
                'entity' => $entityId,
                'field' => $fieldName,
                'id' => $id,
                'dry_run' => true,
                'labels' => $arUpdate,
                'lang_sync' => $langHint['detail'] ?? '',
            ];
            continue;
        }

        $ufEntity = new CUserTypeEntity();
        $ok = $ufEntity->Update($id, $arUpdate);
        if (!$ok) {
            global $APPLICATION;
            $err = $APPLICATION ? $APPLICATION->GetException() : null;
            $msg = $err ? $err->GetString() : 'CUserTypeEntity::Update returned false';
            $report['errors'][] = ['entity' => $entityId, 'field' => $fieldName, 'id' => $id, 'error' => $msg];
            continue;
        }

        $langRes = syncUserFieldLangLabels($id, $list, $form, $filter, false);
        if (!$langRes['ok']) {
            $report['errors'][] = [
                'entity' => $entityId,
                'field' => $fieldName,
                'id' => $id,
                'error' => 'UserFieldLangTable: ' . ($langRes['error'] ?? 'failed'),
            ];
            continue;
        }

        $report['updated'][] = [
            'entity' => $entityId,
            'field' => $fieldName,
            'id' => $id,
            'lang_sync' => $langRes['detail'] ?? 'ok',
        ];
    }
}

if (!$dryRun && class_exists('\CUserTypeEntity')) {
    if (isset($GLOBALS['USER_FIELD_MANAGER']) && is_object($GLOBALS['USER_FIELD_MANAGER'])) {
        $GLOBALS['USER_FIELD_MANAGER']->CleanCache();
    }
}

echo json_encode([
    'dry_run' => $dryRun,
    'updated_count' => count($report['updated']),
    'skipped_count' => count($report['skipped']),
    'errors_count' => count($report['errors']),
    'report' => $report,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(!empty($report['errors']) && !$dryRun ? 1 : 0);
