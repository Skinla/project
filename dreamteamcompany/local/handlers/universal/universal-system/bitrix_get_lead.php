<?php
/**
 * Скрипт для получения всех полей существующего лида
 * 
 * Использование:
 * ?lead_id=468373
 */

use Bitrix\Main\Loader;

// Убедимся, что DOCUMENT_ROOT задан
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$errors = [];

if (!Loader::includeModule('crm')) {
    $errors[] = 'Не удалось подключить модуль crm';
}

header('Content-Type: text/plain; charset=utf-8');

echo "Получение полей лида\n";
echo "====================\n\n";

if ($errors) {
    echo "ОШИБКИ ИНИЦИАЛИЗАЦИИ:\n";
    foreach ($errors as $e) {
        echo "- $e\n";
    }
    echo "\nДальнейшие тесты невозможны.\n";
    exit;
}

/**
 * Получает название элемента из инфоблока по ID
 * Если $iblockId = 0, ищет во всех инфоблоках
 */
function getElementNameById($elementId, $iblockId): string
{
    if (empty($elementId)) {
        return '';
    }
    
    try {
        // Подключаем Bitrix API если еще не подключен
        if (!defined('B_PROLOG_INCLUDED') || !constant('B_PROLOG_INCLUDED')) {
            $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
            $prologPath = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
            if (file_exists($prologPath)) {
                require_once $prologPath;
            }
        }
        
        CModule::IncludeModule("iblock");
        
        if ($iblockId > 0) {
            // Ищем в конкретном инфоблоке
            $filter = [
                'ID' => $elementId,
                'IBLOCK_ID' => $iblockId,
            ];
        } else {
            // Ищем во всех инфоблоках
            $filter = [
                'ID' => $elementId,
            ];
        }
        
        $dbRes = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME']
        );
        
        if ($element = $dbRes->Fetch()) {
            return $element['NAME'] ?? '';
        }
    } catch (\Exception $e) {
        // Игнорируем ошибки
    }
    
    return '';
}

/**
 * Получает лид через REST API (вебхук)
 */
function getLeadByWebhook($leadId): array
{
    // Используем вебхук из параметра или из config.php
    $webhookUrl = $_GET['webhook_url'] ?? null;
    
    if (!$webhookUrl) {
        $configFile = __DIR__ . '/config.php';
        if (file_exists($configFile)) {
            $config = include $configFile;
            $webhookUrl = $config['portal_webhooks']['dreamteamcompany'] ?? null;
        }
    }
    
    // Если не нашли, используем дефолтный вебхук из примера
    if (!$webhookUrl) {
        $webhookUrl = 'https://bitrix.dreamteamcompany.ru/rest/1/j5z6dt6xv7j7sre1/';
    }
    
    $url = $webhookUrl . 'crm.lead.get.json?id=' . $leadId;
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => 'curl error: ' . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => 'HTTP code: ' . $httpCode];
    }
    
    $data = json_decode($result, true);
    if (!$data || !isset($data['result'])) {
        return ['error' => 'неверный формат ответа'];
    }
    
    return $data['result'];
}

/**
 * Получает лид через D7 API
 */
