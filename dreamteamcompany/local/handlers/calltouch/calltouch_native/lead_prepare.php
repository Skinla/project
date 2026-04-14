<?php
/**
 * Функции для подготовки полей лида из данных CallTouch
 */

require_once(__DIR__ . '/bitrix_init.php');
require_once(__DIR__ . '/iblock_functions.php');
require_once(__DIR__ . '/helper_functions.php');
require_once(__DIR__ . '/lead_functions.php');

/**
 * Подготовка полей для лида (адаптированная для CallTouch)
 * 
 * @param array $data Данные от CallTouch
 * @param array $elementData Данные элемента из инфоблока 54
 * @param array $config Конфигурация
 * @return array Массив полей для лида
 */
function prepareLeadFieldsFromCallTouch($data, $elementData, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    logMessage("prepareLeadFieldsFromCallTouch: формируем поля для лида", $logFile, $config);
    logMessage("prepareLeadFieldsFromCallTouch: входящие данные: " . json_encode($data), $logFile, $config);
    
    $leadFields = [];
    $properties = $elementData['properties'];
    
    // Основные поля
    $leadFields['TITLE'] = "Звонок с сайта [" . ($elementData['element']['NAME'] ?? 'unknown') . "]";
    
    // Имя и Фамилия
    $name = trim($data['name'] ?? $data['Name'] ?? '');
    if (!empty($name)) {
        $leadFields['NAME'] = $name;
    } else {
        // Если имя не передано, устанавливаем значения по умолчанию
        $leadFields['NAME'] = 'Имя';
        $leadFields['LAST_NAME'] = 'Фамилия';
    }
    
    // Телефон
    $phone = $data['callerphone'] ?? $data['phone'] ?? '';
    if ($phone) {
        // Нормализуем телефон - всегда формат +7XXXXXXXXXX (только цифры и +)
        $phoneBefore = $phone;
        $phone = normalizePhone($phone);
        
        // ПРИНУДИТЕЛЬНАЯ ПРОВЕРКА: если после нормализации нет +7, добавляем принудительно
        if (empty($phone)) {
            // Если normalizePhone вернул пустую строку, пытаемся нормализовать вручную
            $phoneDigits = preg_replace('/\D+/', '', $phoneBefore);
            $digitsLen = strlen($phoneDigits);
            if ($digitsLen === 11 && ($phoneDigits[0] === '7' || $phoneDigits[0] === '8')) {
                $phone = '+7' . substr($phoneDigits, 1);
            } elseif ($digitsLen === 10) {
                $phone = '+7' . $phoneDigits;
            } else {
                logMessage("prepareLeadFieldsFromCallTouch: Phone normalization failed: cannot normalize '$phoneBefore'", $logFile, $config);
                $phone = ''; // Не сохраняем некорректный телефон
            }
        }
        
        // Финальная проверка: телефон должен начинаться с +7 и содержать 10 цифр после +7
        if (!empty($phone) && !preg_match('/^\+7\d{10}$/', $phone)) {
            logMessage("prepareLeadFieldsFromCallTouch: Phone format validation failed: '$phone', trying to fix", $logFile, $config);
            $phoneDigits = preg_replace('/\D+/', '', $phone);
            $digitsLen = strlen($phoneDigits);
            if ($digitsLen === 11 && $phoneDigits[0] === '7') {
                $phone = '+7' . substr($phoneDigits, 1);
            } elseif ($digitsLen === 10) {
                $phone = '+7' . $phoneDigits;
            } else {
                logMessage("prepareLeadFieldsFromCallTouch: Phone format cannot be fixed: '$phone', skipping", $logFile, $config);
                $phone = ''; // Не сохраняем некорректный телефон
            }
        }
        
        if (!empty($phone)) {
            logMessage("prepareLeadFieldsFromCallTouch: Phone normalization: before='$phoneBefore' => after='$phone'", $logFile, $config);
            
            $leadFields['PHONE'] = [
                [
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => $phone
                ]
            ];
            logMessage("prepareLeadFieldsFromCallTouch: PHONE field set: " . json_encode($leadFields['PHONE']), $logFile, $config);
        } else {
            logMessage("prepareLeadFieldsFromCallTouch: Phone normalization failed, PHONE field not set", $logFile, $config);
        }
    }

    // SOURCE_ID из PROPERTY_192
    if (!empty($properties['PROPERTY_192']['VALUE'])) {
        $sourceElementId = $properties['PROPERTY_192']['VALUE'];
        
        // Получаем PROPERTY_73 из связанного элемента в списке 19
        $sourceId = getSourceIdFromList19($sourceElementId, $config);
        if ($sourceId) {
            $leadFields['SOURCE_ID'] = $sourceId;
            logMessage("prepareLeadFieldsFromCallTouch: SOURCE_ID = $sourceId", $logFile, $config);
        }
    }
    
    // ASSIGNED_BY_ID из PROPERTY_185
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $cityElementId = $properties['PROPERTY_191']['VALUE'];
        
        // Получаем ID пользователя из связанного элемента в списке 22
        $assignedById = getAssignedByIdFromList22($cityElementId, $config);
        if ($assignedById) {
            $leadFields['ASSIGNED_BY_ID'] = $assignedById;
            logMessage("prepareLeadFieldsFromCallTouch: ASSIGNED_BY_ID = $assignedById", $logFile, $config);
        }
    }
    
    // Город
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $leadFields['UF_CRM_1744362815'] = $properties['PROPERTY_191']['VALUE'];
    }
    
    // Исполнитель
    if (!empty($properties['PROPERTY_193']['VALUE'])) {
        $leadFields['UF_CRM_1745957138'] = $properties['PROPERTY_193']['VALUE'];
    }
    
    // Комментарий
    $comment = $data['comment'] ?? $data['COMMENTS'] ?? '';
    if ($comment) {
        $leadFields['COMMENTS'] = $comment;
    }

    // Инфоповод из PROPERTY_194 -> UF_CRM_1754927102
    if (!empty($properties['PROPERTY_194']['VALUE'])) {
        $leadFields['UF_CRM_1754927102'] = (string)$properties['PROPERTY_194']['VALUE'];
    }

    // Наблюдатели из PROPERTY_195 -> OBSERVER_IDS (массив ID сотрудников)
    // PROPERTY_195 - множественное свойство типа "Привязка к пользователю"
    if (!empty($properties['PROPERTY_195'])) {
        $observerRaw = $properties['PROPERTY_195']['VALUE'] ?? null;
        
        // Если VALUE_NUM заполнено (для привязки к пользователю), используем его
        if (!empty($properties['PROPERTY_195']['VALUE_NUM'])) {
            $observerRaw = $properties['PROPERTY_195']['VALUE_NUM'];
        }
        
        if (!empty($observerRaw)) {
            // Обрабатываем как массив (множественное свойство)
            $observerIds = is_array($observerRaw) ? $observerRaw : [$observerRaw];
            
            // Нормализуем к массиву положительных целых (ID пользователей)
            $observerIds = array_values(array_filter(array_map(function($v){
                $n = (int)$v; 
                return $n > 0 ? $n : null;
            }, $observerIds), function($v){ return $v !== null; }));
            
            if (!empty($observerIds)) {
                $leadFields['OBSERVER_IDS'] = $observerIds;
                logMessage("prepareLeadFieldsFromCallTouch: найдены наблюдатели из PROPERTY_195: " . implode(', ', $observerIds), $logFile, $config);
            }
        }
    }
    
    // SOURCE_DESCRIPTION для CallTouch
    $leadFields['SOURCE_DESCRIPTION'] = 'CallTouch (siteId=' . ($data['siteId'] ?? '') . ')';
    
    // UTM поля
    logMessage("prepareLeadFieldsFromCallTouch: UTM данные из CallTouch: " . json_encode(array_filter($data, function($k) { return stripos($k, 'utm_') === 0; }, ARRAY_FILTER_USE_KEY)), $logFile, $config);
    addUtmParameters($leadFields, $data);
    
    logMessage("prepareLeadFieldsFromCallTouch: сформированы поля: " . json_encode($leadFields), $logFile, $config);
    
    return $leadFields;
}

