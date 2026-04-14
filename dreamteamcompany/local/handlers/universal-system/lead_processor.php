<?php
// lead_processor.php
// Обработчик создания лидов

require_once __DIR__ . '/logger_and_queue.php';
require_once __DIR__ . '/duplicate_checker.php';
require_once __DIR__ . '/request_tracker.php';
require_once __DIR__ . '/error_handler.php';

if (!function_exists('reportLeadProcessingError')) {
function reportLeadProcessingError($normalizedFile, array $normalizedData, $reason, $details, $config, $errorType = 'lead_processor', $normalizerFile = 'lead_processor.php') {
    $errorHandler = new ErrorHandler($config);
    $context = [];

    if (!empty($normalizedData['source_domain'])) {
        $context[] = "domain='" . $normalizedData['source_domain'] . "'";
    }
    if (!empty($normalizedData['phone'])) {
        $context[] = "phone='" . $normalizedData['phone'] . "'";
    }
    if (!empty($normalizedData['request_id'])) {
        $context[] = "request_id='" . $normalizedData['request_id'] . "'";
    }

    if ($context !== []) {
        $details .= ' | ' . implode(', ', $context);
    }

    $errorHandler->handleError($errorType, $normalizedFile, $reason, $details, $normalizerFile);
}
}

/**
 * Обрабатывает нормализованные файлы и создает лиды
 */
