<?php
// normalizers/generic_normalizer.php
// Универсальный нормализатор для неизвестных форматов

require_once __DIR__ . '/base_normalizer.php';

class GenericNormalizer extends BaseNormalizer {
    
    public function normalize($rawData) {
        try {
            // Извлекаем данные из новой структуры
            $parsedData = $rawData['parsed_data'] ?? $rawData; // fallback для старых файлов
            $rawBody = $rawData['raw_body'] ?? '';
            $rawHeaders = $rawData['raw_headers'] ?? [];
            
            $normalized = $this->createBaseStructure($rawData);

            // Если домен неизвестен и это запрос с QUERY_STRING (например, GET), пробуем разобрать параметры name/phone/source_domain
            $queryString = $rawHeaders['QUERY_STRING'] ?? '';
            if (!empty($queryString) && (empty($normalized['source_domain']) || $normalized['source_domain'] === 'unknown.domain')) {
                $decoded = urldecode($queryString);
                $qs = [];
                parse_str($decoded, $qs);
                if (!empty($qs)) {
                    if (!empty($qs['source_domain'])) {
                        $normalized['source_domain'] = (string)$qs['source_domain'];
                    }
                    if (!empty($qs['name'])) {
                        $parsedData['name'] = (string)$qs['name'];
                    }
                    if (!empty($qs['phone'])) {
                        $parsedData['phone'] = (string)$qs['phone'];
                        $parsedData['Phone'] = (string)$qs['phone'];
                    }
                    logMessage("GENERIC_QUERY_OVERRIDE | domain='" . ($normalized['source_domain'] ?? '') . "' | name='" . ($parsedData['name'] ?? '') . "' | phone='" . ($parsedData['phone'] ?? '') . "'", $this->config['global_log'], $this->config);
                }
            }
            
            // Проверяем, является ли это Bitrix24 webhook'ом
            $phone = '';
            $name = '';
            
            if (!empty($rawHeaders['QUERY_STRING'])) {
                $queryString = $rawHeaders['QUERY_STRING'];
                $decoded = urldecode($queryString);
                
                // Парсим поля Bitrix24
                if (preg_match_all('/fields\[([^\]]+)\]=([^&]*)/', $decoded, $matches, PREG_SET_ORDER)) {
                    $fields = [];
                    foreach ($matches as $match) {
                        $fieldName = $match[1];
                        $fieldValue = $match[2];
                        
                        if ($fieldName === 'PHONE[0][VALUE]') {
                            $fields['PHONE'] = $fieldValue;
                        } elseif ($fieldName === 'PHONE[0][VALUE_TYPE]') {
                            $fields['PHONE_TYPE'] = $fieldValue;
                        } else {
                            $fields[$fieldName] = $fieldValue;
                        }
                    }
                    
                    // Извлекаем телефон и имя из Bitrix24 полей
                    if (!empty($fields['PHONE'])) {
                        // #region agent log
                        $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
                        $debugData = [
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'C',
                            'location' => 'generic_normalizer.php:normalize',
                            'message' => 'GENERIC_BITRIX24_PHONE_START',
                            'data' => ['raw_phone' => $fields['PHONE']],
                            'timestamp' => (int)(microtime(true) * 1000)
                        ];
                        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        // #endregion
                        
                        $cleaned = preg_replace('/\D+/', '', $fields['PHONE']);
                        $phone = $this->normalizePhone($cleaned);
                        
                        // #region agent log
                        $debugData = [
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'C',
                            'location' => 'generic_normalizer.php:normalize',
                            'message' => 'GENERIC_BITRIX24_PHONE_RESULT',
                            'data' => ['raw' => $fields['PHONE'], 'cleaned' => $cleaned, 'normalized' => $phone],
                            'timestamp' => (int)(microtime(true) * 1000)
                        ];
                        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        // #endregion
                    }
                    
                    if (!empty($fields['NAME'])) {
                        $name = $fields['NAME'];
                    }
                }
            }
            
            // Если не нашли в QUERY_STRING, пробуем стандартные поля
            if (empty($phone)) {
                // #region agent log
                $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
                $debugData = [
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'generic_normalizer.php:normalize',
                    'message' => 'GENERIC_EXTRACT_PHONE_START',
                    'data' => [],
                    'timestamp' => (int)(microtime(true) * 1000)
                ];
                @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                // #endregion
                
                $phone = $this->extractPhone($parsedData);
                
                // #region agent log
                $debugData = [
                    'sessionId' => 'debug-session',
                    'runId' => 'run1',
                    'hypothesisId' => 'C',
                    'location' => 'generic_normalizer.php:normalize',
                    'message' => 'GENERIC_EXTRACT_PHONE_RESULT',
                    'data' => ['phone' => $phone],
                    'timestamp' => (int)(microtime(true) * 1000)
                ];
                @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                // #endregion
            }
            if (empty($name)) {
                $name = $this->extractName($parsedData);
            }
            
            // БЛОКИРОВКА: Проверяем, что телефон не пустой
            if (empty(trim($phone))) {
                logMessage("NORMALIZER_BLOCKED | Type: generic | Телефон пустой, нормализация прервана | Phone: '$phone' | Name: '$name'", $this->config['global_log'], $this->config);
                return null;
            }
            
            $normalized['phone'] = $phone;
            $normalized['name'] = $name;
            
            // СКВОЗНОЕ ЛОГИРОВАНИЕ: Результат нормализации
            logMessage("NORMALIZER_RESULT | Type: generic | Phone: '$phone' | Name: '$name'", $this->config['global_log'], $this->config);
            
            // ДИАГНОСТИКА
            // error_log("🔍 GENERIC НОРМАЛИЗАТОР: phone='$phone', name='$name'");
            // error_log("🔍 GENERIC НОРМАЛИЗАТОР: все поля данных: " . json_encode(array_keys($parsedData), JSON_UNESCAPED_UNICODE));
            
            // Извлекаем UTM
            $utm = $this->extractUtm($parsedData);
            $normalized = array_merge($normalized, $utm);
            
            // Формируем комментарий из всех доступных полей
            $normalized['comment'] = $this->buildGenericComment($parsedData, $rawBody, $rawHeaders);
            
            // Название формы
            $normalized['form_name'] = $this->extractFormName($parsedData);
            
            // Передаем путь к raw-файлу для последующего удаления
            $normalized['raw_file_path'] = $rawData['raw_file_path'] ?? '';
            
            return $normalized;
            
        } catch (Exception $e) {
            $this->logError($e->getMessage(), $rawData);
            throw $e;
        }
    }
    
