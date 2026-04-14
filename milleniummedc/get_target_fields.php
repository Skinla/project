#!/usr/bin/env php
<?php
/**
 * Get lead and deal fields from target (box). Run via SSH from Bitrix www.
 * Output: JSON to stdout
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
\Bitrix\Main\Loader::includeModule('crm');

$langId = (string) \Bitrix\Main\Config\Option::get('main', 'default_site_language', 'ru');
if (strlen($langId) !== 2) {
    $langId = 'ru';
}

$result = [
    'target_url' => 'https://bitrix.milleniummedc.ru',
    'scanned_at' => date('c'),
    'lang_id' => $langId,
    'lead_fields' => [],
    'deal_fields' => [],
];

function ufToRestType($userTypeId) {
    $map = [
        'string' => 'string', 'integer' => 'integer', 'double' => 'double',
        'boolean' => 'boolean', 'datetime' => 'datetime', 'date' => 'date',
        'enumeration' => 'enumeration', 'file' => 'file', 'url' => 'url',
        'address' => 'address', 'money' => 'money', 'employee' => 'employee',
        'crm_status' => 'crm_status', 'crm' => 'crm',
        'iblock_section' => 'iblock_section', 'iblock_element' => 'iblock_element',
    ];
    return $map[$userTypeId] ?? $userTypeId;
}

foreach (['CRM_LEAD' => 'lead_fields', 'CRM_DEAL' => 'deal_fields'] as $entityId => $key) {
    // LANG: иначе LIST_COLUMN_LABEL / EDIT_FORM_LABEL приходят пустыми (реальные строки в b_user_field_lang).
    $res = CUserTypeEntity::GetList(['SORT' => 'ASC'], ['ENTITY_ID' => $entityId, 'LANG' => $langId]);
    while ($row = $res->Fetch()) {
        $fieldName = $row['FIELD_NAME'] ?? '';
        if (strpos($fieldName, 'UF_') !== 0) continue;
        $result[$key][$fieldName] = [
            'type' => ufToRestType($row['USER_TYPE_ID'] ?? 'string'),
            'title' => $row['LIST_COLUMN_LABEL'] ?? $row['EDIT_FORM_LABEL'] ?? $fieldName,
            'isRequired' => ($row['MANDATORY'] ?? 'N') === 'Y' ? 'Y' : 'N',
            'isReadOnly' => 'N',
            'isMultiple' => ($row['MULTIPLE'] ?? 'N') === 'Y' ? 'Y' : 'N',
        ];
        if (($row['USER_TYPE_ID'] ?? '') === 'enumeration' && !empty($row['USER_TYPE_ID'])) {
            $enumRes = CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $row['ID']]);
            $items = [];
            while ($er = $enumRes->Fetch()) {
                $items[$er['ID']] = $er['VALUE'] ?? '';
            }
            if (!empty($items)) {
                $result[$key][$fieldName]['items'] = $items;
            }
        }
    }
}

$standardLead = ['ID','TITLE','HONORIFIC','NAME','SECOND_NAME','LAST_NAME','BIRTHDATE','COMPANY_TITLE','SOURCE_ID','SOURCE_DESCRIPTION','STATUS_ID','STATUS_DESCRIPTION','POST','ADDRESS','ADDRESS_2','ADDRESS_CITY','ADDRESS_POSTAL_CODE','ADDRESS_REGION','ADDRESS_PROVINCE','ADDRESS_COUNTRY','ADDRESS_COUNTRY_CODE','ADDRESS_LEGAL','OPPORTUNITY','CURRENCY_ID','OPPORTUNITY_ACCOUNT','ACCOUNT_CURRENCY_ID','ASSIGNED_BY_ID','COMMENTS','DATE_CREATE','DATE_MODIFY','CREATED_BY_ID','MODIFY_BY_ID','OPENED','ORIGINATOR_ID','ORIGIN_ID','PHONE','EMAIL','WEB','IM','UTM_SOURCE','UTM_MEDIUM','UTM_CAMPAIGN','UTM_CONTENT','UTM_TERM'];
$standardDeal = ['ID','TITLE','TYPE_ID','STAGE_ID','CATEGORY_ID','STAGE_SEMANTIC_ID','IS_NEW','IS_RECURRING','PROBABILITY','CURRENCY_ID','OPPORTUNITY','OPPORTUNITY_ACCOUNT','ACCOUNT_CURRENCY_ID','TAX_VALUE','COMPANY_ID','CONTACT_ID','LEAD_ID','BEGINDATE','CLOSEDATE','ASSIGNED_BY_ID','COMMENTS','DATE_CREATE','DATE_MODIFY','CREATED_BY_ID','MODIFY_BY_ID','OPENED','ORIGINATOR_ID','ORIGIN_ID','SOURCE_ID','SOURCE_DESCRIPTION','UTM_SOURCE','UTM_MEDIUM','UTM_CAMPAIGN','UTM_CONTENT','UTM_TERM'];

foreach ($standardLead as $f) {
    if (!isset($result['lead_fields'][$f])) {
        $result['lead_fields'][$f] = ['type' => 'string', 'title' => $f, 'isRequired' => 'N', 'isReadOnly' => 'N', 'isMultiple' => 'N'];
    }
}
foreach ($standardDeal as $f) {
    if (!isset($result['deal_fields'][$f])) {
        $result['deal_fields'][$f] = ['type' => 'string', 'title' => $f, 'isRequired' => 'N', 'isReadOnly' => 'N', 'isMultiple' => 'N'];
    }
}

ksort($result['lead_fields']);
ksort($result['deal_fields']);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