if (!function_exists('processNormalizedFiles')) {
function processNormalizedFiles($config) {
    $normalizedDir = $config['queue_dir'] . '/normalized';
    $processedDir = $config['queue_dir'] . '/processed';
    
    // Создаем папки если их нет
    if (!is_dir($normalizedDir)) {
        mkdir($normalizedDir, 0777, true);
    }
    if (!is_dir($processedDir)) {
        mkdir($processedDir, 0777, true);
    }
    
    $normalizedFiles = glob($normalizedDir . '/*.json');
    
    if (empty($normalizedFiles)) {
        logMessage("lead_processor: нет файлов в папке normalized", $config['global_log'], $config);
        return;
    }
    
    // Ограничиваем количество обрабатываемых файлов за раз для избежания зависаний
    $maxFilesPerRun = $config['max_files_per_run'] ?? 50;
    $totalFiles = count($normalizedFiles);
    $filesToProcess = array_slice($normalizedFiles, 0, $maxFilesPerRun);
    
    logMessage("lead_processor: найдено $totalFiles файлов для обработки, обрабатываем " . count($filesToProcess) . " файлов за этот запуск", $config['global_log'], $config);
    
    $duplicateChecker = new DuplicateChecker($config);
    $processedCount = 0;
    $cycleStartTime = microtime(true);
    
    foreach ($filesToProcess as $normalizedFile) {
        // Проверяем таймаут выполнения
        $maxExecutionTime = $config['max_execution_time'] ?? 25;
        $totalElapsed = microtime(true) - $cycleStartTime;
        if ($totalElapsed > $maxExecutionTime) {
            logMessage("lead_processor: достигнут лимит времени выполнения, обработано $processedCount из " . count($filesToProcess) . " файлов", $config['global_log'], $config);
            break;
        }
        try {
            // Блокируем файл для обработки
            $lockFile = $normalizedFile . '.lock';
            if (file_exists($lockFile)) {
                // Проверяем, не устарела ли блокировка
                $lockTimeout = $config['lock_timeout'] ?? 300;
                $lockTime = filemtime($lockFile);
                if (time() - $lockTime > $lockTimeout) {
                    @unlink($lockFile);
                    logMessage("lead_processor: удалена устаревшая блокировка для " . basename($normalizedFile), $config['global_log'], $config);
                } else {
                    logMessage("lead_processor: файл заблокирован, пропускаем: " . basename($normalizedFile), $config['global_log'], $config);
                    continue;
                }
            }
            
            // Создаем блокировку
            @file_put_contents($lockFile, date('Y-m-d H:i:s'));
            
            $normalizedData = json_decode(file_get_contents($normalizedFile), true);
            
            if (!$normalizedData) {
                logMessage("lead_processor: ошибка декодирования JSON в файле " . basename($normalizedFile), $config['global_log'], $config);
                moveToFailed($normalizedFile, $config);
                // Удаляем блокировку
                @unlink($lockFile);
                logMessage("lead_processor: блокировка удалена после ошибки JSON: " . basename($normalizedFile), $config['global_log'], $config);
                continue;
            }
            
            // Проверяем дубли
            $duplicateCheck = $duplicateChecker->checkDuplicate($normalizedData, 'normalized');
            
            if ($duplicateCheck['is_duplicate']) {
                logMessage("lead_processor: найден дубль для файла " . basename($normalizedFile) . ", причина: " . $duplicateCheck['reason'], $config['global_log'], $config);
                
                // Логируем дубль
                $duplicateChecker->logDuplicate($duplicateCheck['duplicate_key'], $duplicateCheck['reason'], $normalizedData);
                
                // Если дубль уже обработан, обновляем request_id со статусом 'success' и lead_id
                if (!empty($normalizedData['request_id']) && $duplicateCheck['reason'] === 'already_processed' && !empty($duplicateCheck['lead_id'])) {
                    $requestTracker = new RequestTracker($config);
                    $requestTracker->updateRequest($normalizedData['request_id'], 'success', $duplicateCheck['lead_id']);
                    logMessage("lead_processor: обновлен request_id={$normalizedData['request_id']} со статусом 'success' (дубль), lead_id={$duplicateCheck['lead_id']}", $config['global_log'], $config);
                }
                
                // Перемещаем в папку дублей
                moveToDuplicates($normalizedFile, $duplicateCheck, $config);
                // Удаляем блокировку
                @unlink($lockFile);
                logMessage("lead_processor: блокировка удалена после дубля: " . basename($normalizedFile), $config['global_log'], $config);
                continue;
            }
            
            // Помечаем как "в обработке"
            $duplicateKey = $duplicateCheck['duplicate_key'] ?? '';
            $duplicateChecker->markAsProcessing($duplicateKey, $normalizedData);
            
            // Создаем лид
            $leadId = createLeadFromNormalizedData($normalizedData, $config);
            
            if ($leadId) {
                // Помечаем как обработанный
                $duplicateChecker->markAsProcessed($duplicateKey, $leadId);
                
                // Обновляем request_id со статусом 'success' и lead_id
                if (!empty($normalizedData['request_id'])) {
                    $requestTracker = new RequestTracker($config);
                    $requestTracker->updateRequest($normalizedData['request_id'], 'success', $leadId);
                    logMessage("lead_processor: обновлен request_id={$normalizedData['request_id']} со статусом 'success', lead_id=$leadId", $config['global_log'], $config);
                }
                
                // Сохраняем результат обработки
                $processedData = [
                    'lead_id' => $leadId,
                    'normalized_data' => $normalizedData,
                    'processed_at' => date('Y-m-d H:i:s'),
                    'status' => 'success',
                    'duplicate_key' => $duplicateKey
                ];
                
                $processedFile = saveToQueue($processedData, $processedDir, 'processed_');
                
                // Удаляем нормализованный файл и блокировку
                if (unlink($normalizedFile)) {
                    logMessage("lead_processor: нормализованный файл удален: " . basename($normalizedFile), $config['global_log'], $config);
                } else {
                    logMessage("lead_processor: ОШИБКА удаления нормализованного файла: " . basename($normalizedFile), $config['global_log'], $config);
                }
                
                // Удаляем блокировку
                @unlink($lockFile);
                logMessage("lead_processor: блокировка удалена после успешной обработки: " . basename($normalizedFile), $config['global_log'], $config);
                
                // Удаляем исходный raw-файл только после успешного создания лида
                if (!empty($normalizedData['raw_file_path']) && file_exists($normalizedData['raw_file_path'])) {
                    if (unlink($normalizedData['raw_file_path'])) {
                        logMessage("lead_processor: raw-файл удален: " . basename($normalizedData['raw_file_path']), $config['global_log'], $config);
                    } else {
                        logMessage("lead_processor: ОШИБКА удаления raw-файла: " . basename($normalizedData['raw_file_path']), $config['global_log'], $config);
                    }
                } else {
                    logMessage("lead_processor: raw-файл не найден для удаления: " . ($normalizedData['raw_file_path'] ?? 'не указан'), $config['global_log'], $config);
                }
                
                $processedCount++;
                logMessage("lead_processor: лид создан ID=$leadId для файла " . basename($normalizedFile) . " -> " . basename($processedFile), $config['global_log'], $config);
                
            } else {
                @unlink($lockFile);
                logMessage("lead_processor: ошибка создания лида для файла " . basename($normalizedFile), $config['global_log'], $config);
                
                // Обновляем request_id со статусом 'failed' при ошибке создания лида
                if (!empty($normalizedData['request_id'])) {
                    $requestTracker = new RequestTracker($config);
                    $requestTracker->updateRequest($normalizedData['request_id'], 'failed', null);
                    logMessage("lead_processor: обновлен request_id={$normalizedData['request_id']} со статусом 'failed'", $config['global_log'], $config);
                }
                
                // ВАЖНО: Помечаем как обработанный даже при ошибке, чтобы не блокировать повторную обработку
                $duplicateChecker->markAsProcessed($duplicateKey, null);
                logMessage("lead_processor: телефон помечен как обработанный (с ошибкой) для предотвращения блокировки: " . $duplicateKey, $config['global_log'], $config);
                
                reportLeadProcessingError(
                    $normalizedFile,
                    is_array($normalizedData) ? $normalizedData : [],
                    'lead_creation_failed',
                    'Bitrix24 не вернул ID лида после обработки normalized-файла',
                    $config
                );
                
                // Удаляем нормализованный файл после копирования в queue_errors
                if (unlink($normalizedFile)) {
                    logMessage("lead_processor: нормализованный файл удален после ошибки: " . basename($normalizedFile), $config['global_log'], $config);
                } else {
                    logMessage("lead_processor: ОШИБКА удаления нормализованного файла после ошибки: " . basename($normalizedFile), $config['global_log'], $config);
                }
                
                // Удаляем блокировку
                @unlink($lockFile);
                logMessage("lead_processor: блокировка удалена после ошибки: " . basename($normalizedFile), $config['global_log'], $config);
            }
            
        } catch (Exception $e) {
            logMessage("lead_processor: ошибка обработки файла " . basename($normalizedFile) . ": " . $e->getMessage(), $config['global_log'], $config);
            
            // Обновляем request_id со статусом 'failed' при исключении
            if (isset($normalizedData['request_id']) && !empty($normalizedData['request_id'])) {
                $requestTracker = new RequestTracker($config);
                $requestTracker->updateRequest($normalizedData['request_id'], 'failed', null);
                logMessage("lead_processor: обновлен request_id={$normalizedData['request_id']} со статусом 'failed' (исключение)", $config['global_log'], $config);
            }
            
            // ВАЖНО: Помечаем как обработанный даже при исключении, чтобы не блокировать повторную обработку
            if (isset($duplicateKey)) {
                $duplicateChecker->markAsProcessed($duplicateKey, null);
                logMessage("lead_processor: телефон помечен как обработанный (с исключением) для предотвращения блокировки: " . $duplicateKey, $config['global_log'], $config);
            }
            
            reportLeadProcessingError(
                $normalizedFile,
                isset($normalizedData) && is_array($normalizedData) ? $normalizedData : [],
                'lead_processing_exception',
                $e->getMessage(),
                $config
            );
            
            // Удаляем нормализованный файл после копирования в queue_errors
            if (unlink($normalizedFile)) {
                logMessage("lead_processor: нормализованный файл удален после исключения: " . basename($normalizedFile), $config['global_log'], $config);
            } else {
                logMessage("lead_processor: ОШИБКА удаления нормализованного файла после исключения: " . basename($normalizedFile), $config['global_log'], $config);
            }
            
            // Удаляем блокировку
            @unlink($lockFile);
            logMessage("lead_processor: блокировка удалена после исключения: " . basename($normalizedFile), $config['global_log'], $config);
        } catch (Throwable $e) {
            @unlink($lockFile);
            logMessage("lead_processor: критическая ошибка обработки файла " . basename($normalizedFile) . ": " . $e->getMessage(), $config['global_log'], $config);
            
            // Обновляем request_id со статусом 'failed' при критической ошибке
            if (isset($normalizedData) && is_array($normalizedData) && !empty($normalizedData['request_id'])) {
                $requestTracker = new RequestTracker($config);
                $requestTracker->updateRequest($normalizedData['request_id'], 'failed', null);
                logMessage("lead_processor: обновлен request_id={$normalizedData['request_id']} со статусом 'failed' (критическая ошибка)", $config['global_log'], $config);
            }
            
            if (isset($duplicateKey)) {
                $duplicateChecker->markAsProcessed($duplicateKey, null);
            }
            
            reportLeadProcessingError(
                $normalizedFile,
                isset($normalizedData) && is_array($normalizedData) ? $normalizedData : [],
                'lead_processing_throwable',
                $e->getMessage(),
                $config
            );
            @unlink($normalizedFile);
        }
    }
    
    if ($processedCount > 0) {
        logMessage("lead_processor: обработка завершена, обработано $processedCount файлов", $config['global_log'], $config);
        // После успешной обработки партии ограничиваем размер папки processed
        cleanupOldProcessedFiles($config, 200);
    }
    }
}

/**
 * Создает лид из нормализованных данных
 */