    /**
     * Формирует комментарий из всех доступных полей
     */
    private function buildGenericComment($data, $rawBody = '', $rawHeaders = []) {
        // Если это quiz-форма с вопросами и ответами в массиве answers, формируем только их
        if (!empty($data['answers']) && is_array($data['answers'])) {
            $lines = [];
            $hasValidPairs = false;
            
            foreach ($data['answers'] as $item) {
                $question = $item['q'] ?? '';
                $answer = $item['a'] ?? '';
                
                // Если answer — массив, склеиваем
                if (is_array($answer)) {
                    $answer = implode(', ', $answer);
                }
                
                // Очищаем от HTML тегов и лишних пробелов
                $question = strip_tags(trim($question));
                $answer = strip_tags(trim($answer));
                
                // Добавляем только если есть и вопрос, и ответ
                if (!empty($question) && !empty($answer)) {
                    $lines[] = "$question: $answer";
                    $hasValidPairs = true;
                }
            }
            
            // ВАЖНО: Если массив answers существует, возвращаем только его
            // Если нашли хотя бы одну пару вопрос-ответ, возвращаем их
            if ($hasValidPairs) {
                return implode("\n", $lines);
            }
            // Если массив answers есть, но вопросы пустые - это ошибка данных
            // Не обрабатываем другие поля, чтобы не смешивать данные
            // Возвращаем пустую строку, чтобы не показывать только ответы без вопросов
            return '';
        }
        
        // Исключаем служебные поля
        $excludeFields = [
            'phone', 'Phone', 'PHONE', 'name', 'Name', 'NAME', 'source_domain',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'formname', 'form_name', 'formName', 'form_title', 'formid', 'tranid', 
            'raw_body', 'raw_headers', 'parsed_data',
            'answers', 'raw', 'quiz', 'form', 'contacts', 'extra', 'created', 
            'result', 'paymentAmount', 'delivery', 'Checkbox', 'Checkbox_2', 'ASSIGNED_BY_ID', 'comments'
        ];
        
        // Собираем только обработанные данные (вопросы-ответы)
        $lines = [];
        
        foreach ($data as $key => $value) {
            // Пропускаем служебные поля
            if (in_array($key, $excludeFields)) {
                continue;
            }
            
            // Если значение - строка и не пустое
            if (is_string($value) && !empty($value) && strlen($value) < 500) {
                // Преобразуем ключ в читаемый формат (заменяем подчеркивания на пробелы)
                $question = str_replace('_', ' ', $key);
                
                // Очищаем от HTML тегов
                $question = strip_tags(trim($question));
                $answer = strip_tags(trim($value));
                
                if (!empty($question) && !empty($answer)) {
                    $lines[] = "$question: $answer";
                }
            } elseif (is_array($value) && !empty($value) && !in_array($key, $excludeFields)) {
                // Обрабатываем массивы
                $lines[] = "$key: " . json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }
        
        return !empty($lines) ? implode("\n", $lines) : '';
    }
    
    /**
     * Извлекает название формы
     */
    private function extractFormName($data) {
        // Проверяем различные поля для названия формы
        $formNameFields = [
            'formname', 'form_name', 'formName', 'form_title', 
            'title', 'form_title', 'page_title'
        ];
        
        foreach ($formNameFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                return $data[$field];
            }
        }
        
        return 'Неизвестная форма';
    }
    
