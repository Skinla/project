<?php
// normalizers/base_normalizer.php
// Базовый класс для всех нормализаторов

abstract class BaseNormalizer {
    protected $config;
    
    public function __construct($config) {
        $this->config = $config;
    }
    
    /**
     * Основной метод нормализации - должен быть реализован в каждом нормализаторе
     */
    abstract public function normalize($rawData);
    
    /**
     * Нормализует телефон к единому формату: +7 904 908-25-98
     * Конвертирует 8 в +7, 7 в +7, убирает все символы кроме цифр, форматирует
     */
    protected function normalizePhone($phone) {
        // #region agent log
        $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'base_normalizer.php:normalizePhone',
            'message' => 'PHONE_NORMALIZE_START',
            'data' => ['input' => $phone],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        if (empty($phone)) {
            return '';
        }
        
        // Очищаем от всех нецифровых символов
        $cleanPhone = preg_replace('/\D+/', '', $phone);
        
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'base_normalizer.php:normalizePhone',
            'message' => 'PHONE_CLEANED',
            'data' => ['cleaned' => $cleanPhone, 'length' => strlen($cleanPhone)],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        // Если пусто, возвращаем пустую строку
        if (empty($cleanPhone)) {
            return '';
        }
        
        // Если начинается с 8 и длина 11 цифр, заменяем на 7
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
            $cleanPhone = '7' . substr($cleanPhone, 1);
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:normalizePhone',
                'message' => 'PHONE_8_TO_7',
                'data' => ['converted' => $cleanPhone],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
        }
        