function createLeadFromNormalizedData($normalizedData, $config) {
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Начало обработки лида
    $phone = $normalizedData['phone'] ?? '';
    $name = $normalizedData['name'] ?? '';
    $domain = $normalizedData['source_domain'] ?? '';
    logMessage("LEAD_PROCESSING_START | Phone: $phone | Name: '$name' | Domain: $domain", $config['global_log'], $config);
    
    // БЛОКИРОВКА: Проверяем, что телефон не пустой
    if (empty(trim($phone))) {
        logMessage("LEAD_PROCESSING_BLOCKED | Телефон пустой, лид не создается | Phone: '$phone' | Name: '$name' | Domain: $domain", $config['global_log'], $config);
        return null;
    }
    
    // Получаем вебхук для dreamteamcompany
    $webhookUrl = $config['portal_webhooks']['dreamteamcompany'] ?? null;
    
    if (!$webhookUrl) {
        logMessage("lead_processor: не настроен webhook для dreamteamcompany", $config['global_log'], $config);
        return null;
    }
    
    // Формируем поля для лида
    $assignedById = $config['assigned_by_id'] ?? 1;
    $leadFields = [
        'ASSIGNED_BY_ID' => $assignedById,
        'CREATED_BY_ID' => $assignedById, // Создаем лид от имени ответственного
        'PHONE' => [
            ['VALUE' => $normalizedData['phone'], 'VALUE_TYPE' => 'WORK']
        ],
        'COMMENTS' => $normalizedData['comment']
    ];
    
    // Добавляем имя если есть, иначе устанавливаем "Имя"
    if (!empty($normalizedData['name']) && !empty(trim($normalizedData['name']))) {
        $leadFields['NAME'] = trim($normalizedData['name']);
        logMessage("LEAD_FIELDS_BASIC | NAME установлено: '" . $leadFields['NAME'] . "'", $config['global_log'], $config);
        
        // Если имя есть, фамилию устанавливаем ТОЛЬКО если она реально есть
        $lastName = $normalizedData['last_name'] ?? $normalizedData['surname'] ?? $normalizedData['lastName'] ?? $normalizedData['LAST_NAME'] ?? '';
        if (!empty($lastName) && !empty(trim($lastName))) {
            $leadFields['LAST_NAME'] = trim($lastName);
            logMessage("LEAD_FIELDS_BASIC | LAST_NAME установлено: '" . $leadFields['LAST_NAME'] . "'", $config['global_log'], $config);
        }
        // Если фамилии нет, не устанавливаем поле LAST_NAME вообще
    } else {
        // Если имя отсутствует, устанавливаем "Имя" и "Фамилия"
        $leadFields['NAME'] = 'Имя';
        $leadFields['LAST_NAME'] = 'Фамилия';
        logMessage("LEAD_FIELDS_BASIC | NAME пустое, установлено 'Имя' и 'Фамилия'", $config['global_log'], $config);
    }
    
    // Добавляем UTM поля
    if (!empty($normalizedData['utm_source'])) {
        $leadFields['UTM_SOURCE'] = $normalizedData['utm_source'];
    }
    if (!empty($normalizedData['utm_medium'])) {
        $leadFields['UTM_MEDIUM'] = $normalizedData['utm_medium'];
    }
    if (!empty($normalizedData['utm_campaign'])) {
        $leadFields['UTM_CAMPAIGN'] = $normalizedData['utm_campaign'];
    }
    if (!empty($normalizedData['utm_content'])) {
        $leadFields['UTM_CONTENT'] = $normalizedData['utm_content'];
    }
    if (!empty($normalizedData['utm_term'])) {
        $leadFields['UTM_TERM'] = $normalizedData['utm_term'];
    }
    
            // Создаем лид (с поддержкой CallTouch)
            $leadId = createLeadWithCallTouchSupport($leadFields, $webhookUrl, $normalizedData, $config);
    
    if ($leadId) {
        logMessage("lead_processor: лид создан ID=$leadId для домена " . $normalizedData['source_domain'], $config['global_log'], $config);
    } else {
        logMessage("lead_processor: ошибка создания лида для домена " . $normalizedData['source_domain'], $config['global_log'], $config);
    }
    
    return $leadId;
}

/**
 * Создает лид с универсальной поддержкой инфоблока 54
 */
