<?php
/**
 * Функции для работы с лидами через нативный API Bitrix24
 * Использует CCrmLead вместо REST API
 */

require_once(__DIR__ . '/bitrix_init.php');
require_once(__DIR__ . '/helper_functions.php');

/**
 * Явно запускает CRM automation и BizProc после создания или обновления лида.
 *
 * Ошибки автозапуска не должны ломать уже успешное сохранение лида,
 * поэтому здесь только логирование.
 *
 * @param int $leadId ID лида
 * @param int $userId Контекстный пользователь для запуска automation
 * @param array $config Конфигурация
 * @param bool $forUpdate true — событие изменения (только BizProc Edit); false — как при создании
 * @return void
 */
function runLeadAutomationOnCreate($leadId, $userId, $config, $forUpdate = false) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    $leadId = (int)$leadId;
    $userId = (int)$userId;
    $forUpdate = (bool)$forUpdate;

    if ($leadId <= 0) {
        logMessage("runLeadAutomationOnCreate: пропускаем запуск automation/BizProc из-за некорректного leadId=$leadId", $logFile, $config);
        return;
    }

    try {
        if (!$forUpdate) {
            $starter = new \Bitrix\Crm\Automation\Starter(\CCrmOwnerType::Lead, $leadId);
            $starter->setUserId($userId);
            $starter->runOnAdd();
            logMessage("runLeadAutomationOnCreate: CRM robots запущены для лида ID=$leadId, userId=$userId", $logFile, $config);
        }
    } catch (Throwable $e) {
        logMessage("runLeadAutomationOnCreate: ошибка запуска CRM robots для лида ID=$leadId: " . $e->getMessage(), $logFile, $config);
    }

    try {
        $errors = [];
        $bizEvent = $forUpdate ? \CCrmBizProcEventType::Edit : \CCrmBizProcEventType::Create;
        \CCrmBizProcHelper::AutoStartWorkflows(\CCrmOwnerType::LeadName, $leadId, $bizEvent, $errors);
        if (!empty($errors)) {
            logMessage("runLeadAutomationOnCreate: BizProc запущен с ошибками для лида ID=$leadId: " . json_encode($errors, JSON_UNESCAPED_UNICODE), $logFile, $config);
        } else {
            $ctx = $forUpdate ? 'обновление' : 'создание';
            logMessage("runLeadAutomationOnCreate: BizProc успешно запущен ($ctx) для лида ID=$leadId", $logFile, $config);
        }
    } catch (Throwable $e) {
        logMessage("runLeadAutomationOnCreate: ошибка запуска BizProc для лида ID=$leadId: " . $e->getMessage(), $logFile, $config);
    }
}

/**
 * Создание лида через нативный API Bitrix24
 * 
 * @param array $fields Поля лида
 * @param array $config Конфигурация
 * @return array|false Массив с ID лида или false при ошибке
 */
