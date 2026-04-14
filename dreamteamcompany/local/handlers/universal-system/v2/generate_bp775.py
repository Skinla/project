#!/usr/bin/env python3
"""
Генератор BPT-файла для БП 775 (список-буфер IBlock 49).

Структура БП:
  1. SetFieldActivity — статус = "processing"
  2. CodeActivity — парсинг сырых данных из PROPERTY_RAW_DATA
  3. IfElseActivity — проверка CB_PHONE (пустой -> error + terminate)
  4. CodeActivity — поиск в ИБ54 + списках 19, 22
  5. CodeActivity — подготовка полей лида (Title, Name, Assigned, SourceDescription)
  6. CodeActivity — создание лида (CCrmLead::Add, внутренний API)
  7. SetFieldActivity — обновление полей элемента списка (статус, ID лида)
"""

import os
import random
import zlib


def php_s(value: str) -> str:
    b = value.encode("utf-8")
    return f's:{len(b)}:"{value}";'


def php_int(value: int) -> str:
    return f"i:{value};"


def php_null() -> str:
    return "N;"


def php_bool(value: bool) -> str:
    return f"b:{1 if value else 0};"


def php_array(items: list) -> str:
    parts = [f"a:{len(items)}:{{"]
    for key, val in items:
        parts.append(key)
        parts.append(val)
    parts.append("}")
    return "".join(parts)


def php_array_indexed(items: list) -> str:
    pairs = [(f"i:{i};", val) for i, val in enumerate(items)]
    return php_array(pairs)


def uid() -> str:
    return f"A{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}"


def make_activity(act_type, name, activated, properties, children=None):
    parts = [
        (php_s("Type"), php_s(act_type)),
        (php_s("Name"), php_s(name)),
        (php_s("Activated"), php_s(activated)),
        (php_s("Node"), php_null()),
        (php_s("Properties"), php_array(properties)),
        (php_s("Children"), php_array_indexed(children or [])),
    ]
    return php_array(parts)