function createLeadWithCallTouchSupport($leadFields, $webhookUrl, $normalizedData, $config) {
    $elementData = null;
    
    // Проверяем, есть ли данные CallTouch (поиск по двум полям)
    if (isset($normalizedData['calltouch_data'])) {
        logMessage("createLeadWithCallTouchSupport: обнаружены данные CallTouch, используем инфоблок 54 по subPoolName + siteId", $config['global_log'], $config);
        
        $callTouchData = $normalizedData['calltouch_data'];
        $elementData = getElementDataFromIblock54BySubPoolAndSiteId(
            $callTouchData['subPoolName'] ?? '',
            $callTouchData['siteId'] ?? '',
            $config
        );
    } else {
        // Для остальных типов данных ищем по домену (одно поле)
        $domain = $normalizedData['source_domain'] ?? '';
        if ($domain && $domain !== 'unknown') {
            logMessage("createLeadWithCallTouchSupport: ищем элемент в инфоблоке 54 по домену '$domain'", $config['global_log'], $config);
            
            $elementData = getElementDataFromIblock54ByDomain($domain, $config);
        }
    }
    
    if ($elementData) {
        // СКВОЗНОЕ ЛОГИРОВАНИЕ: Перед обновлением полей
        logMessage("LEAD_FIELDS_BEFORE_IB54 | NAME: '" . ($leadFields['NAME'] ?? '') . "' | TITLE: '" . ($leadFields['TITLE'] ?? '') . "'", $config['global_log'], $config);
        
        // Обновляем поля лида данными из инфоблока 54
        $leadFields = updateLeadFieldsFromIblock54($leadFields, $elementData, $normalizedData, $config);
        
        // СКВОЗНОЕ ЛОГИРОВАНИЕ: После обновления полей
        logMessage("LEAD_FIELDS_AFTER_IB54 | NAME: '" . ($leadFields['NAME'] ?? '') . "' | TITLE: '" . ($leadFields['TITLE'] ?? '') . "'", $config['global_log'], $config);
        logMessage("createLeadWithCallTouchSupport: поля лида обновлены данными из инфоблока 54", $config['global_log'], $config);
    } else {
        logMessage("createLeadWithCallTouchSupport: элемент не найден в инфоблоке 54, используем стандартные поля", $config['global_log'], $config);
    }
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Финальные поля перед отправкой
    logMessage("LEAD_FIELDS_FINAL | NAME: '" . ($leadFields['NAME'] ?? '') . "' | TITLE: '" . ($leadFields['TITLE'] ?? '') . "' | PHONE: '" . ($leadFields['PHONE'][0]['VALUE'] ?? '') . "'", $config['global_log'], $config);
    
    // СВОДНО: компактный payload, который уходит в Bitrix24 (одной строкой)
    $payloadCompact = json_encode($leadFields, JSON_UNESCAPED_UNICODE);
    logMessage("LEAD_PAYLOAD_FINAL | " . $payloadCompact, $config['global_log'], $config);
    
    // Создаем лид стандартным способом
    $result = createLead($leadFields, $webhookUrl, $config);
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Результат создания лида
    if ($result && isset($result['result'])) {
        $leadId = $result['result'];
        logMessage("LEAD_PROCESSING_END | Status: SUCCESS | Lead ID: " . $leadId, $config['global_log'], $config);
        
        // ВРЕМЕННО ОТКЛЮЧЕНО: Запуск бизнес-процесса отключен из-за проблем с работой
        // Запускаем бизнес-процесс после успешного создания лида (если есть данные из инфоблока 54)
        // ВАЖНО: Оборачиваем в try-catch, чтобы ошибки бизнес-процесса не влияли на возврат leadId
        // и не нарушали логику проверки дублей
        /*
        try {
            if ($elementData && isset($elementData['properties'])) {
                $properties = $elementData['properties'];
                
                // Получаем PROPERTY_440 (ID бизнес-процесса) из элемента инфоблока 54
                if (isset($properties['PROPERTY_440']) && isset($properties['PROPERTY_440']['VALUE'])) {
                    $businessProcessId = $properties['PROPERTY_440']['VALUE'];
                    
                    // Проверяем, что значение не пустое и может быть преобразовано в число
                    $businessProcessId = trim((string)$businessProcessId);
                    if (!empty($businessProcessId) && is_numeric($businessProcessId)) {
                        $assignedById = $leadFields['ASSIGNED_BY_ID'] ?? null;
                        
                        if ($assignedById && is_numeric($assignedById) && (int)$assignedById > 0) {
                            // webhookUrl передается для совместимости, но не используется при прямом вызове Bitrix API
                            startBusinessProcess($webhookUrl, $businessProcessId, $leadId, (int)$assignedById, $config);
                        } else {
                            logMessage("startBusinessProcess: ASSIGNED_BY_ID не найден или невалиден ($assignedById), бизнес-процесс не запущен", $config['global_log'], $config);
                        }
                    } else {
                        logMessage("startBusinessProcess: PROPERTY_440 содержит невалидное значение: '$businessProcessId'", $config['global_log'], $config);
                    }
                } else {
                    logMessage("startBusinessProcess: PROPERTY_440 не найден в элементе инфоблока 54", $config['global_log'], $config);
                }
            } else {
                logMessage("startBusinessProcess: elementData отсутствует или не содержит properties, бизнес-процесс не запущен", $config['global_log'], $config);
            }
        } catch (\Exception $e) {
            // КРИТИЧНО: Логируем ошибку, но НЕ прерываем выполнение - лид уже создан!
            logMessage("startBusinessProcess: исключение при запуске бизнес-процесса (не критично, лид создан): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
        } catch (\Throwable $e) {
            // КРИТИЧНО: Логируем ошибку, но НЕ прерываем выполнение - лид уже создан!
            logMessage("startBusinessProcess: критическая ошибка при запуске бизнес-процесса (не критично, лид создан): " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
        }
        */
        
        return $leadId; // ВАЖНО: Всегда возвращаем leadId, даже если бизнес-процесс не запустился
    } else {
        logMessage("LEAD_PROCESSING_END | Status: ERROR | Response: " . json_encode($result, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        return null; // Возвращаем null при ошибке
    }
}

/**
 * Создает лид через Bitrix24 API
 */
function createLead($fields, $webhookUrl, $config = null) {
    // Если config не передан, используем дефолтные значения
    if ($config === null) {
        $config = ['global_log' => 'global.log', 'logs_dir' => __DIR__ . '/logs'];
    }
    
    $maxRetries = $config['max_retries'] ?? 3;
    $retryDelay = $config['retry_delay'] ?? 1000000; // 1 секунда
    
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $qUrl = $webhookUrl . 'crm.lead.add.json';
            
            // СКВОЗНОЕ ЛОГИРОВАНИЕ: Запрос к Bitrix24
            logMessage("BITRIX24_REQUEST | URL: $qUrl | Fields: " . json_encode($fields, JSON_UNESCAPED_UNICODE), $config['global_log'] ?? 'global.log', $config);
            
            // Дополнительное логирование для OBSERVER_IDS
            if (isset($fields['OBSERVER_IDS'])) {
                logMessage("BITRIX24_OBSERVER_IDS | OBSERVER_IDS in fields: " . json_encode($fields['OBSERVER_IDS'], JSON_UNESCAPED_UNICODE), $config['global_log'] ?? 'global.log', $config);
            } else {
                logMessage("BITRIX24_OBSERVER_IDS | OBSERVER_IDS not found in fields", $config['global_log'] ?? 'global.log', $config);
            }
            
            // Формируем данные в правильном формате для Bitrix API
            $qData = [];
            foreach ($fields as $key => $value) {
                if (is_array($value)) {
                    // Специальная обработка для OBSERVER_IDS - массив ID пользователей
                    if ($key === 'OBSERVER_IDS') {
                        // Для OBSERVER_IDS передаем как массив через JSON или множественные параметры
                        foreach ($value as $index => $observerId) {
                            $qData["fields[{$key}][{$index}]"] = (int)$observerId;
                        }
                    } elseif ($key === 'PHONE') {
                        // Для PHONE используем специальный формат
                        foreach ($value as $index => $item) {
                            if (is_array($item)) {
                                foreach ($item as $subKey => $subValue) {
                                    $qData["fields[{$key}][{$index}][{$subKey}]"] = $subValue;
                                }
                            } else {
                                $qData["fields[{$key}][{$index}]"] = $item;
                            }
                        }
                    } else {
                        // Для других массивов (например, UTM)
                        foreach ($value as $index => $item) {
                            if (is_array($item)) {
                                foreach ($item as $subKey => $subValue) {
                                    $qData["fields[{$key}][{$index}][{$subKey}]"] = $subValue;
                                }
                            } else {
                                $qData["fields[{$key}][{$index}]"] = $item;
                            }
                        }
                    }
                } else {
                    $qData["fields[{$key}]"] = $value;
                }
            }
            $qData['params[REGISTER_SONET_EVENT]'] = 'Y';
            
            // Логируем сформированные данные для OBSERVER_IDS
            $observerDataInQuery = [];
            foreach ($qData as $key => $val) {
                if (strpos($key, 'OBSERVER_IDS') !== false) {
                    $observerDataInQuery[$key] = $val;
                }
            }
            if (!empty($observerDataInQuery)) {
                logMessage("BITRIX24_OBSERVER_IDS_QUERY | OBSERVER_IDS in query data: " . json_encode($observerDataInQuery, JSON_UNESCAPED_UNICODE), $config['global_log'] ?? 'global.log', $config);
            } else {
                logMessage("BITRIX24_OBSERVER_IDS_QUERY | OBSERVER_IDS not found in query data", $config['global_log'] ?? 'global.log', $config);
            }
            
            $qData = http_build_query($qData);
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $qUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $qData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => $config['curl_timeout'] ?? 30
            ]);
            $result = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // СКВОЗНОЕ ЛОГИРОВАНИЕ: Ответ от Bitrix24
            logMessage("BITRIX24_RESPONSE | HTTP Code: $httpCode | Response: $result", $config['global_log'] ?? 'global.log', $config);
            
            if ($error) {
                throw new Exception("CURL ошибка: $error");
            }
            
            if ($httpCode !== 200) {
                throw new Exception("HTTP код $httpCode, ответ: $result");
            }
            
            $response = json_decode($result, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON ошибка: " . json_last_error_msg());
            }
            
            if (isset($response['result'])) {
                return $response; // Возвращаем полный ответ для логирования
            } else {
                throw new Exception("Ошибка в ответе: " . json_encode($response));
            }
            
        } catch (Exception $e) {
            if ($attempt === $maxRetries) {
                throw $e;
            }
            usleep($retryDelay);
        }
    }
    
    return false;
}

