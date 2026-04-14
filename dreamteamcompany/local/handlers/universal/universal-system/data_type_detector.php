<?php
// data_type_detector.php
// Определитель типа входящих данных

require_once __DIR__ . '/logger_and_queue.php';
require_once __DIR__ . '/error_handler.php';

/**
 * Определяет тип данных на основе структуры (устаревший метод)
 * Теперь используется getNormalizerFromIblock54()
 */
function detectDataType($rawData) {
    // Колтач - есть поля answers, raw
    if (isset($rawData['answers']) || isset($rawData['raw'])) {
        return 'koltaсh';
    }
    
    // Тильда - есть __submission, formname
    if (isset($rawData['__submission']) || isset($rawData['formname'])) {
        return 'tilda';
    }
    
    // WordPress - есть contact_form, wpcf
    if (isset($rawData['contact_form']) || 
        (isset($rawData['_wpcf7']) && strpos($rawData['_wpcf7'], 'wpcf') !== false)) {
        return 'wordpress';
    }
    
    // Bitrix - есть UF_CRM поля
    if (preg_match('/UF_CRM/', json_encode($rawData))) {
        return 'bitrix';
    }
    
    // CallTouch - есть специфичные поля CallTouch
    if (isset($rawData['callerphone']) || isset($rawData['callphase']) || isset($rawData['siteId']) || isset($rawData['subPoolName'])) {
        return 'calltouch';
    }
    
    // JivoSite - есть jivosite поля
    if (isset($rawData['jivosite']) || isset($rawData['jivo'])) {
        return 'jivosite';
    }
    
    // Calendly - есть calendly поля
    if (isset($rawData['calendly']) || isset($rawData['event'])) {
        return 'calendly';
    }
    
    return 'generic'; // fallback
}

/**
 * Получает нормализатор из инфоблока 54 по SOURCE_DESCRIPTION
 * Используется для Bitrix24 webhook'ов
 */
function getNormalizerFromIblock54BySourceDescription($sourceDescription, $config, $rawFilePath = null) {
    $logMsg = "🔍 ПОИСК В ИБ54: SOURCE_DESCRIPTION='" . $sourceDescription . "'";
    logMessage($logMsg, $config['global_log'], $config);
    // Доп. диагностика скрытых расхождений
    $srcLen = function_exists('mb_strlen') ? mb_strlen($sourceDescription) : strlen($sourceDescription);
    $srcHex = bin2hex($sourceDescription);
    logMessage("🔍 SOURCE_DESCRIPTION_DIAG | len={$srcLen} | hex={$srcHex}", $config['global_log'], $config);
    // error_log($logMsg);
    
    logMessage("getNormalizerFromIblock54BySourceDescription: начинаем обработку для '$sourceDescription'", $config['global_log'], $config);
    
    try {
        logMessage("getNormalizerFromIblock54BySourceDescription: проверяем подключение Bitrix API", $config['global_log'], $config);
        // Подключаем Bitrix API если еще не подключен
        if (!defined('B_PROLOG_INCLUDED') || !constant('B_PROLOG_INCLUDED')) {
            logMessage("getNormalizerFromIblock54BySourceDescription: Bitrix API не подключен, подключаем", $config['global_log'], $config);
            
            // Устанавливаем константы ДО подключения prolog для оптимизации (как в других местах кода)
            if (!defined('BX_SKIP_POST_UNPACK')) {
                define('BX_SKIP_POST_UNPACK', true);
            }
            if (!defined('BX_CRONTAB')) {
                define('BX_CRONTAB', true);
            }
            if (!defined('BX_NO_ACCELERATOR_RESET')) {
                define('BX_NO_ACCELERATOR_RESET', true);
            }
            if (!defined('NO_KEEP_STATISTIC')) {
                define('NO_KEEP_STATISTIC', true);
            }
            if (!defined('NOT_CHECK_PERMISSIONS')) {
                define('NOT_CHECK_PERMISSIONS', true);
            }
            
            // Определяем DOCUMENT_ROOT
            if (empty($_SERVER["DOCUMENT_ROOT"])) {
                $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../..');
            }
            
            $documentRoot = $_SERVER["DOCUMENT_ROOT"];
            $prologPath = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
            logMessage("getNormalizerFromIblock54BySourceDescription: проверяем существование файла prolog: $prologPath", $config['global_log'], $config);
            
            if (file_exists($prologPath)) {
                logMessage("getNormalizerFromIblock54BySourceDescription: файл prolog найден, подключаем", $config['global_log'], $config);
                try {
                    require_once $prologPath;
                    logMessage("getNormalizerFromIblock54BySourceDescription: prolog подключен успешно", $config['global_log'], $config);
                } catch (Throwable $e) {
                    logMessage("getNormalizerFromIblock54BySourceDescription: ОШИБКА при подключении prolog: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                    throw $e;
                }
            } else {
                $errorMsg = "getNormalizerFromIblock54BySourceDescription: файл prolog не найден: $prologPath";
                logMessage($errorMsg, $config['global_log'], $config);
                throw new Exception($errorMsg);
            }
        } else {
            logMessage("getNormalizerFromIblock54BySourceDescription: Bitrix API уже подключен", $config['global_log'], $config);
        }
        
        logMessage("getNormalizerFromIblock54BySourceDescription: подключаем модули iblock и main", $config['global_log'], $config);
        CModule::IncludeModule("iblock");
        logMessage("getNormalizerFromIblock54BySourceDescription: модуль iblock подключен", $config['global_log'], $config);
        CModule::IncludeModule("main");
        logMessage("getNormalizerFromIblock54BySourceDescription: модуль main подключен", $config['global_log'], $config);
        
        $iblockId = $config['iblock']['iblock_54_id'] ?? 54;
        
        // Кэширование: проверяем кэш перед запросом к БД
        // Проверяем, что класс Cache доступен
        $cache = null;
        if (class_exists('\Bitrix\Main\Data\Cache')) {
            try {
                $cache = \Bitrix\Main\Data\Cache::createInstance();
                if (!$cache) {
                    logMessage("getNormalizerFromIblock54BySourceDescription: Cache::createInstance() вернул null, работаем без кэша", $config['global_log'], $config);
                }
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54BySourceDescription: ошибка создания кэша: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
            }
        } else {
            logMessage("getNormalizerFromIblock54BySourceDescription: класс Cache не найден, работаем без кэша", $config['global_log'], $config);
        }
        
        $cacheId = 'normalizer_srcdesc_' . md5($sourceDescription);
        $cacheDir = '/normalizer';
        $cacheTime = 7200; // 2 часа (данные в ИБ54 не меняются, только добавляются новые)
        
        $cacheStarted = false;
        if ($cache) {
            try {
                logMessage("getNormalizerFromIblock54BySourceDescription: пытаемся запустить кэш для '$sourceDescription'", $config['global_log'], $config);
                $cacheStarted = $cache->startDataCache($cacheTime, $cacheId, $cacheDir);
                logMessage("getNormalizerFromIblock54BySourceDescription: startDataCache вернул " . ($cacheStarted ? "true" : "false") . " для '$sourceDescription'", $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54BySourceDescription: ошибка startDataCache: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
                $cacheStarted = false;
            }
        } else {
            logMessage("getNormalizerFromIblock54BySourceDescription: кэш недоступен, работаем без кэша для '$sourceDescription'", $config['global_log'], $config);
        }
        
        if ($cacheStarted) {
            // Кэш не найден, получаем данные из БД
            logMessage("getNormalizerFromIblock54BySourceDescription: кэш не найден, запрашиваем данные из БД для '$sourceDescription'", $config['global_log'], $config);
            $filter = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $sourceDescription,
                'ACTIVE' => 'Y',
            ];
            
            logMessage("getNormalizerFromIblock54BySourceDescription: вызываем CIBlockElement::GetList для SOURCE_DESCRIPTION='$sourceDescription'", $config['global_log'], $config);
            $dbRes = CIBlockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'CODE', 'XML_ID']
            );
            
            if ($dbRes === false) {
                logMessage("getNormalizerFromIblock54BySourceDescription: CIBlockElement::GetList вернул false для '$sourceDescription'", $config['global_log'], $config);
                if ($cache) {
                    $cache->abortDataCache();
                }
                return null;
            }
            
            logMessage("getNormalizerFromIblock54BySourceDescription: CIBlockElement::GetList выполнен успешно для '$sourceDescription'", $config['global_log'], $config);
            $element = $dbRes->Fetch();
            
            if (!$element) {
                if ($cache) {
                    $cache->abortDataCache();
                }
                $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$sourceDescription' в ИБ54";
                logMessage($logMsg, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
                $reason = 'source_description_not_found_in_iblock54';
                $details = "SOURCE_DESCRIPTION '$sourceDescription' не найден в инфоблоке 54";
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'bitrix24_webhook_normalizer.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
                // НЕ возвращаем нормализатор - файл должен остаться в raw_errors
            return null;
        }
            
            $foundElementId = (int)$element['ID'];
            logMessage("getNormalizerFromIblock54BySourceDescription: найден элемент ID=$foundElementId для '$sourceDescription'", $config['global_log'], $config);
        
            // Получаем свойство PROPERTY_388 (нормализатор)
            logMessage("getNormalizerFromIblock54BySourceDescription: получаем PROPERTY_388 для element_id=$foundElementId", $config['global_log'], $config);
            $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId, [], ['CODE' => 'PROPERTY_388']);
            
            if ($dbProps === false) {
                logMessage("getNormalizerFromIblock54BySourceDescription: CIBlockElement::GetProperty вернул false для element_id=$foundElementId", $config['global_log'], $config);
                if ($cache) {
                    $cache->abortDataCache();
                }
                return null;
            }
            
            logMessage("getNormalizerFromIblock54BySourceDescription: CIBlockElement::GetProperty выполнен успешно для element_id=$foundElementId", $config['global_log'], $config);
            
            $normalizerFile = 'bitrix24_webhook_normalizer.php'; // значение по умолчанию
            while ($arProp = $dbProps->Fetch()) {
                if ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE_ENUM'])) {
                    // Для списков используем VALUE_ENUM (текстовое значение)
                    $normalizerFile = $arProp['VALUE_ENUM'];
                    break;
                } elseif ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE'])) {
                    // Если VALUE_ENUM пуст, используем VALUE
                    $normalizerFile = $arProp['VALUE'];
                    break;
                }
            }
            
            // Сохраняем в кэш
            if ($cache) {
                $cache->endDataCache($normalizerFile);
                logMessage("NORMALIZER_CACHE | SourceDescription: '$sourceDescription' | Cache: MISS (данные получены из БД и сохранены в кэш) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            } else {
                logMessage("NORMALIZER_CACHE | SourceDescription: '$sourceDescription' | Cache: DISABLED (данные получены из БД) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            }
        } else {
            // Данные получены из кэша или кэш недоступен
            if ($cache) {
                $normalizerFile = $cache->getVars();
                logMessage("NORMALIZER_CACHE | SourceDescription: '$sourceDescription' | Cache: HIT (данные получены из кэша) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
                
                // Если из кэша получена пустая строка, используем нормализатор по умолчанию
                if (empty($normalizerFile)) {
                    $normalizerFile = 'bitrix24_webhook_normalizer.php';
                    logMessage("getNormalizerFromIblock54BySourceDescription: из кэша получена пустая строка для '$sourceDescription', используем bitrix24_webhook_normalizer.php по умолчанию", $config['global_log'], $config);
                }
                
                if ($normalizerFile) {
                    return $normalizerFile;
                }
            } else {
                // Кэш недоступен, получаем данные из БД напрямую
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $sourceDescription,
                    'ACTIVE' => 'Y',
                ];
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                $element = $dbRes->Fetch();
        
        if (!$element) {
            $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$sourceDescription' в ИБ54";
            logMessage($logMsg, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'source_description_not_found_in_iblock54';
            $details = "SOURCE_DESCRIPTION '$sourceDescription' не найден в инфоблоке 54";
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'bitrix24_webhook_normalizer.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            // НЕ возвращаем нормализатор - файл должен остаться в raw_errors
            return null;
        }
        
                $foundElementId = (int)$element['ID'];
                
                // Получаем свойство PROPERTY_388 (нормализатор)
                $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId, [], ['CODE' => 'PROPERTY_388']);
                
                $normalizerFile = 'bitrix24_webhook_normalizer.php'; // значение по умолчанию
                while ($arProp = $dbProps->Fetch()) {
                    if ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE_ENUM'])) {
                        // Для списков используем VALUE_ENUM (текстовое значение)
                        $normalizerFile = $arProp['VALUE_ENUM'];
                        break;
                    } elseif ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE'])) {
                        // Если VALUE_ENUM пуст, используем VALUE
                        $normalizerFile = $arProp['VALUE'];
                        break;
                    }
                }
                
                logMessage("NORMALIZER_CACHE | SourceDescription: '$sourceDescription' | Cache: DISABLED (данные получены из БД) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            }
        }
        
        $logMsg = "🔍 SELECTED_NORMALIZER_FILE='$normalizerFile' (по SOURCE_DESCRIPTION)";
        logMessage($logMsg, $config['global_log'], $config);
        return $normalizerFile;
        
    } catch (Exception $e) {
        logMessage("getNormalizerFromIblock54BySourceDescription: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        
        // Создаем ошибку через ErrorHandler
        $errorHandler = new ErrorHandler($config);
        $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
        $reason = 'database_error';
        $details = "Ошибка подключения к БД: " . $e->getMessage();
        
        $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'bitrix24_webhook_normalizer.php');
        
        // Перемещаем raw файл в raw_errors, если путь известен
        if ($rawFilePath && file_exists($rawFilePath)) {
            $errorHandler->moveToRawErrors($rawFilePath);
        }
        
        return null;
    }
}

