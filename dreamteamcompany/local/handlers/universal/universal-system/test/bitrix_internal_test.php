<?php
// bitrix_internal_test.php
//
// Вспомогательный скрипт для ПРОВЕРКИ работы внутренних классов Bitrix
// вместо REST API. НИЧЕГО в основной логике не меняет, только тестирует:
// - CCrmLead::Add / ::Delete
// - D7 \Bitrix\Crm\LeadTable::add (если доступен)
// - Чтение из ИБ54 через CIBlockElement
// - Чтение SOURCE_ID (список 19) и ASSIGNED_BY_ID (список 22)
//
// Запускать в проде руками по URL вида:
//   /local/handlers/universal/universal-system/test/bitrix_internal_test.php?token=XXX
//
// РЕКОМЕНДУЕТСЯ:
// - добавить какой-то простой секретный token в GET, чтобы не светить тест снаружи.

// ---------------- БАЗОВАЯ ЗАЩИТА ----------------

// Простейшая защита через токен, чтобы скрипт не дёргали случайно извне
$expectedToken = 'change-me-token'; // при необходимости поменяешь на свой
$passedToken   = $_GET['token'] ?? '';

if ($expectedToken !== '' && $passedToken !== $expectedToken) {
    header('HTTP/1.1 403 Forbidden');
    echo "ACCESS DENIED\n";
    exit;
}

// ---------------- ИНИЦИАЛИЗАЦИЯ BITRIX ----------------

use Bitrix\Main\Loader;

// Убедимся, что DOCUMENT_ROOT задан
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    // Попробуем восстановить относительным путём от текущего файла
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$errors = [];

if (!Loader::includeModule('crm')) {
    $errors[] = 'Не удалось подключить модуль crm';
}
if (!Loader::includeModule('iblock')) {
    $errors[] = 'Не удалось подключить модуль iblock';
}

header('Content-Type: text/plain; charset=utf-8');

echo "Bitrix internal API test\n";
echo "=========================\n\n";

if ($errors) {
    echo "ОШИБКИ ИНИЦИАЛИЗАЦИИ:\n";
    foreach ($errors as $e) {
        echo "- $e\n";
    }
    echo "\nДальнейшие тесты невозможны.\n";
    exit;
}

// ---------------- УТИЛИТЫ ДЛЯ ВЫВОДА ----------------

function t_print_result(string $title, bool $ok, array $details = []): void
{
    echo ($ok ? "[OK]  " : "[FAIL]") . " $title\n";
    if ($details) {
        foreach ($details as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            echo "    - $k: $v\n";
        }
    }
    echo "\n";
}

// ---------------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ДЛЯ ПОЛУЧЕНИЯ ДАННЫХ ----------------

/**
 * Получает все поля созданного лида для проверки
 */
function getLeadFieldsForTest($leadId): array
{
    if (!class_exists('CCrmLead')) {
        return [];
    }

    $lead = new \CCrmLead();
    $leadData = $lead->GetByID($leadId);
    
    if (!$leadData) {
        return [];
    }
    
    // Получаем пользовательские поля отдельно, так как GetByID может не возвращать их все
    $userFields = [];
    if (class_exists('\CUserTypeEntity')) {
        $userFieldEntity = new \CUserTypeEntity();
        $userFieldsData = $userFieldEntity->GetList(
            [],
            [
                'ENTITY_ID' => 'CRM_LEAD',
                'FIELD_NAME' => 'UF_CRM_1744362815'
            ]
        );
        if ($userField = $userFieldsData->Fetch()) {
            // Поле найдено, получаем его значение
            $userFields['UF_CRM_1744362815'] = $leadData['UF_CRM_1744362815'] ?? '';
        }
    }

    // Получаем телефон отдельно
    $phoneData = \CCrmFieldMulti::GetList(
        ['ID' => 'ASC'],
        [
            'ENTITY_ID' => 'LEAD',
            'ELEMENT_ID' => $leadId,
            'TYPE_ID' => 'PHONE'
        ]
    );
    
    $phones = [];
    while ($phone = $phoneData->Fetch()) {
        $phones[] = [
            'VALUE' => $phone['VALUE'] ?? '',
            'VALUE_TYPE' => $phone['VALUE_TYPE'] ?? '',
        ];
    }

    // Получаем наблюдателей отдельно (OBSERVER_IDS может быть строкой или массивом)
    $observerIds = $leadData['OBSERVER_IDS'] ?? '';
    if (is_string($observerIds) && !empty($observerIds)) {
        // Если строка, пробуем разбить по запятой
        $observerIds = array_filter(array_map('trim', explode(',', $observerIds)));
    }
    if (!is_array($observerIds)) {
        $observerIds = [];
    }

    return [
        'ID' => $leadData['ID'] ?? '',
        'TITLE' => $leadData['TITLE'] ?? '',
        'NAME' => $leadData['NAME'] ?? '',
        'LAST_NAME' => $leadData['LAST_NAME'] ?? '',
        'SECOND_NAME' => $leadData['SECOND_NAME'] ?? '',
        'PHONE' => $phones,
        'COMMENTS' => $leadData['COMMENTS'] ?? '',
        'SOURCE_ID' => $leadData['SOURCE_ID'] ?? '',
        'SOURCE_DESCRIPTION' => $leadData['SOURCE_DESCRIPTION'] ?? '',
        'ASSIGNED_BY_ID' => $leadData['ASSIGNED_BY_ID'] ?? '',
        'CREATED_BY_ID' => $leadData['CREATED_BY_ID'] ?? '',
        'UTM_SOURCE' => $leadData['UTM_SOURCE'] ?? '',
        'UTM_MEDIUM' => $leadData['UTM_MEDIUM'] ?? '',
        'UTM_CAMPAIGN' => $leadData['UTM_CAMPAIGN'] ?? '',
        'UTM_CONTENT' => $leadData['UTM_CONTENT'] ?? '',
        'UTM_TERM' => $leadData['UTM_TERM'] ?? '',
        'UF_CRM_1744362815' => $leadData['UF_CRM_1744362815'] ?? $userFields['UF_CRM_1744362815'] ?? '', // Город
        'UF_CRM_1745957138' => $leadData['UF_CRM_1745957138'] ?? '', // Исполнитель
        'UF_CRM_1754927102' => $leadData['UF_CRM_1754927102'] ?? '', // Инфоповод
        'OBSERVER_IDS' => $observerIds,
    ];
}