/**
 * Получает данные элемента из инфоблока 54 по subPoolName и siteId
 */
function getElementDataFromIblock54BySubPoolAndSiteId($subPoolName, $siteId, $config) {
    logMessage("getElementDataFromIblock54BySubPoolAndSiteId: ищем элемент с NAME='$subPoolName' и PROPERTY_199='$siteId'", $config['global_log'], $config);
    
    try {
        // Подключаемся к базе данных Bitrix
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
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
        
        $mysqli = new mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            return false;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = subPoolName И PROPERTY_199 = siteId
        $stmt = $mysqli->prepare("
            SELECT DISTINCT e.ID, e.NAME, e.CODE, e.XML_ID, e.DETAIL_TEXT, e.PREVIEW_TEXT
            FROM b_iblock_element e
            JOIN b_iblock_element_property ep1 ON e.ID = ep1.IBLOCK_ELEMENT_ID
            JOIN b_iblock_property p1 ON ep1.IBLOCK_PROPERTY_ID = p1.ID
            WHERE e.IBLOCK_ID = 54 
            AND e.NAME = ? 
            AND p1.CODE = 'PROPERTY_199' 
            AND ep1.VALUE = ?
        ");
        $stmt->bind_param("ss", $subPoolName, $siteId);
        $stmt->execute();
        $result = $stmt->get_result();
        $element = $result->fetch_assoc();
        
        if (!$element) {
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: элемент с NAME='$subPoolName' и PROPERTY_199='$siteId' не найден в инфоблоке 54", $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = 'raw_' . date('Ymd_His') . '_' . substr(md5($subPoolName . $siteId), 0, 8) . '.json';
            $reason = 'name_property199_pair_not_found';
            $details = "не найден элемент инфоблока 54, NAME='$subPoolName', PROPERTY_199='$siteId'";
            
            $errorHandler->handleError('calltouch', $fileName, $reason, $details, 'calltouch_normalizer.php');
            
            return false;
        }
        
        // Получаем свойства элемента
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
        
        // Логирование всех найденных свойств для диагностики
        $propCodes = array_map(function($p) { return $p['CODE']; }, $props);
        logMessage("IB54_SQL_PROPS | Element ID: " . $element['ID'] . " | Found " . count($props) . " properties | Codes: " . json_encode($propCodes, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        
        // Проверяем, есть ли PROPERTY_195 в результатах SQL
        $hasProp195 = false;
        foreach ($props as $prop) {
            if ($prop['CODE'] === 'PROPERTY_195') {
                $hasProp195 = true;
                logMessage("IB54_PROPERTY_195_SQL | Found in SQL results | VALUE: " . json_encode($prop['VALUE'], JSON_UNESCAPED_UNICODE) . " | VALUE_ENUM: " . json_encode($prop['VALUE_ENUM'], JSON_UNESCAPED_UNICODE) . " | VALUE_NUM: " . json_encode($prop['VALUE_NUM'], JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
                break;
            }
        }
        if (!$hasProp195) {
            logMessage("IB54_PROPERTY_195_SQL | NOT found in SQL results for element ID: " . $element['ID'], $config['global_log'], $config);
        }
        
        // Преобразуем свойства в удобный формат
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
        
        logMessage("getElementDataFromIblock54BySubPoolAndSiteId: найден элемент ID=" . $element['ID'] . " с NAME='" . $element['NAME'] . "'", $config['global_log'], $config);
        
        return [
            'element' => $element,
            'properties' => $properties
        ];
        
    } catch (Exception $e) {
        logMessage("getElementDataFromIblock54BySubPoolAndSiteId: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        return false;
    }
}

/**
 * Получает данные элемента из инфоблока 54 по домену (для всех типов кроме CallTouch)
 */
function getElementDataFromIblock54ByDomain($domain, $config) {
    logMessage("getElementDataFromIblock54ByDomain: ищем элемент с NAME='$domain'", $config['global_log'], $config);
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Поиск в инфоблоке 54
    logMessage("IB54_SEARCH_START | Domain: '$domain'", $config['global_log'], $config);
    
    try {
        // Подключаемся к базе данных Bitrix
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
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
        
        $mysqli = new mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            logMessage("getElementDataFromIblock54ByDomain: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            return false;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = domain
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
            logMessage("IB54_SEARCH_RESULT | Domain: '$domain' | Found: false", $config['global_log'], $config);
            logMessage("getElementDataFromIblock54ByDomain: элемент с NAME='$domain' не найден в инфоблоке 54", $config['global_log'], $config);
            return false;
        }
        
        // СКВОЗНОЕ ЛОГИРОВАНИЕ: Элемент найден
        logMessage("IB54_SEARCH_RESULT | Domain: '$domain' | Found: true | Element ID: " . $element['ID'] . " | Element Name: '" . $element['NAME'] . "'", $config['global_log'], $config);
        
        // Получаем свойства элемента
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
        
        // Логирование всех найденных свойств для диагностики
        $propCodes = array_map(function($p) { return $p['CODE']; }, $props);
        logMessage("IB54_SQL_PROPS | Element ID: " . $element['ID'] . " | Found " . count($props) . " properties | Codes: " . json_encode($propCodes, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        
        // Проверяем, есть ли PROPERTY_195 в результатах SQL
        $hasProp195 = false;
        foreach ($props as $prop) {
            if ($prop['CODE'] === 'PROPERTY_195') {
                $hasProp195 = true;
                logMessage("IB54_PROPERTY_195_SQL | Found in SQL results | VALUE: " . json_encode($prop['VALUE'], JSON_UNESCAPED_UNICODE) . " | VALUE_ENUM: " . json_encode($prop['VALUE_ENUM'], JSON_UNESCAPED_UNICODE) . " | VALUE_NUM: " . json_encode($prop['VALUE_NUM'], JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
                break;
            }
        }
        if (!$hasProp195) {
            logMessage("IB54_PROPERTY_195_SQL | NOT found in SQL results for element ID: " . $element['ID'], $config['global_log'], $config);
        }
        
        // Преобразуем свойства в удобный формат
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
        
        logMessage("getElementDataFromIblock54ByDomain: найден элемент ID=" . $element['ID'] . " с NAME='" . $element['NAME'] . "'", $config['global_log'], $config);
        
        return [
            'element' => $element,
            'properties' => $properties
        ];
        
    } catch (Exception $e) {
        logMessage("getElementDataFromIblock54ByDomain: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        return false;
    }
}

/**
 * Обновляет поля лида данными из инфоблока 54
 */
function updateLeadFieldsFromIblock54($leadFields, $elementData, $normalizedData, $config) {
    logMessage("updateLeadFieldsFromIblock54: обновляем поля лида данными из инфоблока 54", $config['global_log'], $config);
    
    $properties = $elementData['properties'];
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Данные из инфоблока 54
    $elementName = $elementData['element']['NAME'] ?? 'unknown';
    logMessage("IB54_DATA | Element Name: '$elementName' | Properties: " . json_encode(array_keys($properties), JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
    
    // Обновляем заголовок в зависимости от типа данных
    if (isset($normalizedData['calltouch_data'])) {
        $leadFields['TITLE'] = "Звонок с сайта [$elementName]";
    } else {
        $leadFields['TITLE'] = "Лид с сайта [$elementName]";
    }
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Установка TITLE
    logMessage("IB54_TITLE_SET | TITLE: '" . $leadFields['TITLE'] . "'", $config['global_log'], $config);
    
    // SOURCE_ID из PROPERTY_192
    if (!empty($properties['PROPERTY_192']['VALUE'])) {
        $sourceElementId = $properties['PROPERTY_192']['VALUE'];
        $sourceId = getSourceIdFromList19($sourceElementId, $config);
        if ($sourceId) {
            $leadFields['SOURCE_ID'] = $sourceId;
        }
    }
    
    // ASSIGNED_BY_ID из PROPERTY_191
    if (!empty($properties['PROPERTY_191']['VALUE'])) {
        $cityElementId = $properties['PROPERTY_191']['VALUE'];
        $assignedById = getAssignedByIdFromList22($cityElementId, $config);
        if ($assignedById) {
            $leadFields['ASSIGNED_BY_ID'] = $assignedById;
            $leadFields['CREATED_BY_ID'] = $assignedById; // Создаем лид от имени ответственного
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
    
    // Инфоповод из PROPERTY_194
    if (!empty($properties['PROPERTY_194']['VALUE'])) {
        $leadFields['UF_CRM_5FC49F7DA5470'] = (string)$properties['PROPERTY_194']['VALUE'];
    }
    
    // Наблюдатели из PROPERTY_195
    // Детальное логирование для диагностики
    if (isset($properties['PROPERTY_195'])) {
        $prop195 = $properties['PROPERTY_195'];
        $prop195Value = $prop195['VALUE'] ?? null;
        $prop195ValueType = gettype($prop195Value);
        logMessage("IB54_PROPERTY_195_CHECK | PROPERTY_195 exists | Value type: $prop195ValueType | Value: " . json_encode($prop195Value, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        
        if (!empty($prop195Value)) {
            $observerRaw = $prop195Value;
            $observerIds = is_array($observerRaw) ? $observerRaw : [$observerRaw];
            logMessage("IB54_OBSERVERS_RAW | Raw observer data: " . json_encode($observerIds, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
            
            $observerIds = array_values(array_filter(array_map(function($v){
                $n = (int)$v; return $n > 0 ? $n : null;
            }, $observerIds), function($v){ return $v !== null; }));
            
            if (!empty($observerIds)) {
                $leadFields['OBSERVER_IDS'] = $observerIds;
                logMessage("IB54_OBSERVERS_SET | OBSERVER_IDS: " . json_encode($observerIds, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
            } else {
                logMessage("IB54_OBSERVERS_EMPTY | PROPERTY_195 value was empty or invalid after processing | Raw: " . json_encode($observerRaw, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
            }
        } else {
            logMessage("IB54_OBSERVERS_EMPTY_VALUE | PROPERTY_195 exists but VALUE is empty | Property data: " . json_encode($prop195, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        }
    } else {
        logMessage("IB54_OBSERVERS_NOT_FOUND | PROPERTY_195 not found in element properties | Available properties: " . json_encode(array_keys($properties), JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
    }
    
    // SOURCE_DESCRIPTION в зависимости от типа данных
    if (isset($normalizedData['calltouch_data'])) {
        $callTouchData = $normalizedData['calltouch_data'];
        $leadFields['SOURCE_DESCRIPTION'] = 'CallTouch (siteId=' . ($callTouchData['siteId'] ?? '') . ')';
    } else {
        $domain = $normalizedData['source_domain'] ?? '';
        $leadFields['SOURCE_DESCRIPTION'] = 'Сайт: ' . $domain;
    }
    
    // ВАЖНО: Сохраняем NAME из нормализованных данных, если оно есть и не пустое
    // Если имени нет, устанавливаем "Имя"
    // Это нужно, чтобы имя не терялось при обновлении полей из ИБ54
    if (!empty($normalizedData['name']) && !empty(trim($normalizedData['name']))) {
        $leadFields['NAME'] = trim($normalizedData['name']);
        logMessage("IB54_NAME_PRESERVED | NAME сохранено из нормализованных данных: '" . $leadFields['NAME'] . "'", $config['global_log'], $config);
        
        // Если имя есть, фамилию устанавливаем ТОЛЬКО если она реально есть
        $lastName = $normalizedData['last_name'] ?? $normalizedData['surname'] ?? $normalizedData['lastName'] ?? $normalizedData['LAST_NAME'] ?? '';
        if (!empty($lastName) && !empty(trim($lastName))) {
            $leadFields['LAST_NAME'] = trim($lastName);
            logMessage("IB54_LAST_NAME_PRESERVED | LAST_NAME сохранено из нормализованных данных: '" . $leadFields['LAST_NAME'] . "'", $config['global_log'], $config);
        }
        // Если фамилии нет, не устанавливаем поле LAST_NAME вообще
    } else {
        // Если имя отсутствует, устанавливаем "Имя" и "Фамилия"
        $leadFields['NAME'] = 'Имя';
        $leadFields['LAST_NAME'] = 'Фамилия';
        logMessage("IB54_NAME_PRESERVED | NAME пустое, установлено 'Имя' и 'Фамилия'", $config['global_log'], $config);
    }
    
    logMessage("updateLeadFieldsFromIblock54: поля обновлены: " . json_encode($leadFields), $config['global_log'], $config);
    
    return $leadFields;
}

/**
 * Получает SOURCE_ID из списка 19 по ID элемента источника
 */
function getSourceIdFromList19($sourceElementId, $config) {
    logMessage("getSourceIdFromList19: ищем источник для элемента ID=$sourceElementId", $config['global_log'], $config);
    
    try {
        // Подключаемся к базе данных Bitrix
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
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
        
        $mysqli = new mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            logMessage("getSourceIdFromList19: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            return null;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем PROPERTY_73 из связанного элемента (как в старой системе)
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
            logMessage("getSourceIdFromList19: найден источник '$sourceId' в PROPERTY_73", $config['global_log'], $config);
            return $sourceId;
        }
        
        logMessage("getSourceIdFromList19: PROPERTY_73 не найден или пуст для элемента ID=$sourceElementId", $config['global_log'], $config);
        return null;
        
    } catch (Exception $e) {
        logMessage("getSourceIdFromList19: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        return null;
    }
}

/**
 * Получает ASSIGNED_BY_ID из списка 22 по ID элемента города
 */
function getAssignedByIdFromList22($cityElementId, $config) {
    logMessage("getAssignedByIdFromList22: ищем ответственного для города ID=$cityElementId", $config['global_log'], $config);
    
    try {
        // Подключаемся к базе данных Bitrix
        $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
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
        
        $mysqli = new mysqli($host, $username, $password, $dbname);
        if ($mysqli->connect_error) {
            logMessage("getAssignedByIdFromList22: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            return null;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем PROPERTY_185 из связанного элемента (как в старой системе)
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
            logMessage("getAssignedByIdFromList22: найден ответственный '$assignedById' в PROPERTY_185", $config['global_log'], $config);
            return $assignedById;
        }
        
        logMessage("getAssignedByIdFromList22: PROPERTY_185 не найден или пуст для элемента ID=$cityElementId", $config['global_log'], $config);
        return null;
        
    } catch (Exception $e) {
        logMessage("getAssignedByIdFromList22: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        return null;
    }
}

/**
 * Перемещает файл в папку дублей
 */
function moveToDuplicates($filePath, $duplicateCheck, $config) {
    $duplicatesDir = $config['queue_dir'] . '/duplicates';
    
    if (!is_dir($duplicatesDir)) {
        mkdir($duplicatesDir, 0777, true);
    }
    
    $fileName = basename($filePath);
    $newPath = $duplicatesDir . '/' . $fileName;
    
    // Добавляем информацию о дубле
    $data = json_decode(file_get_contents($filePath), true);
    $data['duplicate_info'] = $duplicateCheck;
    $data['moved_to_duplicates_at'] = date('Y-m-d H:i:s');
    
    // Сохраняем в папку дублей
    file_put_contents($newPath, json_encode($data, JSON_UNESCAPED_UNICODE));
    
    // Удаляем исходный файл
    unlink($filePath);
    
    logMessage("lead_processor: файл перемещен в duplicates: $fileName", $config['global_log'], $config);
}

/**
 * Очищает старые файлы в processed, оставляя только N последних.
 *
 * ВАЖНО: функция специально сделана лёгкой для горячего пути:
 * - реальный проход по файлам выполняется не чаще, чем раз в $minIntervalSeconds;
 * - при каждом вызове сначала проверяется "таймстамп" и при частых вызовах
 *   функция просто выходит.
 */
function cleanupOldProcessedFiles($config, $maxFiles = null, $minIntervalSeconds = null) {
    // Используем параметры из config, если не переданы явно
    $maxFiles = $maxFiles ?? $config['cleanup_max_files'] ?? 200;
    $minIntervalSeconds = $minIntervalSeconds ?? $config['cleanup_min_interval'] ?? 300;
    $processedDir = $config['queue_dir'] . '/processed';

    if (!is_dir($processedDir)) {
        return;
    }

    // Файл-маркер, чтобы не запускать тяжёлую очистку слишком часто
    $timestampFile = $processedDir . '/.cleanup_processed_timestamp';
    $now = time();

    if (file_exists($timestampFile)) {
        $lastRun = filemtime($timestampFile);
        if ($lastRun !== false && ($now - $lastRun) < $minIntervalSeconds) {
            // Очистку недавно уже делали — выходим быстро
            return;
        }
    }

    // Обновляем таймстамп как можно раньше, чтобы параллельные вызовы не дёргали очистку
    @file_put_contents($timestampFile, date('Y-m-d H:i:s'));

    $files = glob($processedDir . '/processed_*.json');
    if (!$files) {
        return;
    }

    $total = count($files);
    if ($total <= $maxFiles) {
        return;
    }

    // Сортируем по времени модификации: старые сначала
    usort($files, static function (string $a, string $b): int {
        return filemtime($a) <=> filemtime($b);
    });

    $toDelete = $total - $maxFiles;
    $deleted = 0;

    // Удаляем самые старые файлы
    for ($i = 0; $i < $toDelete; $i++) {
        $file = $files[$i];
        if (@unlink($file)) {
            $deleted++;
            $lockFile = $file . '.lock';
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
        }
    }

    if ($deleted > 0) {
        logMessage(
            "cleanupOldProcessedFiles: удалено $deleted старых processed-файлов, всего было $total, оставлено $maxFiles",
            $config['global_log'],
            $config
        );
    }
}

/**
 * Запускает бизнес-процесс для лида через прямое Bitrix API
 * 
 * @param string $webhookUrl URL вебхука (не используется, оставлен для совместимости)
 * @param int|string $businessProcessId ID бизнес-процесса из PROPERTY_440
 * @param int $leadId ID созданного лида
 * @param int $assignedById ID ответственного (передается в параметр Original_Responsible)
 * @param array $config Конфигурация
 * @return bool true если успешно, false при ошибке
 */
function startBusinessProcess($webhookUrl, $businessProcessId, $leadId, $assignedById, $config) {
    logMessage("startBusinessProcess: запуск бизнес-процесса ID=$businessProcessId для лида ID=$leadId с параметром Original_Responsible=$assignedById", $config['global_log'], $config);
    
    // Валидация входных параметров
    $businessProcessId = (int)$businessProcessId;
    if ($businessProcessId <= 0) {
        logMessage("startBusinessProcess: ОШИБКА - невалидный businessProcessId: $businessProcessId", $config['global_log'], $config);
        return false;
    }
    
    $leadId = (int)$leadId;
    if ($leadId <= 0) {
        logMessage("startBusinessProcess: ОШИБКА - невалидный leadId: $leadId", $config['global_log'], $config);
        return false;
    }
    
    $assignedById = (int)$assignedById;
    if ($assignedById <= 0) {
        logMessage("startBusinessProcess: ОШИБКА - невалидный assignedById: $assignedById", $config['global_log'], $config);
        return false;
    }
    
    try {
        // Подключаем Bitrix API если еще не подключен
        $prologIncluded = defined('B_PROLOG_INCLUDED') && constant('B_PROLOG_INCLUDED');
        logMessage("startBusinessProcess: проверка подключения Bitrix | B_PROLOG_INCLUDED: " . ($prologIncluded ? 'true' : 'false'), $config['global_log'], $config);
        
        if (!$prologIncluded) {
            $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
            $prologPath = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
            logMessage("startBusinessProcess: подключение Bitrix API | Path: $prologPath", $config['global_log'], $config);
            
            if (file_exists($prologPath)) {
                require_once $prologPath;
                logMessage("startBusinessProcess: Bitrix API подключен успешно", $config['global_log'], $config);
            } else {
                logMessage("startBusinessProcess: ОШИБКА - не найден prolog_before.php по пути: $prologPath", $config['global_log'], $config);
                return false;
            }
        }
        
        // Подключаем необходимые модули
        logMessage("startBusinessProcess: проверка класса Loader", $config['global_log'], $config);
        if (!class_exists('\Bitrix\Main\Loader')) {
            logMessage("startBusinessProcess: ОШИБКА - класс Loader не найден", $config['global_log'], $config);
            return false;
        }
        
        $loader = '\Bitrix\Main\Loader';
        logMessage("startBusinessProcess: подключение модуля bizproc", $config['global_log'], $config);
        if (!$loader::includeModule('bizproc')) {
            logMessage("startBusinessProcess: ОШИБКА - не удалось подключить модуль bizproc", $config['global_log'], $config);
            return false;
        }
        logMessage("startBusinessProcess: модуль bizproc подключен успешно", $config['global_log'], $config);
        
        logMessage("startBusinessProcess: подключение модуля crm", $config['global_log'], $config);
        if (!$loader::includeModule('crm')) {
            logMessage("startBusinessProcess: ОШИБКА - не удалось подключить модуль crm", $config['global_log'], $config);
            return false;
        }
        logMessage("startBusinessProcess: модуль crm подключен успешно", $config['global_log'], $config);
        
        // Проверяем наличие класса CBPDocument
        logMessage("startBusinessProcess: проверка класса CBPDocument", $config['global_log'], $config);
        if (!class_exists('CBPDocument')) {
            logMessage("startBusinessProcess: ОШИБКА - класс CBPDocument не найден", $config['global_log'], $config);
            return false;
        }
        logMessage("startBusinessProcess: класс CBPDocument найден", $config['global_log'], $config);
        
        // Формируем DOCUMENT_ID для лида
        // Для CBPDocument::StartWorkflow нужен формат: ['crm', 'CCrmDocumentLead', 'LEAD_ID']
        // где ID - это строка в формате 'LEAD_' + числовой ID
        $documentId = ['crm', 'CCrmDocumentLead', 'LEAD_' . $leadId];
        
        // Формируем параметры бизнес-процесса
        // Original_Responsible передаем как ID пользователя (не user_ID, а просто число)
        $parameters = [
            'Original_Responsible' => $assignedById
        ];
        
        logMessage("startBusinessProcess: запуск через Bitrix API | Template ID: $businessProcessId | Document ID: " . json_encode($documentId, JSON_UNESCAPED_UNICODE) . " | Parameters: " . json_encode($parameters, JSON_UNESCAPED_UNICODE), $config['global_log'], $config);
        
        // Используем CBPDocument::StartWorkflow для запуска бизнес-процесса
        // @var CBPDocument класс доступен после подключения модуля bizproc
        logMessage("startBusinessProcess: вызов CBPDocument::StartWorkflow", $config['global_log'], $config);
        $errors = [];
        /** @noinspection PhpUndefinedClassInspection */
        $workflowId = CBPDocument::StartWorkflow(
            $businessProcessId,
            $documentId,
            $parameters,
            $errors
        );
        
        logMessage("startBusinessProcess: результат StartWorkflow | WorkflowId: " . ($workflowId ?: 'null') . " | Errors count: " . (is_array($errors) ? count($errors) : 0), $config['global_log'], $config);
        
        if (!empty($errors) && is_array($errors)) {
            $errorMessages = [];
            foreach ($errors as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $errorMessages[] = $error['message'];
                } elseif (is_string($error)) {
                    $errorMessages[] = $error;
                } else {
                    $errorMessages[] = json_encode($error, JSON_UNESCAPED_UNICODE);
                }
            }
            $errorText = implode('; ', $errorMessages);
            logMessage("startBusinessProcess: ОШИБКА запуска бизнес-процесса | Errors: $errorText", $config['global_log'], $config);
            return false;
        }
        
        if ($workflowId) {
            logMessage("startBusinessProcess: бизнес-процесс успешно запущен | Workflow ID: $workflowId | Template ID: $businessProcessId | Lead ID: $leadId", $config['global_log'], $config);
            return true;
        } else {
            logMessage("startBusinessProcess: бизнес-процесс не запущен, workflowId пуст | Template ID: $businessProcessId | Lead ID: $leadId", $config['global_log'], $config);
            return false;
        }
        
    } catch (\Exception $e) {
        logMessage("startBusinessProcess: исключение: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString(), $config['global_log'], $config);
        return false;
    } catch (\Throwable $e) {
        logMessage("startBusinessProcess: критическая ошибка: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString(), $config['global_log'], $config);
        return false;
    }
}

/**
 * Получает статистику по обработанным лидам
 */
function getLeadProcessingStats($config) {
    $processedDir = $config['queue_dir'] . '/processed';
    $duplicatesDir = $config['queue_dir'] . '/duplicates';
    $failedDir = $config['queue_dir'] . '/failed';
    
    $stats = [
        'processed' => 0,
        'duplicates' => 0,
        'failed' => 0,
        'total_leads' => 0
    ];
    
    // Подсчитываем обработанные
    if (is_dir($processedDir)) {
        $processedFiles = glob($processedDir . '/*.json');
        $stats['processed'] = count($processedFiles);
        
        // Подсчитываем общее количество лидов
        foreach ($processedFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['lead_id'])) {
                $stats['total_leads']++;
            }
        }
    }
    
    // Подсчитываем дубли
    if (is_dir($duplicatesDir)) {
        $duplicateFiles = glob($duplicatesDir . '/*.json');
        $stats['duplicates'] = count($duplicateFiles);
    }
    
    // Подсчитываем ошибки
    if (is_dir($failedDir)) {
        $failedFiles = glob($failedDir . '/*.json');
        $stats['failed'] = count($failedFiles);
    }
    
    return $stats;
}

// Поддержка запуска из CLI: php lead_processor.php
if (PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    if (isset($argv[1]) && $argv[1] === 'run') {
        $config = require __DIR__ . '/config.php';
        processNormalizedFiles($config);
        echo "OK\n";
    } elseif (isset($argv[1]) && $argv[1] === 'stats') {
        $config = require __DIR__ . '/config.php';
        $stats = getLeadProcessingStats($config);
        echo "Статистика обработки лидов:\n";
        echo "  Обработано файлов: {$stats['processed']}\n";
        echo "  Создано лидов: {$stats['total_leads']}\n";
        echo "  Дублей: {$stats['duplicates']}\n";
        echo "  Ошибок: {$stats['failed']}\n";
    }
}
