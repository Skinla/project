<?php
// request_tracker.php
// Система отслеживания запросов по request_id для предотвращения дубликатов

require_once __DIR__ . '/logger_and_queue.php';

class RequestTracker {
    private $config;
    private $requests = [];
    private $requestsFile;
    
    public function __construct($config) {
        $this->config = $config;
        $this->requestsFile = $config['logs_dir'] . '/request_tracker.log';
        $this->loadRequests();
    }
    
    /**
     * Генерирует request_id на основе hash от raw данных
     */
    public function generateRequestId($rawData) {
        // Создаем стабильный hash от сырых данных
        // Сортируем массив для консистентности
        $normalized = $this->normalizeDataForHash($rawData);
        $hash = md5(json_encode($normalized, JSON_UNESCAPED_UNICODE));
        return $hash;
    }
    
    /**
     * Нормализует данные для создания стабильного hash
     */
    private function normalizeDataForHash($data) {
        // Убираем временные поля и сортируем
        $normalized = $data;
        
        // Удаляем поля, которые могут меняться между запросами
        unset($normalized['timestamp']);
        unset($normalized['raw_file_path']);
        unset($normalized['request_id']);
        
        // Сортируем массив рекурсивно
        ksort($normalized);
        foreach ($normalized as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = $this->normalizeDataForHash($value);
            }
        }
        
        return $normalized;
    }
    
    /**
     * Проверяет существование request_id и возвращает его статус
     */
    public function checkRequest($requestId) {
        if (isset($this->requests[$requestId])) {
            $request = $this->requests[$requestId];
            return [
                'exists' => true,
                'status' => $request['status'],
                'lead_id' => $request['lead_id'] ?? null,
                'created_at' => $request['created_at'] ?? null,
                'updated_at' => $request['updated_at'] ?? null
            ];
        }
        
        return ['exists' => false];
    }
    
    /**
     * Создает новый request со статусом 'processing'
     */
    public function createRequest($requestId, $rawData = null) {
        $this->requests[$requestId] = [
            'status' => 'processing',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'lead_id' => null
        ];
        $this->saveRequests();
        
        logMessage("REQUEST_TRACKER: создан request_id=$requestId со статусом 'processing'", $this->config['global_log'], $this->config);
    }
    
    /**
     * Обновляет request со статусом 'success' и lead_id
     */
    public function updateRequest($requestId, $status, $leadId = null) {
        if (!isset($this->requests[$requestId])) {
            logMessage("REQUEST_TRACKER: попытка обновить несуществующий request_id=$requestId", $this->config['global_log'], $this->config);
            return false;
        }
        
        $this->requests[$requestId]['status'] = $status;
        $this->requests[$requestId]['updated_at'] = date('Y-m-d H:i:s');
        
        if ($leadId !== null) {
            $this->requests[$requestId]['lead_id'] = $leadId;
        }
        
        $this->saveRequests();
        
        logMessage("REQUEST_TRACKER: обновлен request_id=$requestId, status=$status, lead_id=" . ($leadId ?? 'null'), $this->config['global_log'], $this->config);
        return true;
    }
    
    /**
     * Загружает запросы из файла
     */
    private function loadRequests() {
        if (!file_exists($this->requestsFile)) {
            return;
        }
        
        $lines = file($this->requestsFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        
        foreach ($lines as $line) {
            // Формат: [timestamp] request_id:hash | status:processing|success | lead_id:123 | created_at:2025-12-12 10:25:00 | updated_at:2025-12-12 10:25:00
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*request_id:([a-f0-9]{32}).*status:(\w+).*lead_id:(\d+|null).*created_at:([^\|]+).*updated_at:([^\|]+)/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                $requestId = $matches[2];
                $status = $matches[3];
                $leadId = $matches[4] === 'null' ? null : (int)$matches[4];
                $createdAt = $matches[5];
                $updatedAt = $matches[6];
                
                // Оставляем записи за последние 24 часа
                if (($currentTime - $logTime) < 86400) {
                    $this->requests[$requestId] = [
                        'status' => $status,
                        'lead_id' => $leadId,
                        'created_at' => $createdAt,
                        'updated_at' => $updatedAt
                    ];
                }
            }
        }
    }
    
    /**
     * Сохраняет запросы в файл
     */
    private function saveRequests() {
        if (!file_exists($this->requestsFile)) {
            file_put_contents($this->requestsFile, "# Лог отслеживания запросов по request_id\n");
        }
        
        $lines = [];
        foreach ($this->requests as $requestId => $data) {
            $leadId = $data['lead_id'] ?? 'null';
            $lines[] = "[" . date('Y-m-d H:i:s') . "] request_id:$requestId | status:{$data['status']} | lead_id:$leadId | created_at:{$data['created_at']} | updated_at:{$data['updated_at']}";
        }
        
        // Используем flock() для атомарной записи
        $fp = @fopen($this->requestsFile, 'c+');
        if ($fp !== false) {
            if (@flock($fp, LOCK_EX)) {
                ftruncate($fp, 0);
                fwrite($fp, implode("\n", $lines) . "\n");
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        } else {
            // Fallback на старый метод если не удалось открыть файл
            file_put_contents($this->requestsFile, implode("\n", $lines) . "\n");
        }
    }
    
    /**
     * Очищает старые записи (старше 24 часов)
     */
    public function cleanupOldRecords() {
        if (!file_exists($this->requestsFile)) {
            return;
        }
        
        $lines = file($this->requestsFile, FILE_IGNORE_NEW_LINES);
        $currentTime = time();
        $filteredLines = [];
        
        foreach ($lines as $line) {
            if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $logTime = strtotime($matches[1]);
                // Оставляем записи за последние 24 часа
                if (($currentTime - $logTime) < 86400) {
                    $filteredLines[] = $line;
                }
            } else {
                // Сохраняем заголовки и комментарии
                $filteredLines[] = $line;
            }
        }
        
        if (count($filteredLines) !== count($lines)) {
            file_put_contents($this->requestsFile, implode("\n", $filteredLines) . "\n");
            // Перезагружаем после очистки
            $this->loadRequests();
        }
    }
}

