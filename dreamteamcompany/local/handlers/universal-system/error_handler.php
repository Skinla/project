<?php
// error_handler.php
// Единая система обработки ошибок с функциями для работы с ИБ54

require_once __DIR__ . '/logger_and_queue.php';
require_once __DIR__ . '/chat_notifications.php';

class ErrorHandler {
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Обрабатывает ошибку с подробным форматом сообщения
     */
    public function handleError($errorType, $fileName, $reason, $details, $normalizerFile = null) {
        $timestamp = date('d.m.Y H:i:s');
        
        // Формируем заголовок с названием нормализатора
        $header = "🚨 Ошибка по " . ($normalizerFile ?: $this->getErrorTypeName($errorType));
        
        // Получаем базовый URL для ссылок
        $baseUrl = $this->getBaseUrl();
        
        // Формируем сообщение
        $message = $header . "\n\n";
        $message .= "📁 Файл: " . basename($fileName) . "\n";
        $message .= "❌ Причина: " . $reason . "\n";
        $message .= "ℹ️ Детали: " . str_replace('инфоблока 54', '[URL=' . $baseUrl . '/add-to-iblock.php]инфоблока 54[/URL]', $details) . "\n";
        $message .= "⏰ Время: " . $timestamp . "\n\n";
        
        $message .= "Требуется ручная обработка файла из папки [URL=$baseUrl/queue/raw/raw_errors/]raw_errors[/URL].\n";
        $message .= "• [URL=$baseUrl/add-to-iblock.php?file=" . urlencode(basename($fileName)) . "&type=$errorType]Добавить элемент в список 54[/URL]\n";
        $message .= "• [URL=$baseUrl/add-to-exceptions.php?file=" . urlencode(basename($fileName)) . "&type=$errorType]Добавить в исключения[/URL]\n";
        $message .= "• [URL=$baseUrl/retry-raw-errors.php?file=" . urlencode(basename($fileName)) . "]Обработать файл очереди[/URL]\n\n";
        
        // Логируем ошибку
        $this->logError($errorType, $fileName, $reason, $details, $normalizerFile);
        
        // Отправляем уведомление в чат
        $this->sendErrorNotification($message);
        
        // Копируем файл в папку ошибок (оригинал остаётся для повторной обработки)
        $this->copyToErrorQueue($fileName);
        
        return $message;
    }
    
    /**
     * Получает базовый URL для ссылок
     */
    private function getBaseUrl() {
        return 'https://bitrix.dreamteamcompany.ru/local/handlers/universal/universal-system';
    }
    
    /**
     * Получает отображаемое имя типа ошибки
     */
    private function getErrorTypeName($errorType) {
        $names = [
            'calltouch' => 'CallTouch',
            'tilda' => 'Tilda',
            'generic' => 'Generic',
            'koltach' => 'Koltaсh',
            'wordpress' => 'WordPress',
            'bitrix' => 'Bitrix'
        ];
        
        return $names[$errorType] ?? $errorType;
    }
    
    /**
     * Получает отображаемое имя нормализатора
     */
    private function getNormalizerDisplayName($normalizerFile) {
        $names = [
            'calltouch_normalizer.php' => 'CallTouch',
            'tilda_normalizer.php' => 'Tilda',
            'generic_normalizer.php' => 'Generic',
            'koltach_normalizer.php' => 'Koltaсh',
            'wordpress_normalizer.php' => 'WordPress',
            'bitrix_normalizer.php' => 'Bitrix'
        ];
        
        return $names[$normalizerFile] ?? str_replace(['_normalizer.php', '_'], ['', ' '], $normalizerFile);
    }
    
    /**
     * Логирует ошибку
     */
    private function logError($errorType, $fileName, $reason, $details, $normalizerFile) {
        $logMessage = "ERROR [$errorType]: $reason | File: " . basename($fileName);
        if ($normalizerFile) {
            $logMessage .= " | Normalizer: $normalizerFile";
        }
        $logMessage .= " | Details: $details";
        
        logMessage($logMessage, 'errors.log', $this->config);
    }
    
    /**
     * Отправляет уведомление об ошибке в чат
     */
    private function sendErrorNotification($message) {
        try {
            $webhookUrl = $this->config['portal_webhooks']['dreamteamcompany'] ?? '';
            if ($webhookUrl) {
                sendChatMessage($webhookUrl, $this->config['error_chat_id'], $message, $this->config);
            }
        } catch (Exception $e) {
            logMessage("ErrorHandler: не удалось отправить уведомление в чат: " . $e->getMessage(), 'errors.log', $this->config);
        }
    }
    