        // Если начинается с 7 и длина 11 цифр, форматируем с +7
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '7') {
            $formatted = '+' . $cleanPhone;
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:normalizePhone',
                'message' => 'PHONE_FORMATTED',
                'data' => ['formatted' => $formatted],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $formatted;
        }
        
        // Если длина 10 цифр (без кода страны), добавляем +7
        if (strlen($cleanPhone) === 10) {
            $formatted = '+7' . $cleanPhone;
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:normalizePhone',
                'message' => 'PHONE_10_TO_11_FORMATTED',
                'data' => ['formatted' => $formatted],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $formatted;
        }
        
        // Если не подходит под формат, пытаемся нормализовать
        // Если начинается с 7 или 8 и длина >= 10, добавляем +
        if (strlen($cleanPhone) >= 10) {
            if ($cleanPhone[0] === '7') {
                $formatted = '+' . $cleanPhone;
            } elseif ($cleanPhone[0] === '8' && strlen($cleanPhone) === 11) {
                $formatted = '+7' . substr($cleanPhone, 1);
            } else {
                $formatted = '+7' . $cleanPhone;
            }
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:normalizePhone',
                'message' => 'PHONE_FALLBACK_FORMATTED',
                'data' => ['formatted' => $formatted],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $formatted;
        }
        
        // Если не подходит под формат, возвращаем очищенный
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'base_normalizer.php:normalizePhone',
            'message' => 'PHONE_NOT_FORMATTED',
            'data' => ['returned' => $cleanPhone, 'length' => strlen($cleanPhone)],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        return $cleanPhone;
    }
    
    /**
     * Извлекает телефон из данных
     */
    protected function extractPhone($data) {
        // #region agent log
        $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'base_normalizer.php:extractPhone',
            'message' => 'EXTRACT_PHONE_START',
            'data' => ['data_keys' => array_keys($data)],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        // Проверяем основные поля телефона (прямые ключи)
        $phoneFields = ['Phone', 'phone', 'PHONE'];
        
        foreach ($phoneFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $rawPhone = $data[$field];
                $cleaned = preg_replace('/\D+/', '', $rawPhone);
                $normalized = $this->normalizePhone($cleaned);
                // #region agent log
                $debugData = [
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'A',
                    'location' => 'base_normalizer.php:extractPhone',
                    'message' => 'EXTRACT_PHONE_FOUND',
                    'data' => ['field' => $field, 'raw' => $rawPhone, 'normalized' => $normalized],
                    'timestamp' => (int)(microtime(true) * 1000)
                ];
                @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                // #endregion
                return $normalized;
            }
        }
        
        // Проверяем вложенную структуру contacts.phone
        if (isset($data['contacts']) && is_array($data['contacts']) && isset($data['contacts']['phone']) && !empty($data['contacts']['phone'])) {
            $rawPhone = $data['contacts']['phone'];
            $cleaned = preg_replace('/\D+/', '', $rawPhone);
            $normalized = $this->normalizePhone($cleaned);
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:extractPhone',
                'message' => 'EXTRACT_PHONE_FOUND_CONTACTS',
                'data' => ['raw' => $rawPhone, 'normalized' => $normalized],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $normalized;
        }
        
        // Проверяем contacts.phone как прямой ключ (на случай если contacts это строка)
        if (isset($data['contacts.phone']) && !empty($data['contacts.phone'])) {
            $rawPhone = $data['contacts.phone'];
            $cleaned = preg_replace('/\D+/', '', $rawPhone);
            $normalized = $this->normalizePhone($cleaned);
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'base_normalizer.php:extractPhone',
                'message' => 'EXTRACT_PHONE_FOUND_CONTACTS_DOT',
                'data' => ['raw' => $rawPhone, 'normalized' => $normalized],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $normalized;
        }
        
        // Поиск полей с суффиксами (Phone_1, Phone_2, Phone_10, phone_3, PHONE_5 и т.д.)
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value)) {
                // Проверяем паттерн: Phone_число, phone_число, PHONE_число
                if (preg_match('/^(Phone|phone|PHONE)_\d+$/i', $key)) {
                    $rawPhone = $value;
                    $cleaned = preg_replace('/\D+/', '', $rawPhone);
                    $normalized = $this->normalizePhone($cleaned);
                    // #region agent log
                    $debugData = [
                        'sessionId' => 'debug-session',
                        'runId' => 'run1',
                        'hypothesisId' => 'A',
                        'location' => 'base_normalizer.php:extractPhone',
                        'message' => 'EXTRACT_PHONE_FOUND_SUFFIX',
                        'data' => ['key' => $key, 'raw' => $rawPhone, 'normalized' => $normalized],
                        'timestamp' => (int)(microtime(true) * 1000)
                    ];
                    @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                    // #endregion
                    return $normalized;
                }
            }
        }
        
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'base_normalizer.php:extractPhone',
            'message' => 'EXTRACT_PHONE_NOT_FOUND',
            'data' => [],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        return '';
    }
    
    /**
     * Извлекает имя из данных
     */
    protected function extractName($data) {
        // Проверяем стандартные поля имени (прямые ключи), НО НЕ formname
        $nameFields = ['name', 'Name', 'NAME', 'fio', 'FIO', 'fullname', 'FullName', 'FULLNAME'];
        
        foreach ($nameFields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && $data[$field] !== 'Неизвестно') {
                $value = trim($data[$field]);
                
                // ИСКЛЮЧАЕМ названия форм и технические значения
                if (strlen($value) < 100 && 
                    !preg_match('/сколько.*стоить|имплантация|лечение|консультация|узнайте/i', $value) &&
                    !preg_match('/^\d+$/', $value) && // Исключаем чисто числовые значения
                    !preg_match('/^[A-Za-z0-9_]+$/', $value)) { // Исключаем технические значения
                    return $value;
                }
            }
        }
        
        // Проверяем вложенную структуру contacts.name
        if (isset($data['contacts']) && is_array($data['contacts']) && isset($data['contacts']['name']) && !empty($data['contacts']['name']) && $data['contacts']['name'] !== 'Неизвестно') {
            $value = trim($data['contacts']['name']);
            if (strlen($value) < 100 && !preg_match('/сколько.*стоить|имплантация|лечение|консультация|узнайте/i', $value)) {
                return $value;
            }
        }
        
        // Проверяем contacts.name как прямой ключ (на случай если contacts это строка)
        if (isset($data['contacts.name']) && !empty($data['contacts.name']) && $data['contacts.name'] !== 'Неизвестно') {
            $value = trim($data['contacts.name']);
            if (strlen($value) < 100 && !preg_match('/сколько.*стоить|имплантация|лечение|консультация|узнайте/i', $value)) {
                return $value;
            }
        }
        
        return '';
    }
    
    /**
     * Извлекает комментарий из данных, обрабатывая все варианты написания
     * Проверяет: COMMENTS, Comment, comment, comments, COMMENT, Commentary, commentary
     */
    protected function extractComment($data) {
        // Варианты написания поля комментария (порядок важен - проверяем от более специфичных к общим)
        $commentFields = [
            'comments', 'COMMENTS', 'Comment', 'comment', 'COMMENT', 
            'Commentary', 'commentary', 'COMMENTARY',
            'message', 'Message', 'MESSAGE',
            'note', 'Note', 'NOTE',
            'description', 'Description', 'DESCRIPTION'
        ];
        
        // Проверяем прямые ключи
        foreach ($commentFields as $field) {
            // Проверяем isset и что значение не пустое (даже если это строка "0")
            if (isset($data[$field])) {
                $value = $data[$field];
                
                // Пропускаем null и пустые массивы
                if ($value === null || (is_array($value) && empty($value))) {
                    continue;
                }
                
                // Если это массив, склеиваем
                if (is_array($value)) {
                    $value = implode("\n", array_filter($value));
                }
                
                // Преобразуем в строку если не строка
                if (!is_string($value)) {
                    $value = (string)$value;
                }
                
                // Очищаем от HTML тегов и лишних пробелов
                $value = strip_tags(trim($value));
                if (!empty($value)) {
                    return $value;
                }
            }
        }
        
        // Проверяем вложенную структуру comments (массив комментариев)
        if (isset($data['comments']) && is_array($data['comments'])) {
            $commentLines = [];
            foreach ($data['comments'] as $comment) {
                if (!empty($comment)) {
                    $value = is_array($comment) ? implode("\n", $comment) : $comment;
                    $value = strip_tags(trim($value));
                    if (!empty($value)) {
                        $commentLines[] = $value;
                    }
                }
            }
            if (!empty($commentLines)) {
                return implode("\n", $commentLines);
            }
        }
        
        return '';
    }
    
    /**
     * Извлекает UTM метки
     */
    protected function extractUtm($data) {
        $utm = [];
        $utmFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        
        foreach ($utmFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $utm[$field] = $data[$field];
            }
        }
        
        return $utm;
    }
    
    /**
     * Извлекает домен источника
     */
    protected function extractSourceDomain($data) {
        return $data['source_domain'] ?? 'unknown';
    }
    
    /**
     * Создает базовую структуру нормализованных данных
     */
    protected function createBaseStructure($rawData) {
        return [
            'source_domain' => $this->extractSourceDomain($rawData),
            'phone' => '',
            'name' => '',
            'comment' => '',
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'utm_content' => '',
            'utm_term' => '',
            'form_name' => '',
            'timestamp' => date('Y-m-d H:i:s'),
            'raw_data' => $rawData
        ];
    }
    
    /**
     * Логирует ошибку нормализации
     */
    protected function logError($message, $rawData = null) {
        $logMessage = "Ошибка нормализации (" . static::class . "): $message";
        if ($rawData) {
            try {
                $logMessage .= " | Данные: " . json_encode($rawData, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (Exception $e) {
                $logMessage .= " | Данные: [ошибка сериализации: " . $e->getMessage() . "]";
            }
        }
        if (isset($this->config['global_log']) && function_exists('logMessage')) {
            logMessage($logMessage, $this->config['global_log'], $this->config);
        }
    }
}