function createLeadDirect($fields, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    logMessage("createLeadDirect: создаем лид через нативный API", $logFile, $config);
    
    try {
        CModule::IncludeModule("crm");
        
        $lead = new \CCrmLead(false);
        
        // Формируем поля для CCrmLead::Add
        $arFields = [];
        
        // Основные поля
        if (!empty($fields['TITLE'])) {
            $arFields['TITLE'] = $fields['TITLE'];
        }
        if (!empty($fields['NAME'])) {
            $arFields['NAME'] = $fields['NAME'];
        }
        if (!empty($fields['LAST_NAME'])) {
            $arFields['LAST_NAME'] = $fields['LAST_NAME'];
        }
        if (!empty($fields['STATUS_ID'])) {
            $arFields['STATUS_ID'] = $fields['STATUS_ID'];
        } else {
            $arFields['STATUS_ID'] = $config['lead']['STATUS_ID'] ?? 'NEW';
        }
        if (!empty($fields['SOURCE_ID'])) {
            $arFields['SOURCE_ID'] = $fields['SOURCE_ID'];
        }
        if (!empty($fields['SOURCE_DESCRIPTION'])) {
            $arFields['SOURCE_DESCRIPTION'] = $fields['SOURCE_DESCRIPTION'];
        }
        if (!empty($fields['ASSIGNED_BY_ID'])) {
            $arFields['ASSIGNED_BY_ID'] = (int)$fields['ASSIGNED_BY_ID'];
        }
        if (!empty($fields['COMMENTS'])) {
            $arFields['COMMENTS'] = $fields['COMMENTS'];
        }
        
        // Пользовательские поля
        foreach ($fields as $key => $value) {
            if (strpos($key, 'UF_CRM_') === 0) {
                $arFields[$key] = $value;
            }
        }
        
        // Телефоны через мультиполя
        if (!empty($fields['PHONE']) && is_array($fields['PHONE'])) {
            logMessage("createLeadDirect: PHONE field before save: " . json_encode($fields['PHONE']), $logFile, $config);
            $arFields['FM'] = [
                'PHONE' => $fields['PHONE']
            ];
            logMessage("createLeadDirect: arFields['FM'] set: " . json_encode($arFields['FM']), $logFile, $config);
        }
        
        // UTM-метки добавляем напрямую в поля лида
        if (!empty($fields['UTM_SOURCE'])) {
            $arFields['UTM_SOURCE'] = $fields['UTM_SOURCE'];
        }
        if (!empty($fields['UTM_MEDIUM'])) {
            $arFields['UTM_MEDIUM'] = $fields['UTM_MEDIUM'];
        }
        if (!empty($fields['UTM_CAMPAIGN'])) {
            $arFields['UTM_CAMPAIGN'] = $fields['UTM_CAMPAIGN'];
        }
        if (!empty($fields['UTM_CONTENT'])) {
            $arFields['UTM_CONTENT'] = $fields['UTM_CONTENT'];
        }
        if (!empty($fields['UTM_TERM'])) {
            $arFields['UTM_TERM'] = $fields['UTM_TERM'];
        }
        
        // Контекстный пользователь берем из ASSIGNED_BY_ID (он приходит из списка 22),
        // чтобы robots/BizProc запускались от того же ответственного.
        $currentUserId = (int)$fields['ASSIGNED_BY_ID'];

        // Создаем лид
        $leadId = $lead->Add($arFields, true, ['REGISTER_SONET_EVENT' => 'Y', 'CURRENT_USER' => $currentUserId]);
        
        if (!$leadId) {
            $error = $lead->LAST_ERROR;
            logMessage("createLeadDirect: ошибка создания лида: " . $error, $logFile, $config);
            return false;
        }

        runLeadAutomationOnCreate((int)$leadId, $currentUserId, $config);
        
        // Проверяем, что реально сохранилось в Bitrix
        if (!empty($arFields['FM']['PHONE']) && is_array($arFields['FM']['PHONE'])) {
            $savedPhone = $arFields['FM']['PHONE'][0]['VALUE'] ?? '';
            logMessage("createLeadDirect: телефон, который был передан в Bitrix: " . $savedPhone, $logFile, $config);
            
            // Получаем сохраненный телефон из Bitrix для проверки
            try {
                $savedLead = \CCrmLead::GetByID($leadId);
                if ($savedLead) {
                    $dbPhones = \CCrmFieldMulti::GetList(
                        ['ID' => 'ASC'],
                        [
                            'ENTITY_ID' => 'LEAD',
                            'TYPE_ID' => 'PHONE',
                            'ELEMENT_ID' => $leadId
                        ]
                    );
                    $actualPhones = [];
                    while ($arPhone = $dbPhones->Fetch()) {
                        $actualPhones[] = $arPhone['VALUE'] ?? '';
                    }
                    logMessage("createLeadDirect: телефоны, которые реально сохранились в Bitrix: " . json_encode($actualPhones), $logFile, $config);
                }
            } catch (Exception $e) {
                logMessage("createLeadDirect: ошибка при проверке сохраненного телефона: " . $e->getMessage(), $logFile, $config);
            }
        }
        
        logMessage("createLeadDirect: лид успешно создан с ID=$leadId", $logFile, $config);
        return ['ID' => $leadId];
        
    } catch (Exception $e) {
        logMessage("createLeadDirect: исключение: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

/**
 * Обновление лида через нативный API
 * 
 * @param int $leadId ID лида
 * @param array $fields Поля для обновления
 * @param array $config Конфигурация
 * @return bool Успешность обновления
 */
function updateLeadDirect($leadId, $fields, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    
    // Проверяем валидность ID лида
    $leadId = (int)$leadId;
    if ($leadId <= 0) {
        logMessage("updateLeadDirect: ошибка - некорректный ID лида: $leadId", $logFile, $config);
        return false;
    }
    
    logMessage("updateLeadDirect: обновляем лид ID=$leadId", $logFile, $config);
    
    try {
        // Подключаем модуль CRM
        if (!CModule::IncludeModule("crm")) {
            logMessage("updateLeadDirect: ошибка - не удалось подключить модуль CRM", $logFile, $config);
            return false;
        }
        
        $lead = new \CCrmLead(false);
        
        // Получаем текущие данные лида для проверки заполненности полей
        $currentLeadData = null;
        try {
            $dbRes = \CCrmLead::GetListEx(
                ['ID' => 'ASC'],
                ['ID' => $leadId, 'CHECK_PERMISSIONS' => 'N'],
                false,
                false,
                [],
                ['QUERY_OPTIONS' => ['LIMIT' => 1]]
            );
            
            if ($dbRes && is_object($dbRes)) {
                $currentLeadData = $dbRes->GetNext();
            }
        } catch (Throwable $e) {
            logMessage("updateLeadDirect: ошибка при получении текущих данных лида ID=$leadId: " . $e->getMessage() . ", продолжаем обновление", $logFile, $config);
        }
        
        // Формируем поля для обновления
        $arFields = [];
        
        // Исключаем поля, которые не должны обновляться (если они уже заполнены)
        $excludedFields = ['ASSIGNED_BY_ID', 'PHONE']; // Ответственный и телефон всегда исключаем
        
        foreach ($fields as $key => $value) {
            if (in_array($key, $excludedFields)) {
                logMessage("updateLeadDirect: пропускаем поле $key (в списке исключений)", $logFile, $config);
                continue;
            }
            
            // Явно исключаем FM['PHONE'] - телефон не должен обновляться
            if ($key === 'FM' && is_array($value) && isset($value['PHONE'])) {
                logMessage("updateLeadDirect: пропускаем FM['PHONE'] - телефон не обновляется при обновлении лида", $logFile, $config);
                // Если в FM есть другие поля кроме PHONE, добавляем их без PHONE
                $fmWithoutPhone = $value;
                unset($fmWithoutPhone['PHONE']);
                if (!empty($fmWithoutPhone)) {
                    $arFields['FM'] = $fmWithoutPhone;
                    logMessage("updateLeadDirect: добавлены другие мультиполя из FM (без PHONE): " . json_encode($fmWithoutPhone), $logFile, $config);
                }
                continue;
            }
            
            if (strpos($key, 'UF_CRM_') === 0) {
                $arFields[$key] = $value;
            } elseif (!in_array($key, ['OBSERVER_IDS'])) {
                $arFields[$key] = $value;
            }
        }
        
        // Явно убеждаемся, что FM['PHONE'] не попадет в $arFields
        if (isset($arFields['FM']) && is_array($arFields['FM']) && isset($arFields['FM']['PHONE'])) {
            logMessage("updateLeadDirect: ВНИМАНИЕ! Обнаружен FM['PHONE'] в arFields, удаляем его", $logFile, $config);
            unset($arFields['FM']['PHONE']);
            // Если FM стал пустым, удаляем его полностью
            if (empty($arFields['FM'])) {
                unset($arFields['FM']);
            }
        }
        
        // Проверяем и защищаем поля ФИО (NAME, LAST_NAME, SECOND_NAME) от перезаписи
        // Если поле уже заполнено реальными данными (не пустое и не значение по умолчанию), не обновляем
        if (!empty($currentLeadData) && is_array($currentLeadData)) {
            $fioFieldsToCheck = ['NAME', 'LAST_NAME', 'SECOND_NAME'];
            foreach ($fioFieldsToCheck as $fioField) {
                if (isset($arFields[$fioField])) {
                    $currentValue = trim($currentLeadData[$fioField] ?? '');
                    $newValue = trim($arFields[$fioField] ?? '');
                    
                    // Значения по умолчанию из prepareLeadFieldsFromCallTouch
                    $defaultValues = [
                        'NAME' => 'Имя',
                        'LAST_NAME' => 'Фамилия',
                        'SECOND_NAME' => ''
                    ];
                    
                    // Если текущее значение заполнено и не является значением по умолчанию - защищаем от перезаписи
                    if (!empty($currentValue) && $currentValue !== ($defaultValues[$fioField] ?? '')) {
                        // Поле уже заполнено реальными данными - не обновляем
                        unset($arFields[$fioField]);
                        logMessage("updateLeadDirect: поле $fioField уже заполнено = '$currentValue', новое значение = '$newValue' - не обновляем (защита от перезаписи)", $logFile, $config);
                    } else {
                        // Текущее значение пустое или по умолчанию - можно обновить
                        if (!empty($newValue)) {
                            logMessage("updateLeadDirect: поле $fioField заполняем = '$newValue' (было: '$currentValue')", $logFile, $config);
                        } else {
                            // Новое значение пустое - удаляем из списка обновления
                            unset($arFields[$fioField]);
                        }
                    }
                }
            }
        } else {
            // Если не удалось получить текущие данные - защищаем ФИО от перезаписи (не обновляем)
            // Это защита от потери данных, если GetListEx не сработал
            $fioFieldsToCheck = ['NAME', 'LAST_NAME', 'SECOND_NAME'];
            foreach ($fioFieldsToCheck as $fioField) {
                if (isset($arFields[$fioField])) {
                    unset($arFields[$fioField]);
                    logMessage("updateLeadDirect: не удалось получить текущие данные лида для проверки ФИО, поле $fioField защищено от перезаписи (безопасность)", $logFile, $config);
                }
            }
        }
        
        // Проверяем и заполняем город (UF_CRM_1744362815), если не заполнен
        if (!empty($fields['UF_CRM_1744362815'])) {
            $currentCity = $currentLeadData['UF_CRM_1744362815'] ?? null;
            if (empty($currentCity)) {
                $arFields['UF_CRM_1744362815'] = $fields['UF_CRM_1744362815'];
                logMessage("updateLeadDirect: заполняем поле город (UF_CRM_1744362815) = " . $fields['UF_CRM_1744362815'] . " (было пусто)", $logFile, $config);
            } else {
                logMessage("updateLeadDirect: поле город (UF_CRM_1744362815) уже заполнено = " . $currentCity . ", не обновляем", $logFile, $config);
            }
        }
        
        // Обновляем источник (SOURCE_ID) в любом случае, если он есть в новых данных
        if (!empty($fields['SOURCE_ID'])) {
            $currentSourceId = $currentLeadData['SOURCE_ID'] ?? null;
            $arFields['SOURCE_ID'] = $fields['SOURCE_ID'];
            if (empty($currentSourceId)) {
                logMessage("updateLeadDirect: заполняем поле источник (SOURCE_ID) = " . $fields['SOURCE_ID'] . " (было пусто)", $logFile, $config);
            } else {
                logMessage("updateLeadDirect: обновляем поле источник (SOURCE_ID) = " . $fields['SOURCE_ID'] . " (было: " . $currentSourceId . ")", $logFile, $config);
            }
        }
        
        // ФИНАЛЬНАЯ ПРОВЕРКА: явно удаляем PHONE из всех возможных мест перед обновлением
        unset($arFields['PHONE']);
        if (isset($arFields['FM']) && is_array($arFields['FM'])) {
            unset($arFields['FM']['PHONE']);
            // Если FM стал пустым, удаляем его полностью
            if (empty($arFields['FM'])) {
                unset($arFields['FM']);
            }
        }
        
        // Проверяем, есть ли поля для обновления
        if (empty($arFields)) {
            logMessage("updateLeadDirect: нет полей для обновления лида ID=$leadId (все поля защищены или пустые)", $logFile, $config);
            return true; // Возвращаем true, так как обновление не требуется
        }
        
        // Логируем финальный список полей для обновления
        logMessage("updateLeadDirect: финальный список полей для обновления (без PHONE): " . json_encode(array_keys($arFields)), $logFile, $config);
        
        // Обновляем лид
        $result = $lead->Update($leadId, $arFields, true, ['REGISTER_SONET_EVENT' => 'Y']);
        
        if (!$result) {
            $error = $lead->LAST_ERROR;
            logMessage("updateLeadDirect: ошибка обновления лида ID=$leadId: " . $error, $logFile, $config);
            return false;
        }

        $automationUserId = (int)$fields['ASSIGNED_BY_ID'];
        runLeadAutomationOnCreate($leadId, $automationUserId, $config, true);
        
        // Обновляем UTM-метки если есть (добавляем напрямую в поля)
        if (!empty($fields['UTM_SOURCE'])) {
            $arFields['UTM_SOURCE'] = $fields['UTM_SOURCE'];
        }
        if (!empty($fields['UTM_MEDIUM'])) {
            $arFields['UTM_MEDIUM'] = $fields['UTM_MEDIUM'];
        }
        if (!empty($fields['UTM_CAMPAIGN'])) {
            $arFields['UTM_CAMPAIGN'] = $fields['UTM_CAMPAIGN'];
        }
        if (!empty($fields['UTM_CONTENT'])) {
            $arFields['UTM_CONTENT'] = $fields['UTM_CONTENT'];
        }
        if (!empty($fields['UTM_TERM'])) {
            $arFields['UTM_TERM'] = $fields['UTM_TERM'];
        }
        
        logMessage("updateLeadDirect: лид ID=$leadId успешно обновлен", $logFile, $config);
        return true;
        
    } catch (Exception $e) {
        logMessage("updateLeadDirect: исключение: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

/**
 * Поиск дубля лида по телефону и паттерну "телефон - Входящий звонок"
 * 
 * @param string $normalizedPhone Нормализованный телефон
 * @param string $originalPhone Оригинальный телефон из запроса (может быть в разных форматах)
 * @param string|null $period Период поиска
 * @param array $config Конфигурация
 * @return int|null ID найденного лида или null
 */
function findDuplicateLeadByPhoneAndIncomingCallPattern($normalizedPhone, $originalPhone, $period, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    
    try {
        CModule::IncludeModule("crm");
        
        // Нормализуем оригинальный телефон для сравнения
        $normalizedOriginal = normalizePhone($originalPhone);
        
        // Пробуем разные варианты формата телефона для поиска
        // Bitrix может хранить телефоны в разных форматах, поэтому пробуем несколько вариантов
        $phoneVariants = [
            $normalizedPhone, // +79049202075
            $normalizedOriginal, // может отличаться
        ];
        
        // Добавляем варианты без + для поиска (если телефон начинается с +7)
        if (strpos($normalizedPhone, '+7') === 0) {
            $phoneWithoutPlus = substr($normalizedPhone, 1); // 79049202075
            $phoneVariants[] = $phoneWithoutPlus;
            $phoneVariants[] = "8" . substr($phoneWithoutPlus, 1); // 89049202075
        }
        
        $phoneVariants = array_unique($phoneVariants);
        
        logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: поиск дубля для телефона $normalizedPhone (оригинал: $originalPhone), пробуем варианты: " . implode(', ', $phoneVariants), $logFile, $config);
        
        // Фильтр по периоду
        $periodFilter = [];
        if ($period) {
            $from = computeDateFromByPeriod($period);
            if ($from !== null) {
                $periodFilter['>=DATE_CREATE'] = $from;
            }
        }
        
        $allFoundLeads = [];
        $checkedCount = 0;
        
        // Ищем по каждому варианту телефона через CCrmFieldMulti
        // Фильтр PHONE в CCrmLead::GetList() работает некорректно, поэтому используем CCrmFieldMulti
        foreach ($phoneVariants as $phoneVariant) {
            logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: поиск через CCrmFieldMulti с VALUE='$phoneVariant'", $logFile, $config);
            
            // Ищем через CCrmFieldMulti по точному совпадению VALUE
            $dbPhones = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    'VALUE' => $phoneVariant
                ]
            );
            
            $leadIdsFromPhone = [];
            while ($arPhone = $dbPhones->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                if ($elementId > 0) {
                    $leadIdsFromPhone[] = $elementId;
                }
            }
            
            // Также пробуем поиск по частичному совпадению (LIKE)
            $dbPhonesLike = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    '%VALUE' => $phoneVariant
                ]
            );
            
            while ($arPhone = $dbPhonesLike->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                $phoneValue = $arPhone['VALUE'] ?? '';
                
                // Проверяем нормализацию для частичного совпадения
                $normalizedPhoneValue = normalizePhone($phoneValue);
                if (($normalizedPhoneValue === $normalizedPhone || $normalizedPhoneValue === $normalizedOriginal) && $elementId > 0) {
                    $leadIdsFromPhone[] = $elementId;
                }
            }
            
            $leadIdsFromPhone = array_unique($leadIdsFromPhone);
            
            if (empty($leadIdsFromPhone)) {
                logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: не найдено лидов с PHONE='$phoneVariant' через CCrmFieldMulti", $logFile, $config);
                continue;
            }
            
            logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: найдено " . count($leadIdsFromPhone) . " лидов с PHONE='$phoneVariant' через CCrmFieldMulti", $logFile, $config);
            
            // Получаем информацию о лидах по найденным ID
            foreach ($leadIdsFromPhone as $leadId) {
                // Пропускаем, если уже проверяли этот лид
                if (isset($allFoundLeads[$leadId])) {
                    continue;
                }
                
                // Применяем фильтр по периоду, если указан
                if (!empty($periodFilter)) {
                    $arLead = \CCrmLead::GetByID($leadId);
                    if (empty($arLead)) {
                        continue;
                    }
                    
                    $dateCreate = $arLead['DATE_CREATE'] ?? '';
                    if (!empty($periodFilter['>=DATE_CREATE']) && !empty($dateCreate)) {
                        $from = $periodFilter['>=DATE_CREATE'];
                        if (strtotime($dateCreate) < strtotime($from)) {
                            logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: лид ID=$leadId пропущен - не попадает в период", $logFile, $config);
                            continue;
                        }
                    }
                }
                
                // Получаем информацию о лиде
                $arLead = \CCrmLead::GetByID($leadId);
                if (empty($arLead)) {
                    continue;
                }
                
                $checkedCount++;
                $leadTitle = $arLead['TITLE'] ?? '';
                
                logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: проверяем лид ID=$leadId, TITLE='$leadTitle'", $logFile, $config);
                
                // Проверяем, соответствует ли заголовок паттерну "телефон - Входящий звонок"
                if (preg_match('/^(.+?)\s*-\s*Входящий\s*звонок$/iu', $leadTitle, $matches)) {
                    $titlePhone = trim($matches[1]);
                    
                    // Нормализуем телефон из заголовка
                    $normalizedTitlePhone = normalizePhone($titlePhone);
                    
                    logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: лид ID=$leadId соответствует паттерну, телефон из заголовка='$titlePhone' (нормализован: '$normalizedTitlePhone')", $logFile, $config);
                    
                    // Сравниваем нормализованные телефоны
                    if ($normalizedTitlePhone === $normalizedPhone || $normalizedTitlePhone === $normalizedOriginal) {
                        logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: ✓ НАЙДЕН ДУБЛЬ! Лид ID=$leadId с заголовком '$leadTitle' и телефоном, совпадающим с запросом", $logFile, $config);
                        return $leadId;
                    } else {
                        logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: лид ID=$leadId не совпадает по телефону из заголовка (нормализованный '$normalizedTitlePhone' != '$normalizedPhone' и != '$normalizedOriginal')", $logFile, $config);
                    }
                } else {
                    logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: лид ID=$leadId не соответствует паттерну 'телефон - Входящий звонок' (заголовок: '$leadTitle')", $logFile, $config);
                }
                
                $allFoundLeads[$leadId] = ['ID' => $leadId, 'TITLE' => $leadTitle, 'PHONE' => $leadPhone];
            }
        }
        
        if ($checkedCount === 0) {
            logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: не найдено лидов с PHONE в вариантах: " . implode(', ', $phoneVariants), $logFile, $config);
        } else {
            $foundLeadsList = array_values($allFoundLeads);
            logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: проверено $checkedCount лидов, дубль не найден. Найденные лиды: " . json_encode($foundLeadsList, JSON_UNESCAPED_UNICODE), $logFile, $config);
        }
        
        return null;
        
    } catch (Exception $e) {
        logMessage("findDuplicateLeadByPhoneAndIncomingCallPattern: исключение: " . $e->getMessage(), $logFile, $config);
        return null;
    }
}

/**
 * Поиск дубля лида по телефону и заголовку
 * 
 * Логика: сначала находим все лиды по телефону (пробуя разные форматы),
 * затем среди найденных проверяем TITLE на соответствие ключевым словам
 * 
 * @param string $normalizedPhone Нормализованный телефон
 * @param array $titleKeywords Ключевые слова для поиска в заголовке
 * @param string|null $period Период поиска
 * @param array $config Конфигурация
 * @return int|null ID найденного лида или null
 */
function findDuplicateLeadByPhoneAndTitleDirect($normalizedPhone, $titleKeywords, $period, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    
    try {
        CModule::IncludeModule("crm");
        
        if (empty($titleKeywords) || !is_array($titleKeywords)) {
            logMessage("findDuplicateLeadByPhoneAndTitleDirect: ключевые слова не указаны, пропускаем поиск", $logFile, $config);
            return null;
        }
        
        // Пробуем разные варианты формата телефона для поиска
        // Bitrix может хранить телефоны в разных форматах
        $phoneVariants = [
            $normalizedPhone, // +79049202075
        ];
        
        // Добавляем варианты без + для поиска (если телефон начинается с +7)
        if (strpos($normalizedPhone, '+7') === 0) {
            $phoneWithoutPlus = substr($normalizedPhone, 1); // 79049202075
            $phoneVariants[] = $phoneWithoutPlus;
            $phoneVariants[] = "8" . substr($phoneWithoutPlus, 1); // 89049202075
        }
        
        $phoneVariants = array_unique($phoneVariants);
        
        logMessage("findDuplicateLeadByPhoneAndTitleDirect: поиск дубля для телефона $normalizedPhone, пробуем варианты: " . implode(', ', $phoneVariants) . ", ключевые слова: " . implode(', ', $titleKeywords), $logFile, $config);
        
        // Фильтр по периоду
        $periodFilter = [];
        if ($period) {
            $from = computeDateFromByPeriod($period);
            if ($from !== null) {
                $periodFilter['>=DATE_CREATE'] = $from;
            }
        }
        
        $allFoundLeads = [];
        $checkedCount = 0;
        
        // ШАГ 1: Ищем все лиды по каждому варианту телефона через CCrmFieldMulti
        // Фильтр PHONE в CCrmLead::GetList() работает некорректно, поэтому используем CCrmFieldMulti
        foreach ($phoneVariants as $phoneVariant) {
            logMessage("findDuplicateLeadByPhoneAndTitleDirect: поиск через CCrmFieldMulti с VALUE='$phoneVariant'", $logFile, $config);
            
            // Ищем через CCrmFieldMulti по точному совпадению VALUE
            $dbPhones = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    'VALUE' => $phoneVariant
                ]
            );
            
            $leadIdsFromPhone = [];
            while ($arPhone = $dbPhones->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                if ($elementId > 0) {
                    $leadIdsFromPhone[] = $elementId;
                }
            }
            
            // Также пробуем поиск по частичному совпадению (LIKE)
            $dbPhonesLike = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    '%VALUE' => $phoneVariant
                ]
            );
            
            while ($arPhone = $dbPhonesLike->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                $phoneValue = $arPhone['VALUE'] ?? '';
                
                // Проверяем нормализацию для частичного совпадения
                $normalizedPhoneValue = normalizePhone($phoneValue);
                if ($normalizedPhoneValue === $normalizedPhone && $elementId > 0) {
                    $leadIdsFromPhone[] = $elementId;
                }
            }
            
            $leadIdsFromPhone = array_unique($leadIdsFromPhone);
            
            if (empty($leadIdsFromPhone)) {
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: не найдено лидов с PHONE='$phoneVariant' через CCrmFieldMulti", $logFile, $config);
                continue;
            }
            
            logMessage("findDuplicateLeadByPhoneAndTitleDirect: найдено " . count($leadIdsFromPhone) . " лидов с PHONE='$phoneVariant' через CCrmFieldMulti, ID лидов: " . implode(', ', $leadIdsFromPhone), $logFile, $config);
            
            // Получаем информацию о лидах по найденным ID
            foreach ($leadIdsFromPhone as $originalLeadId) {
                // Приводим ID к числу
                $leadId = (int)$originalLeadId;
                if ($leadId <= 0) {
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: некорректный ID лида: $leadId (исходное значение: " . var_export($originalLeadId, true) . ") - пропускаем", $logFile, $config);
                    continue;
                }
                
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: обрабатываем лид ID=$leadId (тип: " . gettype($leadId) . ")", $logFile, $config);
                
                // Пропускаем, если уже проверяли этот лид
                if (isset($allFoundLeads[$leadId])) {
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId уже проверен, пропускаем", $logFile, $config);
                    continue;
                }
                
                // Проверяем, что модуль CRM загружен
                if (!CModule::IncludeModule("crm")) {
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: модуль CRM не загружен - пропускаем", $logFile, $config);
                    continue;
                }
                
                // Получаем данные лида по ID
                // Используем GetListEx с CHECK_PERMISSIONS => 'N' (как в форуме Bitrix)
                $arLead = null;
                try {
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: получаем данные лида ID=$leadId (тип: " . gettype($leadId) . ")", $logFile, $config);
                    
                    // Используем GetListEx с отключенной проверкой прав (работает в CLI)
                    $dbRes = \CCrmLead::GetListEx(
                        ['ID' => 'ASC'],
                        ['ID' => $leadId, 'CHECK_PERMISSIONS' => 'N'],
                        false,
                        false,
                        [],
                        ['QUERY_OPTIONS' => ['LIMIT' => 1]]
                    );
                    
                    if ($dbRes && is_object($dbRes)) {
                        $arLead = $dbRes->GetNext();
                        if (!empty($arLead) && is_array($arLead)) {
                            logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId найден через GetListEx, TITLE='" . ($arLead['TITLE'] ?? '') . "'", $logFile, $config);
                        } else {
                            logMessage("findDuplicateLeadByPhoneAndTitleDirect: GetListEx->GetNext() вернул пустой результат для ID=$leadId", $logFile, $config);
                        }
                    } else {
                        logMessage("findDuplicateLeadByPhoneAndTitleDirect: GetListEx вернул некорректный результат для ID=$leadId (тип: " . gettype($dbRes) . ")", $logFile, $config);
                    }
                    
                    // Если GetListEx не сработал, пропускаем этот лид
                    if (empty($arLead) || !is_array($arLead)) {
                        logMessage("findDuplicateLeadByPhoneAndTitleDirect: GetListEx не вернул данные для лида ID=$leadId, пропускаем", $logFile, $config);
                        continue;
                    }
                    
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId найден, TITLE='" . ($arLead['TITLE'] ?? '') . "', DATE_CREATE='" . ($arLead['DATE_CREATE'] ?? '') . "'", $logFile, $config);
                } catch (Throwable $e) {
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: ошибка при получении лида ID=$leadId: " . $e->getMessage() . " в " . $e->getFile() . ":" . $e->getLine(), $logFile, $config);
                    continue;
                }
                
                $leadTitle = $arLead['TITLE'] ?? '';
                $dateCreate = $arLead['DATE_CREATE'] ?? '';
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId найден, TITLE='$leadTitle', DATE_CREATE='$dateCreate'", $logFile, $config);
                
                // Применяем фильтр по периоду, если указан
                if (!empty($periodFilter) && !empty($periodFilter['>=DATE_CREATE']) && !empty($dateCreate)) {
                    $from = $periodFilter['>=DATE_CREATE'];
                    // Bitrix возвращает дату в формате "DD.MM.YYYY HH:MM:SS", конвертируем в timestamp
                    // Пробуем разные форматы
                    $dateCreateTs = false;
                    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $dateCreate, $matches)) {
                        // Формат Bitrix: DD.MM.YYYY HH:MM:SS
                        $dateCreateTs = mktime((int)$matches[4], (int)$matches[5], (int)$matches[6], (int)$matches[2], (int)$matches[1], (int)$matches[3]);
                    } else {
                        // Стандартный формат: YYYY-MM-DD HH:MM:SS
                        $dateCreateTs = strtotime($dateCreate);
                    }
                    $fromTs = strtotime($from);
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId, сравнение дат: DATE_CREATE='$dateCreate' (TS=$dateCreateTs), FROM='$from' (TS=$fromTs)", $logFile, $config);
                    if ($dateCreateTs === false || $dateCreateTs < $fromTs) {
                        logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId пропущен - не попадает в период (DATE_CREATE='$dateCreate' TS=$dateCreateTs < '$from' TS=$fromTs)", $logFile, $config);
                        continue;
                    }
                }
                
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId прошел все проверки, добавляем в список для проверки ключевых слов", $logFile, $config);
                
                // Телефон уже проверен - лид найден через CCrmFieldMulti с точным совпадением VALUE
                // Добавляем лид для проверки TITLE на ключевые слова
                $allFoundLeads[$leadId] = [
                    'ID' => $leadId,
                    'TITLE' => $leadTitle,
                    'DATE_CREATE' => $dateCreate
                ];
            }
        }
        
        if (empty($allFoundLeads)) {
            logMessage("findDuplicateLeadByPhoneAndTitleDirect: не найдено лидов с PHONE в вариантах: " . implode(', ', $phoneVariants), $logFile, $config);
            return null;
        }
        
        logMessage("findDuplicateLeadByPhoneAndTitleDirect: найдено " . count($allFoundLeads) . " лидов по телефону, проверяем TITLE на ключевые слова", $logFile, $config);
        
        // ШАГ 2: Проверяем найденные лиды на соответствие ключевым словам в TITLE
        // Собираем все лиды С ключевыми словами (не возвращаем сразу первый)
        $leadsWithKeywords = [];
        
        foreach ($allFoundLeads as $leadId => $leadData) {
            $checkedCount++;
            $leadTitle = $leadData['TITLE'];
            $dateCreate = $leadData['DATE_CREATE'] ?? '';
            
            logMessage("findDuplicateLeadByPhoneAndTitleDirect: проверяем лид ID=$leadId, TITLE='$leadTitle'", $logFile, $config);
            
            // Проверяем, содержит ли заголовок хотя бы одно из ключевых слов
            $hasKeyword = false;
            foreach ($titleKeywords as $keyword) {
                if (stripos($leadTitle, $keyword) !== false) {
                    $hasKeyword = true;
                    logMessage("findDuplicateLeadByPhoneAndTitleDirect: ✓ лид ID=$leadId содержит ключевое слово '$keyword' в TITLE='$leadTitle'", $logFile, $config);
                    break; // Достаточно одного совпадения
                }
            }
            
            if ($hasKeyword) {
                // Добавляем лид с ключевым словом в список для дальнейшей обработки
                $leadsWithKeywords[] = [
                    'ID' => $leadId,
                    'TITLE' => $leadTitle,
                    'DATE_CREATE' => $dateCreate
                ];
            } else {
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: лид ID=$leadId не содержит ни одного ключевого слова из списка: " . implode(', ', $titleKeywords), $logFile, $config);
            }
        }
        
        // Если найдены лиды с ключевыми словами - возвращаем последний (самый новый)
        if (!empty($leadsWithKeywords)) {
            // Сортируем по DATE_CREATE (последний = самый новый)
            try {
                usort($leadsWithKeywords, function($a, $b) {
                    $dateA = $a['DATE_CREATE'] ?? '';
                    $dateB = $b['DATE_CREATE'] ?? '';
                    
                    // Если даты пустые, ставим их в конец
                    if (empty($dateA) && empty($dateB)) {
                        return 0;
                    }
                    if (empty($dateA)) {
                        return 1; // A идет после B
                    }
                    if (empty($dateB)) {
                        return -1; // B идет после A
                    }
                    
                    // Конвертируем даты в timestamp для сравнения
                    $tsA = false;
                    $tsB = false;
                    
                    // Парсим дату A
                    $matchesA = [];
                    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $dateA, $matchesA)) {
                        $tsA = mktime((int)$matchesA[4], (int)$matchesA[5], (int)$matchesA[6], (int)$matchesA[2], (int)$matchesA[1], (int)$matchesA[3]);
                    } else {
                        $tsA = strtotime($dateA);
                    }
                    
                    // Парсим дату B
                    $matchesB = [];
                    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $dateB, $matchesB)) {
                        $tsB = mktime((int)$matchesB[4], (int)$matchesB[5], (int)$matchesB[6], (int)$matchesB[2], (int)$matchesB[1], (int)$matchesB[3]);
                    } else {
                        $tsB = strtotime($dateB);
                    }
                    
                    // Если не удалось распарсить, ставим в конец
                    if ($tsA === false && $tsB === false) {
                        return 0;
                    }
                    if ($tsA === false) {
                        return 1;
                    }
                    if ($tsB === false) {
                        return -1;
                    }
                    
                    // Сортируем по убыванию (самый новый первый)
                    return ($tsB <=> $tsA);
                });
            } catch (Throwable $e) {
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: ошибка при сортировке лидов: " . $e->getMessage() . " в " . $e->getFile() . ":" . $e->getLine(), $logFile, $config);
                // В случае ошибки сортировки берем первый лид
                $lastLead = $leadsWithKeywords[0];
            }
            
            if (!empty($leadsWithKeywords) && isset($leadsWithKeywords[0])) {
                $lastLead = $leadsWithKeywords[0];
                $lastLeadId = $lastLead['ID'];
                logMessage("findDuplicateLeadByPhoneAndTitleDirect: ✓ НАЙДЕН ДУБЛЬ! Найдено " . count($leadsWithKeywords) . " лидов с ключевыми словами, возвращаем последний (самый новый) ID=$lastLeadId, TITLE='" . $lastLead['TITLE'] . "', DATE_CREATE='" . ($lastLead['DATE_CREATE'] ?? '') . "'", $logFile, $config);
                return $lastLeadId;
            }
        }
        
        // Если не найдено лидов с ключевыми словами - возвращаем null (создадим новый лид)
        logMessage("findDuplicateLeadByPhoneAndTitleDirect: проверено $checkedCount лидов, не найдено лидов с ключевыми словами. Создаем новый лид. Найденные лиды: " . json_encode(array_values($allFoundLeads), JSON_UNESCAPED_UNICODE), $logFile, $config);
        
        return null;
        
    } catch (Exception $e) {
        logMessage("findDuplicateLeadByPhoneAndTitleDirect: исключение: " . $e->getMessage(), $logFile, $config);
        return null;
    }
}

