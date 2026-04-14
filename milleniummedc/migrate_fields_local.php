#!/usr/bin/env php
<?php
/**
 * Create missing lead/deal user fields on box. Run via SSH.
 * Usage: php migrate_fields_local.php [source_fields.json path] [--dry-run]
 */
$options = getopt('', ['dry-run']);
$dryRun = isset($options['dry-run']);
$scanPath = $argv[1] ?? null;
if (!$scanPath || !file_exists($scanPath)) {
    foreach ([__DIR__ . '/source_fields.json', __DIR__ . '/data/source_fields.json', '/tmp/source_fields.json'] as $p) {
        if (file_exists($p)) { $scanPath = $p; break; }
    }
}
if (!$scanPath || !file_exists($scanPath)) {
    fwrite(STDERR, "Error: source_fields.json not found\n");
    exit(1);
}

$source = json_decode(file_get_contents($scanPath), true);
if (!$source) {
    fwrite(STDERR, "Error: invalid JSON\n");
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
try {
    require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
    \Bitrix\Main\Loader::includeModule('crm');
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap: " . $e->getMessage() . "\n");
    exit(1);
}

function restTypeToUf($type) {
    $map = [
        'string' => 'string', 'integer' => 'integer', 'double' => 'double',
        'boolean' => 'boolean', 'datetime' => 'datetime', 'date' => 'date',
        'enumeration' => 'enumeration', 'file' => 'file', 'url' => 'url',
        'address' => 'address', 'money' => 'money', 'employee' => 'employee',
        'crm_status' => 'crm_status', 'crm' => 'crm',
    ];
    if (strpos($type, 'rest_') === 0 || strpos($type, 'multifields') !== false) return 'string';
    return $map[$type] ?? 'string';
}

$stats = ['lead_added' => 0, 'lead_skipped' => 0, 'deal_added' => 0, 'deal_skipped' => 0, 'errors' => []];

foreach (['lead_fields' => 'CRM_LEAD', 'deal_fields' => 'CRM_DEAL'] as $key => $entityId) {
    echo "=== " . ($key === 'lead_fields' ? 'Lead' : 'Deal') . " fields ===\n";

    $existing = [];
    $res = CUserTypeEntity::GetList([], ['ENTITY_ID' => $entityId]);
    while ($r = $res->Fetch()) {
        $existing[$r['FIELD_NAME']] = true;
    }

    foreach ($source[$key] ?? [] as $fieldName => $field) {
        if (strpos($fieldName, 'UF_') !== 0) continue;

        if (isset($existing[$fieldName])) {
            echo "  Skip (exists): $fieldName\n";
            $stats[$key === 'lead_fields' ? 'lead_skipped' : 'deal_skipped']++;
            continue;
        }

        $ufType = restTypeToUf($field['type'] ?? 'string');
        $title = $field['title'] ?? $fieldName;

        $arFields = [
            'ENTITY_ID' => $entityId,
            'FIELD_NAME' => $fieldName,
            'USER_TYPE_ID' => $ufType,
            'SORT' => 500,
            'MULTIPLE' => ($field['isMultiple'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'MANDATORY' => ($field['isRequired'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'SHOW_FILTER' => 'N',
            'SHOW_IN_LIST' => 'Y',
            'EDIT_IN_LIST' => 'Y',
            'IS_SEARCHABLE' => 'N',
        ];
        $arFields['EDIT_FORM_LABEL'] = $title;
        $arFields['LIST_COLUMN_LABEL'] = $title;
        $arFields['LIST_FILTER_LABEL'] = $title;

        if ($ufType === 'enumeration' && !empty($field['items'])) {
            $list = [];
            $i = 0;
            foreach ($field['items'] as $k => $v) {
                $list[] = ['XML_ID' => (string)$k, 'VALUE' => (string)$v, 'DEF' => $i === 0 ? 'Y' : 'N', 'SORT' => ($i + 1) * 10];
                $i++;
            }
            $arFields['LIST'] = $list;
        }

        if ($dryRun) {
            echo "  [DRY-RUN] Would add: $fieldName ($ufType) - $title\n";
            $stats[$key === 'lead_fields' ? 'lead_added' : 'deal_added']++;
            continue;
        }

        try {
            $ufEntity = new CUserTypeEntity();
            $id = $ufEntity->Add($arFields);
        } catch (\Throwable $e) {
            $stats['errors'][] = $e->getMessage() . " for $fieldName";
            $stats[$key === 'lead_fields' ? 'lead_skipped' : 'deal_skipped']++;
            continue;
        }
        if ($id) {
            echo "  Added: $fieldName - $title\n";
            $stats[$key === 'lead_fields' ? 'lead_added' : 'deal_added']++;
            $existing[$fieldName] = true;
        } else {
            $ex = $GLOBALS['APPLICATION']->GetException();
            $stats['errors'][] = ($ex ? $ex->GetString() : 'Unknown') . " for $fieldName";
            $stats[$key === 'lead_fields' ? 'lead_skipped' : 'deal_skipped']++;
        }
    }
}

echo "\n=== Summary ===\n";
echo "Leads: added={$stats['lead_added']}, skipped={$stats['lead_skipped']}\n";
echo "Deals: added={$stats['deal_added']}, skipped={$stats['deal_skipped']}\n";
if (!empty($stats['errors'])) {
    echo "Errors: " . count($stats['errors']) . "\n";
    foreach (array_slice($stats['errors'], 0, 5) as $e) echo "  - $e\n";
}