function getLeadByD7($leadId): ?array
{
    if (!class_exists('\Bitrix\Crm\LeadTable')) {
        return ['error' => 'класс LeadTable не найден'];
    }
    
    try {
        $result = \Bitrix\Crm\LeadTable::getById($leadId);
        if (!$result) {
            return null;
        }
        
        $lead = $result->fetch();
        if (!$lead) {
            return null;
        }
        
        // Преобразуем в массив, если это объект
        if (is_object($lead)) {
            return $lead->toArray();
        }
        
        return $lead;
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получает лид через прямой SQL запрос
 */
function getLeadBySQL($leadId): array
{
    try {
        if (!class_exists('CCrmLead')) {
            return ['error' => 'CCrmLead class not found'];
        }
        
        // Получаем основные поля через Bitrix API
        $lead = new \CCrmLead();
        $leadData = $lead->GetByID($leadId);
        
        if (!$leadData) {
            return ['error' => 'лид не найден'];
        }
        
        // Получаем пользовательские поля через Bitrix API
        // Пробуем получить через GetListEx с выборкой всех пользовательских полей
        $userFields = [];
        try {
            if (method_exists('\CCrmLead', 'GetListEx')) {
                $dbRes = \CCrmLead::GetListEx(
                    ['ID' => 'ASC'],
                    ['ID' => $leadId],
                    false,
                    false,
                    ['UF_*'] // Все пользовательские поля
                );
                $leadWithUF = $dbRes->Fetch();
                if ($leadWithUF) {
                    // Извлекаем только пользовательские поля
                    foreach ($leadWithUF as $key => $value) {
                        if (strpos($key, 'UF_') === 0) {
                            $userFields[$key] = $value;
                        }
                    }
                }
            }
            
            // Если GetListEx не доступен или не вернул поля, пробуем из $leadData
            if (empty($userFields)) {
                foreach ($leadData as $key => $value) {
                    if (strpos($key, 'UF_') === 0) {
                        $userFields[$key] = $value;
                    }
                }
            }
        } catch (\Exception $e) {
            // Если ошибка, пробуем из $leadData
            foreach ($leadData as $key => $value) {
                if (strpos($key, 'UF_') === 0) {
                    $userFields[$key] = $value;
                }
            }
        }
        
        // Получаем телефоны через Bitrix API
        $phones = [];
        if (class_exists('CCrmFieldMulti')) {
            $phoneData = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'ELEMENT_ID' => $leadId,
                    'TYPE_ID' => 'PHONE'
                ]
            );
            while ($phone = $phoneData->Fetch()) {
                $phones[] = [
                    'VALUE' => $phone['VALUE'] ?? '',
                    'VALUE_TYPE' => $phone['VALUE_TYPE'] ?? '',
                ];
            }
        }
        
        // Получаем UTM-метки через Bitrix API
        $utmFields = [];
        
        // Сначала пробуем получить из $leadData (они могут быть там)
        if (array_key_exists('UTM_SOURCE', $leadData)) {
            $utmFields['UTM_SOURCE'] = $leadData['UTM_SOURCE'];
        }
        if (array_key_exists('UTM_MEDIUM', $leadData)) {
            $utmFields['UTM_MEDIUM'] = $leadData['UTM_MEDIUM'];
        }
        if (array_key_exists('UTM_CAMPAIGN', $leadData)) {
            $utmFields['UTM_CAMPAIGN'] = $leadData['UTM_CAMPAIGN'];
        }
        if (array_key_exists('UTM_CONTENT', $leadData)) {
            $utmFields['UTM_CONTENT'] = $leadData['UTM_CONTENT'];
        }
        if (array_key_exists('UTM_TERM', $leadData)) {
            $utmFields['UTM_TERM'] = $leadData['UTM_TERM'];
        }
        
        // Если UTM-метки не найдены в $leadData, пробуем через D7 API
        if (empty($utmFields) && class_exists('\Bitrix\Crm\UtmTable')) {
            try {
                $utmResult = \Bitrix\Crm\UtmTable::getList([
                    'filter' => [
                        'ENTITY_TYPE_ID' => 1, // Lead
                        'ENTITY_ID' => $leadId
                    ]
                ]);
                while ($utm = $utmResult->fetch()) {
                    $code = strtoupper($utm['CODE'] ?? '');
                    $value = $utm['VALUE'] ?? '';
                    if ($code === 'SOURCE' || $code === 'UTM_SOURCE') {
                        $utmFields['UTM_SOURCE'] = $value;
                    } elseif ($code === 'MEDIUM' || $code === 'UTM_MEDIUM') {
                        $utmFields['UTM_MEDIUM'] = $value;
                    } elseif ($code === 'CAMPAIGN' || $code === 'UTM_CAMPAIGN') {
                        $utmFields['UTM_CAMPAIGN'] = $value;
                    } elseif ($code === 'CONTENT' || $code === 'UTM_CONTENT') {
                        $utmFields['UTM_CONTENT'] = $value;
                    } elseif ($code === 'TERM' || $code === 'UTM_TERM') {
                        $utmFields['UTM_TERM'] = $value;
                    }
                }
            } catch (\Exception $e) {
                // Игнорируем ошибки при получении UTM-меток через D7 API
            }
        }
        
        // Объединяем данные: сначала $leadData, потом $userFields, потом UTM-поля, потом PHONE
        // Это гарантирует, что UTM-поля не будут перезаписаны
        $result = array_merge($leadData, $userFields, $utmFields, ['PHONE' => $phones]);
        
        return $result;
        
    } catch (\Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

/**
 * Получает все поля лида через CCrmLead::GetByID
 */
function getLeadFields($leadId): array
{
    if (!class_exists('CCrmLead')) {
        return [];
    }

    $lead = new \CCrmLead();
    $leadData = $lead->GetByID($leadId);
    
    if (!$leadData) {
        return [];
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
        $observerIds = array_filter(array_map('trim', explode(',', $observerIds)));
    }
    if (!is_array($observerIds)) {
        $observerIds = [];
    }

    // Получаем пользовательские поля через Bitrix API
    $cityValue = $leadData['UF_CRM_1744362815'] ?? '';
    $executorValue = $leadData['UF_CRM_1745957138'] ?? '';
    $infoReasonValue = $leadData['UF_CRM_1754927102'] ?? '';
    
    // Если пользовательские поля не найдены в GetByID, пробуем через GetListEx
    if (empty($cityValue) && empty($executorValue) && empty($infoReasonValue)) {
        try {
            if (method_exists('\CCrmLead', 'GetListEx')) {
                $dbRes = \CCrmLead::GetListEx(
                    ['ID' => 'ASC'],
                    ['ID' => $leadId],
                    false,
                    false,
                    ['UF_CRM_1744362815', 'UF_CRM_1745957138', 'UF_CRM_1754927102']
                );
                $leadWithUF = $dbRes->Fetch();
                if ($leadWithUF) {
                    if (!empty($leadWithUF['UF_CRM_1744362815'])) {
                        $cityValue = $leadWithUF['UF_CRM_1744362815'];
                    }
                    if (!empty($leadWithUF['UF_CRM_1745957138'])) {
                        $executorValue = $leadWithUF['UF_CRM_1745957138'];
                    }
                    if (!empty($leadWithUF['UF_CRM_1754927102'])) {
                        $infoReasonValue = $leadWithUF['UF_CRM_1754927102'];
                    }
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки при получении пользовательских полей
        }
    }
    
    // Получаем названия элементов
    $cityName = '';
    $executorName = '';
    $infoReasonName = '';
    
    if (!empty($cityValue)) {
        $cityName = getElementNameById($cityValue, 22); // Список 22 - города
    }
    
    if (!empty($executorValue)) {
        // Исполнитель может быть из разных инфоблоков, пробуем найти
        // Обычно это список пользователей или другой инфоблок
        // Пока оставляем как есть, можно добавить определение инфоблока
        $executorName = getElementNameById($executorValue, 0); // 0 означает поиск во всех инфоблоках
    }
    
    if (!empty($infoReasonValue)) {
        // Инфоповод может быть из разных инфоблоков
        $infoReasonName = getElementNameById($infoReasonValue, 0);
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
        'UF_CRM_1744362815' => $cityValue, // Город (ID)
        'UF_CRM_1744362815_NAME' => $cityName, // Город (Название)
        'UF_CRM_1745957138' => $executorValue, // Исполнитель (ID)
        'UF_CRM_1745957138_NAME' => $executorName, // Исполнитель (Название)
        'UF_CRM_1754927102' => $infoReasonValue, // Инфоповод (ID)
        'UF_CRM_1754927102_NAME' => $infoReasonName, // Инфоповод (Название)
        'OBSERVER_IDS' => $observerIds,
    ];
}

// Получаем ID лида из параметра (по умолчанию 468373)
$leadId = (int)($_GET['lead_id'] ?? 468373);

if ($leadId <= 0) {
    echo "ОШИБКА: неверный параметр lead_id\n";
    echo "\nИспользование:\n";
    echo "  ?lead_id=468373\n";
    echo "  или просто откройте файл без параметров (будет использован лид 468373 по умолчанию)\n";
    exit;
}

echo "Параметры:\n";
echo "  lead_id = $leadId" . (isset($_GET['lead_id']) ? '' : ' [дефолт]') . "\n";
echo "\n";

// Получаем данные всеми методами
echo "МЕТОД 1: CCrmLead::GetByID\n";
echo "---------------------------\n";
$leadFieldsCCrm = getLeadFields($leadId);
if (empty($leadFieldsCCrm) || isset($leadFieldsCCrm['error'])) {
    echo "[FAIL] " . ($leadFieldsCCrm['error'] ?? 'лид не найден') . "\n\n";
} else {
    // Дополняем пользовательскими полями через SQL, если их нет (CCrmLead::GetByID может не возвращать их)
    if (empty($leadFieldsCCrm['UF_CRM_1744362815']) || empty($leadFieldsCCrm['UF_CRM_1745957138']) || empty($leadFieldsCCrm['UF_CRM_1754927102'])) {
        $sqlData = getLeadBySQL($leadId);
        if (!isset($sqlData['error'])) {
            if (empty($leadFieldsCCrm['UF_CRM_1744362815'])) {
                $leadFieldsCCrm['UF_CRM_1744362815'] = $sqlData['UF_CRM_1744362815'] ?? '';
                // Получаем название города
                if (!empty($leadFieldsCCrm['UF_CRM_1744362815'])) {
                    $leadFieldsCCrm['UF_CRM_1744362815_NAME'] = getElementNameById($leadFieldsCCrm['UF_CRM_1744362815'], 22);
                }
            }
            if (empty($leadFieldsCCrm['UF_CRM_1745957138'])) {
                $leadFieldsCCrm['UF_CRM_1745957138'] = $sqlData['UF_CRM_1745957138'] ?? '';
                if (!empty($leadFieldsCCrm['UF_CRM_1745957138'])) {
                    $leadFieldsCCrm['UF_CRM_1745957138_NAME'] = getElementNameById($leadFieldsCCrm['UF_CRM_1745957138'], 0);
                }
            }
            if (empty($leadFieldsCCrm['UF_CRM_1754927102'])) {
                $leadFieldsCCrm['UF_CRM_1754927102'] = $sqlData['UF_CRM_1754927102'] ?? '';
                if (!empty($leadFieldsCCrm['UF_CRM_1754927102'])) {
                    $leadFieldsCCrm['UF_CRM_1754927102_NAME'] = getElementNameById($leadFieldsCCrm['UF_CRM_1754927102'], 0);
                }
            }
        }
    }
    
    echo "[OK] Данные получены\n";
    echo "    - TITLE: " . ($leadFieldsCCrm['TITLE'] ?? 'не задано') . "\n";
    echo "    - NAME: " . ($leadFieldsCCrm['NAME'] ?? 'не задано') . "\n";
    echo "    - LAST_NAME: " . ($leadFieldsCCrm['LAST_NAME'] ?? 'не задано') . "\n";
    echo "    - SECOND_NAME: " . ($leadFieldsCCrm['SECOND_NAME'] ?? 'не задано') . "\n";
    echo "    - PHONE: " . (!empty($leadFieldsCCrm['PHONE']) ? json_encode($leadFieldsCCrm['PHONE'], JSON_UNESCAPED_UNICODE) : 'не задано') . "\n";
    echo "    - COMMENTS: " . ($leadFieldsCCrm['COMMENTS'] ?? 'не задано') . "\n";
    echo "    - SOURCE_ID: " . ($leadFieldsCCrm['SOURCE_ID'] ?? 'не задано') . "\n";
    echo "    - SOURCE_DESCRIPTION: " . ($leadFieldsCCrm['SOURCE_DESCRIPTION'] ?? 'не задано') . "\n";
    echo "    - ASSIGNED_BY_ID: " . ($leadFieldsCCrm['ASSIGNED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - CREATED_BY_ID: " . ($leadFieldsCCrm['CREATED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - UTM_SOURCE: " . ($leadFieldsCCrm['UTM_SOURCE'] ?? 'не задано') . "\n";
    echo "    - UTM_MEDIUM: " . ($leadFieldsCCrm['UTM_MEDIUM'] ?? 'не задано') . "\n";
    echo "    - UTM_CAMPAIGN: " . ($leadFieldsCCrm['UTM_CAMPAIGN'] ?? 'не задано') . "\n";
    echo "    - UTM_CONTENT: " . ($leadFieldsCCrm['UTM_CONTENT'] ?? 'не задано') . "\n";
    echo "    - UTM_TERM: " . ($leadFieldsCCrm['UTM_TERM'] ?? 'не задано') . "\n";
    echo "    - UF_CRM_1744362815 (Город): " . ($leadFieldsCCrm['UF_CRM_1744362815'] ?: 'не задано') . 
            ($leadFieldsCCrm['UF_CRM_1744362815_NAME'] ?? '' ? ' (' . $leadFieldsCCrm['UF_CRM_1744362815_NAME'] . ')' : '') . "\n";
    echo "    - UF_CRM_1745957138 (Исполнитель): " . ($leadFieldsCCrm['UF_CRM_1745957138'] ?? 'не задано') . 
            (isset($leadFieldsCCrm['UF_CRM_1745957138_NAME']) && $leadFieldsCCrm['UF_CRM_1745957138_NAME'] ? ' (' . $leadFieldsCCrm['UF_CRM_1745957138_NAME'] . ')' : '') . "\n";
    echo "    - UF_CRM_1754927102 (Инфоповод): " . ($leadFieldsCCrm['UF_CRM_1754927102'] ?? 'не задано') . 
            (isset($leadFieldsCCrm['UF_CRM_1754927102_NAME']) && $leadFieldsCCrm['UF_CRM_1754927102_NAME'] ? ' (' . $leadFieldsCCrm['UF_CRM_1754927102_NAME'] . ')' : '') . "\n";
    $observerIds = $leadFieldsCCrm['OBSERVER_IDS'] ?? '';
    if (is_array($observerIds)) {
        $observerIds = implode(', ', $observerIds);
    }
    echo "    - OBSERVER_IDS: " . (!empty($observerIds) ? $observerIds : 'не задано') . "\n";
}
echo "\n";

echo "МЕТОД 2: REST API (Webhook)\n";
echo "---------------------------\n";
$leadFieldsWebhook = getLeadByWebhook($leadId);
if (isset($leadFieldsWebhook['error'])) {
    echo "[FAIL] " . $leadFieldsWebhook['error'] . "\n\n";
} else {
    echo "[OK] Данные получены\n";
    echo "    - TITLE: " . ($leadFieldsWebhook['TITLE'] ?? 'не задано') . "\n";
    echo "    - NAME: " . ($leadFieldsWebhook['NAME'] ?? 'не задано') . "\n";
    echo "    - LAST_NAME: " . ($leadFieldsWebhook['LAST_NAME'] ?? 'не задано') . "\n";
    echo "    - SECOND_NAME: " . ($leadFieldsWebhook['SECOND_NAME'] ?? 'не задано') . "\n";
    echo "    - PHONE: " . (!empty($leadFieldsWebhook['PHONE']) ? json_encode($leadFieldsWebhook['PHONE'], JSON_UNESCAPED_UNICODE) : 'не задано') . "\n";
    echo "    - COMMENTS: " . ($leadFieldsWebhook['COMMENTS'] ?? 'не задано') . "\n";
    echo "    - SOURCE_ID: " . ($leadFieldsWebhook['SOURCE_ID'] ?? 'не задано') . "\n";
    echo "    - SOURCE_DESCRIPTION: " . ($leadFieldsWebhook['SOURCE_DESCRIPTION'] ?? 'не задано') . "\n";
    echo "    - ASSIGNED_BY_ID: " . ($leadFieldsWebhook['ASSIGNED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - CREATED_BY_ID: " . ($leadFieldsWebhook['CREATED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - UTM_SOURCE: " . ($leadFieldsWebhook['UTM_SOURCE'] ?? 'не задано') . "\n";
    echo "    - UTM_MEDIUM: " . ($leadFieldsWebhook['UTM_MEDIUM'] ?? 'не задано') . "\n";
    echo "    - UTM_CAMPAIGN: " . ($leadFieldsWebhook['UTM_CAMPAIGN'] ?? 'не задано') . "\n";
    echo "    - UTM_CONTENT: " . ($leadFieldsWebhook['UTM_CONTENT'] ?? 'не задано') . "\n";
    echo "    - UTM_TERM: " . ($leadFieldsWebhook['UTM_TERM'] ?? 'не задано') . "\n";
    // Получаем названия элементов для REST API
    $cityName = '';
    $executorName = '';
    $infoReasonName = '';
    
    if (!empty($leadFieldsWebhook['UF_CRM_1744362815'])) {
        $cityName = getElementNameById($leadFieldsWebhook['UF_CRM_1744362815'], 22);
    }
    if (!empty($leadFieldsWebhook['UF_CRM_1745957138'])) {
        $executorName = getElementNameById($leadFieldsWebhook['UF_CRM_1745957138'], 0);
    }
    if (!empty($leadFieldsWebhook['UF_CRM_1754927102'])) {
        $infoReasonName = getElementNameById($leadFieldsWebhook['UF_CRM_1754927102'], 0);
    }
    
    echo "    - UF_CRM_1744362815 (Город): " . ($leadFieldsWebhook['UF_CRM_1744362815'] ?? 'не задано') . 
            ($cityName ? ' (' . $cityName . ')' : '') . "\n";
    echo "    - UF_CRM_1745957138 (Исполнитель): " . ($leadFieldsWebhook['UF_CRM_1745957138'] ?? 'не задано') . 
            ($executorName ? ' (' . $executorName . ')' : '') . "\n";
    echo "    - UF_CRM_1754927102 (Инфоповод): " . ($leadFieldsWebhook['UF_CRM_1754927102'] ?? 'не задано') . 
            ($infoReasonName ? ' (' . $infoReasonName . ')' : '') . "\n";
    $observerIds = $leadFieldsWebhook['OBSERVER_IDS'] ?? '';
    if (is_array($observerIds)) {
        $observerIds = implode(', ', $observerIds);
    }
    echo "    - OBSERVER_IDS: " . (!empty($observerIds) ? $observerIds : 'не задано') . "\n";
}
echo "\n";

echo "МЕТОД 3: D7 API (\\Bitrix\\Crm\\LeadTable)\n";
echo "----------------------------------------\n";
$leadFieldsD7 = getLeadByD7($leadId);
if (!$leadFieldsD7 || isset($leadFieldsD7['error'])) {
    echo "[FAIL] " . ($leadFieldsD7['error'] ?? 'лид не найден') . "\n\n";
} else {
    // Дополняем пользовательскими полями и UTM-метками через CCrmLead::GetByID, если их нет
    if (empty($leadFieldsD7['UF_CRM_1744362815']) || empty($leadFieldsD7['UF_CRM_1745957138']) || empty($leadFieldsD7['UF_CRM_1754927102']) || 
        empty($leadFieldsD7['UTM_SOURCE']) || empty($leadFieldsD7['UTM_MEDIUM']) || empty($leadFieldsD7['UTM_CAMPAIGN']) || 
        empty($leadFieldsD7['UTM_CONTENT']) || empty($leadFieldsD7['UTM_TERM'])) {
        
        // Пробуем получить через CCrmLead::GetByID
        if (class_exists('CCrmLead')) {
            $lead = new \CCrmLead();
            $leadDataCCrm = $lead->GetByID($leadId);
            if ($leadDataCCrm) {
                if (empty($leadFieldsD7['UF_CRM_1744362815'])) {
                    $leadFieldsD7['UF_CRM_1744362815'] = $leadDataCCrm['UF_CRM_1744362815'] ?? '';
                }
                if (empty($leadFieldsD7['UF_CRM_1745957138'])) {
                    $leadFieldsD7['UF_CRM_1745957138'] = $leadDataCCrm['UF_CRM_1745957138'] ?? '';
                }
                if (empty($leadFieldsD7['UF_CRM_1754927102'])) {
                    $leadFieldsD7['UF_CRM_1754927102'] = $leadDataCCrm['UF_CRM_1754927102'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_SOURCE'])) {
                    $leadFieldsD7['UTM_SOURCE'] = $leadDataCCrm['UTM_SOURCE'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_MEDIUM'])) {
                    $leadFieldsD7['UTM_MEDIUM'] = $leadDataCCrm['UTM_MEDIUM'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_CAMPAIGN'])) {
                    $leadFieldsD7['UTM_CAMPAIGN'] = $leadDataCCrm['UTM_CAMPAIGN'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_CONTENT'])) {
                    $leadFieldsD7['UTM_CONTENT'] = $leadDataCCrm['UTM_CONTENT'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_TERM'])) {
                    $leadFieldsD7['UTM_TERM'] = $leadDataCCrm['UTM_TERM'] ?? '';
                }
            }
        }
        
        // Если всё ещё нет, пробуем через SQL
        if (empty($leadFieldsD7['UF_CRM_1744362815']) || empty($leadFieldsD7['UTM_SOURCE'])) {
            $sqlData = getLeadBySQL($leadId);
            if (!isset($sqlData['error'])) {
                if (empty($leadFieldsD7['UF_CRM_1744362815'])) {
                    $leadFieldsD7['UF_CRM_1744362815'] = $sqlData['UF_CRM_1744362815'] ?? '';
                }
                if (empty($leadFieldsD7['UF_CRM_1745957138'])) {
                    $leadFieldsD7['UF_CRM_1745957138'] = $sqlData['UF_CRM_1745957138'] ?? '';
                }
                if (empty($leadFieldsD7['UF_CRM_1754927102'])) {
                    $leadFieldsD7['UF_CRM_1754927102'] = $sqlData['UF_CRM_1754927102'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_SOURCE'])) {
                    $leadFieldsD7['UTM_SOURCE'] = $sqlData['UTM_SOURCE'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_MEDIUM'])) {
                    $leadFieldsD7['UTM_MEDIUM'] = $sqlData['UTM_MEDIUM'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_CAMPAIGN'])) {
                    $leadFieldsD7['UTM_CAMPAIGN'] = $sqlData['UTM_CAMPAIGN'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_CONTENT'])) {
                    $leadFieldsD7['UTM_CONTENT'] = $sqlData['UTM_CONTENT'] ?? '';
                }
                if (empty($leadFieldsD7['UTM_TERM'])) {
                    $leadFieldsD7['UTM_TERM'] = $sqlData['UTM_TERM'] ?? '';
                }
            }
        }
    }
    
    // Получаем телефон через CCrmFieldMulti, так как D7 не возвращает PHONE напрямую
    $phonesD7 = [];
    if (class_exists('CCrmFieldMulti')) {
        $phoneData = \CCrmFieldMulti::GetList(
            ['ID' => 'ASC'],
            [
                'ENTITY_ID' => 'LEAD',
                'ELEMENT_ID' => $leadId,
                'TYPE_ID' => 'PHONE'
            ]
        );
        
        while ($phone = $phoneData->Fetch()) {
            $phonesD7[] = [
                'VALUE' => $phone['VALUE'] ?? '',
                'VALUE_TYPE' => $phone['VALUE_TYPE'] ?? '',
            ];
        }
    }
    
    echo "[OK] Данные получены\n";
    echo "    - TITLE: " . ($leadFieldsD7['TITLE'] ?? 'не задано') . "\n";
    echo "    - NAME: " . ($leadFieldsD7['NAME'] ?? 'не задано') . "\n";
    echo "    - LAST_NAME: " . ($leadFieldsD7['LAST_NAME'] ?? 'не задано') . "\n";
    echo "    - SECOND_NAME: " . ($leadFieldsD7['SECOND_NAME'] ?? 'не задано') . "\n";
    echo "    - PHONE: " . (!empty($phonesD7) ? json_encode($phonesD7, JSON_UNESCAPED_UNICODE) : 'не задано') . "\n";
    echo "    - COMMENTS: " . ($leadFieldsD7['COMMENTS'] ?? 'не задано') . "\n";
    echo "    - SOURCE_ID: " . ($leadFieldsD7['SOURCE_ID'] ?? 'не задано') . "\n";
    echo "    - SOURCE_DESCRIPTION: " . ($leadFieldsD7['SOURCE_DESCRIPTION'] ?? 'не задано') . "\n";
    echo "    - ASSIGNED_BY_ID: " . ($leadFieldsD7['ASSIGNED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - CREATED_BY_ID: " . ($leadFieldsD7['CREATED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - UTM_SOURCE: " . ($leadFieldsD7['UTM_SOURCE'] ?? 'не задано') . "\n";
    echo "    - UTM_MEDIUM: " . ($leadFieldsD7['UTM_MEDIUM'] ?? 'не задано') . "\n";
    echo "    - UTM_CAMPAIGN: " . ($leadFieldsD7['UTM_CAMPAIGN'] ?? 'не задано') . "\n";
    echo "    - UTM_CONTENT: " . ($leadFieldsD7['UTM_CONTENT'] ?? 'не задано') . "\n";
    echo "    - UTM_TERM: " . ($leadFieldsD7['UTM_TERM'] ?? 'не задано') . "\n";
    // Получаем названия элементов для D7
    $cityName = '';
    $executorName = '';
    $infoReasonName = '';
    
    if (!empty($leadFieldsD7['UF_CRM_1744362815'])) {
        $cityName = getElementNameById($leadFieldsD7['UF_CRM_1744362815'], 22);
    }
    if (!empty($leadFieldsD7['UF_CRM_1745957138'])) {
        $executorName = getElementNameById($leadFieldsD7['UF_CRM_1745957138'], 0);
    }
    if (!empty($leadFieldsD7['UF_CRM_1754927102'])) {
        $infoReasonName = getElementNameById($leadFieldsD7['UF_CRM_1754927102'], 0);
    }
    
    echo "    - UF_CRM_1744362815 (Город): " . ($leadFieldsD7['UF_CRM_1744362815'] ?? 'не задано') . 
            ($cityName ? ' (' . $cityName . ')' : '') . "\n";
    echo "    - UF_CRM_1745957138 (Исполнитель): " . ($leadFieldsD7['UF_CRM_1745957138'] ?? 'не задано') . 
            ($executorName ? ' (' . $executorName . ')' : '') . "\n";
    echo "    - UF_CRM_1754927102 (Инфоповод): " . ($leadFieldsD7['UF_CRM_1754927102'] ?? 'не задано') . 
            ($infoReasonName ? ' (' . $infoReasonName . ')' : '') . "\n";
    $observerIds = $leadFieldsD7['OBSERVER_IDS'] ?? '';
    if (is_array($observerIds)) {
        $observerIds = implode(', ', $observerIds);
    }
    echo "    - OBSERVER_IDS: " . (!empty($observerIds) ? $observerIds : 'не задано') . "\n";
    echo "    - NOTE: PHONE получен через CCrmFieldMulti::GetList (D7 не возвращает PHONE напрямую)\n";
}
echo "\n";

echo "МЕТОД 4: Прямой SQL запрос\n";
echo "--------------------------\n";
$leadFieldsSQL = getLeadBySQL($leadId);
if (isset($leadFieldsSQL['error'])) {
    echo "[FAIL] " . $leadFieldsSQL['error'] . "\n\n";
} else {
    echo "[OK] Данные получены\n";
    echo "    - TITLE: " . ($leadFieldsSQL['TITLE'] ?? 'не задано') . "\n";
    echo "    - NAME: " . ($leadFieldsSQL['NAME'] ?? 'не задано') . "\n";
    echo "    - LAST_NAME: " . ($leadFieldsSQL['LAST_NAME'] ?? 'не задано') . "\n";
    echo "    - SECOND_NAME: " . ($leadFieldsSQL['SECOND_NAME'] ?? 'не задано') . "\n";
    echo "    - PHONE: " . (!empty($leadFieldsSQL['PHONE']) ? json_encode($leadFieldsSQL['PHONE'], JSON_UNESCAPED_UNICODE) : 'не задано') . "\n";
    echo "    - COMMENTS: " . ($leadFieldsSQL['COMMENTS'] ?? 'не задано') . "\n";
    echo "    - SOURCE_ID: " . ($leadFieldsSQL['SOURCE_ID'] ?? 'не задано') . "\n";
    echo "    - SOURCE_DESCRIPTION: " . ($leadFieldsSQL['SOURCE_DESCRIPTION'] ?? 'не задано') . "\n";
    echo "    - ASSIGNED_BY_ID: " . ($leadFieldsSQL['ASSIGNED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - CREATED_BY_ID: " . ($leadFieldsSQL['CREATED_BY_ID'] ?? 'не задано') . "\n";
    echo "    - UTM_SOURCE: " . ($leadFieldsSQL['UTM_SOURCE'] ?? 'не задано') . "\n";
    echo "    - UTM_MEDIUM: " . ($leadFieldsSQL['UTM_MEDIUM'] ?? 'не задано') . "\n";
    echo "    - UTM_CAMPAIGN: " . ($leadFieldsSQL['UTM_CAMPAIGN'] ?? 'не задано') . "\n";
    echo "    - UTM_CONTENT: " . ($leadFieldsSQL['UTM_CONTENT'] ?? 'не задано') . "\n";
    echo "    - UTM_TERM: " . ($leadFieldsSQL['UTM_TERM'] ?? 'не задано') . "\n";
    
    // Получаем названия элементов
    $cityName = '';
    $executorName = '';
    $infoReasonName = '';
    
    if (!empty($leadFieldsSQL['UF_CRM_1744362815'])) {
        $cityName = getElementNameById($leadFieldsSQL['UF_CRM_1744362815'], 22);
    }
    if (!empty($leadFieldsSQL['UF_CRM_1745957138'])) {
        $executorName = getElementNameById($leadFieldsSQL['UF_CRM_1745957138'], 0);
    }
    if (!empty($leadFieldsSQL['UF_CRM_1754927102'])) {
        $infoReasonName = getElementNameById($leadFieldsSQL['UF_CRM_1754927102'], 0);
    }
    
    echo "    - UF_CRM_1744362815 (Город): " . ($leadFieldsSQL['UF_CRM_1744362815'] ?? 'не задано') . 
            ($cityName ? ' (' . $cityName . ')' : '') . "\n";
    echo "    - UF_CRM_1745957138 (Исполнитель): " . ($leadFieldsSQL['UF_CRM_1745957138'] ?? 'не задано') . 
            ($executorName ? ' (' . $executorName . ')' : '') . "\n";
    echo "    - UF_CRM_1754927102 (Инфоповод): " . ($leadFieldsSQL['UF_CRM_1754927102'] ?? 'не задано') . 
            ($infoReasonName ? ' (' . $infoReasonName . ')' : '') . "\n";
    $observerIds = $leadFieldsSQL['OBSERVER_IDS'] ?? '';
    if (is_array($observerIds)) {
        $observerIds = implode(', ', $observerIds);
    }
    echo "    - OBSERVER_IDS: " . (!empty($observerIds) ? $observerIds : 'не задано') . "\n";
}
echo "\n";

echo "Готово.\n";