/**
 * Установка наблюдателей для лида
 * 
 * @param int $leadId ID лида
 * @param array $observerIds Массив ID наблюдателей
 * @param array $config Конфигурация
 * @return bool Успешность установки
 */
function setLeadObserversDirect($leadId, $observerIds, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    
    // Нормализация ID
    $observerIds = array_values(array_filter(array_map(function($v){
        $n = (int)$v; 
        return $n > 0 ? $n : null;
    }, $observerIds), function($v){ return $v !== null; }));
    
    if (empty($observerIds)) {
        return true; // нечего ставить
    }
    
    try {
        CModule::IncludeModule("crm");
        
        // Используем CCrmLead::Update для установки наблюдателей
        $lead = new \CCrmLead(false);
        
        // Формируем массив наблюдателей для поля OBSERVER_IDS
        // В Bitrix24 наблюдатели устанавливаются через поле OBSERVER_IDS
        $arFields = [
            'OBSERVER_IDS' => $observerIds
        ];
        
        $result = $lead->Update($leadId, $arFields, true, ['REGISTER_SONET_EVENT' => 'Y']);
        
        if ($result) {
            logMessage("setLeadObserversDirect: наблюдатели успешно установлены для лида ID=$leadId", $logFile, $config);
            return true;
        } else {
            $error = $lead->LAST_ERROR;
            logMessage("setLeadObserversDirect: ошибки при установке наблюдателей: " . $error, $logFile, $config);
            return false;
        }
        
    } catch (Exception $e) {
        logMessage("setLeadObserversDirect: исключение: " . $e->getMessage(), $logFile, $config);
        return false;
    } catch (Error $e) {
        logMessage("setLeadObserversDirect: ошибка: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

