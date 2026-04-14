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
            logMessage("getNormalizerFromIblock54BySourceDescription: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'database_error';
            $details = "Ошибка подключения к БД: " . $mysqli->connect_error;
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'bitrix24_webhook_normalizer.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = SOURCE_DESCRIPTION
        $stmt = $mysqli->prepare("
            SELECT DISTINCT e.ID, e.NAME, e.CODE, e.XML_ID
            FROM b_iblock_element e
            WHERE e.IBLOCK_ID = 54 
            AND e.NAME = ?
        ");
        $stmt->bind_param("s", $sourceDescription);
        $stmt->execute();
        $result = $stmt->get_result();
        $element = $result->fetch_assoc();
        
        if (!$element) {
            $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$sourceDescription' в ИБ54";
            logMessage($logMsg, $config['global_log'], $config);
            // error_log($logMsg);
            
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
        
        // Получаем свойство PROPERTY_388 (нормализатор) как ТЕКСТ варианта через join с b_iblock_property_enum
        $stmt = $mysqli->prepare("
            SELECT pe.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property_enum pe ON pe.ID = ep.VALUE
            WHERE ep.IBLOCK_ELEMENT_ID = ?
              AND ep.IBLOCK_PROPERTY_ID = 388
        ");
        $stmt->bind_param("i", $element['ID']);
        $stmt->execute();
        $result = $stmt->get_result();
        $property = $result->fetch_assoc();
        
        $normalizerFile = $property['VALUE'] ?? 'bitrix24_webhook_normalizer.php';
        $elemName = $element['NAME'] ?? '';
        $elemLen = function_exists('mb_strlen') ? mb_strlen($elemName) : strlen($elemName);
        $elemHex = bin2hex($elemName);
        logMessage("🔍 ЭЛЕМЕНТ НАЙДЕН: ID={$element['ID']} | NAME='{$elemName}' | name_len={$elemLen} | name_hex={$elemHex}", $config['global_log'], $config);
        logMessage("🔍 PROPERTY_388: '" . ($normalizerFile ?? '') . "'", $config['global_log'], $config);
        
        $logMsg = "🔍 ЭЛЕМЕНТ НАЙДЕН: ID={$element['ID']}, NAME='{$element['NAME']}', нормализатор='$normalizerFile'";
        logMessage($logMsg, $config['global_log'], $config);
        // error_log($logMsg);
        
        $mysqli->close();
        logMessage("🔍 SELECTED_NORMALIZER_FILE='$normalizerFile' (по SOURCE_DESCRIPTION)", $config['global_log'], $config);
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
            logMessage("getNormalizerFromIblock54ByTitle: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'database_error';
            $details = "Ошибка подключения к БД: " . $mysqli->connect_error;
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'data_type_detector.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = title
        $stmt = $mysqli->prepare("
            SELECT DISTINCT e.ID, e.NAME, e.CODE, e.XML_ID
            FROM b_iblock_element e
            WHERE e.IBLOCK_ID = 54 
            AND e.NAME = ?
        ");
        $stmt->bind_param("s", $title);
        $stmt->execute();
        $result = $stmt->get_result();
        $element = $result->fetch_assoc();
        
        if (!$element) {
            $logMsg = "🔍 ЭЛЕМЕНТ НЕ НАЙДЕН: '$title' в ИБ54";
            logMessage($logMsg, $config['global_log'], $config);
            // error_log($logMsg);
            
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
            
            $mysqli->close();
            return null;
        }
        
        // СКВОЗНОЕ ЛОГИРОВАНИЕ: Элемент найден
        logMessage("NORMALIZER_SEARCH_RESULT | Title: '$title' | Found: true | Element ID: " . $element['ID'] . " | Element Name: '" . $element['NAME'] . "'", $config['global_log'], $config);
        
        // Получаем нормализатор из PROPERTY_388
        $stmt = $mysqli->prepare("
            SELECT ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            WHERE ep.IBLOCK_ELEMENT_ID = ?
            AND p.CODE = 'PROPERTY_388'
        ");
        $stmt->bind_param("i", $element['ID']);
        $stmt->execute();
        $result = $stmt->get_result();
        $normalizerRow = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($normalizerRow && !empty($normalizerRow['VALUE'])) {
            $normalizerFile = $normalizerRow['VALUE'];
            $logMsg = "🔍 НОРМАЛИЗАТОР НАЙДЕН: '$normalizerFile' для элемента '$title'";
            logMessage($logMsg, $config['global_log'], $config);
            // error_log($logMsg);
            return $normalizerFile;
        } else {
            $logMsg = "🔍 НОРМАЛИЗАТОР НЕ НАЙДЕН: PROPERTY_388 пуст для элемента '$title'";
            logMessage($logMsg, $config['global_log'], $config);
            // error_log($logMsg);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'normalizer_not_found';
            $details = "Нормализатор не найден в PROPERTY_388 для элемента '$title'";
            
            $errorHandler->handleError('bitrix24_webhook', $fileName, $reason, $details, 'data_type_detector.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
        
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
            logMessage("getNormalizerFromIblock54: ОШИБКА подключения к БД: " . $mysqli->connect_error, $config['global_log'], $config);
            
            // Создаем ошибку через ErrorHandler
            $errorHandler = new ErrorHandler($config);
            $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
            $reason = 'database_error';
            $details = "Ошибка подключения к БД: " . $mysqli->connect_error;
            
            $errorHandler->handleError('generic', $fileName, $reason, $details, 'generic_normalizer.php');
            
            // Перемещаем raw файл в raw_errors, если путь известен
            if ($rawFilePath && file_exists($rawFilePath)) {
                $errorHandler->moveToRawErrors($rawFilePath);
            }
            
            return null;
        }
        $mysqli->set_charset("utf8");
        
        // Получаем элемент из инфоблока 54 по NAME = domain
        $stmt = $mysqli->prepare("
            SELECT DISTINCT e.ID, e.NAME, e.CODE, e.XML_ID
            FROM b_iblock_element e
            WHERE e.IBLOCK_ID = 54 
            AND e.NAME = ?
        ");
        $stmt->bind_param("s", $domain);
        $stmt->execute();
        $result = $stmt->get_result();
        $element = $result->fetch_assoc();
        
        if (!$element) {
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
        
        // Получаем PROPERTY_388 (Обработчик) как ТЕКСТ варианта через join с b_iblock_property_enum
        $stmt = $mysqli->prepare("
            SELECT p.CODE, pe.VALUE as VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON ep.IBLOCK_PROPERTY_ID = p.ID
            JOIN b_iblock_property_enum pe ON pe.ID = ep.VALUE
            WHERE ep.IBLOCK_ELEMENT_ID = ? AND p.CODE = 'PROPERTY_388'
        ");
        $stmt->bind_param("i", $element['ID']);
        $stmt->execute();
        $result = $stmt->get_result();
        $property388 = $result->fetch_assoc();
        
        $mysqli->close();
        
        if ($property388 && !empty($property388['VALUE'])) {
            $normalizerFile = $property388['VALUE'];
            logMessage("NORMALIZER_SEARCH_RESULT | Domain: '$domain' | Found: true | Normalizer: '$normalizerFile'", $config['global_log'], $config);
            logMessage("getNormalizerFromIblock54: найден нормализатор '$normalizerFile' для домена '$domain'", $config['global_log'], $config);
            return $normalizerFile;
        } else {
            logMessage("NORMALIZER_SEARCH_RESULT | Domain: '$domain' | Found: true | Normalizer: 'generic_normalizer.php' (default)", $config['global_log'], $config);
            logMessage("getNormalizerFromIblock54: PROPERTY_388 не найден или пуст для домена '$domain', используем generic_normalizer.php", $config['global_log'], $config);
            
            // Возвращаем generic_normalizer.php по умолчанию
            return 'generic_normalizer.php';
        }
        
    } catch (Exception $e) {
        logMessage("getNormalizerFromIblock54: ОШИБКА: " . $e->getMessage(), $config['global_log'], $config);
        
        // Создаем ошибку через ErrorHandler
        $errorHandler = new ErrorHandler($config);
        $fileName = $rawFilePath ? basename($rawFilePath) : 'unknown_file.json';
        $reason = 'database_error';
        $details = "Ошибка подключения к БД: " . $e->getMessage();
        
        $errorHandler->handleError('generic', $fileName, $reason, $details, 'generic_normalizer.php');
        
        // Перемещаем raw файл в raw_errors, если путь известен
        if ($rawFilePath && file_exists($rawFilePath)) {
            $errorHandler->moveToRawErrors($rawFilePath);
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
 * Извлекает host из URL/строки для fallback-маршрутизации.
 */
function extractLookupHostFromValue($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (is_string($host) && $host !== '') {
        return $host;
    }

    return $value;
}

/**
 * Собирает кандидатные идентификаторы Marquiz в порядке приоритета.
 */
function extractMarquizLookupCandidates($parsedData) {
    $candidates = [];
    $seen = [];

    $addCandidate = static function ($value, $label) use (&$candidates, &$seen) {
        $value = trim((string)$value);
        if ($value === '' || isset($seen[$value])) {
            return;
        }

        $seen[$value] = true;
        $candidates[] = [
            'value' => $value,
            'label' => $label,
        ];
    };

    $addCandidate($parsedData['formid'] ?? '', 'formid');

    if (!empty($parsedData['form']) && is_array($parsedData['form'])) {
        $addCandidate($parsedData['form']['id'] ?? '', 'form.id');
    }

    if (!empty($parsedData['quiz']) && is_array($parsedData['quiz'])) {
        $addCandidate($parsedData['quiz']['id'] ?? '', 'quiz.id');
    }

    if (!empty($parsedData['extra']) && is_array($parsedData['extra'])) {
        $addCandidate(extractLookupHostFromValue($parsedData['extra']['href'] ?? ''), 'extra.href.host');
        $addCandidate(extractLookupHostFromValue($parsedData['extra']['referrer'] ?? ''), 'extra.referrer.host');
    }

    return $candidates;
}

/**
 * Признак Marquiz-подобного payload, чтобы маркировать ошибку корректным нормализатором.
 */
function isMarquizLikePayload($parsedData) {
    if (!is_array($parsedData)) {
        return false;
    }

    if (!empty($parsedData['formid']) || !empty($parsedData['tranid'])) {
        return true;
    }

    if (!empty($parsedData['quiz']) && is_array($parsedData['quiz'])) {
        return true;
    }

    if (!empty($parsedData['form']) && is_array($parsedData['form'])) {
        return true;
    }

    if (!empty($parsedData['answers']) && is_array($parsedData['answers'])) {
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
            logMessage("data_type_detector: не удалось заблокировать raw-файл " . basename($rawFile) . ", пропускаем", $config['global_log'], $config);
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
            logMessage("data_type_detector: raw-файл уже заблокирован, пропускаем: " . basename($rawFile), $config['global_log'], $config);
            continue;
        }
        
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
            break;
        }
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
            
            if ($domain === 'unknown.domain' && (!empty($rawHeaders['QUERY_STRING']) || !empty($rawBody))) {
                // Парсим QUERY_STRING для поиска SOURCE_DESCRIPTION
                $queryString = $rawHeaders['QUERY_STRING'] ?? '';
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
                    $normalizerFile = getNormalizerFromIblock54BySourceDescription($sourceDescription, $config, $rawFile);
                    
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
                    $matched = true;
                    $assignedById = urldecode($matches[1]);
                    $logMsg = "🔍 ASSIGNED_BY_ID НАЙДЕН: '$assignedById'";
                    logMessage($logMsg, $config['global_log'], $config);
                    // error_log($logMsg);
                    
                    // Ищем нормализатор по ASSIGNED_BY_ID как по названию элемента в ИБ54
                    $normalizerFile = getNormalizerFromIblock54ByTitle($assignedById, $config, $rawFile);
                    
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
                        $normalizerFile = getNormalizerFromIblock54BySourceDescription($sourceDescription, $config, $rawFile);
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
                        
                        // Ищем нормализатор по ASSIGNED_BY_ID как по названию элемента в ИБ54
                        $normalizerFile = getNormalizerFromIblock54ByTitle($assignedById, $config, $rawFile);
                        
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
                
                // Шаг 1: Сначала ищем по source_domain, но без авто-перемещения файла и без отправки сообщения.
                // Это важно для Marquiz/quiz-форм: если source_domain не найден, мы еще должны попробовать formid.
                $normalizerFile = getNormalizerFromIblock54($domain, $config, null, true);
                
                $lookupAttempts = ["source_domain='$domain'"];

                if ($normalizerFile !== null) {
                    logMessage("data_type_detector: нормализатор найден по source_domain '$domain': '$normalizerFile'", $config['global_log'], $config);
                } else {
                    // Шаг 2: Если не нашли по source_domain, пробуем идентификаторы Marquiz/quiz-форм.
                    $lookupCandidates = extractMarquizLookupCandidates($parsedData);
                    $attemptedCandidate = false;

                    foreach ($lookupCandidates as $candidate) {
                        $candidateValue = $candidate['value'];
                        $candidateLabel = $candidate['label'];

                        if ($candidateValue === $domain) {
                            continue;
                        }

                        $attemptedCandidate = true;
                        $lookupAttempts[] = $candidateLabel . "='" . $candidateValue . "'";
                        logMessage("data_type_detector: source_domain '$domain' не найден в ИБ54, пробуем поиск по $candidateLabel: '$candidateValue'", $config['global_log'], $config);

                        $candidateNormalizer = getNormalizerFromIblock54($candidateValue, $config, null, true);

                        if ($candidateNormalizer !== null) {
                            $normalizerFile = $candidateNormalizer;
                            $domain = $candidateValue;
                            $rawData['source_domain'] = $candidateValue;
                            logMessage("data_type_detector: нормализатор найден по $candidateLabel '$candidateValue': '$normalizerFile'", $config['global_log'], $config);
                            break;
                        }

                        logMessage("data_type_detector: $candidateLabel '$candidateValue' не найден в ИБ54", $config['global_log'], $config);
                    }

                    if (!$attemptedCandidate) {
                        logMessage("data_type_detector: source_domain '$domain' не найден и Marquiz-идентификаторы отсутствуют", $config['global_log'], $config);
                    }
                }
                
                // Шаг 3: Если не найден → отправляем сообщение и перемещаем в raw_errors
                if ($normalizerFile === null) {
                    $fileName = basename($rawFile);
                    $reason = 'domain_not_found_in_iblock54';
                    $details = "Не найдено в ИБ54 по идентификаторам: " . implode(', ', $lookupAttempts) . ". Добавьте элемент в ИБ54 с нужным нормализатором.";
                    $errorNormalizer = isMarquizLikePayload($parsedData) ? 'marquiz_normalizer.php' : 'generic_normalizer.php';
                    
                    logMessage("data_type_detector: файл пропущен - '$domain' не найден в ИБ54, отправляем сообщение в чат", $config['global_log'], $config);
                    
                    // Отправляем ошибку через ErrorHandler (он отправит сообщение в чат и переместит файл)
                    $errorHandler = new ErrorHandler($config);
                    $errorHandler->handleError('data_type_detector', $fileName, $reason, $details, $errorNormalizer);
                    
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
            
        } catch (Exception $e) {
            // Освобождаем блокировку при ошибке
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