/**
 * Создание/обновление лида с данными из списка 54 (нативный API)
 * 
 * @param array $data Данные от CallTouch
 * @param array $config Конфигурация
 * @return array|false Результат создания/обновления или false при ошибке
 */
function createLeadDirectViaNativeFromCallTouch($data, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    logMessage("createLeadDirectViaNativeFromCallTouch: начало выполнения", $logFile, $config);
    
    // Определяем портал из данных CallTouch
    $sourceDomain = getPreferredSourceDomain($data);
    
    // Получаем данные из инфоблока 54 - всегда ищем по паре NAME + siteId
    $elementData = null;
    $siteId = $data['siteId'] ?? '';

    // Определяем ключ имени для поиска в ИБ54
    $nameKey = trim((string)($data['hostname'] ?? ''));
    if ($nameKey === '' && !empty($data['url'])) {
        $nameKey = getDomainUrl((string)$data['url']);
    }
    if ($nameKey === '' && !empty($data['callUrl'])) {
        $nameKey = getDomainUrl((string)$data['callUrl']);
    }
    if ($nameKey === '' && !empty($data['siteName'])) {
        $nameKey = (string)$data['siteName'];
    }
    if ($nameKey === '') {
        $rawSub = trim((string)($data['subPoolName'] ?? ''));
        if (!($rawSub === '' || strcasecmp($rawSub, 'null') === 0)) {
            $nameKey = $rawSub;
        }
    }

    if ($nameKey !== '' && !empty($siteId)) {
        logMessage("createLeadDirectViaNativeFromCallTouch: ищем по паре NAME='$nameKey' + PROPERTY_199='$siteId'", $logFile, $config);
        // Проверяем, является ли источник специальным (Карты или 2Гис)
        // ЗАКОММЕНТИРОВАНО: специальные источники обрабатываются как обычные
        /*
        $isSpecialSource = false;
        $nameKeyLower = mb_strtolower($nameKey, 'UTF-8');
        $normalizedKey = preg_replace('/\s+/', '', $nameKeyLower);
        if (strpos($nameKeyLower, 'карты') !== false || 
            strpos($normalizedKey, '2гис') !== false || 
            strpos($normalizedKey, '2gis') !== false) {
            $isSpecialSource = true;
            logMessage("createLeadDirectViaNativeFromCallTouch: обнаружен специальный источник '$nameKey' - пропускаем дедупликацию и обновления", $logFile, $config);
        }
        */
        $isSpecialSource = false; // Все источники обрабатываются одинаково
        
        logMessage("createLeadDirectViaNativeFromCallTouch: ищем по паре NAME='" . $nameKey . "' + PROPERTY_199='" . $siteId . "'", $logFile, $config);
        $elementData = getElementDataFromIblock54BySubPoolAndSiteId($nameKey, $siteId, $config);
        
        // Если не нашли по hostname/url/siteName, пробуем subPoolName как fallback
        if (!$elementData && !empty($data['subPoolName'])) {
            $rawSub = trim((string)$data['subPoolName']);
            if ($rawSub !== '' && strcasecmp($rawSub, 'null') !== 0 && $rawSub !== $nameKey) {
                logMessage("createLeadDirectViaNativeFromCallTouch: не найден по '$nameKey', пробуем subPoolName='$rawSub'", $logFile, $config);
                $elementData = getElementDataFromIblock54BySubPoolAndSiteId($rawSub, $siteId, $config);
                if ($elementData) {
                    logMessage("createLeadDirectViaNativeFromCallTouch: найдена пара по subPoolName, элемент ID=" . $elementData['element']['ID'], $logFile, $config);
                    $nameKey = $rawSub; // Обновляем nameKey для дальнейшего использования
                }
            }
        }
        
        if ($elementData) {
            logMessage("createLeadDirectViaNativeFromCallTouch: найдена пара, элемент ID=" . $elementData['element']['ID'], $logFile, $config);
        } else {
            logMessage("createLeadDirectViaNativeFromCallTouch: ОШИБКА — не найден элемент по паре NAME='" . $nameKey . "' + PROPERTY_199='" . $siteId . "'", $logFile, $config);
            return ['ERROR_TYPE' => 'name_property199_pair_not_found', 'NAME' => $nameKey, 'SITE_ID' => $siteId];
        }
    } else {
        logMessage("createLeadDirectViaNativeFromCallTouch: ОШИБКА — отсутствует nameKey или siteId для поиска по паре", $logFile, $config);
        return ['ERROR_TYPE' => 'name_property199_pair_not_found', 'NAME' => $nameKey, 'SITE_ID' => $siteId];
    }
    
    if (!$elementData) {
        logMessage("createLeadDirectViaNativeFromCallTouch: не удалось найти элемент в инфоблоке 54", $logFile, $config);
        return false;
    }
    
    // Формируем поля для лида
    $leadFields = prepareLeadFieldsFromCallTouch($data, $elementData, $config);

    // Проверка ctCallerId: если уже видели этот звонок — ничего не делаем, не надо обновлять
    $ctConf = $config['ctCallerId'] ?? [];
    $ctEnabled = (bool)($ctConf['enabled'] ?? false);
    $ctId = (string)($data['ctCallerId'] ?? '');
    if ($ctEnabled && $ctId !== '') {
        $indexFile = $ctConf['index_file'] ?? (__DIR__ . '/ctcallerid_index.json');
        $retention = (string)($ctConf['retention'] ?? '30m');
        $index = loadCtCallerIdIndex($indexFile);
        pruneCtCallerIdIndex($index, $retention);
        if (!empty($index[$ctId]) && !empty($index[$ctId]['leadId'])) {
            $knownLeadId = (int)$index[$ctId]['leadId'];
            logMessage("createLeadDirectViaNativeFromCallTouch: найден ctCallerId=$ctId в индексе → пропускаем обработку (лид ID=$knownLeadId уже существует)", $logFile, $config);
            return ['ID' => $knownLeadId, 'SKIPPED' => true];
        }
    }

    // Дедупликация: при включенной настройке пытаемся найти существующий лид по телефону и заголовку
    $dedupConf = $config['deduplication'] ?? [];
    $dedupEnabled = (bool)($dedupConf['enabled'] ?? false);
    
    if ($dedupEnabled) {
        $searchPhone = $data['callerphone'] ?? ($data['phone'] ?? '');
        $searchPhone = normalizePhone($searchPhone);
        if ($searchPhone) {
            $period = (string)($dedupConf['period'] ?? '30d');
            
            // ЗАКОММЕНТИРОВАНО: проверка паттерна "телефон - Входящий звонок" избыточна,
            // если "Входящий звонок" есть в ключевых словах, так как findDuplicateLeadByPhoneAndTitleDirect
            // найдет те же лиды через stripos(). Оставлено на случай, если понадобится точная проверка паттерна.
            /*
            $originalPhone = $searchPhone; // Сохраняем оригинальный телефон для проверки паттерна
            // Сначала проверяем паттерн "телефон - Входящий звонок" (для всех источников)
            logMessage("createLeadDirectViaNativeFromCallTouch: запускаем поиск дубля по паттерну 'телефон - Входящий звонок' для телефона $searchPhone (оригинал: $originalPhone)", $logFile, $config);
            $existingId = findDuplicateLeadByPhoneAndIncomingCallPattern($searchPhone, $originalPhone, $period, $config);
            
            if ($existingId) {
                logMessage("createLeadDirectViaNativeFromCallTouch: ✓ найден лид вида 'телефон - Входящий звонок' ID=$existingId по телефону $searchPhone — выполняем обновление", $logFile, $config);
                $ok = updateLeadDirect($existingId, $leadFields, $config);
                if ($ok) {
                    // Обрабатываем наблюдателей, если они есть
                    if (!empty($leadFields['OBSERVER_IDS']) && is_array($leadFields['OBSERVER_IDS'])) {
                        setLeadObserversDirect($existingId, $leadFields['OBSERVER_IDS'], $config);
                    }
                    
                    // Если есть ctCallerId, зафиксируем сопоставление
                    if ($ctEnabled && $ctId !== '') {
                        $indexFile = $ctConf['index_file'] ?? (__DIR__ . '/ctcallerid_index.json');
                        $retention = (string)($ctConf['retention'] ?? '30m');
                        $index = loadCtCallerIdIndex($indexFile);
                        pruneCtCallerIdIndex($index, $retention);
                        $index[$ctId] = ['leadId' => $existingId, 'ts' => time()];
                        saveCtCallerIdIndex($indexFile, $index);
                    }
                    return ['ID' => $existingId, 'UPDATED' => true];
                } else {
                    logMessage("createLeadDirectViaNativeFromCallTouch: ошибка обновления лида ID=$existingId, продолжаем пробовать создать новый", $logFile, $config);
                }
            } else {
                logMessage("createLeadDirectViaNativeFromCallTouch: дубль по паттерну 'телефон - Входящий звонок' не найден для телефона $searchPhone", $logFile, $config);
            }
            */
            
            // Проверяем по ключевым словам (если "Входящий звонок" есть в ключевых словах,
            // то проверка паттерна "телефон - Входящий звонок" избыточна, так как findDuplicateLeadByPhoneAndTitleDirect
            // найдет те же лиды через stripos())
            $existingId = null; // Инициализируем переменную
            $titleKeywords = $dedupConf['title_keywords'] ?? [];
            if (!is_array($titleKeywords)) { $titleKeywords = []; }
            if (!empty($titleKeywords)) {
                try {
                    logMessage("createLeadDirectViaNativeFromCallTouch: запускаем поиск дубля по ключевым словам для телефона $searchPhone", $logFile, $config);
                    $existingId = findDuplicateLeadByPhoneAndTitleDirect($searchPhone, $titleKeywords, $period, $config);
                    logMessage("createLeadDirectViaNativeFromCallTouch: поиск дубля завершен, результат: " . ($existingId ? "найден ID=$existingId" : "не найден"), $logFile, $config);
                } catch (Exception $e) {
                    logMessage("createLeadDirectViaNativeFromCallTouch: исключение при поиске дубля: " . $e->getMessage() . ", продолжаем создание нового лида", $logFile, $config);
                    $existingId = null;
                } catch (Error $e) {
                    logMessage("createLeadDirectViaNativeFromCallTouch: ошибка при поиске дубля: " . $e->getMessage() . ", продолжаем создание нового лида", $logFile, $config);
                    $existingId = null;
                }
            }
            if ($existingId) {
                logMessage("createLeadDirectViaNativeFromCallTouch: найден дубль лида ID=$existingId по телефону $searchPhone — выполняем обновление", $logFile, $config);
                $ok = updateLeadDirect($existingId, $leadFields, $config);
                if ($ok) {
                    // Обрабатываем наблюдателей, если они есть
                    if (!empty($leadFields['OBSERVER_IDS']) && is_array($leadFields['OBSERVER_IDS'])) {
                        setLeadObserversDirect($existingId, $leadFields['OBSERVER_IDS'], $config);
                    }
                    
                    // Если есть ctCallerId, зафиксируем сопоставление
                    if ($ctEnabled && $ctId !== '') {
                        $indexFile = $ctConf['index_file'] ?? (__DIR__ . '/ctcallerid_index.json');
                        $retention = (string)($ctConf['retention'] ?? '30m');
                        $index = loadCtCallerIdIndex($indexFile);
                        pruneCtCallerIdIndex($index, $retention);
                        $index[$ctId] = ['leadId' => $existingId, 'ts' => time()];
                        saveCtCallerIdIndex($indexFile, $index);
                    }
                    return ['ID' => $existingId, 'UPDATED' => true];
                } else {
                    logMessage("createLeadDirectViaNativeFromCallTouch: ошибка обновления лида ID=$existingId, продолжаем пробовать создать новый", $logFile, $config);
                }
            }
        }
    }
    
    // Создаем новый лид
    logMessage("createLeadDirectViaNativeFromCallTouch: дубль не найден или дедупликация отключена, создаем новый лид", $logFile, $config);
    $result = createLeadDirect($leadFields, $config);
    
    if ($result) {
        $leadId = (int)$result['ID'];
        
        // Если есть ctCallerId, зафиксируем сопоставление
        // ЗАКОММЕНТИРОВАНО: сохранение ctCallerId для всех источников
        // if ($ctEnabled && $ctId !== '' && !$isSpecialSource) {
        if ($ctEnabled && $ctId !== '') {
            $indexFile = $ctConf['index_file'] ?? (__DIR__ . '/ctcallerid_index.json');
            $retention = (string)($ctConf['retention'] ?? '30m');
            $index = loadCtCallerIdIndex($indexFile);
            pruneCtCallerIdIndex($index, $retention);
            $index[$ctId] = ['leadId' => $leadId, 'ts' => time()];
            saveCtCallerIdIndex($indexFile, $index);
        }
        
        // Обрабатываем наблюдателей, если они есть
        if (!empty($leadFields['OBSERVER_IDS']) && is_array($leadFields['OBSERVER_IDS'])) {
            setLeadObserversDirect($leadId, $leadFields['OBSERVER_IDS'], $config);
        }
        
        // ЗАКОММЕНТИРОВАНО: убрана пометка о специальном источнике
        // logMessage("createLeadDirectViaNativeFromCallTouch: лид успешно создан с ID=$leadId" . ($isSpecialSource ? " (специальный источник)" : ""), $logFile, $config);
        logMessage("createLeadDirectViaNativeFromCallTouch: лид успешно создан с ID=$leadId", $logFile, $config);
        return $result;
    } else {
        logMessage("createLeadDirectViaNativeFromCallTouch: ОШИБКА создания лида", $logFile, $config);
        return false;
    }
}