    /**
     * Расширенное извлечение имени для универсального формата
     */
    protected function extractName($data) {
        // СКВОЗНОЕ ЛОГИРОВАНИЕ: Начало извлечения имени
        logMessage("NAME_EXTRACTION_START | Type: generic", $this->config['global_log'], $this->config);
        
        // Сначала пробуем стандартные поля
        $name = parent::extractName($data);
        if ($name) {
            logMessage("NAME_EXTRACTION_RESULT | Found in parent::extractName: '$name'", $this->config['global_log'], $this->config);
            return $name;
        }
        
        // Ищем поля содержащие "имя" или "name", НО НЕ "formname"
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value)) {
                $lowerKey = strtolower($key);
                
                // ИСКЛЮЧАЕМ поля формы и служебные поля
                if (strpos($lowerKey, 'formname') !== false || 
                    strpos($lowerKey, 'form_name') !== false ||
                    strpos($lowerKey, 'formid') !== false ||
                    strpos($lowerKey, 'tranid') !== false ||
                    strpos($lowerKey, 'phone') !== false ||
                    strpos($lowerKey, 'тел') !== false ||
                    strpos($lowerKey, 'checkbox') !== false ||
                    strpos($lowerKey, 'utm_') !== false) {
                    continue;
                }
                
                // Ищем поля с именем человека (точные совпадения)
                if ($lowerKey === 'name' || $lowerKey === 'fio' || $lowerKey === 'fullname') {
                    
                    // СКВОЗНОЕ ЛОГИРОВАНИЕ: Проверка поля имени
                    logMessage("NAME_FIELD_CHECK | Field: '$key' | Value: '$value' | Length: " . strlen($value), $this->config['global_log'], $this->config);
                    
                    // Дополнительная проверка - не должно быть длинным текстом формы
                    if (strlen($value) < 100 && 
                        !preg_match('/сколько.*стоить|имплантация|лечение|консультация|узнайте/i', $value) &&
                        !preg_match('/^\d+$/', $value) && // Исключаем чисто числовые значения
                        !preg_match('/^[A-Za-z0-9_]+$/', $value)) { // Исключаем технические значения
                        
                        $result = trim($value);
                        logMessage("NAME_EXTRACTION_RESULT | Found in field '$key': '$result'", $this->config['global_log'], $this->config);
                        return $result;
                    } else {
                        logMessage("NAME_FIELD_REJECTED | Field: '$key' | Value: '$value' | Reason: failed validation", $this->config['global_log'], $this->config);
                    }
                }
            }
        }
        
        // Если имя не найдено, возвращаем пустую строку
        logMessage("NAME_EXTRACTION_RESULT | No name found, returning empty string", $this->config['global_log'], $this->config);
        return '';
    }
    
    /**
     * Расширенное извлечение телефона для универсального формата
     */
    protected function extractPhone($data) {
        // #region agent log
        $debugLogPath = __DIR__ . '/../../.cursor/debug.log';
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => 'generic_normalizer.php:extractPhone',
            'message' => 'GENERIC_EXTRACT_PHONE_METHOD_START',
            'data' => [],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        // Сначала пробуем стандартные поля
        $phone = parent::extractPhone($data);
        if ($phone) {
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'C',
                'location' => 'generic_normalizer.php:extractPhone',
                'message' => 'GENERIC_EXTRACT_PHONE_FOUND_PARENT',
                'data' => ['phone' => $phone],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
            return $phone;
        }
        
        // Ищем поля содержащие "тел" или "phone" (включая Phone_1, Phone_2, Phone_10 и т.д.)
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value)) {
                $lowerKey = strtolower($key);
                
                // Проверяем паттерн: Phone_число, phone_число, PHONE_число
                if (preg_match('/^(phone|тел|mobile)_\d+$/i', $key)) {
                    $cleanPhone = preg_replace('/\D+/', '', $value);
                    if (strlen($cleanPhone) >= 10) {
                        $normalized = $this->normalizePhone($cleanPhone);
                        // #region agent log
                        $debugData = [
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'C',
                            'location' => 'generic_normalizer.php:extractPhone',
                            'message' => 'GENERIC_EXTRACT_PHONE_FOUND_SUFFIX',
                            'data' => ['key' => $key, 'raw' => $value, 'normalized' => $normalized],
                            'timestamp' => (int)(microtime(true) * 1000)
                        ];
                        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        // #endregion
                        return $normalized;
                    }
                }
                // Проверяем поля содержащие "тел" или "phone" или "mobile"
                elseif (strpos($lowerKey, 'тел') !== false || 
                        strpos($lowerKey, 'phone') !== false ||
                        strpos($lowerKey, 'mobile') !== false) {
                    $cleanPhone = preg_replace('/\D+/', '', $value);
                    if (strlen($cleanPhone) >= 10) {
                        $normalized = $this->normalizePhone($cleanPhone);
                        // #region agent log
                        $debugData = [
                            'sessionId' => 'debug-session',
                            'runId' => 'run1',
                            'hypothesisId' => 'C',
                            'location' => 'generic_normalizer.php:extractPhone',
                            'message' => 'GENERIC_EXTRACT_PHONE_FOUND_KEYWORD',
                            'data' => ['key' => $key, 'raw' => $value, 'normalized' => $normalized],
                            'timestamp' => (int)(microtime(true) * 1000)
                        ];
                        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
                        // #endregion
                        return $normalized;
                    }
                }
            }
        }
        
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'C',
            'location' => 'generic_normalizer.php:extractPhone',
            'message' => 'GENERIC_EXTRACT_PHONE_NOT_FOUND',
            'data' => [],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        return '';
    }
}