/**
 * Получает SOURCE_ID из списка 19 (для теста, без конфига)
 */
function getSourceIdFromList19ForTest($sourceElementId): ?string
{
    try {
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
        $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
        
        if (file_exists($dbConfigFile)) {
            $dbConfig = include($dbConfigFile);
            $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
            $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
            $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
            $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
        } else {
            $host = 'localhost';
            $dbname = 'sitemanager';
            $username = 'bitrix0';
            $password = '_V5to0nMt5kzN_pR2[HT';
        }
        
        $mysqli = new \mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            return null;
        }
        $mysqli->set_charset("utf8");
        
        $stmt = $mysqli->prepare("
            SELECT p.CODE, ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ? AND p.CODE = 'PROPERTY_73'
        ");
        $stmt->bind_param("i", $sourceElementId);
        $stmt->execute();
        $result = $stmt->get_result();
        $property73 = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($property73 && !empty($property73['VALUE'])) {
            return $property73['VALUE'];
        }
        
        return null;
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Получает ASSIGNED_BY_ID из списка 22 (для теста, без конфига)
 */
function getAssignedByIdFromList22ForTest($cityElementId): ?string
{
    try {
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
        $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
        
        if (file_exists($dbConfigFile)) {
            $dbConfig = include($dbConfigFile);
            $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
            $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
            $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
            $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
        } else {
            $host = 'localhost';
            $dbname = 'sitemanager';
            $username = 'bitrix0';
            $password = '_V5to0nMt5kzN_pR2[HT';
        }
        
        $mysqli = new \mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            return null;
        }
        $mysqli->set_charset("utf8");
        
        $stmt = $mysqli->prepare("
            SELECT p.CODE, ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ? AND p.CODE = 'PROPERTY_185'
        ");
        $stmt->bind_param("i", $cityElementId);
        $stmt->execute();
        $result = $stmt->get_result();
        $property185 = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($property185 && !empty($property185['VALUE'])) {
            return $property185['VALUE'];
        }
        
        return null;
    } catch (\Exception $e) {
        return null;
    }
}

// ---------------- ТЕСТ: CCrmLead::Add (с полными полями как в обработчике) ----------------

function testCrmLeadClassic(): void
{
    if (!class_exists('CCrmLead')) {
        t_print_result('CCrmLead::Add — класс не найден', false);
        return;
    }

    // Получаем данные из ИБ54 по домену
    $ib54Data = testIblock54Domain();
    if (!$ib54Data['element']) {
        t_print_result('CCrmLead::Add (создание тестового лида)', false, [
            'reason' => 'не удалось получить данные из ИБ54, невозможно заполнить все поля',
        ]);
        return;
    }

    $elementData = $ib54Data;
    $properties = $elementData['properties'];
    $elementName = $elementData['element']['NAME'] ?? 'unknown';

    global $USER;
    $userId = is_object($USER) && method_exists($USER, 'GetID') ? (int)$USER->GetID() : 1;
    if ($userId <= 0) {
        $userId = 1;
    }

    // Получаем SOURCE_ID из списка 19
    $sourceId = null;
    if (!empty($properties['PROPERTY_192']['VALUE'])) {
        $sourceElementId = $properties['PROPERTY_192']['VALUE'];
        $sourceId = getSourceIdFromList19ForTest($sourceElementId);
    }

    // Получаем ASSIGNED_BY_ID из списка 22
    $assignedById = $userId;
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $cityElementId = $properties['PROPERTY_191']['VALUE'];
        $assignedByIdFromCity = getAssignedByIdFromList22ForTest($cityElementId);
        if ($assignedByIdFromCity) {
            $assignedById = $assignedByIdFromCity;
        }
    }

    // Формируем поля как в обработчике
    // Разбиваем полное имя на части
    $fullName = 'Тестовый Тест Тестович';
    $nameParts = explode(' ', trim($fullName));
    $name = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    $secondName = $nameParts[2] ?? '';
    
    $fields = [
        'TITLE' => "Лид с сайта [$elementName]",
        'ASSIGNED_BY_ID' => $assignedById,
        'CREATED_BY_ID' => $assignedById,
        'FM' => [
            'PHONE' => [
                'n0' => [
                    'VALUE' => '+79991234567',
                    'VALUE_TYPE' => 'WORK',
                ],
            ],
        ],
        'NAME' => $name,
        'LAST_NAME' => $lastName,
        'SECOND_NAME' => $secondName,
        'COMMENTS' => 'Тестовый лид для проверки внутренних методов Bitrix. Обработать вручную. Создан через CCrmLead::Add.',
        'SOURCE_DESCRIPTION' => 'Сайт: ' . $elementName,
    ];

    // Добавляем SOURCE_ID если найден
    if ($sourceId) {
        $fields['SOURCE_ID'] = $sourceId;
    }

    // Город (UF_CRM_1744362815)
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $fields['UF_CRM_1744362815'] = $properties['PROPERTY_191']['VALUE'];
    }

    // Исполнитель (UF_CRM_1745957138)
    if (!empty($properties['PROPERTY_193']['VALUE'])) {
        $fields['UF_CRM_1745957138'] = $properties['PROPERTY_193']['VALUE'];
    }

    // Инфоповод (UF_CRM_1754927102)
    if (!empty($properties['PROPERTY_194']['VALUE'])) {
        $fields['UF_CRM_1754927102'] = (string)$properties['PROPERTY_194']['VALUE'];
    }

    // Наблюдатели (OBSERVER_IDS)
    if (!empty($properties['PROPERTY_195']['VALUE'])) {
        $observerRaw = $properties['PROPERTY_195']['VALUE'];
        $observerIds = is_array($observerRaw) ? $observerRaw : [$observerRaw];
        $observerIds = array_values(array_filter(array_map(function($v){
            $n = (int)$v; return $n > 0 ? $n : null;
        }, $observerIds), function($v){ return $v !== null; }));
        if (!empty($observerIds)) {
            $fields['OBSERVER_IDS'] = $observerIds;
        }
    }

    $lead = new \CCrmLead();

    $id = $lead->Add($fields, true, ['DISABLE_USER_FIELD_CHECK' => true]);

    if (!$id) {
        t_print_result('CCrmLead::Add (создание тестового лида)', false, [
            'LAST_ERROR' => $lead->LAST_ERROR ?? 'нет текста ошибки',
        ]);
        return;
    }

    // Устанавливаем телефон и UTM поля через CCrmLead::Update
    // Используем формат FM для множественных полей
    $leadUpdate = new \CCrmLead();
    $updateFields = [
        'FM' => [
            'PHONE' => [
                'n0' => [
                    'VALUE' => '+79991234567',
                    'VALUE_TYPE' => 'WORK',
                ],
            ],
        ],
        'UTM_SOURCE' => 'test_source',
        'UTM_MEDIUM' => 'test_medium',
        'UTM_CAMPAIGN' => 'test_campaign',
        'UTM_CONTENT' => 'test_content',
        'UTM_TERM' => 'test_term',
    ];
    $leadUpdate->Update($id, $updateFields, true, ['DISABLE_USER_FIELD_CHECK' => true]);
    
    // Если Update не установил телефон, пробуем через прямой SQL
    $phoneCheck = \CCrmFieldMulti::GetList(
        ['ID' => 'ASC'],
        [
            'ENTITY_ID' => 'LEAD',
            'ELEMENT_ID' => $id,
            'TYPE_ID' => 'PHONE'
        ]
    );
    $hasPhone = $phoneCheck->Fetch() !== false;
    
    if (!$hasPhone) {
        // Пробуем добавить телефон через прямой SQL запрос
        try {
            $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
            $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
            
            if (file_exists($dbConfigFile)) {
                $dbConfig = include($dbConfigFile);
                $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
                $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
                $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
                $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
            } else {
                $host = 'localhost';
                $dbname = 'sitemanager';
                $username = 'bitrix0';
                $password = '_V5to0nMt5kzN_pR2[HT';
            }
            
            $mysqli = new \mysqli($host, $username, $password, $dbname);
            if (!$mysqli->connect_error) {
                $mysqli->set_charset("utf8");
                $stmt = $mysqli->prepare("
                    INSERT INTO b_crm_field_multi (ENTITY_ID, ELEMENT_ID, TYPE_ID, VALUE, VALUE_TYPE)
                    VALUES ('CRM_LEAD', ?, 'PHONE', '+79991234567', 'WORK')
                ");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $mysqli->close();
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки SQL
        }
    }

    // Получаем все поля созданного лида для проверки
    $createdLeadFields = getLeadFieldsForTest($id);
    
    // Проверяем город напрямую через SQL, если не нашли в GetByID
    $cityValue = $createdLeadFields['UF_CRM_1744362815'] ?? '';
    if (empty($cityValue)) {
        try {
            $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
            $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
            
            if (file_exists($dbConfigFile)) {
                $dbConfig = include($dbConfigFile);
                $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
                $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
                $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
                $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
            } else {
                $host = 'localhost';
                $dbname = 'sitemanager';
                $username = 'bitrix0';
                $password = '_V5to0nMt5kzN_pR2[HT';
            }
            
            $mysqli = new \mysqli($host, $username, $password, $dbname);
            if (!$mysqli->connect_error) {
                $mysqli->set_charset("utf8");
                $stmt = $mysqli->prepare("SELECT UF_CRM_1744362815 FROM b_crm_lead WHERE ID = ?");
                if ($stmt !== false) {
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result && $row = $result->fetch_assoc()) {
                        $cityValue = $row['UF_CRM_1744362815'] ?? '';
                    }
                    $stmt->close();
                }
                $mysqli->close();
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки SQL
        }
    }

    t_print_result('CCrmLead::Add (создание тестового лида)', true, [
        'LEAD_ID' => $id,
        'NOTE' => 'Лид НЕ удалён, обработать вручную. Название содержит "ТЕСТ:" для фильтрации.',
        '--- ВСЕ ПОЛЯ СОЗДАННОГО ЛИДА ---' => '',
        'TITLE' => $createdLeadFields['TITLE'] ?? 'не задано',
        'NAME' => $createdLeadFields['NAME'] ?? 'не задано',
        'LAST_NAME' => $createdLeadFields['LAST_NAME'] ?? 'не задано',
        'SECOND_NAME' => $createdLeadFields['SECOND_NAME'] ?? 'не задано',
        'PHONE' => !empty($createdLeadFields['PHONE']) ? json_encode($createdLeadFields['PHONE'], JSON_UNESCAPED_UNICODE) : 'не задано',
        'COMMENTS' => $createdLeadFields['COMMENTS'] ?? 'не задано',
        'SOURCE_ID' => $createdLeadFields['SOURCE_ID'] ?? 'не задано',
        'SOURCE_DESCRIPTION' => $createdLeadFields['SOURCE_DESCRIPTION'] ?? 'не задано',
        'ASSIGNED_BY_ID' => $createdLeadFields['ASSIGNED_BY_ID'] ?? 'не задано',
        'CREATED_BY_ID' => $createdLeadFields['CREATED_BY_ID'] ?? 'не задано',
        'UTM_SOURCE' => $createdLeadFields['UTM_SOURCE'] ?? 'не задано',
        'UTM_MEDIUM' => $createdLeadFields['UTM_MEDIUM'] ?? 'не задано',
        'UTM_CAMPAIGN' => $createdLeadFields['UTM_CAMPAIGN'] ?? 'не задано',
        'UTM_CONTENT' => $createdLeadFields['UTM_CONTENT'] ?? 'не задано',
        'UTM_TERM' => $createdLeadFields['UTM_TERM'] ?? 'не задано',
        'UF_CRM_1744362815 (Город)' => $cityValue ?: 'не задано',
        'UF_CRM_1745957138 (Исполнитель)' => $createdLeadFields['UF_CRM_1745957138'] ?? 'не задано',
        'UF_CRM_1754927102 (Инфоповод)' => $createdLeadFields['UF_CRM_1754927102'] ?? 'не задано',
        'OBSERVER_IDS' => !empty($createdLeadFields['OBSERVER_IDS']) ? (is_array($createdLeadFields['OBSERVER_IDS']) ? implode(', ', $createdLeadFields['OBSERVER_IDS']) : $createdLeadFields['OBSERVER_IDS']) : 'не задано',
    ]);
}

// ---------------- ТЕСТ: D7 \Bitrix\Crm\LeadTable (с полными полями как в обработчике) ----------------

function testCrmLeadD7(): void
{
    if (!class_exists('\Bitrix\Crm\LeadTable')) {
        t_print_result('\Bitrix\Crm\LeadTable::add — класс не найден (вероятно старая версия Bitrix)', false);
        return;
    }

    // Получаем данные из ИБ54 по домену
    $ib54Data = testIblock54Domain();
    if (!$ib54Data['element']) {
        t_print_result('\Bitrix\Crm\LeadTable::add (создание тестового лида)', false, [
            'reason' => 'не удалось получить данные из ИБ54, невозможно заполнить все поля',
        ]);
        return;
    }

    $elementData = $ib54Data;
    $properties = $elementData['properties'];
    $elementName = $elementData['element']['NAME'] ?? 'unknown';

    global $USER;
    $userId = is_object($USER) && method_exists($USER, 'GetID') ? (int)$USER->GetID() : 1;
    if ($userId <= 0) {
        $userId = 1;
    }

    // Получаем SOURCE_ID из списка 19
    $sourceId = null;
    if (!empty($properties['PROPERTY_192']['VALUE'])) {
        $sourceElementId = $properties['PROPERTY_192']['VALUE'];
        $sourceId = getSourceIdFromList19ForTest($sourceElementId);
    }

    // Получаем ASSIGNED_BY_ID из списка 22
    $assignedById = $userId;
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $cityElementId = $properties['PROPERTY_191']['VALUE'];
        $assignedByIdFromCity = getAssignedByIdFromList22ForTest($cityElementId);
        if ($assignedByIdFromCity) {
            $assignedById = $assignedByIdFromCity;
        }
    }

    // Формируем поля как в обработчике
    // ВАЖНО: PHONE нельзя устанавливать через D7 API (read-only ExpressionField)
    // Устанавливаем его отдельно через CCrmLead::Update() после создания
    // Разбиваем полное имя на части
    $fullName = 'Тестовый Тест Тестович';
    $nameParts = explode(' ', trim($fullName));
    $name = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
    $secondName = $nameParts[2] ?? '';
    
    $fields = [
        'TITLE' => "Лид с сайта [$elementName]",
        'ASSIGNED_BY_ID' => $assignedById,
        'CREATED_BY_ID' => $assignedById,
        'MODIFY_BY_ID' => $assignedById,
        'NAME' => $name,
        'LAST_NAME' => $lastName,
        'SECOND_NAME' => $secondName,
        'COMMENTS' => 'Тестовый лид для проверки внутренних методов Bitrix. Обработать вручную. Создан через \Bitrix\Crm\LeadTable::add.',
        'SOURCE_DESCRIPTION' => 'Сайт: ' . $elementName,
    ];

    // Добавляем SOURCE_ID если найден
    if ($sourceId) {
        $fields['SOURCE_ID'] = $sourceId;
    }

    // Город (UF_CRM_1744362815)
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $fields['UF_CRM_1744362815'] = $properties['PROPERTY_191']['VALUE'];
    }

    // Исполнитель (UF_CRM_1745957138)
    if (!empty($properties['PROPERTY_193']['VALUE'])) {
        $fields['UF_CRM_1745957138'] = $properties['PROPERTY_193']['VALUE'];
    }

    // Инфоповод (UF_CRM_1754927102)
    if (!empty($properties['PROPERTY_194']['VALUE'])) {
        $fields['UF_CRM_1754927102'] = (string)$properties['PROPERTY_194']['VALUE'];
    }

    // Наблюдатели (OBSERVER_IDS)
    if (!empty($properties['PROPERTY_195']['VALUE'])) {
        $observerRaw = $properties['PROPERTY_195']['VALUE'];
        $observerIds = is_array($observerRaw) ? $observerRaw : [$observerRaw];
        $observerIds = array_values(array_filter(array_map(function($v){
            $n = (int)$v; return $n > 0 ? $n : null;
        }, $observerIds), function($v){ return $v !== null; }));
        if (!empty($observerIds)) {
            $fields['OBSERVER_IDS'] = $observerIds;
        }
    }

    try {
        /** @var \Bitrix\Main\ORM\Data\AddResult $result */
        $result = \Bitrix\Crm\LeadTable::add($fields);

        if (!$result->isSuccess()) {
            t_print_result('\Bitrix\Crm\LeadTable::add (создание тестового лида)', false, [
                'errors' => $result->getErrorMessages(),
            ]);
            return;
        }

        $id = (int)$result->getId();
        
        // Обновляем PHONE и UTM поля через CCrmLead, так как D7 API не поддерживает их установку напрямую
        // Используем формат FM для множественных полей
        if (class_exists('CCrmLead')) {
            $lead = new \CCrmLead();
            $updateFields = [
                'FM' => [
                    'PHONE' => [
                        'n0' => [
                            'VALUE' => '+79991234567',
                            'VALUE_TYPE' => 'WORK',
                        ],
                    ],
                ],
                'UTM_SOURCE' => 'test_source',
                'UTM_MEDIUM' => 'test_medium',
                'UTM_CAMPAIGN' => 'test_campaign',
                'UTM_CONTENT' => 'test_content',
                'UTM_TERM' => 'test_term',
            ];
            $lead->Update($id, $updateFields, true, ['DISABLE_USER_FIELD_CHECK' => true]);
            
            // Если Update не установил телефон, пробуем через прямой SQL
            $phoneCheck = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'ELEMENT_ID' => $id,
                    'TYPE_ID' => 'PHONE'
                ]
            );
            $hasPhone = $phoneCheck->Fetch() !== false;
            
            if (!$hasPhone) {
                // Пробуем добавить телефон через прямой SQL запрос
                try {
                    $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
                    $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
                    
                    if (file_exists($dbConfigFile)) {
                        $dbConfig = include($dbConfigFile);
                        $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
                        $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
                        $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
                        $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
                    } else {
                        $host = 'localhost';
                        $dbname = 'sitemanager';
                        $username = 'bitrix0';
                        $password = '_V5to0nMt5kzN_pR2[HT';
                    }
                    
                    $mysqli = new \mysqli($host, $username, $password, $dbname);
                    if (!$mysqli->connect_error) {
                        $mysqli->set_charset("utf8");
                        $stmt = $mysqli->prepare("
                            INSERT INTO b_crm_field_multi (ENTITY_ID, ELEMENT_ID, TYPE_ID, VALUE, VALUE_TYPE)
                            VALUES ('CRM_LEAD', ?, 'PHONE', '+79991234567', 'WORK')
                        ");
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $mysqli->close();
                    }
                } catch (\Exception $e) {
                    // Игнорируем ошибки SQL
                }
            }
        }
        
        // Получаем все поля созданного лида для проверки
        $createdLeadFields = getLeadFieldsForTest($id);
        
        // Проверяем город напрямую через SQL, если не нашли в GetByID
        $cityValue = $createdLeadFields['UF_CRM_1744362815'] ?? '';
        if (empty($cityValue)) {
            try {
                $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
                $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
                
                if (file_exists($dbConfigFile)) {
                    $dbConfig = include($dbConfigFile);
                    $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
                    $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
                    $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
                    $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
                } else {
                    $host = 'localhost';
                    $dbname = 'sitemanager';
                    $username = 'bitrix0';
                    $password = '_V5to0nMt5kzN_pR2[HT';
                }
                
                $mysqli = new \mysqli($host, $username, $password, $dbname);
                if (!$mysqli->connect_error) {
                    $mysqli->set_charset("utf8");
                    $stmt = $mysqli->prepare("SELECT UF_CRM_1744362815 FROM b_crm_lead WHERE ID = ?");
                    if ($stmt !== false) {
                        $stmt->bind_param("i", $id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result && $row = $result->fetch_assoc()) {
                            $cityValue = $row['UF_CRM_1744362815'] ?? '';
                        }
                        $stmt->close();
                    }
                    $mysqli->close();
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки SQL
            }
        }

        t_print_result('\Bitrix\Crm\LeadTable::add (создание тестового лида)', true, [
            'LEAD_ID' => $id,
            'NOTE' => 'Лид НЕ удалён, обработать вручную. Название содержит "ТЕСТ:" для фильтрации.',
            '--- ВСЕ ПОЛЯ СОЗДАННОГО ЛИДА ---' => '',
            'TITLE' => $createdLeadFields['TITLE'] ?? 'не задано',
            'NAME' => $createdLeadFields['NAME'] ?? 'не задано',
            'LAST_NAME' => $createdLeadFields['LAST_NAME'] ?? 'не задано',
            'SECOND_NAME' => $createdLeadFields['SECOND_NAME'] ?? 'не задано',
            'PHONE' => !empty($createdLeadFields['PHONE']) ? json_encode($createdLeadFields['PHONE'], JSON_UNESCAPED_UNICODE) : 'не задано',
            'COMMENTS' => $createdLeadFields['COMMENTS'] ?? 'не задано',
            'SOURCE_ID' => $createdLeadFields['SOURCE_ID'] ?? 'не задано',
            'SOURCE_DESCRIPTION' => $createdLeadFields['SOURCE_DESCRIPTION'] ?? 'не задано',
            'ASSIGNED_BY_ID' => $createdLeadFields['ASSIGNED_BY_ID'] ?? 'не задано',
            'CREATED_BY_ID' => $createdLeadFields['CREATED_BY_ID'] ?? 'не задано',
            'UTM_SOURCE' => $createdLeadFields['UTM_SOURCE'] ?? 'не задано',
            'UTM_MEDIUM' => $createdLeadFields['UTM_MEDIUM'] ?? 'не задано',
            'UTM_CAMPAIGN' => $createdLeadFields['UTM_CAMPAIGN'] ?? 'не задано',
            'UTM_CONTENT' => $createdLeadFields['UTM_CONTENT'] ?? 'не задано',
            'UTM_TERM' => $createdLeadFields['UTM_TERM'] ?? 'не задано',
            'UF_CRM_1744362815 (Город)' => $cityValue ?: 'не задано',
            'UF_CRM_1745957138 (Исполнитель)' => $createdLeadFields['UF_CRM_1745957138'] ?? 'не задано',
            'UF_CRM_1754927102 (Инфоповод)' => $createdLeadFields['UF_CRM_1754927102'] ?? 'не задано',
            'OBSERVER_IDS' => !empty($createdLeadFields['OBSERVER_IDS']) ? (is_array($createdLeadFields['OBSERVER_IDS']) ? implode(', ', $createdLeadFields['OBSERVER_IDS']) : $createdLeadFields['OBSERVER_IDS']) : 'не задано',
        ]);
    } catch (\Throwable $e) {
        t_print_result('\Bitrix\Crm\LeadTable::add (создание тестового лида)', false, [
            'exception' => $e->getMessage(),
        ]);
    }
}