/**
 * Получает нормализатор из инфоблока 54 по названию элемента
 * Используется для ASSIGNED_BY_ID
 */
function getNormalizerFromIblock54ByTitle($title, $config, $rawFilePath = null) {
    $logMsg = "🔍 ПОИСК В ИБ54 ПО НАЗВАНИЮ: TITLE='" . $title . "'";
    logMessage($logMsg, $config['global_log'], $config);
    // error_log($logMsg);
    
    try {
        // Подключаем Bitrix API если еще не подключен (как в aat/lead_processor.php и bitrix/lead_processor.php)
        logMessage("getNormalizerFromIblock54ByTitle: проверяем подключение Bitrix API", $config['global_log'], $config);
        if (!defined('B_PROLOG_INCLUDED') || !constant('B_PROLOG_INCLUDED')) {
            // Устанавливаем константы ДО подключения prolog (как в рабочих примерах)
            if (!defined('BX_SKIP_POST_UNPACK')) {
                define('BX_SKIP_POST_UNPACK', true);
            }
            if (!defined('BX_CRONTAB')) {
                define('BX_CRONTAB', true);
            }
            if (!defined('BX_NO_ACCELERATOR_RESET')) {
                define('BX_NO_ACCELERATOR_RESET', true);
            }
            if (!defined('NO_KEEP_STATISTIC')) {
                define('NO_KEEP_STATISTIC', true);
            }
            if (!defined('NOT_CHECK_PERMISSIONS')) {
                define('NOT_CHECK_PERMISSIONS', true);
            }
            
            // Определяем DOCUMENT_ROOT
            if (empty($_SERVER["DOCUMENT_ROOT"])) {
                $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../..');
            }
            
            $documentRoot = $_SERVER["DOCUMENT_ROOT"];
            $prologPath = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
            
            logMessage("getNormalizerFromIblock54ByTitle: подключаем prolog_before.php из $prologPath", $config['global_log'], $config);
        
            if (!file_exists($prologPath)) {
                logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА - файл prolog_before.php не найден по пути $prologPath", $config['global_log'], $config);
                throw new Exception("Файл prolog_before.php не найден по пути: $prologPath");
            }
            
            require_once $prologPath;
            logMessage("getNormalizerFromIblock54ByTitle: prolog_before.php подключен успешно", $config['global_log'], $config);
        } else {
            logMessage("getNormalizerFromIblock54ByTitle: Bitrix API уже подключен", $config['global_log'], $config);
        }
        
        // Используем D7 API если доступен (как в тестовом файле)
        logMessage("getNormalizerFromIblock54ByTitle: проверяем доступность D7 API", $config['global_log'], $config);
        if (class_exists('\Bitrix\Main\Loader')) {
            logMessage("getNormalizerFromIblock54ByTitle: используем D7 API для подключения модулей", $config['global_log'], $config);
            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                throw new Exception("Не удалось подключить модуль iblock через D7 API");
            }
            logMessage("getNormalizerFromIblock54ByTitle: модуль iblock подключен через D7 API", $config['global_log'], $config);
        } else {
            logMessage("getNormalizerFromIblock54ByTitle: D7 API недоступен, используем старый API", $config['global_log'], $config);
            CModule::IncludeModule("iblock");
            CModule::IncludeModule("main");
            logMessage("getNormalizerFromIblock54ByTitle: модули подключены через старый API", $config['global_log'], $config);
        }
        
        $iblockId = $config['iblock']['iblock_54_id'] ?? 54;
        logMessage("getNormalizerFromIblock54ByTitle: используем iblock_id=$iblockId", $config['global_log'], $config);
        
        // Кэширование: проверяем кэш перед запросом к БД
        // Проверяем, что класс Cache доступен
        $cache = null;
        if (class_exists('\Bitrix\Main\Data\Cache')) {
            try {
                $cache = \Bitrix\Main\Data\Cache::createInstance();
                if (!$cache) {
                    logMessage("getNormalizerFromIblock54ByTitle: Cache::createInstance() вернул null, работаем без кэша", $config['global_log'], $config);
                }
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54ByTitle: ошибка создания кэша: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
            }
        } else {
            logMessage("getNormalizerFromIblock54ByTitle: класс Cache не найден, работаем без кэша", $config['global_log'], $config);
        }
        
        $cacheId = 'normalizer_title_' . md5($title);
        $cacheDir = '/normalizer';
        $cacheTime = 7200; // 2 часа (данные в ИБ54 не меняются, только добавляются новые)
        
        $cacheStarted = false;
        $element = null;
        $normalizerFile = null;
        
        if ($cache) {
            try {
                logMessage("getNormalizerFromIblock54ByTitle: проверяем кэш для title='$title'", $config['global_log'], $config);
                $cacheStarted = $cache->startDataCache($cacheTime, $cacheId, $cacheDir);
                logMessage("getNormalizerFromIblock54ByTitle: startDataCache вернул " . ($cacheStarted ? "true (кэш не найден)" : "false (кэш найден)"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54ByTitle: ошибка startDataCache: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
                $cacheStarted = false;
            }
        } else {
            logMessage("getNormalizerFromIblock54ByTitle: кэш недоступен, работаем без кэша", $config['global_log'], $config);
        }
        
        if ($cacheStarted) {
            // Кэш не найден, получаем данные из БД
            logMessage("getNormalizerFromIblock54ByTitle: кэш не найден, запрашиваем данные из БД для title='$title'", $config['global_log'], $config);
            
            try {
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $title,
                    'ACTIVE' => 'Y',
                ];
                
                logMessage("getNormalizerFromIblock54ByTitle: вызываем CIBlockElement::GetList для title='$title'", $config['global_log'], $config);
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                
                if (!$dbRes) {
                    throw new Exception("CIBlockElement::GetList вернул false для title='$title'");
                }
                
                logMessage("getNormalizerFromIblock54ByTitle: CIBlockElement::GetList выполнен успешно для title='$title'", $config['global_log'], $config);
                
                $element = $dbRes->Fetch();
                
                logMessage("getNormalizerFromIblock54ByTitle: Fetch выполнен, element=" . ($element ? "найден (ID={$element['ID']})" : "не найден"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА в CIBlockElement::GetList: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                if ($cache) {
                    try {
                        $cache->abortDataCache();
                    } catch (Throwable $e2) {
                        logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА в abortDataCache: " . $e2->getMessage(), $config['global_log'], $config);
                    }
                }
                return null;
            }
        } else {
// Кэш найден или кэш недоступен
            if ($cache) {
                $normalizerFile = $cache->getVars();
                logMessage("NORMALIZER_CACHE | Title: '$title' | Cache: HIT (данные получены из кэша) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
                
                // Если из кэша получена пустая строка, используем нормализатор по умолчанию
                if (empty($normalizerFile)) {
                    $normalizerFile = 'generic_normalizer.php';
                    logMessage("getNormalizerFromIblock54ByTitle: из кэша получена пустая строка для элемента '$title', используем generic_normalizer.php по умолчанию", $config['global_log'], $config);
                }
                
                if ($normalizerFile) {
                    return $normalizerFile;
                }
            }
            // Если кэш недоступен или данные не найдены в кэше, получаем из БД
            logMessage("getNormalizerFromIblock54ByTitle: кэш недоступен или пуст, запрашиваем данные из БД для title='$title'", $config['global_log'], $config);
            
            try {
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $title,
                    'ACTIVE' => 'Y',
                ];
                
                logMessage("getNormalizerFromIblock54ByTitle: вызываем CIBlockElement::GetList для title='$title'", $config['global_log'], $config);
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                
                if (!$dbRes) {
                    throw new Exception("CIBlockElement::GetList вернул false для title='$title'");
                }
                
                logMessage("getNormalizerFromIblock54ByTitle: CIBlockElement::GetList выполнен успешно для title='$title'", $config['global_log'], $config);
                
                $element = $dbRes->Fetch();
                
                logMessage("getNormalizerFromIblock54ByTitle: Fetch выполнен, element=" . ($element ? "найден (ID={$element['ID']})" : "не найден"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА в CIBlockElement::GetList: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                return null;
            }
        }
        
        if (!$element) {
            
            if (!$element) {
                if ($cache) {
                    $cache->abortDataCache();
                }
                $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$title' в ИБ54";
                logMessage($logMsg, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
                $reason = 'element_not_found';
                $details = "Элемент с названием '$title' не найден в инфоблоке 54";
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'data_type_detector.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
            
            $foundElementId = (int)$element['ID'];
            
            // СКВОЗНОЕ ЛОГИРОВАНИЕ: Элемент найден
            logMessage("NORMALIZER_SEARCH_RESULT | Title: '$title' | Found: true | Element ID: " . $foundElementId . " | Element Name: '" . $element['NAME'] . "'", $config['global_log'], $config);
        
            // Получаем нормализатор из PROPERTY_388
            $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId, [], ['CODE' => 'PROPERTY_388']);
            
            $normalizerFile = null;
            while ($arProp = $dbProps->Fetch()) {
                if ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE_ENUM'])) {
                    // Для списков используем VALUE_ENUM (текстовое значение)
                    $normalizerFile = $arProp['VALUE_ENUM'];
                    break;
                } elseif ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE'])) {
                    // Если VALUE_ENUM пуст, используем VALUE
                    $normalizerFile = $arProp['VALUE'];
                    break;
                }
            }
            
            // Если PROPERTY_388 пустое, используем generic_normalizer.php по умолчанию (BaseNormalizer)
            if (!$normalizerFile || empty($normalizerFile)) {
                $normalizerFile = 'generic_normalizer.php';
                $logMsg = "🔍 НОРМАЛИЗАТОР НЕ НАЙДЕН: PROPERTY_388 пуст для элемента '$title', используем generic_normalizer.php по умолчанию";
                logMessage($logMsg, $config['global_log'], $config);
            }
            
            // Сохраняем в кэш (всегда сохраняем нормализатор, даже если это значение по умолчанию)
            if ($cache) {
                $cache->endDataCache($normalizerFile);
                logMessage("NORMALIZER_CACHE | Title: '$title' | Cache: MISS (данные получены из БД и сохранены в кэш) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            } else {
                logMessage("NORMALIZER_CACHE | Title: '$title' | Cache: DISABLED (данные получены из БД) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            }
        } else {
            // Данные получены из кэша или кэш недоступен
            if ($cache) {
                $normalizerFile = $cache->getVars();
                logMessage("NORMALIZER_CACHE | Title: '$title' | Cache: HIT (данные получены из кэша) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
                
                // Если из кэша получена пустая строка, используем нормализатор по умолчанию
                if (empty($normalizerFile)) {
                    $normalizerFile = 'generic_normalizer.php';
                    logMessage("getNormalizerFromIblock54ByTitle: из кэша получена пустая строка для элемента '$title', используем generic_normalizer.php по умолчанию (кэш будет обновлен при следующем сохранении)", $config['global_log'], $config);
                }
                
                if ($normalizerFile) {
                    $logMsg = "🔍 НОРМАЛИЗАТОР НАЙДЕН: '$normalizerFile' для элемента '$title'";
                    logMessage($logMsg, $config['global_log'], $config);
                    return $normalizerFile;
                }
            } else {
                // Кэш недоступен, получаем данные из БД напрямую
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $title,
                    'ACTIVE' => 'Y',
                ];
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                $element = $dbRes->Fetch();
        
        if (!$element) {
            $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$title' в ИБ54";
            logMessage($logMsg, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'element_not_found';
            $details = "Элемент с названием '$title' не найден в инфоблоке 54";
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'data_type_detector.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
        
                $foundElementId = (int)$element['ID'];
        
        // Получаем нормализатор из PROPERTY_388
                $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId, [], ['CODE' => 'PROPERTY_388']);
                
                $normalizerFile = null;
                while ($arProp = $dbProps->Fetch()) {
                    if ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE_ENUM'])) {
                        $normalizerFile = $arProp['VALUE_ENUM'];
                        break;
                    } elseif ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE'])) {
                        $normalizerFile = $arProp['VALUE'];
                        break;
                    }
                }
                
                // Если PROPERTY_388 пустое, используем generic_normalizer.php по умолчанию (BaseNormalizer)
                if (!$normalizerFile || empty($normalizerFile)) {
                    $normalizerFile = 'generic_normalizer.php';
                    $logMsg = "🔍 НОРМАЛИЗАТОР НЕ НАЙДЕН: PROPERTY_388 пуст для элемента '$title', используем generic_normalizer.php по умолчанию";
                    logMessage($logMsg, $config['global_log'], $config);
                }
                
                logMessage("NORMALIZER_CACHE | Title: '$title' | Cache: DISABLED (данные получены из БД) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            }
        }
        
        $logMsg = "🔍 НОРМАЛИЗАТОР НАЙДЕН: '$normalizerFile' для элемента '$title'";
        logMessage($logMsg, $config['global_log'], $config);
        return $normalizerFile;
        
    } catch (Exception $e) {
        logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        
        // Создаем ошибку через ErrorHandler
        $errorHandler = new ErrorHandler($config);
        $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
        $reason = 'exception';
        $details = "Ошибка при поиске элемента по названию: " . $e->getMessage();
        
        $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'data_type_detector.php');
        
        // Перемещаем raw файл в raw_errors, если путь известен
        if ($rawFilePath && file_exists($rawFilePath)) {
            $errorHandler->moveToRawErrors($rawFilePath);
        }
        
        return null;
    }
}

/**
 * Получает нормализатор из инфоблока 54 по домену
 * @param string $domain Домен или другой идентификатор для поиска
 * @param array $config Конфигурация
 * @param string|null $rawFilePath Путь к raw файлу (если null, файл не будет перемещен в raw_errors)
 * @param bool $skipAutoMove Если true, не перемещает файл в raw_errors и не отправляет сообщение
 * @return string|null Имя файла нормализатора или null если не найден
 */
function getNormalizerFromIblock54($domain, $config, $rawFilePath = null, $skipAutoMove = false) {
    logMessage("getNormalizerFromIblock54: ищем нормализатор для домена '$domain'", $config['global_log'], $config);
    
    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Поиск нормализатора
    logMessage("NORMALIZER_SEARCH_START | Domain: '$domain'", $config['global_log'], $config);
    
    try {
        // Подключаем Bitrix API если еще не подключен (упрощенная версия, как в bitrix/lead_processor.php)
        logMessage("getNormalizerFromIblock54: проверяем подключение Bitrix API", $config['global_log'], $config);
        if (!defined('B_PROLOG_INCLUDED') || !constant('B_PROLOG_INCLUDED')) {
            // Устанавливаем константы ДО подключения prolog (как в рабочих примерах)
            if (!defined('BX_SKIP_POST_UNPACK')) {
                define('BX_SKIP_POST_UNPACK', true);
            }
            if (!defined('BX_CRONTAB')) {
                define('BX_CRONTAB', true);
            }
            if (!defined('BX_NO_ACCELERATOR_RESET')) {
                define('BX_NO_ACCELERATOR_RESET', true);
            }
            if (!defined('NO_KEEP_STATISTIC')) {
                define('NO_KEEP_STATISTIC', true);
            }
            if (!defined('NOT_CHECK_PERMISSIONS')) {
                define('NOT_CHECK_PERMISSIONS', true);
            }
            
            // Определяем DOCUMENT_ROOT
            if (empty($_SERVER["DOCUMENT_ROOT"])) {
                $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../..');
            }
            
            $documentRoot = $_SERVER["DOCUMENT_ROOT"];
            $prologPath = $documentRoot . '/bitrix/modules/main/include/prolog_before.php';
            
            logMessage("getNormalizerFromIblock54: подключаем prolog_before.php из $prologPath", $config['global_log'], $config);
        
            if (!file_exists($prologPath)) {
                logMessage("getNormalizerFromIblock54: ОШИБКА - файл prolog_before.php не найден по пути $prologPath", $config['global_log'], $config);
                throw new Exception("Файл prolog_before.php не найден по пути: $prologPath");
            }
            
            require_once $prologPath;
            logMessage("getNormalizerFromIblock54: prolog_before.php подключен успешно", $config['global_log'], $config);
        } else {
            logMessage("getNormalizerFromIblock54: Bitrix API уже подключен", $config['global_log'], $config);
        }
        
        // Используем D7 API если доступен (как в тестовом файле)
        logMessage("getNormalizerFromIblock54: проверяем доступность D7 API", $config['global_log'], $config);
        if (class_exists('\Bitrix\Main\Loader')) {
            logMessage("getNormalizerFromIblock54: используем D7 API для подключения модулей", $config['global_log'], $config);
            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                throw new Exception("Не удалось подключить модуль iblock через D7 API");
            }
            logMessage("getNormalizerFromIblock54: модуль iblock подключен через D7 API", $config['global_log'], $config);
        } else {
            logMessage("getNormalizerFromIblock54: D7 API недоступен, используем старый API", $config['global_log'], $config);
            CModule::IncludeModule("iblock");
            CModule::IncludeModule("main");
            logMessage("getNormalizerFromIblock54: модули подключены через старый API", $config['global_log'], $config);
        }
        
        $iblockId = $config['iblock']['iblock_54_id'] ?? 54;
        logMessage("getNormalizerFromIblock54: используем iblock_id=$iblockId", $config['global_log'], $config);
        
        // Кэширование: проверяем кэш перед запросом к БД
        // Проверяем, что класс Cache доступен
        $cache = null;
        if (class_exists('\Bitrix\Main\Data\Cache')) {
            try {
                $cache = \Bitrix\Main\Data\Cache::createInstance();
                if (!$cache) {
                    logMessage("getNormalizerFromIblock54: Cache::createInstance() вернул null, работаем без кэша", $config['global_log'], $config);
                }
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ошибка создания кэша: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
            }
        } else {
            logMessage("getNormalizerFromIblock54: класс Cache не найден, работаем без кэша", $config['global_log'], $config);
        }
        
        $cacheId = 'normalizer_domain_' . md5($domain);
        $cacheDir = '/normalizer';
        $cacheTime = 7200; // 2 часа (данные в ИБ54 не меняются, только добавляются новые)
        
        $cacheStarted = false;
        $element = null;
        $normalizerFile = null;
        
        if ($cache) {
            try {
                logMessage("getNormalizerFromIblock54: проверяем кэш для domain='$domain'", $config['global_log'], $config);
                $cacheStarted = $cache->startDataCache($cacheTime, $cacheId, $cacheDir);
                logMessage("getNormalizerFromIblock54: startDataCache вернул " . ($cacheStarted ? "true (кэш не найден)" : "false (кэш найден)"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ошибка startDataCache: " . $e->getMessage() . ", работаем без кэша", $config['global_log'], $config);
                $cache = null;
                $cacheStarted = false;
            }
        } else {
            logMessage("getNormalizerFromIblock54: кэш недоступен, работаем без кэша", $config['global_log'], $config);
        }
        
        if ($cacheStarted) {
            // Кэш не найден, получаем данные из БД
            logMessage("getNormalizerFromIblock54: кэш не найден, запрашиваем данные из БД для domain='$domain'", $config['global_log'], $config);
            
            try {
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $domain,
                    'ACTIVE' => 'Y',
                ];
                
                logMessage("getNormalizerFromIblock54: вызываем CIBlockElement::GetList для domain='$domain'", $config['global_log'], $config);
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                
                if (!$dbRes) {
                    throw new Exception("CIBlockElement::GetList вернул false для domain='$domain'");
                }
                
                logMessage("getNormalizerFromIblock54: CIBlockElement::GetList выполнен успешно для domain='$domain'", $config['global_log'], $config);
                
                $element = $dbRes->Fetch();
                
                logMessage("getNormalizerFromIblock54: Fetch выполнен, element=" . ($element ? "найден (ID={$element['ID']})" : "не найден"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ОШИБКА в CIBlockElement::GetList: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                if ($cache) {
                    try {
                        $cache->abortDataCache();
                    } catch (Throwable $e2) {
                        logMessage("getNormalizerFromIblock54: ОШИБКА в abortDataCache: " . $e2->getMessage(), $config['global_log'], $config);
            }
                }
            return null;
        }
        } else {
            // Кэш найден или кэш недоступен
            if ($cache) {
                $normalizerFile = $cache->getVars();
                logMessage("NORMALIZER_CACHE | Domain: '$domain' | Cache: HIT (данные получены из кэша) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
                
                // Если из кэша получена пустая строка, используем нормализатор по умолчанию
                if (empty($normalizerFile)) {
                    $normalizerFile = 'generic_normalizer.php';
                    logMessage("getNormalizerFromIblock54: из кэша получена пустая строка для домена '$domain', используем generic_normalizer.php по умолчанию", $config['global_log'], $config);
                }
                
                if ($normalizerFile) {
                    return $normalizerFile;
                }
            }
            // Если кэш недоступен или данные не найдены в кэше, получаем из БД
            logMessage("getNormalizerFromIblock54: кэш недоступен или пуст, запрашиваем данные из БД для domain='$domain'", $config['global_log'], $config);
            
            try {
                $filter = [
                    'IBLOCK_ID' => $iblockId,
                    'NAME' => $domain,
                    'ACTIVE' => 'Y',
                ];
                
                logMessage("getNormalizerFromIblock54: вызываем CIBlockElement::GetList для domain='$domain'", $config['global_log'], $config);
                
                $dbRes = CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    $filter,
                    false,
                    ['nTopCount' => 1],
                    ['ID', 'NAME', 'CODE', 'XML_ID']
                );
                
                if (!$dbRes) {
                    throw new Exception("CIBlockElement::GetList вернул false для domain='$domain'");
                }
                
                logMessage("getNormalizerFromIblock54: CIBlockElement::GetList выполнен успешно для domain='$domain'", $config['global_log'], $config);
                
                $element = $dbRes->Fetch();
                
                logMessage("getNormalizerFromIblock54: Fetch выполнен, element=" . ($element ? "найден (ID={$element['ID']})" : "не найден"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ОШИБКА в CIBlockElement::GetList: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                return null;
            }
        }
        
        if (!$element) {
                if ($cache) {
                    $cache->abortDataCache();
                }
            logMessage("NORMALIZER_SEARCH_RESULT | Domain: '$domain' | Found: false", $config['global_log'], $config);
            logMessage("getNormalizerFromIblock54: элемент с NAME='$domain' не найден в инфоблоке 54", $config['global_log'], $config);
            
            // Если skipAutoMove = true, не перемещаем файл и не отправляем сообщение
            // (это делается в вызывающем коде)
            if ($skipAutoMove) {
                return null;
            }
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            // передаем реальный путь к raw-файлу, если он известен
            $fileName = $rawFilePath ?: ('raw_' . date('Ymd_His') . '_' . substr(md5($domain), 0, 8) . '.json');
            $reason = 'domain_not_found_in_iblock54';
            $details = "домен '$domain' не найден в инфоблоке 54";
            
            $errorHandler->handleError('generic', $fileName, $reason, $details, 'generic_normalizer.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            // НЕ возвращаем нормализатор - файл должен остаться в raw_errors
            return null;
        }
        
            $foundElementId = (int)$element['ID'];
            
            logMessage("getNormalizerFromIblock54: найден элемент ID=$foundElementId, получаем PROPERTY_388", $config['global_log'], $config);
            
            // Получаем PROPERTY_388 (Обработчик)
            try {
                $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId, [], ['CODE' => 'PROPERTY_388']);
                
                if (!$dbProps) {
                    throw new Exception("CIBlockElement::GetProperty вернул false для element_id=$foundElementId");
                }
                
                logMessage("getNormalizerFromIblock54: CIBlockElement::GetProperty выполнен успешно для element_id=$foundElementId", $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ОШИБКА в CIBlockElement::GetProperty: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                if ($cache) {
                    try {
                        $cache->abortDataCache();
                    } catch (Throwable $e2) {
                        logMessage("getNormalizerFromIblock54: ОШИБКА в abortDataCache: " . $e2->getMessage(), $config['global_log'], $config);
                    }
                }
                return null;
            }
            
            $normalizerFile = null;
            try {
                while ($arProp = $dbProps->Fetch()) {
                    if ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE_ENUM'])) {
                        // Для списков используем VALUE_ENUM (текстовое значение)
                        $normalizerFile = $arProp['VALUE_ENUM'];
                        break;
                    } elseif ($arProp['CODE'] === 'PROPERTY_388' && !empty($arProp['VALUE'])) {
                        // Если VALUE_ENUM пуст, используем VALUE
                        $normalizerFile = $arProp['VALUE'];
                        break;
                    }
                }
                
                logMessage("getNormalizerFromIblock54: цикл Fetch завершен, normalizerFile=" . ($normalizerFile ?: "не найден"), $config['global_log'], $config);
            } catch (Throwable $e) {
                logMessage("getNormalizerFromIblock54: ОШИБКА в цикле Fetch: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                if ($cache) {
                    try {
                        $cache->abortDataCache();
                    } catch (Throwable $e2) {
                        logMessage("getNormalizerFromIblock54: ОШИБКА в abortDataCache: " . $e2->getMessage(), $config['global_log'], $config);
                    }
                }
                return null;
            }
            
            // Если нормализатор не найден, используем generic_normalizer.php по умолчанию
            if (!$normalizerFile || empty($normalizerFile)) {
                $normalizerFile = 'generic_normalizer.php';
            }
            
            // Сохраняем в кэш
            if ($cache) {
                $cache->endDataCache($normalizerFile);
                logMessage("NORMALIZER_CACHE | Domain: '$domain' | Cache: MISS (данные получены из БД и сохранены в кэш) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            } else {
                logMessage("NORMALIZER_CACHE | Domain: '$domain' | Cache: DISABLED (данные получены из БД) | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            }
        
        if ($normalizerFile && $normalizerFile !== 'generic_normalizer.php') {
            logMessage("NORMALIZER_SEARCH_RESULT | Domain: '$domain' | Found: true | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            logMessage("getNormalizerFromIblock54: найден нормализатор '$normalizerFile' для домена '$domain'", $config['global_log'], $config);
        } else {
            logMessage("NORMALIZER_SEARCH_RESULT | Domain: '$domain' | Found: true | Normalizer: 'generic_normalizer.php' (default)", $config['global_log'], $config);
            logMessage("getNormalizerFromIblock54: PROPERTY_388 не найден или пуст для домена '$domain', используем generic_normalizer.php", $config['global_log'], $config);
        }
        
        return $normalizerFile;
        
    } catch (Throwable $e) {
        $errorMsg = "getNormalizerFromIblock54: ОШИБКА: " . $e->getMessage();
        $errorMsg .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
        $errorMsg .= " | Trace: " . $e->getTraceAsString();
        logMessage($errorMsg, $config['global_log'], $config);
        
        // Дублируем в error_log для гарантии
        error_log("getNormalizerFromIblock54: ОШИБКА: " . $e->getMessage());
        
        // Создаем ошибку через ErrorHandler
        try {
        $errorHandler = new ErrorHandler($config);
        $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
        $reason = 'database_error';
        $details = "Ошибка подключения к БД: " . $e->getMessage();
        
        $errorHandler->handleError('generic', $fileName, $reason, $details, 'generic_normalizer.php');
        
        // Перемещаем raw файл в raw_errors, если путь известен
        if ($rawFilePath && file_exists($rawFilePath)) {
            $errorHandler->moveToRawErrors($rawFilePath);
            }
        } catch (Throwable $e2) {
            logMessage("getNormalizerFromIblock54: ОШИБКА в ErrorHandler: " . $e2->getMessage(), $config['global_log'], $config);
        }
        
        return null;
    }
}

/**
 * Проверяет, является ли запрос пустым (тестовым/невалидным)
 * Пустые запросы не обрабатываются и не отправляют уведомления
 */
function isEmptyRequest($rawData) {
    $rawBody = $rawData['raw_body'] ?? '';
    $rawHeaders = $rawData['raw_headers'] ?? [];
    $parsedData = $rawData['parsed_data'] ?? [];
    
    // Проверяем условия пустого запроса
    $isEmptyBody = empty(trim($rawBody));
    $isEmptyHeaders = empty($rawHeaders['HTTP_REFERER']) && 
                      empty($rawHeaders['HTTP_ORIGIN']) && 
                      empty($rawHeaders['QUERY_STRING']);
    $isGetRequest = ($rawHeaders['REQUEST_METHOD'] ?? '') === 'GET';
    $hasOnlyUnknownDomain = count($parsedData) === 1 && 
                           isset($parsedData['source_domain']) && 
                           $parsedData['source_domain'] === 'unknown.domain';
    
    // Дополнительная проверка: нет полезных данных в parsed_data
    $hasNoUsefulData = true;
    foreach ($parsedData as $key => $value) {
        // Пропускаем source_domain, так как он всегда есть
        if ($key === 'source_domain') {
            continue;
        }
        // Если есть любое другое поле с непустым значением - это не пустой запрос
        if (!empty($value)) {
            $hasNoUsefulData = false;
            break;
        }
    }
    
    // Если все условия выполнены - это пустой запрос
    if ($isEmptyBody && $isEmptyHeaders && $isGetRequest && $hasOnlyUnknownDomain && $hasNoUsefulData) {
        return true;
    }
    
    return false;
}

/**
 * Обрабатывает файлы из папки raw и определяет их тип
 */
function processRawFiles($config) {
    $rawDir = $config['queue_dir'] . '/raw';
    $detectedDir = $config['queue_dir'] . '/detected';
    
    // Создаем папки если их нет
    if (!is_dir($rawDir)) {
        mkdir($rawDir, 0777, true);
    }
    if (!is_dir($detectedDir)) {
        mkdir($detectedDir, 0777, true);
    }
    
    // Обрабатываем только файлы в корне raw/, НЕ в подпапках
    $rawFiles = glob($rawDir . '/raw_*.json');
    
    if (empty($rawFiles)) {
        logMessage("data_type_detector: нет файлов в папке raw", $config['global_log'], $config);
        return;
    }
    
    // Ограничиваем количество обрабатываемых файлов за раз для избежания зависаний
    $maxFilesPerRun = $config['max_files_per_run'] ?? 50;
    $totalFiles = count($rawFiles);
    $filesToProcess = array_slice($rawFiles, 0, $maxFilesPerRun);
    
    logMessage("data_type_detector: найдено $totalFiles файлов для обработки, обрабатываем " . count($filesToProcess) . " файлов за этот запуск", $config['global_log'], $config);
    
    $processedCount = 0;
    $cycleStartTime = microtime(true);
    
    foreach ($filesToProcess as $rawFile) {
        // #region agent log
        $debugLogPath = __DIR__ . '/../.cursor/debug.log';
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => 'processRawFiles',
            'message' => 'RAW_FILE_PROCESSING_START',
            'data' => [
                'rawFile' => basename($rawFile),
                'timestamp' => microtime(true)
            ],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        // Блокируем raw-файл перед обработкой
        $rawLockFile = $rawFile . '.lock';
        
        // Проверяем, существует ли старый lock-файл и не устарел ли он
        if (file_exists($rawLockFile)) {
            $lockTimeout = $config['lock_timeout'] ?? 300; // 5 минут по умолчанию
            $lockTime = filemtime($rawLockFile);
            $lockAge = time() - $lockTime;
            
            if ($lockAge > $lockTimeout) {
                // Lock-файл устарел, удаляем его
                logMessage("data_type_detector: обнаружен устаревший lock-файл для " . basename($rawFile) . " (возраст: {$lockAge} сек), удаляем", $config['global_log'], $config);
                @unlink($rawLockFile);
            } else {
                // Lock-файл еще актуален, проверяем, действительно ли он заблокирован
                $testFp = @fopen($rawLockFile, 'r');
                if ($testFp !== false) {
                    $testLocked = @flock($testFp, LOCK_EX | LOCK_NB);
                    if (!$testLocked) {
                        // Файл действительно заблокирован другим процессом
                        fclose($testFp);
                        logMessage("data_type_detector: raw-файл заблокирован активным процессом, пропускаем: " . basename($rawFile), $config['global_log'], $config);
                        continue;
                    } else {
                        // Lock-файл существует, но не заблокирован - это "мертвая" блокировка
                        flock($testFp, LOCK_UN);
                        fclose($testFp);
                        logMessage("data_type_detector: обнаружена мертвая блокировка для " . basename($rawFile) . ", удаляем lock-файл", $config['global_log'], $config);
                        @unlink($rawLockFile);
                    }
                }
            }
        }
        
        $rawLockFp = @fopen($rawLockFile, 'c+');
        if ($rawLockFp === false) {
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'processRawFiles:lockRawFile',
                'message' => 'RAW_FILE_LOCK_FAILED',
                'data' => ['rawFile' => basename($rawFile), 'timestamp' => microtime(true)],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            logMessage("data_type_detector: не удалось открыть lock-файл для " . basename($rawFile) . ", пропускаем", $config['global_log'], $config);
            continue;
        }
        
        $rawLocked = @flock($rawLockFp, LOCK_EX | LOCK_NB);
        if (!$rawLocked) {
            fclose($rawLockFp);
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'processRawFiles:lockRawFile',
                'message' => 'RAW_FILE_ALREADY_LOCKED',
                'data' => ['rawFile' => basename($rawFile), 'timestamp' => microtime(true)],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            logMessage("data_type_detector: raw-файл уже заблокирован другим процессом, пропускаем: " . basename($rawFile), $config['global_log'], $config);
            continue;
        }
        
        // Записываем время блокировки в файл
        ftruncate($rawLockFp, 0);
        fwrite($rawLockFp, date('Y-m-d H:i:s'));
        fflush($rawLockFp);
        
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => 'processRawFiles:lockRawFile',
            'message' => 'RAW_FILE_LOCKED',
            'data' => ['rawFile' => basename($rawFile), 'timestamp' => microtime(true)],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        // Перед любой новой обработкой raw-файла чистим возможные "хвосты"
        // в detected/normalized, которые могли остаться от прошлых неуспешных запусков.
        try {
            cleanupDerivedQueuesForRaw($rawFile, $config);
        } catch (Throwable $e) {
            logMessage(
                "data_type_detector: ошибка очистки производных очередей для raw-файла " . basename($rawFile) . ": " . $e->getMessage(),
                $config['global_log'],
                $config
            );
        }

        // Проверяем таймаут выполнения (максимум 25 секунд на весь цикл)
        $maxExecutionTime = $config['max_execution_time'] ?? 25;
        $totalElapsed = microtime(true) - $cycleStartTime;
        if ($totalElapsed > $maxExecutionTime) {
            logMessage("data_type_detector: достигнут лимит времени выполнения, обработано $processedCount из " . count($filesToProcess) . " файлов", $config['global_log'], $config);
            // Освобождаем блокировку перед break
            if (isset($rawLockFp) && $rawLockFp !== false) {
                @flock($rawLockFp, LOCK_UN);
                fclose($rawLockFp);
            }
            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                @unlink($rawLockFile);
            }
            break;
        }
        
        $fileStartTime = microtime(true);
        $maxFileProcessingTime = 10; // Максимум 10 секунд на обработку одного файла
        
        try {
            $rawData = json_decode(file_get_contents($rawFile), true);
            
            if (!$rawData) {
                logMessage("data_type_detector: ошибка декодирования JSON в файле " . basename($rawFile), $config['global_log'], $config);
                $errorHandler = new ErrorHandler($config);
                $errorHandler->handleError('json_decode_error', $rawFile, 'json_decode_failed', 'Ошибка декодирования JSON для файла ' . basename($rawFile), 'data_type_detector.php');
                // Перемещаем raw файл в raw_errors
                $errorHandler->moveToRawErrors($rawFile);
                // Освобождаем блокировку перед continue
                if (isset($rawLockFp) && $rawLockFp !== false) {
                    @flock($rawLockFp, LOCK_UN);
                    fclose($rawLockFp);
                }
                if (isset($rawLockFile) && file_exists($rawLockFile)) {
                    @unlink($rawLockFile);
                }
                continue;
            }
            
            // Извлекаем данные из новой структуры
            $domain = $rawData['source_domain'] ?? 'unknown';
            $parsedData = $rawData['parsed_data'] ?? $rawData; // fallback для старых файлов
            $rawBody = $rawData['raw_body'] ?? '';
            $rawHeaders = $rawData['raw_headers'] ?? [];
            
            // Проверяем, является ли это Bitrix24 webhook'ом
            $normalizerFile = null;
            
            // АГРЕССИВНАЯ ДИАГНОСТИКА - всегда логируем
            $logMsg = "🔍 ДИАГНОСТИКА: файл=" . basename($rawFile) . ", domain='$domain', QUERY_STRING='" . ($rawHeaders['QUERY_STRING'] ?? 'НЕТ') . "'";
            logMessage($logMsg, $config['global_log'], $config);
            // error_log($logMsg); // Дублируем в системный лог
            
            // Проверяем, является ли это Bitrix24 webhook'ом
            // Проверяем наличие признаков Bitrix24 webhook в QUERY_STRING или raw_body
            // независимо от значения domain (ранее проверялось только для unknown.domain)
            $queryString = $rawHeaders['QUERY_STRING'] ?? '';
            $isBitrix24Webhook = false;
            
            if (!empty($queryString)) {
                $decoded = urldecode($queryString);
                // Проверяем наличие fields[SOURCE_DESCRIPTION] или fields[ASSIGNED_BY_ID] или ASSIGNED_BY_ID=
                if (preg_match('/fields\[SOURCE_DESCRIPTION\]=|fields\[ASSIGNED_BY_ID\]=|ASSIGNED_BY_ID=/', $decoded)) {
                    $isBitrix24Webhook = true;
                }
            }
            
            // Также проверяем raw_body на наличие признаков Bitrix24 webhook
            if (!$isBitrix24Webhook && !empty($rawBody)) {
                $decodedBody = urldecode($rawBody);
                if (preg_match('/fields\[SOURCE_DESCRIPTION\]=|fields\[ASSIGNED_BY_ID\]=/', $decodedBody)) {
                    $isBitrix24Webhook = true;
                } else {
                    // Проверяем JSON формат
                    $jsonData = json_decode($rawBody, true);
                    if ($jsonData && isset($jsonData['ASSIGNED_BY_ID'])) {
                        $isBitrix24Webhook = true;
                    }
                }
            }
            
            if ($isBitrix24Webhook || ($domain === 'unknown.domain' && (!empty($queryString) || !empty($rawBody)))) {
                // Парсим QUERY_STRING для поиска SOURCE_DESCRIPTION
                // $queryString уже определен выше
                $decoded = urldecode($queryString);
                
                $logMsg = "🔍 BITRIX24 УСЛОВИЕ ВЫПОЛНЕНО: декодированный QUERY_STRING: '$decoded'";
                logMessage($logMsg, $config['global_log'], $config);
                // error_log($logMsg);
                
                $matched = false;
                if (preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', $decoded, $matches)) {
                    $matched = true;
                    $sourceDescription = $matches[1];
                    $logMsg = "🔍 SOURCE_DESCRIPTION НАЙДЕН: '$sourceDescription'";
                    logMessage($logMsg, $config['global_log'], $config);
                    // error_log($logMsg);
                    
                    // Ищем нормализатор по SOURCE_DESCRIPTION
                    try {
                        // Проверяем таймаут обработки файла
                        $fileElapsed = microtime(true) - $fileStartTime;
                        if ($fileElapsed > $maxFileProcessingTime) {
                            logMessage("data_type_detector: превышен таймаут обработки файла " . basename($rawFile) . " ($fileElapsed сек), пропускаем", $config['global_log'], $config);
                            // Освобождаем блокировку перед continue
                            if (isset($rawLockFp) && $rawLockFp !== false) {
                                @flock($rawLockFp, LOCK_UN);
                                fclose($rawLockFp);
                            }
                            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                                @unlink($rawLockFile);
                            }
                            continue;
                        }
                        
                        logMessage("data_type_detector: вызываем getNormalizerFromIblock54BySourceDescription для '$sourceDescription'", $config['global_log'], $config);
                        $normalizerFile = getNormalizerFromIblock54BySourceDescription($sourceDescription, $config, $rawFile);
                        logMessage("data_type_detector: getNormalizerFromIblock54BySourceDescription вернул " . ($normalizerFile ?? 'null') . " для '$sourceDescription'", $config['global_log'], $config);
                    } catch (Throwable $e) {
                        logMessage("data_type_detector: ОШИБКА в getNormalizerFromIblock54BySourceDescription для '$sourceDescription': " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
                        $normalizerFile = null;
                    }
                    
                    // Если нормализатор не найден (SOURCE_DESCRIPTION не в ИБ54), файл уже перемещен в raw_errors
                    if ($normalizerFile === null) {
                        logMessage("data_type_detector: файл пропущен - SOURCE_DESCRIPTION '$sourceDescription' не найден в ИБ54, файл перемещен в raw_errors: " . basename($rawFile), $config['global_log'], $config);
                        // Освобождаем блокировку перед continue
                        if (isset($rawLockFp) && $rawLockFp !== false) {
                            @flock($rawLockFp, LOCK_UN);
                            fclose($rawLockFp);
                        }
                        if (isset($rawLockFile) && file_exists($rawLockFile)) {
                            @unlink($rawLockFile);
                        }
                        continue;
                    }
                    
                    // ВАЖНО: Обновляем source_domain на SOURCE_DESCRIPTION, чтобы при создании лида можно было найти элемент в ИБ54
                    $oldDomain = $domain;
                    $domain = $sourceDescription;
                    $rawData['source_domain'] = $sourceDescription;
                    logMessage("🔁 SOURCE_DESCRIPTION_APPLIED_AS_DOMAIN | old='$oldDomain' -> new='$domain'", $config['global_log'], $config);
                    // error_log("SOURCE_DESCRIPTION_APPLIED_AS_DOMAIN: '$oldDomain' -> '$domain'");
                }
                
                // НОВАЯ ЛОГИКА: Проверяем ASSIGNED_BY_ID (простой формат)
                if (!$matched && preg_match('/ASSIGNED_BY_ID=([^&]*)/', $decoded, $matches)) {
                    // Проверяем таймаут обработки файла
                    $fileElapsed = microtime(true) - $fileStartTime;
                    if ($fileElapsed > $maxFileProcessingTime) {
                        logMessage("data_type_detector: превышен таймаут обработки файла " . basename($rawFile) . " ($fileElapsed сек), пропускаем", $config['global_log'], $config);
                        // Освобождаем блокировку перед continue
                        if (isset($rawLockFp) && $rawLockFp !== false) {
                            @flock($rawLockFp, LOCK_UN);
                            fclose($rawLockFp);
                        }
                        if (isset($rawLockFile) && file_exists($rawLockFile)) {
                            @unlink($rawLockFile);
                        }
                        continue;
                    }
                    
                    $matched = true;
                    $assignedById = urldecode($matches[1]);
                    $logMsg = "🔍 ASSIGNED_BY_ID НАЙДЕН: '$assignedById'";
                    logMessage($logMsg, $config['global_log'], $config);
                    // error_log($logMsg);
                    
                    // Ищем нормализатор по ASSIGNED_BY_ID как по названию элемента в ИБ54
                    try {
                        $normalizerFile = getNormalizerFromIblock54ByTitle($assignedById, $config, $rawFile);
                    } catch (Throwable $e) {
                        logMessage("data_type_detector: ОШИБКА в getNormalizerFromIblock54ByTitle для '$assignedById': " . $e->getMessage(), $config['global_log'], $config);
                        $normalizerFile = null;
                    }
                    
                    if ($normalizerFile === null) {
                        logMessage("data_type_detector: файл пропущен - ASSIGNED_BY_ID '$assignedById' не найден в ИБ54, файл перемещен в raw_errors: " . basename($rawFile), $config['global_log'], $config);
                        // Освобождаем блокировку перед continue
                        if (isset($rawLockFp) && $rawLockFp !== false) {
                            @flock($rawLockFp, LOCK_UN);
                            fclose($rawLockFp);
                        }
                        if (isset($rawLockFile) && file_exists($rawLockFile)) {
                            @unlink($rawLockFile);
                        }
                        continue;
                    }
                    
                    // ВАЖНО: Обновляем source_domain на ASSIGNED_BY_ID, чтобы при создании лида можно было найти элемент в ИБ54
                    $oldDomain = $domain;
                    $domain = $assignedById;
                    $rawData['source_domain'] = $assignedById;
                    logMessage("🔁 ASSIGNED_BY_ID_APPLIED_AS_DOMAIN | old='$oldDomain' -> new='$domain'", $config['global_log'], $config);
                    // error_log("ASSIGNED_BY_ID_APPLIED_AS_DOMAIN: '$oldDomain' -> '$domain'");
                }

                // Fallback: попытка извлечь SOURCE_DESCRIPTION из raw_body
                if (!$matched && !empty($rawBody)) {
                    $decodedBody = urldecode($rawBody);
                    logMessage("🔍 BITRIX24 Fallback: декодированный RAW_BODY: '" . $decodedBody . "'", $config['global_log'], $config);
                    if (preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', $decodedBody, $m2)) {
                        $matched = true;
                        $sourceDescription = $m2[1];
                        $logMsg = "🔍 SOURCE_DESCRIPTION НАЙДЕН (BODY): '" . $sourceDescription . "'";
                        logMessage($logMsg, $config['global_log'], $config);
                        // error_log($logMsg);
                        // Проверяем таймаут обработки файла
                        $fileElapsed = microtime(true) - $fileStartTime;
                        if ($fileElapsed > $maxFileProcessingTime) {
                            logMessage("data_type_detector: превышен таймаут обработки файла " . basename($rawFile) . " ($fileElapsed сек), пропускаем", $config['global_log'], $config);
                            // Освобождаем блокировку перед continue
                            if (isset($rawLockFp) && $rawLockFp !== false) {
                                @flock($rawLockFp, LOCK_UN);
                                fclose($rawLockFp);
                            }
                            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                                @unlink($rawLockFile);
                            }
                            continue;
                        }
                        
                        try {
                            $normalizerFile = getNormalizerFromIblock54BySourceDescription($sourceDescription, $config, $rawFile);
                        } catch (Throwable $e) {
                            logMessage("data_type_detector: ОШИБКА в getNormalizerFromIblock54BySourceDescription (BODY) для '$sourceDescription': " . $e->getMessage(), $config['global_log'], $config);
                            $normalizerFile = null;
                        }
                        if ($normalizerFile === null) {
                            logMessage("data_type_detector: файл пропущен - SOURCE_DESCRIPTION '" . $sourceDescription . "' не найден в ИБ54, файл перемещен в raw_errors: " . basename($rawFile), $config['global_log'], $config);
                            // Освобождаем блокировку перед continue
                            if (isset($rawLockFp) && $rawLockFp !== false) {
                                @flock($rawLockFp, LOCK_UN);
                                fclose($rawLockFp);
                            }
                            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                                @unlink($rawLockFile);
                            }
                            continue;
                        }
                        
                        // ВАЖНО: Обновляем source_domain на SOURCE_DESCRIPTION, чтобы при создании лида можно было найти элемент в ИБ54
                        $oldDomain = $domain;
                        $domain = $sourceDescription;
                        $rawData['source_domain'] = $sourceDescription;
                        logMessage("🔁 SOURCE_DESCRIPTION_APPLIED_AS_DOMAIN (BODY) | old='$oldDomain' -> new='$domain'", $config['global_log'], $config);
                        // error_log("SOURCE_DESCRIPTION_APPLIED_AS_DOMAIN (BODY): '$oldDomain' -> '$domain'");
                    }
                }
                
                // НОВАЯ ЛОГИКА: Проверяем ASSIGNED_BY_ID в JSON формате (raw_body)
                if (!$matched && !empty($rawBody)) {
                    $jsonData = json_decode($rawBody, true);
                    if ($jsonData && isset($jsonData['ASSIGNED_BY_ID'])) {
                        $matched = true;
                        $assignedById = $jsonData['ASSIGNED_BY_ID'];
                        $logMsg = "🔍 ASSIGNED_BY_ID НАЙДЕН (JSON): '$assignedById'";
                        logMessage($logMsg, $config['global_log'], $config);
                        // error_log($logMsg);
                        
                        // Проверяем таймаут обработки файла
                        $fileElapsed = microtime(true) - $fileStartTime;
                        if ($fileElapsed > $maxFileProcessingTime) {
                            logMessage("data_type_detector: превышен таймаут обработки файла " . basename($rawFile) . " ($fileElapsed сек), пропускаем", $config['global_log'], $config);
                            // Освобождаем блокировку перед continue
                            if (isset($rawLockFp) && $rawLockFp !== false) {
                                @flock($rawLockFp, LOCK_UN);
                                fclose($rawLockFp);
                            }
                            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                                @unlink($rawLockFile);
                            }
                            continue;
                        }
                        
                        // Ищем нормализатор по ASSIGNED_BY_ID как по названию элемента в ИБ54
                        try {
                            $normalizerFile = getNormalizerFromIblock54ByTitle($assignedById, $config, $rawFile);
                        } catch (Throwable $e) {
                            logMessage("data_type_detector: ОШИБКА в getNormalizerFromIblock54ByTitle (JSON) для '$assignedById': " . $e->getMessage(), $config['global_log'], $config);
                            $normalizerFile = null;
                        }
                        
                        if ($normalizerFile === null) {
                            logMessage("data_type_detector: файл пропущен - ASSIGNED_BY_ID '$assignedById' не найден в ИБ54, файл перемещен в raw_errors: " . basename($rawFile), $config['global_log'], $config);
                            // Освобождаем блокировку перед continue
                            if (isset($rawLockFp) && $rawLockFp !== false) {
                                @flock($rawLockFp, LOCK_UN);
                                fclose($rawLockFp);
                            }
                            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                                @unlink($rawLockFile);
                            }
                            continue;
                        }
                        
                        // ВАЖНО: Обновляем source_domain на ASSIGNED_BY_ID, чтобы при создании лида можно было найти элемент в ИБ54
                        $oldDomain = $domain;
                        $domain = $assignedById;
                        $rawData['source_domain'] = $assignedById;
                        logMessage("🔁 ASSIGNED_BY_ID_APPLIED_AS_DOMAIN (JSON) | old='$oldDomain' -> new='$domain'", $config['global_log'], $config);
                        // error_log("ASSIGNED_BY_ID_APPLIED_AS_DOMAIN (JSON): '$oldDomain' -> '$domain'");
                    }
                }

                if (!$matched) {
                    logMessage("data_type_detector: SOURCE_DESCRIPTION не найден в QUERY_STRING", $config['global_log'], $config);
                    // Дополнительно: если домен неизвестен, пробуем вытащить source_domain из QUERY_STRING
                    // Это покрывает кейсы GET, когда домен приходит как простой параметр
                    $qs = [];
                    parse_str($queryString, $qs);
                    if (!empty($qs['source_domain'])) {
                        $newDomain = trim((string)$qs['source_domain']);
                        if ($newDomain !== '') {
                            $oldDomain = $domain;
                            $domain = $newDomain;
                            // Также обновим в rawData для сквозного сохранения в detected
                            $rawData['source_domain'] = $newDomain;
                            logMessage("🔁 QUERY_SOURCE_DOMAIN_APPLIED | old='$oldDomain' -> new='$newDomain'", $config['global_log'], $config);
                            // error_log("QUERY_SOURCE_DOMAIN_APPLIED: '$oldDomain' -> '$newDomain'");
                        }
                    }
                }
            } else {
                logMessage("data_type_detector: не Bitrix24 webhook - domain='$domain', QUERY_STRING пустой", $config['global_log'], $config);
            }
            
            // Если не Bitrix24 webhook или не найден по SOURCE_DESCRIPTION, ищем по домену
            if ($normalizerFile === null) {
                // Проверяем, не является ли это пустым запросом
                if (isEmptyRequest($rawData)) {
                    logMessage("data_type_detector: пропущен пустой/тестовый запрос: " . basename($rawFile), $config['global_log'], $config);
                    
                    // Просто перемещаем в raw_errors БЕЗ отправки сообщения в чат
                    $errorHandler = new ErrorHandler($config);
                    $errorHandler->moveToRawErrors($rawFile);
                    
                    // Освобождаем блокировку перед continue
                    if (isset($rawLockFp) && $rawLockFp !== false) {
                        @flock($rawLockFp, LOCK_UN);
                        fclose($rawLockFp);
                    }
                    if (isset($rawLockFile) && file_exists($rawLockFile)) {
                        @unlink($rawLockFile);
                    }
                    continue;
                }
                
                // Шаг 1: Всегда сначала ищем по source_domain (даже если это unknown.domain)
                // Проверяем таймаут обработки файла
                $fileElapsed = microtime(true) - $fileStartTime;
                if ($fileElapsed > $maxFileProcessingTime) {
                    logMessage("data_type_detector: превышен таймаут обработки файла " . basename($rawFile) . " ($fileElapsed сек), пропускаем", $config['global_log'], $config);
                    // Освобождаем блокировку перед continue
                    if (isset($rawLockFp) && $rawLockFp !== false) {
                        @flock($rawLockFp, LOCK_UN);
                        fclose($rawLockFp);
                    }
                    if (isset($rawLockFile) && file_exists($rawLockFile)) {
                        @unlink($rawLockFile);
                    }
                    continue;
                }
                
                try {
                    $normalizerFile = getNormalizerFromIblock54($domain, $config, $rawFile);
                } catch (Throwable $e) {
                    logMessage("data_type_detector: ОШИБКА в getNormalizerFromIblock54 для '$domain': " . $e->getMessage(), $config['global_log'], $config);
                    $normalizerFile = null;
                }
                
                if ($normalizerFile !== null) {
                    logMessage("data_type_detector: нормализатор найден по source_domain '$domain': '$normalizerFile'", $config['global_log'], $config);
                } else {
                    // Шаг 2: Если не нашли по source_domain, проверяем есть ли formid
                    $formid = $parsedData['formid'] ?? null;
                    
                    if (!empty($formid)) {
                        logMessage("data_type_detector: source_domain '$domain' не найден в ИБ54, пробуем поиск по formid: '$formid'", $config['global_log'], $config);
                        
                        // Обновляем domain на formid перед поиском (для единообразного сообщения об ошибке)
                        $domain = $formid;
                        $rawData['source_domain'] = $formid;
                        
                        // Ищем по formid (используем ту же функцию, она ищет по NAME)
                        // ВАЖНО: передаем null в rawFilePath и skipAutoMove=true, чтобы функция НЕ перемещала файл в raw_errors
                        // (мы сделаем это сами с правильным сообщением)
                        $normalizerFile = getNormalizerFromIblock54($formid, $config, null, true);
                        
                        if ($normalizerFile !== null) {
                            logMessage("data_type_detector: нормализатор найден по formid '$formid': '$normalizerFile'", $config['global_log'], $config);
                        } else {
                            logMessage("data_type_detector: formid '$formid' также не найден в ИБ54", $config['global_log'], $config);
                        }
                    } else {
                        logMessage("data_type_detector: source_domain '$domain' не найден и formid отсутствует", $config['global_log'], $config);
                    }
                }
                
                // Шаг 3: Если не найден → отправляем сообщение и перемещаем в raw_errors
                if ($normalizerFile === null) {
                    $fileName = basename($rawFile);
                    $reason = 'domain_not_found_in_iblock54';
                    $details = "'$domain' не найден в ИБ54. Добавьте в ИБ54 с нормализатором.";
                    
                    logMessage("data_type_detector: файл пропущен - '$domain' не найден в ИБ54, отправляем сообщение в чат", $config['global_log'], $config);
                    
                    // Отправляем ошибку через ErrorHandler (он отправит сообщение в чат и переместит файл)
                    $errorHandler = new ErrorHandler($config);
                    $errorHandler->handleError('data_type_detector', $fileName, $reason, $details, 'generic_normalizer.php');
                    
                    // Перемещаем файл в raw_errors
                    $errorHandler->moveToRawErrors($rawFile);
                    
                    // Освобождаем блокировку перед continue
                    if (isset($rawLockFp) && $rawLockFp !== false) {
                        @flock($rawLockFp, LOCK_UN);
                        fclose($rawLockFp);
                    }
                    if (isset($rawLockFile) && file_exists($rawLockFile)) {
                        @unlink($rawLockFile);
                    }
                    continue;
                }
            }
            
            // Формируем данные с определенным нормализатором
            $detectedData = [
                'normalizer_file' => $normalizerFile,
                'raw_data' => $rawData,
                'parsed_data' => $parsedData,
                'raw_body' => $rawBody,
                'raw_headers' => $rawHeaders,
                'detected_at' => date('Y-m-d H:i:s'),
                'source_domain' => $domain,
                'original_file' => basename($rawFile),
                'raw_file_path' => $rawFile,
                'raw_file_name' => basename($rawFile),
                'request_id' => $rawData['request_id'] ?? null
            ];
            
            // Сохраняем в папку detected
            $detectedFile = saveToQueue($detectedData, $detectedDir, 'detected_');
            // Не удаляем raw-файл: он должен сохраняться до успешного создания лида
            
            $processedCount++;
            logMessage("data_type_detector: нормализатор определен как '$normalizerFile' для файла " . basename($rawFile) . " -> " . basename($detectedFile), $config['global_log'], $config);
            
            // Освобождаем блокировку raw-файла
            flock($rawLockFp, LOCK_UN);
            fclose($rawLockFp);
            @unlink($rawLockFile);
            
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'processRawFiles',
                'message' => 'RAW_FILE_PROCESSING_SUCCESS',
                'data' => ['rawFile' => basename($rawFile), 'timestamp' => microtime(true)],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            
        } catch (Throwable $e) {
            // Освобождаем блокировку при ошибке
            logMessage("data_type_detector: КРИТИЧЕСКАЯ ОШИБКА при обработке файла " . basename($rawFile) . ": " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine(), $config['global_log'], $config);
            if (isset($rawLockFp) && $rawLockFp !== false) {
                @flock($rawLockFp, LOCK_UN);
                fclose($rawLockFp);
            }
            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                @unlink($rawLockFile);
            }
            
            logMessage("data_type_detector: ошибка обработки файла " . basename($rawFile) . ": " . $e->getMessage(), $config['global_log'], $config);
            $errorHandler = new ErrorHandler($config);
            $errorHandler->handleError('processing_exception', $rawFile, 'processing_exception', $e->getMessage(), 'data_type_detector.php');
            // Перемещаем raw файл в raw_errors
            $errorHandler->moveToRawErrors($rawFile);
        } catch (Throwable $e) {
            // Освобождаем блокировку при критической ошибке
            if (isset($rawLockFp) && $rawLockFp !== false) {
                @flock($rawLockFp, LOCK_UN);
                fclose($rawLockFp);
            }
            if (isset($rawLockFile) && file_exists($rawLockFile)) {
                @unlink($rawLockFile);
            }
            
            logMessage("data_type_detector: критическая ошибка обработки файла " . basename($rawFile) . ": " . $e->getMessage(), $config['global_log'], $config);
            $errorHandler = new ErrorHandler($config);
            $errorHandler->handleError('processing_exception', $rawFile, 'processing_exception', $e->getMessage(), 'data_type_detector.php');
            $errorHandler->moveToRawErrors($rawFile);
        }
    }
    
    if ($processedCount > 0) {
        logMessage("data_type_detector: обработка завершена, обработано $processedCount файлов", $config['global_log'], $config);
    }
}

/**
 * Очищает производные очереди (detected / normalized) для конкретного raw-файла.
 *
 * Идея: если исходный raw-файл всё ещё лежит в очереди raw, именно он считается
 * единственным источником истины. Все ранее созданные по нему detected/normalized
 * должны быть удалены, чтобы не плодить дубли при повторных запусках обработки.
 *
 * Удаляются только файловые следы в очередях, ссылки на которые явно указывают
 * на данный raw (по полям raw_file_name / original_file / raw_file_path).
 */
function cleanupDerivedQueuesForRaw($rawFilePath, $config) {
    $queueDir = $config['queue_dir'] ?? (__DIR__ . '/queue');
    $rawBaseName = basename($rawFilePath);

    $targets = [
        $queueDir . '/detected',
        $queueDir . '/normalized',
    ];

    foreach ($targets as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $files = glob($dir . '/*.json');
        if (!$files) {
            continue;
        }

        foreach ($files as $file) {
            $json = @file_get_contents($file);
            if ($json === false) {
                continue;
            }

            $data = json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }

            // В detected/normalized мы всегда прокидываем эти поля
            $rawName = $data['raw_file_name'] ?? $data['original_file'] ?? null;
            $rawPath = $data['raw_file_path'] ?? null;

            $isSameByName = $rawName !== null && $rawName === $rawBaseName;
            $isSameByPath = $rawPath !== null && $rawPath === $rawFilePath;

            if (!$isSameByName && !$isSameByPath) {
                continue;
            }

            // Проверяем, не заблокирован ли файл
            $lockFile = $file . '.lock';
            $isLocked = false;
            
            if (file_exists($lockFile)) {
                // Проверяем, не устарела ли блокировка
                $lockTimeout = $config['lock_timeout'] ?? 300;
                $lockTime = filemtime($lockFile);
                if (time() - $lockTime > $lockTimeout) {
                    @unlink($lockFile);
                } else {
                    // Пытаемся открыть lock-файл для проверки активной блокировки
                    $lockFp = @fopen($lockFile, 'r');
                    if ($lockFp !== false) {
                        // Пытаемся получить блокировку (неблокирующая)
                        if (@flock($lockFp, LOCK_EX | LOCK_NB)) {
                            // Файл не заблокирован, можно удалить
                            flock($lockFp, LOCK_UN);
                            fclose($lockFp);
                            @unlink($lockFile);
                        } else {
                            // Файл заблокирован, пропускаем удаление
                            fclose($lockFp);
                            $isLocked = true;
                            
                            // #region agent log
                            $debugLogPath = __DIR__ . '/../.cursor/debug.log';
                            $debugData = [
                                'sessionId' => 'debug-session',
                                'runId' => 'run1',
                                'hypothesisId' => 'D',
                                'location' => 'cleanupDerivedQueuesForRaw',
                                'message' => 'CLEANUP_SKIP_LOCKED_FILE',
                                'data' => [
                                    'file' => basename($file),
                                    'rawFile' => $rawBaseName,
                                    'timestamp' => microtime(true)
                                ],
                                'timestamp' => (int)(microtime(true) * 1000)
                            ];
                            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                            // #endregion
                        }
                    }
                }
            }
            
            // Удаляем файл только если он не заблокирован
            if (!$isLocked) {
                @unlink($file);

                logMessage(
                    "cleanupDerivedQueuesForRaw: удалён файл '" . basename($file) . "' для raw '" . $rawBaseName . "'",
                    $config['global_log'],
                    $config
                );
            }
        }
    }
}

/**
 * Получает статистику по нормализаторам
 */
function getNormalizerStats($config) {
    $detectedDir = $config['queue_dir'] . '/detected';
    
    if (!is_dir($detectedDir)) {
        return [];
    }
    
    $files = glob($detectedDir . '/*.json');
    $stats = [];
    
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['normalizer_file'])) {
            $normalizer = $data['normalizer_file'];
            $stats[$normalizer] = ($stats[$normalizer] ?? 0) + 1;
        }
    }
    
    return $stats;
}

/**
 * Обрабатывает один файл (для использования в других скриптах)
 */
function detectSingleFile($filePath, $config) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $rawData = json_decode(file_get_contents($filePath), true);
    
    if (!$rawData) {
        return false;
    }
    
    $dataType = detectDataType($rawData);
    
    return [
        'type' => $dataType,
        'raw_data' => $rawData,
        'detected_at' => date('Y-m-d H:i:s'),
        'source_domain' => $rawData['source_domain'] ?? 'unknown'
    ];
}

// Поддержка запуска из CLI: php data_type_detector.php
if (PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    if (isset($argv[1]) && $argv[1] === 'run') {
        $config = require __DIR__ . '/config.php';
        processRawFiles($config);
        echo "OK\n";
    } elseif (isset($argv[1]) && $argv[1] === 'stats') {
        $config = require __DIR__ . '/config.php';
        $stats = getNormalizerStats($config);
        echo "Статистика по нормализаторам:\n";
        foreach ($stats as $normalizer => $count) {
            echo "  $normalizer: $count файлов\n";
        }
    }
}