def make_set_field(name, title, field_values):
    items = [(php_s(k), php_s(v)) for k, v in field_values.items()]
    return make_activity(
        "SetFieldActivity",
        name,
        "Y",
        [
            (php_s("FieldValue"), php_array(items)),
            (php_s("ModifiedBy"), php_array([])),
            (php_s("MergeMultipleFields"), php_s("N")),
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_set_variable(name, title, var_values):
    items = [(php_s(k), php_s(v)) for k, v in var_values.items()]
    return make_activity(
        "SetVariableActivity",
        name,
        "Y",
        [
            (php_s("VariableValue"), php_array(items)),
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_code(name, title, code):
    return make_activity(
        "CodeActivity",
        name,
        "Y",
        [
            (php_s("ExecuteCode"), php_s(code)),
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_log(name, title, text):
    return make_activity(
        "LogActivity",
        name,
        "Y",
        [
            (php_s("Text"), php_s(text)),
            (php_s("SetVariable"), php_s("0")),
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_terminate(name, title):
    return make_activity(
        "TerminateActivity",
        name,
        "Y",
        [
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_if_else(name, title, branches):
    return make_activity(
        "IfElseActivity",
        name,
        "Y",
        [
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
        branches,
    )


def make_branch_var_condition(name, title, var_name, operator, value, children):
    return make_activity(
        "IfElseBranchActivity",
        name,
        "Y",
        [
            (php_s("Title"), php_s(title)),
            (
                php_s("propertyvariablecondition"),
                php_array(
                    [
                        (
                            php_int(0),
                            php_array_indexed(
                                [php_s(var_name), php_s(operator), php_s(value), php_s("0")]
                            ),
                        )
                    ]
                ),
            ),
        ],
        children,
    )


def make_branch_else(name, title, children):
    return make_activity(
        "IfElseBranchActivity",
        name,
        "Y",
        [
            (php_s("Title"), php_s(title)),
            (php_s("truecondition"), php_s("1")),
        ],
        children,
    )


def make_crm_create_lead(name, title, fields_map):
    field_items = [(php_s(k), php_s(v)) for k, v in fields_map.items()]
    return make_activity(
        "CrmCreateDynamicActivity",
        name,
        "Y",
        [
            (php_s("DynamicTypeId"), php_s("1")),
            (php_s("OnlyDynamicEntities"), php_s("N")),
            (php_s("DynamicEntitiesFields"), php_array(field_items)),
            (php_s("Title"), php_s(title)),
            (php_s("EditorComment"), php_s("")),
        ],
    )


def make_variable_def(var_name, human_name, var_type="string", default=""):
    return (
        php_s(var_name),
        php_array(
            [
                (php_s("Name"), php_s(human_name)),
                (php_s("Description"), php_s("")),
                (php_s("Type"), php_s(var_type)),
                (php_s("Required"), php_s("0")),
                (php_s("Multiple"), php_s("0")),
                (php_s("Options"), php_s("")),
                (php_s("Default"), php_s(default)),
            ]
        ),
    )


def build_document_fields():
    fields = [
        ("NAME", "Название", "string"),
        ("PROPERTY_RAW_DATA", "Сырые данные", "string"),
        ("PROPERTY_STATUS", "Статус обработки", "string"),
        ("PROPERTY_SOURCE_DOMAIN", "Домен источника", "string"),
        ("PROPERTY_PHONE", "Телефон", "string"),
        ("PROPERTY_TITLE", "Название лида", "string"),
        ("PROPERTY_UF_CRM_1774525830934", "Имя (ИИ)", "string"),
        ("PROPERTY_LAST_NAME", "Фамилия", "string"),
        ("PROPERTY_SECOND_NAME", "Отчество", "string"),
        ("PROPERTY_ASSIGNED_BY_ID", "Ответственный", "user"),
        ("PROPERTY_ASSIGNED_BY_EMAIL", "Ответственный (e-mail)", "string"),
        ("PROPERTY_ASSIGNED_BY_PERSONAL_MOBILE", "Ответственный (Мобильный телефон)", "string"),
        ("PROPERTY_ASSIGNED_BY_WORK_PHONE", "Ответственный (Рабочий телефон)", "string"),
        ("PROPERTY_SOURCE_ID", "Источник", "string"),
        ("PROPERTY_TRACKING_SOURCE_ID", "Источник сквозной аналитики", "string"),
        ("PROPERTY_SOURCE_DESCRIPTION", "Дополнительно об источнике", "string"),
        ("PROPERTY_COMMENTS", "Комментарий", "string"),
        ("PROPERTY_OBSERVER_IDS", "Наблюдатели", "user"),
        ("PROPERTY_UF_CRM_LEAD_1761829560855", "Врач-агент", "select"),
        ("PROPERTY_UF_CRM_1773161068", "Город (список)", "select"),
        ("PROPERTY_UF_CRM_1754927102", "Инфоповод (стр)", "string"),
        ("PROPERTY_OPENED", "Доступен для всех", "select"),
        ("PROPERTY_STATUS_ID", "Стадия", "select"),
        ("PROPERTY_UTM_SOURCE", "UTM Source", "string"),
        ("PROPERTY_UTM_MEDIUM", "UTM Medium", "string"),
        ("PROPERTY_UTM_CAMPAIGN", "UTM Campaign", "string"),
        ("PROPERTY_UTM_CONTENT", "UTM Content", "string"),
        ("PROPERTY_UTM_TERM", "UTM Term", "string"),
        ("PROPERTY_ERROR_MSG", "Сообщение об ошибке", "string"),
    ]
    items = []
    for code, name, ftype in fields:
        field_def = php_array(
            [
                (php_s("Name"), php_s(name)),
                (php_s("Type"), php_s(ftype)),
                (php_s("Required"), php_s("1" if code == "NAME" else "0")),
                (php_s("Multiple"), php_s("0")),
                (php_s("Options"), php_s("")),
            ]
        )
        items.append((php_s(code), field_def))
    return php_array(items)


PARSE_RAW_DATA_PHP = r"""
$rootActivity = $this->GetRootActivity();
$setVar = function ($name, $value) use ($rootActivity) {
    if (method_exists($rootActivity, 'SetVariable')) {
        $rootActivity->SetVariable($name, (string)$value);
    }
};
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};

try {
    $log('[1/3] Начало парсинга сырых данных');

    $rawJson = (string)$this->GetVariable('CB_RAW_JSON');
    $rawJson = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
    if ($rawJson === '') {
        $setVar('CB_RESULT', 'error');
        $setVar('CB_ERROR_MSG', 'RAW_DATA пуст');
        $log('[1/3] ОШИБКА: RAW_DATA пуст');
        return;
    }

    $jsonFlags = JSON_INVALID_UTF8_SUBSTITUTE;
    if (defined('JSON_BIGINT_AS_STRING')) {
        $jsonFlags |= JSON_BIGINT_AS_STRING;
    }
    $data = json_decode($rawJson, true, 512, $jsonFlags);
    if (!is_array($data)) {
        $setVar('CB_RESULT', 'error');
        $setVar('CB_ERROR_MSG', 'Невалидный JSON в RAW_DATA: ' . json_last_error_msg());
        $log('[1/3] ОШИБКА: невалидный JSON | ' . json_last_error_msg());
        return;
    }

    $rawHeaders = $data['raw_headers'] ?? [];
    if (!is_array($rawHeaders)) {
        $rawHeaders = [];
    }
    $rawBody = (string)($data['raw_body'] ?? '');
    $contentType = (string)($rawHeaders['CONTENT_TYPE'] ?? '');

    if (array_key_exists('parsed_data', $data)) {
        $pd = $data['parsed_data'];
        if (is_array($pd)) {
            $parsedData = $pd;
        } elseif (is_string($pd) && $pd !== '') {
            $parsedData = json_decode($pd, true, 512, $jsonFlags);
            $parsedData = is_array($parsedData) ? $parsedData : [];
        } else {
            $parsedData = [];
        }
    } else {
        $parsedData = $data;
    }

    $isFormUrlencoded = stripos($contentType, 'application/x-www-form-urlencoded') !== false;
    $looksLikeQueryBody = $rawBody !== '' && preg_match('/[^=&]+=/', $rawBody);
    if ($rawBody !== '' && ($isFormUrlencoded || ($contentType === '' && $looksLikeQueryBody))) {
        $reparsed = [];
        parse_str($rawBody, $reparsed);
        if (is_array($reparsed)) {
            foreach ($reparsed as $k => $v) {
                $parsedData[$k] = $v;
            }
        }
    }

    if (is_array($parsedData)) {
        array_walk_recursive($parsedData, function (&$value) {
            if (is_string($value)) {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        });
    } else {
        $parsedData = [];
    }

    $domain = (string)($data['source_domain'] ?? ($parsedData['source_domain'] ?? 'unknown'));

    $phone = '';
    $phoneFields = ['Phone', 'phone', 'PHONE'];
    foreach ($phoneFields as $f) {
        if (!empty($parsedData[$f])) {
            $phone = (string)$parsedData[$f];
            break;
        }
    }
    if (empty($phone) && !empty($parsedData['contacts']['phone'])) {
        $phone = (string)$parsedData['contacts']['phone'];
    }
    if (empty($phone) && !empty($parsedData['callerphone'])) {
        $phone = (string)$parsedData['callerphone'];
    }
    if (empty($phone) && !empty($rawHeaders['QUERY_STRING'])) {
        $qs = urldecode($rawHeaders['QUERY_STRING']);
        if (preg_match('/fields\[PHONE\]\[0\]\[VALUE\]=([^&]+)/', $qs, $m)) {
            $phone = urldecode($m[1]);
        }
    }
    if (empty($phone)) {
        foreach ($parsedData as $k => $v) {
            if (is_string($v) && !empty($v) && preg_match('/^phone/i', $k)) {
                $phone = $v;
                break;
            }
        }
    }

    $cleanPhone = preg_replace('/\D+/', '', $phone);
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
        $cleanPhone = '7' . substr($cleanPhone, 1);
    }
    if (strlen($cleanPhone) === 10) {
        $cleanPhone = '7' . $cleanPhone;
    }
    if (strlen($cleanPhone) >= 11 && $cleanPhone[0] === '7') {
        $phone = '+' . $cleanPhone;
    } elseif (!empty($cleanPhone)) {
        $phone = '+' . $cleanPhone;
    }

    $name = '';
    $nameFields = ['name', 'Name', 'NAME', 'fio', 'FIO', 'fullname'];
    foreach ($nameFields as $f) {
        if (!empty($parsedData[$f]) && $parsedData[$f] !== 'Неизвестно') {
            $val = trim($parsedData[$f]);
            if (strlen($val) < 100 && !preg_match('/^\d+$/', $val)) {
                $name = $val;
                break;
            }
        }
    }
    if (empty($name) && !empty($parsedData['contacts']['name'])) {
        $name = trim($parsedData['contacts']['name']);
    }

    $utmFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
    foreach ($utmFields as $uf) {
        $setVar('CB_' . strtoupper($uf), (string)($parsedData[$uf] ?? ''));
    }

    $excludeKeys = ['phone','Phone','PHONE','name','Name','NAME','source_domain',
        'utm_source','utm_medium','utm_campaign','utm_content','utm_term',
        'rawBody','raw_body','raw_headers','parsed_data',
        'contacts','extra','__submission','ASSIGNED_BY_ID','callerphone'];
    $commentLines = [];

    if (!empty($parsedData['answers']) && is_array($parsedData['answers'])) {
        foreach ($parsedData['answers'] as $item) {
            $q = $item['q'] ?? '';
            $a = $item['a'] ?? '';
            if (is_array($a)) $a = implode(', ', $a);
            $q = strip_tags(trim($q));
            $a = strip_tags(trim($a));
            if (!empty($q) && !empty($a)) {
                $commentLines[] = "$q: $a";
            }
        }
    } else {
        foreach ($parsedData as $k => $v) {
            if (in_array($k, $excludeKeys)) continue;
            if (is_string($v) && !empty($v) && strlen($v) < 500) {
                $commentLines[] = str_replace('_', ' ', $k) . ': ' . strip_tags(trim($v));
            }
        }
    }
    $comment = implode("\n", $commentLines);

    $lastName = '';
    foreach (['last_name', 'surname', 'lastName', 'LAST_NAME'] as $f) {
        if (!empty($parsedData[$f])) {
            $lastName = trim((string)$parsedData[$f]);
            break;
        }
    }
    $secondName = '';
    foreach (['second_name', 'middle_name', 'patronymic', 'otchestvo', 'SECOND_NAME'] as $f) {
        if (!empty($parsedData[$f])) {
            $secondName = trim((string)$parsedData[$f]);
            break;
        }
    }

    $isCallTouch = isset($parsedData['callerphone']) || isset($parsedData['subPoolName']) || isset($parsedData['siteId']);
    $sourceType = $isCallTouch ? 'calltouch' : 'web';
    $leadTitle = (($sourceType === 'calltouch') ? 'Звонок' : 'Лид') . " с сайта [$domain]";
    $sourceDescription = ($sourceType === 'calltouch') ? 'CallTouch' : ('Сайт: ' . $domain);

    $setVar('CB_TITLE', $sourceType);
    $setVar('CB_LEAD_TITLE', $leadTitle);
    $setVar('CB_PHONE', $phone);
    $setVar('CB_NAME', $name);
    $setVar('CB_LAST_NAME', $lastName);
    $setVar('CB_SECOND_NAME', $secondName);
    $setVar('CB_DOMAIN', $domain);
    $setVar('CB_COMMENT', $comment);
    $setVar('CB_SOURCE_DESCRIPTION', $sourceDescription);
    $setVar('CB_OPENED', 'Y');
    $setVar('CB_STATUS_ID', 'NEW');
    $setVar('CB_RESULT', 'parsed');
    $setVar('CB_ERROR_MSG', '');

    $setVar('CB_ASSIGNED_BY_ID', (string)($parsedData['ASSIGNED_BY_ID'] ?? $parsedData['assigned_by_id'] ?? ''));
    $setVar('CB_ASSIGNED_BY_EMAIL', (string)($parsedData['ASSIGNED_BY_EMAIL'] ?? $parsedData['assigned_by_email'] ?? ''));
    $setVar('CB_ASSIGNED_BY_PERSONAL_MOBILE', (string)($parsedData['ASSIGNED_BY_PERSONAL_MOBILE'] ?? $parsedData['assigned_by_personal_mobile'] ?? ''));
    $setVar('CB_ASSIGNED_BY_WORK_PHONE', (string)($parsedData['ASSIGNED_BY_WORK_PHONE'] ?? $parsedData['assigned_by_work_phone'] ?? ''));
    $setVar('CB_SOURCE_ID', (string)($parsedData['SOURCE_ID'] ?? $parsedData['source_id'] ?? ''));
    $setVar('CB_TRACKING_SOURCE_ID', (string)($parsedData['TRACKING_SOURCE_ID'] ?? $parsedData['tracking_source_id'] ?? ''));
    $setVar('CB_OBSERVER_IDS', (string)($parsedData['OBSERVER_IDS'] ?? $parsedData['observer_ids'] ?? ''));
    $setVar('CB_CITY_ID', (string)($parsedData['UF_CRM_1773161068'] ?? $parsedData['UF_CRM_1744362815'] ?? $parsedData['city'] ?? ''));
    $setVar('CB_ISPOLNITEL', (string)($parsedData['UF_CRM_LEAD_1761829560855'] ?? $parsedData['UF_CRM_1745957138'] ?? $parsedData['ispolnitel'] ?? ''));
    $setVar('CB_INFOPOVOD', (string)($parsedData['UF_CRM_1754927102'] ?? $parsedData['infopovod'] ?? ''));

    $log("[1/3] Парсинг OK | Phone: $phone | Name: $name | Domain: $domain | Title: $leadTitle");

} catch (\Throwable $e) {
    $setVar('CB_RESULT', 'error');
    $setVar('CB_ERROR_MSG', 'Parse error: ' . $e->getMessage());
    $this->WriteToTrackingService('[1/3] ОШИБКА парсинга: ' . $e->getMessage());
}
"""

LOOKUP_IB54_PHP = r"""
$rootActivity = $this->GetRootActivity();
$setVar = function ($name, $value) use ($rootActivity) {
    if (method_exists($rootActivity, 'SetVariable')) {
        $rootActivity->SetVariable($name, (string)$value);
    }
};
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};

try {
    $log('[2/3] Поиск в ИБ54');

    if (!\Bitrix\Main\Loader::includeModule('iblock')) {
        $log('[2/3] ОШИБКА: модуль iblock не доступен');
        return;
    }

    $domain = (string)$getVar('CB_DOMAIN');
    $setVar('id_54', '');
    $log("[2/3] Domain=$domain");

    $res = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 54, 'NAME' => $domain, 'ACTIVE' => 'Y'],
        false, ['nTopCount' => 1],
        ['ID', 'NAME']
    );

    $element = $res->GetNext();
    if (!$element) {
        $log("[2/3] Элемент не найден в ИБ54 для домена '$domain'");
        return;
    }

    $setVar('id_54', (string)$element['ID']);
    $log('[2/3] Найден элемент ИБ54 ID=' . $element['ID']);

} catch (\Throwable $e) {
    $this->WriteToTrackingService('[2/3] ОШИБКА ИБ54: ' . $e->getMessage());
}
"""

WRITE_SPECIAL_LIST_FIELDS_PHP = r"""
$rootActivity = $this->GetRootActivity();
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};

try {
    if (!\Bitrix\Main\Loader::includeModule('iblock')) {
        $log('[4/5] ОШИБКА: модуль iblock не доступен');
        return;
    }

    $documentId = $this->GetDocumentId();
    $elementId = is_array($documentId) ? (int)end($documentId) : (int)$documentId;
    if ($elementId <= 0) {
        $log('[4/5] ОШИБКА: не удалось определить ID элемента');
        return;
    }

    $assignedById = trim((string)$getVar('CB_ASSIGNED_BY_ID'));
    $observerIdsRaw = trim((string)$getVar('CB_OBSERVER_IDS'));
    $cityId = trim((string)$getVar('CB_CITY_ID'));
    $ispolnitelRaw = trim((string)$getVar('CB_ISPOLNITEL'));
    $infopovod = trim((string)$getVar('CB_INFOPOVOD'));

    $propertyValues = [];

    if ($assignedById !== '') {
        $propertyValues['PROPERTY_ASSIGNED_BY_ID'] = (int)$assignedById;
    }

    if ($observerIdsRaw !== '') {
        $observerIds = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $observerIdsRaw))));
        if (!empty($observerIds)) {
            $propertyValues['PROPERTY_OBSERVER_IDS'] = $observerIds;
        }
    }

    if ($cityId !== '') {
        $propertyValues['PROPERTY_822'] = (int)$cityId;
    }

    if ($infopovod !== '') {
        $propertyValues['PROPERTY_UF_CRM_1754927102'] = $infopovod;
    }

    if (!empty($propertyValues)) {
        \CIBlockElement::SetPropertyValuesEx($elementId, 49, $propertyValues);
        $log('[4/5] Записаны сложные поля списка: ' . implode(', ', array_keys($propertyValues)));
    } else {
        $log('[4/5] Нет данных для сложных полей списка');
    }
} catch (\Throwable $e) {
    $this->WriteToTrackingService('[4/5] ОШИБКА записи сложных полей: ' . $e->getMessage());
}
"""

PREPARE_LEAD_PHP = r"""
$rootActivity = $this->GetRootActivity();
$setVar = function ($name, $value) use ($rootActivity) {
    if (method_exists($rootActivity, 'SetVariable')) {
        $rootActivity->SetVariable($name, (string)$value);
    }
};
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};

try {
    $log('[3/4] Подготовка полей лида');

    $sourceType = (string)$getVar('CB_TITLE');
    $leadTitle = (string)$getVar('CB_LEAD_TITLE');
    $domain = (string)$getVar('CB_DOMAIN');
    $name = (string)$getVar('CB_NAME');
    $assignedById = (string)$getVar('CB_ASSIGNED_BY_ID');

    if (empty($leadTitle)) {
        $prefix = ($sourceType === 'calltouch') ? 'Звонок' : 'Лид';
        $leadTitle = "$prefix с сайта [$domain]";
    }
    $setVar('CB_LEAD_TITLE', $leadTitle);

    if (empty($name)) {
        $setVar('CB_NAME', 'Имя');
        $setVar('CB_LAST_NAME', 'Фамилия');
    } else {
        $setVar('CB_LAST_NAME', '');
    }

    if (empty($assignedById)) {
        $setVar('CB_ASSIGNED_BY_ID', '1');
    }

    if (strpos($leadTitle, 'Звонок') !== false) {
        $setVar('CB_SOURCE_DESCRIPTION', 'CallTouch');
    } else {
        $setVar('CB_SOURCE_DESCRIPTION', 'Сайт: ' . $domain);
    }

    $setVar('CB_RESULT', 'prepared');

    $log('[3/4] === ПОЛЯ ЛИДА ===');
    $log('[3/4] TITLE=' . $leadTitle);
    $log('[3/4] NAME=' . (string)$getVar('CB_NAME'));
    $log('[3/4] LAST_NAME=' . (string)$getVar('CB_LAST_NAME'));
    $log('[3/4] PHONE=' . (string)$getVar('CB_PHONE'));
    $log('[3/4] ASSIGNED_BY_ID=' . (string)$getVar('CB_ASSIGNED_BY_ID'));
    $log('[3/4] SOURCE_ID=' . (string)$getVar('CB_SOURCE_ID'));
    $log('[3/4] SOURCE_DESCRIPTION=' . (string)$getVar('CB_SOURCE_DESCRIPTION'));
    $log('[3/4] COMMENTS=' . mb_substr((string)$getVar('CB_COMMENT'), 0, 200));
    $log('[3/4] OBSERVER_IDS=' . (string)$getVar('CB_OBSERVER_IDS'));
    $log('[3/4] UF_CRM_1744362815 (Город)=' . (string)$getVar('CB_CITY_ID'));
    $log('[3/4] UF_CRM_1745957138 (Исполнитель)=' . (string)$getVar('CB_ISPOLNITEL'));
    $log('[3/4] UF_CRM_1754927102 (Инфоповод)=' . (string)$getVar('CB_INFOPOVOD'));
    $log('[3/4] UTM_SOURCE=' . (string)$getVar('CB_UTM_SOURCE'));
    $log('[3/4] UTM_MEDIUM=' . (string)$getVar('CB_UTM_MEDIUM'));
    $log('[3/4] UTM_CAMPAIGN=' . (string)$getVar('CB_UTM_CAMPAIGN'));
    $log('[3/4] UTM_CONTENT=' . (string)$getVar('CB_UTM_CONTENT'));
    $log('[3/4] UTM_TERM=' . (string)$getVar('CB_UTM_TERM'));
    $log('[3/4] OPENED=' . (string)$getVar('CB_OPENED'));
    $log('[3/4] STATUS_ID=' . (string)$getVar('CB_STATUS_ID'));
    $log('[3/4] === КОНЕЦ ПОЛЕЙ ===');

} catch (\Throwable $e) {
    $setVar('CB_RESULT', 'error');
    $setVar('CB_ERROR_MSG', 'PrepareFields: ' . $e->getMessage());
    $this->WriteToTrackingService('[3/4] ИСКЛЮЧЕНИЕ: ' . $e->getMessage());
}
"""

CREATE_LEAD_PHP = r"""
$rootActivity = $this->GetRootActivity();
$setVar = function ($name, $value) use ($rootActivity) {
    if (method_exists($rootActivity, 'SetVariable')) {
        $rootActivity->SetVariable($name, (string)$value);
    }
};
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};

try {
    $log('[4/4] Создание лида (CCrmLead::Add)');

    $phone = (string)$getVar('CB_PHONE');
    $name = (string)$getVar('CB_NAME');
    $lastName = (string)$getVar('CB_LAST_NAME');
    $domain = (string)$getVar('CB_DOMAIN');
    $comment = (string)$getVar('CB_COMMENT');
    $title = (string)$getVar('CB_TITLE');
    $sourceId = (string)$getVar('CB_SOURCE_ID');
    $sourceDesc = (string)$getVar('CB_SOURCE_DESCRIPTION');
    $assignedById = (string)$getVar('CB_ASSIGNED_BY_ID');
    $cityId = (string)$getVar('CB_CITY_ID');
    $infopovod = (string)$getVar('CB_INFOPOVOD');
    $ispolnitel = (string)$getVar('CB_ISPOLNITEL');
    $observerIds = (string)$getVar('CB_OBSERVER_IDS');

    if (empty($phone)) {
        $setVar('CB_RESULT', 'error');
        $setVar('CB_ERROR_MSG', 'Телефон пуст');
        $log('[4/4] ОШИБКА: телефон пуст');
        return;
    }

    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        $setVar('CB_RESULT', 'error');
        $setVar('CB_ERROR_MSG', 'Модуль CRM не доступен');
        $log('[4/4] ОШИБКА: модуль crm не подключён');
        return;
    }

    $assignedId = !empty($assignedById) ? (int)$assignedById : 1;

    $fields = [
        'TITLE' => $title,
        'NAME' => $name,
        'ASSIGNED_BY_ID' => $assignedId,
        'CREATED_BY_ID' => $assignedId,
        'COMMENTS' => $comment,
        'OPENED' => 'Y',
        'STATUS_ID' => 'NEW',
    ];

    if (!empty($lastName)) {
        $fields['LAST_NAME'] = $lastName;
    }

    if (!empty($sourceId)) {
        $fields['SOURCE_ID'] = $sourceId;
    }

    if (!empty($sourceDesc)) {
        $fields['SOURCE_DESCRIPTION'] = $sourceDesc;
    }

    if (!empty($cityId)) {
        $fields['UF_CRM_1744362815'] = $cityId;
    }
    if (!empty($ispolnitel)) {
        $fields['UF_CRM_1745957138'] = $ispolnitel;
    }
    if (!empty($infopovod)) {
        $fields['UF_CRM_1754927102'] = $infopovod;
    }

    $utmMap = [
        'CB_UTM_SOURCE' => 'UTM_SOURCE',
        'CB_UTM_MEDIUM' => 'UTM_MEDIUM',
        'CB_UTM_CAMPAIGN' => 'UTM_CAMPAIGN',
        'CB_UTM_CONTENT' => 'UTM_CONTENT',
        'CB_UTM_TERM' => 'UTM_TERM',
    ];
    foreach ($utmMap as $bpVar => $crmField) {
        $val = (string)$getVar($bpVar);
        if (!empty($val)) {
            $fields[$crmField] = $val;
        }
    }

    $fields['FM'] = [
        'PHONE' => [
            'n0' => ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK'],
        ],
    ];

    if (!empty($observerIds)) {
        $obs = array_filter(array_map('intval', explode(',', $observerIds)));
        if (!empty($obs)) {
            $fields['OBSERVER_IDS'] = array_values($obs);
        }
    }

    $lead = new \CCrmLead(false);
    $leadId = $lead->Add($fields, true, [
        'REGISTER_SONET_EVENT' => true,
        'CURRENT_USER' => $assignedId,
    ]);

    if ($leadId > 0) {
        $setVar('CB_LEAD_ID', (string)$leadId);
        $setVar('CB_RESULT', 'success');
        $log("[4/4] Лид создан ID=$leadId");
    } else {
        $errMsg = $lead->LAST_ERROR ?: 'Неизвестная ошибка';
        $setVar('CB_RESULT', 'error');
        $setVar('CB_ERROR_MSG', 'CCrmLead: ' . $errMsg);
        $log('[4/4] Ошибка: ' . $errMsg);
    }

} catch (\Throwable $e) {
    $setVar('CB_RESULT', 'error');
    $setVar('CB_ERROR_MSG', 'Exception: ' . $e->getMessage());
    $this->WriteToTrackingService('[4/4] ИСКЛЮЧЕНИЕ: ' . $e->getMessage());
}
"""


def build_bpt():
    variables = [
        make_variable_def("CB_RAW_JSON", "Сырые данные JSON"),
        make_variable_def("id_54", "ID элемента списка 54"),
        make_variable_def("CB_PHONE", "Телефон"),
        make_variable_def("CB_NAME", "Имя"),
        make_variable_def("CB_LAST_NAME", "Фамилия"),
        make_variable_def("CB_SECOND_NAME", "Отчество"),
        make_variable_def("CB_DOMAIN", "Домен"),
        make_variable_def("CB_COMMENT", "Комментарий"),
        make_variable_def("CB_TITLE", "Тип источника"),
        make_variable_def("CB_LEAD_TITLE", "Название лида"),
        make_variable_def("CB_ASSIGNED_BY_ID", "Ответственный", "user"),
        make_variable_def("CB_ASSIGNED_BY_EMAIL", "Ответственный (e-mail)"),
        make_variable_def("CB_ASSIGNED_BY_PERSONAL_MOBILE", "Ответственный (Мобильный телефон)"),
        make_variable_def("CB_ASSIGNED_BY_WORK_PHONE", "Ответственный (Рабочий телефон)"),
        make_variable_def("CB_SOURCE_ID", "Источник"),
        make_variable_def("CB_TRACKING_SOURCE_ID", "Источник сквозной аналитики"),
        make_variable_def("CB_SOURCE_DESCRIPTION", "Описание источника"),
        make_variable_def("CB_OBSERVER_IDS", "Наблюдатели"),
        make_variable_def("CB_CITY_ID", "Город (UF_CRM_1744362815)"),
        make_variable_def("CB_ISPOLNITEL", "Исполнитель (UF_CRM_1745957138)"),
        make_variable_def("CB_INFOPOVOD", "Инфоповод (UF_CRM_1754927102)"),
        make_variable_def("CB_OPENED", "Доступен для всех", "string", "Y"),
        make_variable_def("CB_STATUS_ID", "Стадия лида", "string", "NEW"),
        make_variable_def("CB_UTM_SOURCE", "UTM Source"),
        make_variable_def("CB_UTM_MEDIUM", "UTM Medium"),
        make_variable_def("CB_UTM_CAMPAIGN", "UTM Campaign"),
        make_variable_def("CB_UTM_CONTENT", "UTM Content"),
        make_variable_def("CB_UTM_TERM", "UTM Term"),
        make_variable_def("CB_LEAD_ID", "ID лида"),
        make_variable_def("CB_RESULT", "Результат"),
        make_variable_def("CB_ERROR_MSG", "Сообщение об ошибке"),
    ]

    a1_read = make_set_variable(uid(), "Читаем RAW_DATA", {
        "CB_RAW_JSON": "{=Document:PROPERTY_RAW_DATA}",
    })
    a2_parse = make_code(uid(), "Парсинг данных", PARSE_RAW_DATA_PHP.strip())
    a3_lookup = make_code(uid(), "Обогащение из ИБ54", LOOKUP_IB54_PHP.strip())
    a4_write = make_set_field(uid(), "Запись полей в элемент", {
        "PROPERTY_STATUS": "{=Variable:CB_RESULT}",
        "PROPERTY_SOURCE_DOMAIN": "{=Variable:CB_DOMAIN}",
        "PROPERTY_PHONE": "{=Variable:CB_PHONE}",
        "PROPERTY_TITLE": "{=Variable:CB_LEAD_TITLE}",
        "PROPERTY_UF_CRM_1774525830934": "{=Variable:CB_NAME}",
        "PROPERTY_LAST_NAME": "{=Variable:CB_LAST_NAME}",
        "PROPERTY_SECOND_NAME": "{=Variable:CB_SECOND_NAME}",
        "PROPERTY_ASSIGNED_BY_WORK_PHONE": "{=Variable:CB_ASSIGNED_BY_WORK_PHONE}",
        "PROPERTY_SOURCE_ID": "{=Variable:CB_SOURCE_ID}",
        "PROPERTY_TRACKING_SOURCE_ID": "{=Variable:CB_TRACKING_SOURCE_ID}",
        "PROPERTY_SOURCE_DESCRIPTION": "{=Variable:CB_SOURCE_DESCRIPTION}",
        "PROPERTY_COMMENTS": "{=Variable:CB_COMMENT}",
        "PROPERTY_OPENED": "{=Variable:CB_OPENED}",
        "PROPERTY_STATUS_ID": "{=Variable:CB_STATUS_ID}",
        "PROPERTY_UTM_SOURCE": "{=Variable:CB_UTM_SOURCE}",
        "PROPERTY_UTM_MEDIUM": "{=Variable:CB_UTM_MEDIUM}",
        "PROPERTY_UTM_CAMPAIGN": "{=Variable:CB_UTM_CAMPAIGN}",
        "PROPERTY_UTM_CONTENT": "{=Variable:CB_UTM_CONTENT}",
        "PROPERTY_UTM_TERM": "{=Variable:CB_UTM_TERM}",
        "PROPERTY_ERROR_MSG": "{=Variable:CB_ERROR_MSG}",
    })
    a5_write_special = make_code(uid(), "Запись сложных полей списка", WRITE_SPECIAL_LIST_FIELDS_PHP.strip())
    log_report = make_log(
        uid(),
        "Лог: заполненные поля",
        "TITLE={=Variable:CB_LEAD_TITLE} | NAME={=Variable:CB_NAME} | LAST_NAME={=Variable:CB_LAST_NAME} | SECOND_NAME={=Variable:CB_SECOND_NAME} | PHONE={=Variable:CB_PHONE} | ASSIGNED_BY_ID={=Variable:CB_ASSIGNED_BY_ID} | ASSIGNED_EMAIL={=Variable:CB_ASSIGNED_BY_EMAIL} | ASSIGNED_MOBILE={=Variable:CB_ASSIGNED_BY_PERSONAL_MOBILE} | ASSIGNED_WORK_PHONE={=Variable:CB_ASSIGNED_BY_WORK_PHONE} | SOURCE_ID={=Variable:CB_SOURCE_ID} | TRACKING_SOURCE_ID={=Variable:CB_TRACKING_SOURCE_ID} | SOURCE_DESCRIPTION={=Variable:CB_SOURCE_DESCRIPTION} | COMMENTS={=Variable:CB_COMMENT} | OBSERVER_IDS={=Variable:CB_OBSERVER_IDS} | CITY={=Variable:CB_CITY_ID} | ISPOLNITEL={=Variable:CB_ISPOLNITEL} | INFOPOVOD={=Variable:CB_INFOPOVOD} | UTM_SOURCE={=Variable:CB_UTM_SOURCE} | UTM_MEDIUM={=Variable:CB_UTM_MEDIUM} | UTM_CAMPAIGN={=Variable:CB_UTM_CAMPAIGN} | UTM_CONTENT={=Variable:CB_UTM_CONTENT} | UTM_TERM={=Variable:CB_UTM_TERM} | OPENED={=Variable:CB_OPENED} | STATUS_ID={=Variable:CB_STATUS_ID} | RESULT={=Variable:CB_RESULT} | ERROR={=Variable:CB_ERROR_MSG}",
    )

    root_children = [a1_read, a2_parse, a3_lookup, a4_write, a5_write_special, log_report]

    root = make_activity(
        "SequentialWorkflowActivity",
        uid(),
        "Y",
        [
            (php_s("Title"), php_s("БП 775: Обработка входящих заявок")),
            (php_s("Permission"), php_array([])),
        ],
        root_children,
    )

    bpt = php_array(
        [
            (php_s("VERSION"), php_int(2)),
            (php_s("TEMPLATE"), php_array([(php_int(0), root)])),
            (php_s("PARAMETERS"), php_array([])),
            (php_s("VARIABLES"), php_array(variables)),
            (php_s("CONSTANTS"), php_array([])),
            (php_s("DOCUMENT_FIELDS"), build_document_fields()),
        ]
    )

    return bpt


def validate_bpt(data: str):
    assert data.count("{") == data.count("}"), f"Braces mismatch: {{ = {data.count('{')}, }} = {data.count('}')}"

    compressed = zlib.compress(data.encode("utf-8"), 9)
    assert compressed[:2] == b"\x78\xda", f"Wrong zlib header: {compressed[:2].hex()}, expected 78da"

    decompressed = zlib.decompress(compressed).decode("utf-8")
    assert decompressed == data, "Round-trip decompression mismatch"

    print(f"  Braces balanced: {data.count('{')}")
    print(f"  Compressed size: {len(compressed)} bytes")
    print(f"  Zlib header: {compressed[:2].hex()} (OK)")
    print("  Round-trip: OK")


def main():
    random.seed(775)

    bpt_data = build_bpt()

    print("Validating BPT...")
    validate_bpt(bpt_data)

    compressed = zlib.compress(bpt_data.encode("utf-8"), 9)

    output_path = os.path.join(os.path.dirname(__file__), "bp775_lead_processor.bpt")
    with open(output_path, "wb") as f:
        f.write(compressed)

    print(f"\nBPT file saved: {output_path}")
    print(f"File size: {len(compressed)} bytes")
    print(f"Decompressed size: {len(bpt_data)} bytes")


if __name__ == "__main__":
    main()