// ---------------- ТЕСТ: Чтение ИБ54 по домену (как в обработчике) ----------------

function testIblock54Domain(): array
{
    // Дефолтные значения для теста (можно переопределить через GET параметр)
    $domain = (string)($_GET['test_domain'] ?? 'tgl-vsezubysrazy.ru');
    if ($domain === '') {
        t_print_result('Чтение ИБ54 по домену (NAME)', false, [
            'reason' => 'не передан параметр test_domain в query string',
        ]);
        return ['element' => null, 'properties' => []];
    }

    try {
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
        $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
        
        if (file_exists($dbConfigFile)) {
            $dbConfig = include($dbConfigFile);
            $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
            $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
            $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
            $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
        } else {
            $host = 'localhost';
            $dbname = 'sitemanager';
            $username = 'bitrix0';
            $password = '_V5to0nMt5kzN_pR2[HT';
        }
        
        $mysqli = new \mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            t_print_result('Чтение ИБ54 по домену (NAME)', false, [
                'domain' => $domain,
                'error' => 'Ошибка подключения к БД: ' . $mysqli->connect_error,
            ]);
            return ['element' => null, 'properties' => []];
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = domain (как в обработчике)
        $stmt = $mysqli->prepare("
            SELECT DISTINCT e.ID, e.NAME, e.CODE, e.XML_ID, e.DETAIL_TEXT, e.PREVIEW_TEXT
            FROM b_iblock_element e
            WHERE e.IBLOCK_ID = 54 
            AND e.NAME = ?
        ");
        $stmt->bind_param("s", $domain);
        $stmt->execute();
        $result = $stmt->get_result();
        $element = $result->fetch_assoc();
        
        if (!$element) {
            t_print_result('Чтение ИБ54 по домену (NAME)', false, [
                'domain' => $domain,
                'message' => 'элемент не найден в инфоблоке 54',
            ]);
            $mysqli->close();
            return ['element' => null, 'properties' => []];
        }
        
        // Получаем свойства элемента (как в обработчике)
        $stmt = $mysqli->prepare("
            SELECT p.CODE, p.NAME, ep.VALUE, ep.VALUE_ENUM, ep.VALUE_NUM
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ?
        ");
        $stmt->bind_param("i", $element['ID']);
        $stmt->execute();
        $result = $stmt->get_result();
        $props = $result->fetch_all(MYSQLI_ASSOC);
        
        // Преобразуем свойства в удобный формат (как в обработчике)
        $properties = [];
        foreach ($props as $prop) {
            $code = $prop['CODE'];
            if (!isset($properties[$code])) {
                $properties[$code] = $prop;
            } else {
                if (!isset($properties[$code]['VALUE'])) {
                    $properties[$code]['VALUE'] = $prop['VALUE'];
                } else {
                    $current = $properties[$code]['VALUE'];
                    if (is_array($current)) {
                        $current[] = $prop['VALUE'];
                        $properties[$code]['VALUE'] = $current;
                    } else {
                        $properties[$code]['VALUE'] = [$current, $prop['VALUE']];
                    }
                }
            }
        }
        
        $mysqli->close();
        
        t_print_result('Чтение ИБ54 по домену (NAME)', true, [
            'domain' => $domain,
            'element_id' => $element['ID'],
            'element_name' => $element['NAME'],
            'PROPERTY_192' => $properties['PROPERTY_192']['VALUE'] ?? 'не задано',
            'PROPERTY_191' => $properties['PROPERTY_191']['VALUE'] ?? 'не задано',
            'PROPERTY_193' => $properties['PROPERTY_193']['VALUE'] ?? 'не задано',
            'PROPERTY_194' => $properties['PROPERTY_194']['VALUE'] ?? 'не задано',
            'PROPERTY_195' => $properties['PROPERTY_195']['VALUE'] ?? 'не задано',
        ]);
        
        return [
            'element' => $element,
            'properties' => $properties
        ];
        
    } catch (\Exception $e) {
        t_print_result('Чтение ИБ54 по домену (NAME)', false, [
            'domain' => $domain,
            'error' => $e->getMessage(),
        ]);
        return ['element' => null, 'properties' => []];
    }
}

// ---------------- ТЕСТ: Получение SOURCE_ID из списка 19 (как в обработчике) ----------------

function testSourceFromList19($sourceElementId = null): void
{
    // Если не передан ID, пытаемся получить из ИБ54 через test_domain
    if ($sourceElementId === null) {
        $ib54Data = testIblock54Domain();
        if ($ib54Data['properties']['PROPERTY_192']['VALUE'] ?? null) {
            $sourceElementId = (int)$ib54Data['properties']['PROPERTY_192']['VALUE'];
        } else {
            // Fallback на дефолтное значение или GET параметр
            $sourceElementId = (int)($_GET['test_source_element_id'] ?? 0);
        }
    }
    
    if ($sourceElementId <= 0) {
        t_print_result('Получение SOURCE_ID из списка 19', false, [
            'reason' => 'не передан параметр test_source_element_id и не найден PROPERTY_192 в ИБ54',
        ]);
        return;
    }

    try {
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
        $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
        
        if (file_exists($dbConfigFile)) {
            $dbConfig = include($dbConfigFile);
            $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
            $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
            $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
            $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
        } else {
            $host = 'localhost';
            $dbname = 'sitemanager';
            $username = 'bitrix0';
            $password = '_V5to0nMt5kzN_pR2[HT';
        }
        
        $mysqli = new \mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            t_print_result('Получение SOURCE_ID из списка 19', false, [
                'ELEMENT_ID' => $sourceElementId,
                'error' => 'Ошибка подключения к БД: ' . $mysqli->connect_error,
            ]);
            return;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем PROPERTY_73 из связанного элемента (как в обработчике)
        $stmt = $mysqli->prepare("
            SELECT p.CODE, ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ? AND p.CODE = 'PROPERTY_73'
        ");
        $stmt->bind_param("i", $sourceElementId);
        $stmt->execute();
        $result = $stmt->get_result();
        $property73 = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($property73 && !empty($property73['VALUE'])) {
            $sourceId = $property73['VALUE'];
            t_print_result('Получение SOURCE_ID из списка 19', true, [
                'ELEMENT_ID' => $sourceElementId,
                'SOURCE_ID' => $sourceId,
            ]);
            return;
        }
        
        t_print_result('Получение SOURCE_ID из списка 19', false, [
            'ELEMENT_ID' => $sourceElementId,
            'message' => 'PROPERTY_73 не найден или пуст для элемента',
        ]);
        
    } catch (\Exception $e) {
        t_print_result('Получение SOURCE_ID из списка 19', false, [
            'ELEMENT_ID' => $sourceElementId,
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------- ТЕСТ: Получение ASSIGNED_BY_ID из списка 22 (как в обработчике) ----------------

function testAssignedByFromList22($cityElementId = null): void
{
    // Если не передан ID, пытаемся получить из ИБ54 через test_domain
    if ($cityElementId === null) {
        $ib54Data = testIblock54Domain();
        if ($ib54Data['properties']['PROPERTY_191']['VALUE'] ?? null) {
            $cityElementId = (int)$ib54Data['properties']['PROPERTY_191']['VALUE'];
        } else {
            // Fallback на дефолтное значение или GET параметр
            $cityElementId = (int)($_GET['test_city_element_id'] ?? 0);
        }
    }
    
    if ($cityElementId <= 0) {
        t_print_result('Получение ASSIGNED_BY_ID из списка 22', false, [
            'reason' => 'не передан параметр test_city_element_id и не найден PROPERTY_191 в ИБ54',
        ]);
        return;
    }

    try {
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../../..';
        $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
        
        if (file_exists($dbConfigFile)) {
            $dbConfig = include($dbConfigFile);
            $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
            $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
            $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
            $password = $dbConfig['connections']['value']['default']['password'] ?? '_V5to0nMt5kzN_pR2[HT';
        } else {
            $host = 'localhost';
            $dbname = 'sitemanager';
            $username = 'bitrix0';
            $password = '_V5to0nMt5kzN_pR2[HT';
        }
        
        $mysqli = new \mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            t_print_result('Получение ASSIGNED_BY_ID из списка 22', false, [
                'ELEMENT_ID' => $cityElementId,
                'error' => 'Ошибка подключения к БД: ' . $mysqli->connect_error,
            ]);
            return;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем PROPERTY_185 из связанного элемента (как в обработчике)
        $stmt = $mysqli->prepare("
            SELECT p.CODE, ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ? AND p.CODE = 'PROPERTY_185'
        ");
        $stmt->bind_param("i", $cityElementId);
        $stmt->execute();
        $result = $stmt->get_result();
        $property185 = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($property185 && !empty($property185['VALUE'])) {
            $assignedById = $property185['VALUE'];
            t_print_result('Получение ASSIGNED_BY_ID из списка 22', true, [
                'ELEMENT_ID' => $cityElementId,
                'ASSIGNED_BY_ID' => $assignedById,
            ]);
            return;
        }
        
        t_print_result('Получение ASSIGNED_BY_ID из списка 22', false, [
            'ELEMENT_ID' => $cityElementId,
            'message' => 'PROPERTY_185 не найден или пуст для элемента',
        ]);
        
    } catch (\Exception $e) {
        t_print_result('Получение ASSIGNED_BY_ID из списка 22', false, [
            'ELEMENT_ID' => $cityElementId,
            'error' => $e->getMessage(),
        ]);
    }
}

// ---------------- ЗАПУСК ТЕСТОВ ----------------

echo "Параметры:\n";
$domain = (string)($_GET['test_domain'] ?? 'tgl-vsezubysrazy.ru');
$sourceId = (int)($_GET['test_source_element_id'] ?? 87);
$cityId = (int)($_GET['test_city_element_id'] ?? 9823);
echo "  test_domain            = " . ($domain ?: '(не задано)') . (isset($_GET['test_domain']) ? '' : ' [дефолт]') . "\n";
echo "  test_source_element_id = " . ($sourceId > 0 ? $sourceId : '(не задано)') . (isset($_GET['test_source_element_id']) ? '' : ' [дефолт]') . "\n";
echo "  test_city_element_id   = " . ($cityId > 0 ? $cityId : '(не задано)') . (isset($_GET['test_city_element_id']) ? '' : ' [дефолт]') . "\n";
echo "\n";

echo "ТЕСТЫ CRM ЛИДОВ:\n";
echo "-----------------\n";
testCrmLeadClassic();
testCrmLeadD7();

echo "ТЕСТЫ ИНФОБЛОКОВ:\n";
echo "------------------\n";
testIblock54Domain();
testSourceFromList19();
testAssignedByFromList22();

echo "Готово.\n";

