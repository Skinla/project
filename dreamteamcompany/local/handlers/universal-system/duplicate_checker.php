<?php
// duplicate_checker.php
// Централизованная система проверки дублей

require_once __DIR__ . '/logger_and_queue.php';

class DuplicateChecker {
    private $config;
    private $processedPhones = [];
    private $processingPhones = [];
    private $processedFile;
    private $processingFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->processedFile = $config['logs_dir'] . '/processed_phones.log';
        $this->processingFile = $config['logs_dir'] . '/processing_phones.log';
        $this->loadProcessedPhones();
        $this->loadProcessingPhones();
    }
    
    /**
     * Проверяет дубль на основе телефона и домена
     */
    public function checkDuplicate($data, $stage = 'normalized') {
        $phone = $this->extractPhone($data, $stage);
        $domain = $data['source_domain'] ?? 'unknown';
        
        if (!$phone) {
            return ['is_duplicate' => false, 'reason' => 'no_phone'];
        }
        
        $duplicateKey = $phone . '|' . $domain;
        
        // Проверяем уже обработанные
        if (isset($this->processedPhones[$duplicateKey])) {
            return [
                'is_duplicate' => true,
                'reason' => 'already_processed',
                'processed_at' => $this->processedPhones[$duplicateKey]['processed_at'],
                'lead_id' => $this->processedPhones[$duplicateKey]['lead_id'],
                'duplicate_key' => $duplicateKey
            ];
        }
        
        // Проверяем в процессе обработки
        if (isset($this->processingPhones[$duplicateKey])) {
            // error_log("DUPLICATE_CHECKER: Найден дубль в обработке: $duplicateKey");
            return [
                'is_duplicate' => true,
                'reason' => 'processing',
                'started_at' => $this->processingPhones[$duplicateKey]['started_at'],
                'duplicate_key' => $duplicateKey
            ];
        }
        
        return ['is_duplicate' => false, 'duplicate_key' => $duplicateKey, 'phone' => $phone];
    }
    
    /**
     * Помечает телефон как "в процессе обработки"
     */
    public function markAsProcessing($duplicateKey, $data) {
        $this->processingPhones[$duplicateKey] = [
            'started_at' => date('Y-m-d H:i:s'),
            'phone' => $this->extractPhone($data, 'normalized'),
            'domain' => $data['source_domain'] ?? 'unknown'
        ];
        $this->saveProcessingPhones();
        
        logMessage("Телефон помечен как 'в обработке': $duplicateKey", $this->config['global_log'], $this->config);
        
        // ДОПОЛНИТЕЛЬНОЕ ЛОГИРОВАНИЕ ДЛЯ ОТЛАДКИ
        // error_log("DUPLICATE_CHECKER: Телефон помечен как 'в обработке': $duplicateKey");
    }
    
    /**
     * Помечает телефон как "обработанный"
     */
    public function markAsProcessed($duplicateKey, $leadId) {
        // Убираем из processing
        if (isset($this->processingPhones[$duplicateKey])) {
            $processingData = $this->processingPhones[$duplicateKey];
            unset($this->processingPhones[$duplicateKey]);
        } else {
            $processingData = ['phone' => '', 'domain' => 'unknown'];
        }
        
        // Добавляем в processed
        $this->processedPhones[$duplicateKey] = [
            'processed_at' => date('Y-m-d H:i:s'),
            'lead_id' => $leadId,
            'phone' => $processingData['phone'],
            'domain' => $processingData['domain']
        ];
        
        $this->saveProcessedPhones();
        $this->saveProcessingPhones();
        
        logMessage("Телефон помечен как 'обработанный': $duplicateKey, лид ID: $leadId", $this->config['global_log'], $this->config);
    }
    
    /**
     * Логирует дубль
     */
    public function logDuplicate($duplicateKey, $reason, $data) {
        $duplicateFile = $this->config['logs_dir'] . '/duplicates.log';
        
        if (!file_exists($duplicateFile)) {
            file_put_contents($duplicateFile, "# Лог дублей\n");
        }
        
        $timestamp = date('[Y-m-d H:i:s]');
        $logEntry = "$timestamp phone:$duplicateKey | reason:$reason | stage:" . ($data['stage'] ?? 'unknown') . "\n";
        
        file_put_contents($duplicateFile, $logEntry, FILE_APPEND);
        
        logMessage("Дубль зафиксирован: $duplicateKey, причина: $reason", $this->config['global_log'], $this->config);
    }
    
    /**
     * Очищает старые записи (старше 30 минут)
     */
    public function cleanupOldRecords() {
        $this->cleanupOldProcessed();
        $this->cleanupOldProcessing();
    }
    
    /**
     * Извлекает телефон из данных в зависимости от этапа
     */
    private function extractPhone($data, $stage) {
        switch ($stage) {
            case 'raw':
                return $this->extractPhoneFromRaw($data);
            case 'normalized':
                return $data['phone'] ?? '';
            default:
                return $this->extractPhoneFromRaw($data);
        }
    }
    
    /**
     * Извлекает телефон из сырых данных
     */
    private function extractPhoneFromRaw($data) {
        // Проверяем различные варианты полей телефона
        $phoneFields = ['Phone', 'phone', 'PHONE', 'contacts.phone'];
        
        foreach ($phoneFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                return preg_replace('/\D+/', '', $data[$field]);
            }
        }
        
        // Проверяем contacts.phone
        if (isset($data['contacts']['phone']) && !empty($data['contacts']['phone'])) {
            return preg_replace('/\D+/', '', $data['contacts']['phone']);
        }
        
        // Поиск полей с суффиксами (Phone_2, phone_1, PHONE_3 и т.д.)
        foreach ($data as $key => $value) {
            if (is_string($value) && preg_match('/^phone/i', $key) && !empty($value)) {
                return preg_replace('/\D+/', '', $value);
            }
        }
        
        return '';
    }
    
    /**
     * Загружает обработанные телефоны из файла
     */
    private function loadProcessedPhones() {
        if (!file_exists($this->processedFile)) {
            return;
        }
        
        $lines = file($this->processedFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        
        foreach ($lines as $line) {
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*phone:([^|]+)\|([^|]+).*lead_id:(\d+)/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                $phone = $matches[2];
                $domain = $matches[3];
                $leadId = $matches[4];
                
                // Оставляем записи за последние 30 минут
                if (($currentTime - $logTime) < 1800) {
                    $duplicateKey = $phone . '|' . $domain;
                    $this->processedPhones[$duplicateKey] = [
                        'processed_at' => $matches[1],
                        'lead_id' => $leadId,
                        'phone' => $phone,
                        'domain' => $domain
                    ];
                }
            }
        }
    }
    
    /**
     * Загружает телефоны в обработке из файла
     */
    private function loadProcessingPhones() {
        if (!file_exists($this->processingFile)) {
            return;
        }
        
        $lines = file($this->processingFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        
        foreach ($lines as $line) {
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*phone:([^|]+)\|([^|]+)/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                $phone = $matches[2];
                $domain = $matches[3];
                
                // Оставляем записи за последние 30 минут
                if (($currentTime - $logTime) < 1800) {
                    $duplicateKey = $phone . '|' . $domain;
                    $this->processingPhones[$duplicateKey] = [
                        'started_at' => $matches[1],
                        'phone' => $phone,
                        'domain' => $domain
                    ];
                }
            }
        }
    }
    
    /**
     * Сохраняет обработанные телефоны в файл
     */
    private function saveProcessedPhones() {
        if (!file_exists($this->processedFile)) {
            file_put_contents($this->processedFile, "# Лог обработанных телефонов\n");
        }
        
        $lines = [];
        foreach ($this->processedPhones as $duplicateKey => $data) {
            $lines[] = "[{$data['processed_at']}] phone:$duplicateKey | lead_id:{$data['lead_id']}";
        }
        
        file_put_contents($this->processedFile, implode("\n", $lines) . "\n");
    }
    
    /**
     * Сохраняет телефоны в обработке в файл
     */
    private function saveProcessingPhones() {
        if (!file_exists($this->processingFile)) {
            file_put_contents($this->processingFile, "# Лог телефонов в обработке\n");
        }
        
        $lines = [];
        foreach ($this->processingPhones as $duplicateKey => $data) {
            $lines[] = "[{$data['started_at']}] phone:$duplicateKey | status:processing";
        }
        
        file_put_contents($this->processingFile, implode("\n", $lines) . "\n");
    }
    
    /**
     * Очищает старые записи обработанных телефонов
     */
    private function cleanupOldProcessed() {
        if (!file_exists($this->processedFile)) {
            return;
        }
        
        $lines = file($this->processedFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        $filteredLines = [];
        
        foreach ($lines as $line) {
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                // Оставляем записи за последние 30 минут
                if (($currentTime - $logTime) < 1800) {
                    $filteredLines[] = $line;
                }
            } else {
                // Сохраняем заголовки и комментарии
                $filteredLines[] = $line;
            }
        }
        
        if (count($filteredLines) !== count($lines)) {
            file_put_contents($this->processedFile, implode("\n", $filteredLines) . "\n");
        }
    }
    
    /**
     * Очищает старые записи телефонов в обработке
     */
    private function cleanupOldProcessing() {
        if (!file_exists($this->processingFile)) {
            return;
        }
        
        $lines = file($this->processingFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        $filteredLines = [];
        
        foreach ($lines as $line) {
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                // Оставляем записи за последние 30 минут
                if (($currentTime - $logTime) < 1800) {
                    $filteredLines[] = $line;
                }
            } else {
                // Сохраняем заголовки и комментарии
                $filteredLines[] = $line;
            }
        }
        
        if (count($filteredLines) !== count($lines)) {
            file_put_contents($this->processingFile, implode("\n", $filteredLines) . "\n");
        }
    }
}