    /**
     * Перемещает файл в папку ошибок
     */
    private function copyToErrorQueue($fileName) {
        $errorDir = $this->config['queue_dir'] . '/queue_errors';
        
        if (!is_dir($errorDir)) {
            mkdir($errorDir, 0777, true);
        }
        
        $newPath = $errorDir . '/' . basename($fileName);
        
        if (file_exists($fileName)) {
            if (@copy($fileName, $newPath)) {
                logMessage("ErrorHandler: файл скопирован в queue_errors: " . basename($fileName), 'errors.log', $this->config);
            } else {
                logMessage("ErrorHandler: не удалось скопировать файл в queue_errors: " . basename($fileName), 'errors.log', $this->config);
            }
        } else {
            // Если передано только имя (без пути) и файла нет, зафиксируем предупреждение
            logMessage("ErrorHandler: исходный файл не найден для копирования в queue_errors: " . basename($fileName), 'errors.log', $this->config);
        }
    }

    /**
     * Добавляет домен в исключения
     */
    public function addToExceptions($domain, $reason = '', $normalizer = 'generic_normalizer.php') {
        $exceptionsLog = $this->config['logs_dir'] . '/exceptions.log';
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'domain' => $domain,
            'reason' => $reason,
            'normalizer' => $normalizer,
            'added_by' => 'web_interface'
        ];
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        
        if (file_put_contents($exceptionsLog, $logLine, FILE_APPEND | LOCK_EX)) {
            logMessage("ErrorHandler: домен '$domain' добавлен в исключения: $reason", 'exceptions.log', $this->config);
            return true;
        } else {
            logMessage("ErrorHandler: ошибка при добавлении домена '$domain' в исключения", 'exceptions.log', $this->config);
            return false;
        }
    }
    
    /**
     * Перемещает raw файл в папку raw_errors
     */
    public function moveToRawErrors($filePath) {
        $rawDir = $this->config['queue_dir'] . '/raw';
        $rawErrorsDir = $rawDir . '/raw_errors';
        
        // Создаем папку если нет
        if (!is_dir($rawErrorsDir)) {
            if (!mkdir($rawErrorsDir, 0777, true)) {
                logMessage("ErrorHandler: не удалось создать папку raw_errors: " . $rawErrorsDir, 'errors.log', $this->config);
                return false;
            }
        }
        
        $fileName = basename($filePath);
        $newPath = $rawErrorsDir . '/' . $fileName;
        
        // Перемещаем файл
        if (file_exists($filePath)) {
            if (rename($filePath, $newPath)) {
                logMessage("ErrorHandler: файл перемещен в raw_errors: " . $fileName, 'errors.log', $this->config);
                return true;
            } else {
                logMessage("ErrorHandler: не удалось переместить файл в raw_errors: " . $fileName, 'errors.log', $this->config);
                return false;
            }
        } else {
            logMessage("ErrorHandler: исходный файл не найден для перемещения в raw_errors: " . $filePath, 'errors.log', $this->config);
            return false;
        }
    }
    
    /**
     * Добавляет элемент в ИБ54
     */
    public function addElementToIblock54($elementData, $normalizerFile = null) {
        logMessage("ErrorHandler: добавление элемента в ИБ54: " . json_encode($elementData), 'errors.log', $this->config);
        
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
                throw new Exception("Ошибка подключения к БД: " . $mysqli->connect_error);
            }
            $mysqli->set_charset("utf8");
            
            // Подготавливаем данные для вставки
            $name = $elementData['name'] ?? '';
            $code = $this->generateCode($name);
            $xmlId = $this->generateXmlId($name);
            
            // Вставляем элемент в ИБ54
            $stmt = $mysqli->prepare("
                INSERT INTO b_iblock_element 
                (IBLOCK_ID, NAME, CODE, XML_ID, ACTIVE, SORT, TIMESTAMP_X, MODIFIED_BY, CREATED_BY, CREATED_DATE)
                VALUES (54, ?, ?, ?, 'Y', 500, NOW(), 1, 1, NOW())
            ");
            $stmt->bind_param("sss", $name, $code, $xmlId);
            
            if (!$stmt->execute()) {
                throw new Exception("Ошибка вставки элемента: " . $stmt->error);
            }
            
            $elementId = $mysqli->insert_id;
            
            // Добавляем свойства элемента
            $this->addElementProperties($mysqli, $elementId, $elementData, $normalizerFile);
            
            $mysqli->close();
            
            logMessage("ErrorHandler: элемент добавлен в ИБ54 с ID=$elementId", 'errors.log', $this->config);
            
            return $elementId;
            
        } catch (Exception $e) {
            logMessage("ErrorHandler: ошибка добавления элемента в ИБ54: " . $e->getMessage(), 'errors.log', $this->config);
            return false;
        }
    }
    
    /**
     * Добавляет свойства элемента
     */
    private function addElementProperties($mysqli, $elementId, $elementData, $normalizerFile) {
        // Получаем ID свойств
        $propertyIds = $this->getPropertyIds($mysqli);
        
        // PROPERTY_388 (Обработчик) - нормализатор
        if ($normalizerFile && isset($propertyIds['PROPERTY_388'])) {
            $this->setElementProperty($mysqli, $elementId, $propertyIds['PROPERTY_388'], $normalizerFile);
        }
        
        // PROPERTY_199 (siteId для CallTouch)
        if (isset($elementData['siteId']) && isset($propertyIds['PROPERTY_199'])) {
            $this->setElementProperty($mysqli, $elementId, $propertyIds['PROPERTY_199'], $elementData['siteId']);
        }
        
        // Другие свойства из elementData
        foreach ($elementData as $key => $value) {
            if (strpos($key, 'PROPERTY_') === 0 && isset($propertyIds[$key])) {
                $this->setElementProperty($mysqli, $elementId, $propertyIds[$key], $value);
            }
        }
    }
    
    /**
     * Получает ID свойств ИБ54
     */
    private function getPropertyIds($mysqli) {
        $stmt = $mysqli->prepare("
            SELECT CODE, ID 
            FROM b_iblock_property 
            WHERE IBLOCK_ID = 54
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $propertyIds = [];
        while ($row = $result->fetch_assoc()) {
            $propertyIds[$row['CODE']] = $row['ID'];
        }
        
        return $propertyIds;
    }
    
    /**
     * Устанавливает свойство элемента
     */
    private function setElementProperty($mysqli, $elementId, $propertyId, $value) {
        $stmt = $mysqli->prepare("
            INSERT INTO b_iblock_element_property 
            (IBLOCK_ELEMENT_ID, IBLOCK_PROPERTY_ID, VALUE)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $elementId, $propertyId, $value);
        $stmt->execute();
    }
    
    /**
     * Генерирует код элемента
     */
    private function generateCode($name) {
        $code = strtolower($name);
        $code = preg_replace('/[^a-z0-9]/', '_', $code);
        $code = preg_replace('/_+/', '_', $code);
        $code = trim($code, '_');
        return $code;
    }
    
    /**
     * Генерирует XML_ID элемента
     */
    private function generateXmlId($name) {
        return strtolower(str_replace(' ', '_', $name));
    }
    
    /**
     * Обрабатывает файл из очереди ошибок
     */
    public function processErrorFile($fileName) {
        $errorDir = $this->config['queue_dir'] . '/queue_errors';
        $filePath = $errorDir . '/' . $fileName;
        
        if (!file_exists($filePath)) {
            logMessage("ErrorHandler: файл не найден в queue_errors: $fileName", 'errors.log', $this->config);
            return false;
        }
        
        // Перемещаем файл обратно в raw очередь
        $rawDir = $this->config['queue_dir'] . '/raw';
        $newPath = $rawDir . '/' . $fileName;
        
        if (rename($filePath, $newPath)) {
            logMessage("ErrorHandler: файл возвращен в raw очередь: $fileName", 'errors.log', $this->config);
            
            // Запускаем обработку очереди
            require_once __DIR__ . '/queue_manager.php';
            processAllQueues($this->config);
            
            return true;
        } else {
            logMessage("ErrorHandler: не удалось вернуть файл в raw очередь: $fileName", 'errors.log', $this->config);
            return false;
        }
    }
    
    /**
     * Получает список файлов в очереди ошибок
     */
    public function getErrorFiles() {
        $errorDir = $this->config['queue_dir'] . '/queue_errors';
        
        if (!is_dir($errorDir)) {
            return [];
        }
        
        $files = glob($errorDir . '/*.json');
        return array_map('basename', $files);
    }
    
    /**
     * Получает статистику ошибок
     */
    public function getErrorStats() {
        $errorLogFile = $this->config['logs_dir'] . '/errors.log';
        
        if (!file_exists($errorLogFile)) {
            return [
                'total_errors' => 0,
                'errors_by_type' => [],
                'errors_by_normalizer' => []
            ];
        }
        
        $lines = file($errorLogFile);
        $stats = [
            'total_errors' => 0,
            'errors_by_type' => [],
            'errors_by_normalizer' => []
        ];
        
        foreach ($lines as $line) {
            if (strpos($line, 'ERROR [') !== false) {
                $stats['total_errors']++;
                
                // Парсим тип ошибки
                if (preg_match('/ERROR \[([^\]]+)\]/', $line, $matches)) {
                    $errorType = $matches[1];
                    $stats['errors_by_type'][$errorType] = ($stats['errors_by_type'][$errorType] ?? 0) + 1;
                }
                
                // Парсим нормализатор
                if (preg_match('/Normalizer: ([^\s|]+)/', $line, $matches)) {
                    $normalizer = $matches[1];
                    $stats['errors_by_normalizer'][$normalizer] = ($stats['errors_by_normalizer'][$normalizer] ?? 0) + 1;
                }
            }
        }
        
        return $stats;
    }
}
