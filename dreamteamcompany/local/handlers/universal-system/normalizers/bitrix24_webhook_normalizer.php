<?php
// bitrix24_webhook_normalizer.php
// Нормализатор для webhook'ов от Bitrix24

require_once __DIR__ . '/base_normalizer.php';

class Bitrix24WebhookNormalizer extends BaseNormalizer {
    
    public function normalize($rawData) {
        try {
            // Извлекаем данные из новой структуры
            $parsedData = $rawData['parsed_data'] ?? $rawData; // fallback для старых файлов
            $rawBody = $rawData['raw_body'] ?? '';
            $rawHeaders = $rawData['raw_headers'] ?? [];
            
            $normalized = $this->createBaseStructure($rawData);
            
            // Парсим QUERY_STRING для извлечения полей
            $queryString = $rawHeaders['QUERY_STRING'] ?? '';
            $fields = $this->parseQueryString($queryString);
            // Fallback: некоторые клиенты присылают fields[...] в теле (raw_body) даже при GET/POST
            if (empty($fields) || (empty($fields['PHONE']) && !empty($rawBody))) {
                $bodyFields = $this->parseQueryString($rawBody);
                if (!empty($bodyFields)) {
                    // Объединяем, не перетирая уже найденные значения из QUERY_STRING
                    foreach ($bodyFields as $k => $v) {
                        if (!isset($fields[$k]) || $fields[$k] === '') {
                            $fields[$k] = $v;
                        }
                    }
                    logMessage("BITRIX24_FALLBACK_BODY_PARSED | поля извлечены из raw_body", $this->config['global_log'], $this->config);
                }
            }
            
            // НОВАЯ ЛОГИКА: Проверяем JSON формат в raw_body
            if (empty($fields) || (empty($fields['PHONE']) && !empty($rawBody))) {
                $jsonData = json_decode($rawBody, true);
                if ($jsonData && is_array($jsonData)) {
                    // Объединяем JSON поля с уже найденными
                    foreach ($jsonData as $k => $v) {
                        if (!isset($fields[$k]) || $fields[$k] === '') {
                            $fields[$k] = $v;
                        }
                    }
                    logMessage("BITRIX24_JSON_BODY_PARSED | поля извлечены из JSON raw_body", $this->config['global_log'], $this->config);
                }
            }
            
            // Извлекаем телефон
            $normalized['phone'] = $this->extractPhoneFromFields($fields);
            
            // БЛОКИРОВКА: Проверяем, что телефон не пустой
            if (empty(trim($normalized['phone']))) {
                logMessage("NORMALIZER_BLOCKED | Type: bitrix24 | Телефон пустой, нормализация прервана | Phone: '{$normalized['phone']}'", $this->config['global_log'], $this->config);
                return null;
            }
            
            // Извлекаем имя
            $normalized['name'] = $this->extractNameFromFields($fields);
            
            // СКВОЗНОЕ ЛОГИРОВАНИЕ: Результат нормализации
            logMessage("NORMALIZER_RESULT | Type: bitrix24 | Phone: '{$normalized['phone']}' | Name: '{$normalized['name']}'", $this->config['global_log'], $this->config);
            
        // ДИАГНОСТИКА
        // error_log("🔍 BITRIX24 НОРМАЛИЗАТОР: phone='{$normalized['phone']}', name='{$normalized['name']}'");
        // Дублируем критичную диагностику в глобальный лог (без изменения логики)
        logMessage("BITRIX24_NORMALIZER_RESULT | phone='{$normalized['phone']}' | name='{$normalized['name']}'", $this->config['global_log'], $this->config);
            
            // Извлекаем UTM (может быть в COMMENTS)
            $utm = $this->extractUtmFromComments($fields['COMMENTS'] ?? '');
            $normalized = array_merge($normalized, $utm);
            
            // Формируем комментарий
            $normalized['comment'] = $this->buildComment($fields, $rawBody, $rawHeaders);
            
            // Название формы (используем TITLE)
            $normalized['form_name'] = $fields['TITLE'] ?? 'Bitrix24 Webhook';
            
            // Добавляем информацию о городе из SOURCE_DESCRIPTION
            $normalized['city'] = $this->extractCityFromSourceDescription($fields['SOURCE_DESCRIPTION'] ?? '');

            // ВАЖНО: подменяем source_domain на SOURCE_DESCRIPTION, чтобы маппинг по ИБ54 сработал в lead_processor
            if (!empty($fields['SOURCE_DESCRIPTION'])) {
                $normalized['source_domain'] = $fields['SOURCE_DESCRIPTION'];
                logMessage("BITRIX24_SOURCE_DOMAIN_SET | '{$normalized['source_domain']}'", $this->config['global_log'], $this->config);
            }
            
            // НОВАЯ ЛОГИКА: Если есть ASSIGNED_BY_ID, используем его для поиска в 54 блоке по названию
            if (!empty($fields['ASSIGNED_BY_ID'])) {
                $normalized['found_element_name'] = $fields['ASSIGNED_BY_ID'];
                $normalized['search_method'] = 'TITLE';
                logMessage("BITRIX24_ASSIGNED_BY_ID_SEARCH | Используем ASSIGNED_BY_ID='{$fields['ASSIGNED_BY_ID']}' для поиска в 54 блоке", $this->config['global_log'], $this->config);
            }
            
            // Данные из ИБ54 подставляются на этапе создания лида (lead_processor)
            // Здесь не обращаемся к БД, чтобы не блокировать нормализацию
            
            // Передаем путь к raw-файлу для последующего удаления
            $normalized['raw_file_path'] = $rawData['raw_file_path'] ?? '';
            
            return $normalized;
            
        } catch (Exception $e) {
            $this->logError($e->getMessage(), $rawData);
            throw $e;
        }
    }
    
    /**
     * Парсит QUERY_STRING и извлекает поля
     */
    private function parseQueryString($queryString) {
        $fields = [];
        
        if (empty($queryString)) {
            // error_log("🔍 BITRIX24: QUERY_STRING пустой");
            return $fields;
        }
        
        // ДИАГНОСТИКА
        // error_log("🔍 BITRIX24 QUERY_STRING: '$queryString'");
        
        // Декодируем URL
        $decoded = urldecode($queryString);
        // error_log("🔍 BITRIX24 DECODED: '$decoded'");
        logMessage("BITRIX24_QUERY_DECODED | '" . $decoded . "'", $this->config['global_log'], $this->config);
        
        // Парсим поля вида fields[TITLE]=value или fields[PHONE][0][VALUE]=value
        if (preg_match_all('/fields\[([^=]+)\]=([^&]*)/', $decoded, $matches, PREG_SET_ORDER)) {
            // error_log("🔍 BITRIX24: найдено " . count($matches) . " полей fields[]");
            foreach ($matches as $match) {
                $fieldName = $match[1];
                $fieldValue = trim(urldecode($match[2])); // Декодируем URL и убираем пробелы
                
                // error_log("🔍 BITRIX24 ПОЛЕ: '$fieldName' = '$fieldValue'");
                
                // Обрабатываем специальные поля
                if ($fieldName === 'PHONE[0][VALUE]' || $fieldName === 'PHONE][0][VALUE') {
                    $fields['PHONE'] = $fieldValue;
                    // error_log("🔍 BITRIX24 НАЙДЕН ТЕЛЕФОН: '$fieldValue'");
                    logMessage("BITRIX24_PHONE_FOUND | raw='$fieldValue'", $this->config['global_log'], $this->config);
                } elseif ($fieldName === 'PHONE[0][VALUE_TYPE]' || $fieldName === 'PHONE][0][VALUE_TYPE') {
                    $fields['PHONE_TYPE'] = $fieldValue;
                } else {
                    $fields[$fieldName] = $fieldValue;
                    if ($fieldName === 'NAME') {
                        // error_log("🔍 BITRIX24 НАЙДЕНО ИМЯ: '$fieldValue'");
                    }
                    if ($fieldName === 'SOURCE_DESCRIPTION') {
                        // error_log("🔍 BITRIX24 НАЙДЕН SOURCE_DESCRIPTION: '$fieldValue'");
                    }
                    if ($fieldName === 'ASSIGNED_BY_ID') {
                        // error_log("🔍 BITRIX24 НАЙДЕН ASSIGNED_BY_ID: '$fieldValue'");
                        logMessage("BITRIX24_ASSIGNED_BY_ID_FOUND | raw='$fieldValue'", $this->config['global_log'], $this->config);
                    }
                }
            }
        } else {
            // error_log("🔍 BITRIX24: не найдено полей fields[], пробуем простой формат");
            
            // Парсим простые поля вида KEY=VALUE
            if (preg_match_all('/([^=&]+)=([^&]*)/', $decoded, $matches, PREG_SET_ORDER)) {
                // error_log("🔍 BITRIX24: найдено " . count($matches) . " простых полей");
                foreach ($matches as $match) {
                    $fieldName = $match[1];
                    $fieldValue = trim(urldecode($match[2])); // Декодируем URL и убираем пробелы
                    
                    // error_log("🔍 BITRIX24 ПРОСТОЕ ПОЛЕ: '$fieldName' = '$fieldValue'");
                    
                    $fields[$fieldName] = $fieldValue;
                    
                    if ($fieldName === 'PHONE') {
                        // error_log("🔍 BITRIX24 НАЙДЕН ТЕЛЕФОН: '$fieldValue'");
                        logMessage("BITRIX24_PHONE_FOUND | raw='$fieldValue'", $this->config['global_log'], $this->config);
                    }
                    if ($fieldName === 'NAME') {
                        // error_log("🔍 BITRIX24 НАЙДЕНО ИМЯ: '$fieldValue'");
                    }
                    if ($fieldName === 'SOURCE_DESCRIPTION') {
                        // error_log("🔍 BITRIX24 НАЙДЕН SOURCE_DESCRIPTION: '$fieldValue'");
                    }
                    if ($fieldName === 'ASSIGNED_BY_ID') {
                        // error_log("🔍 BITRIX24 НАЙДЕН ASSIGNED_BY_ID: '$fieldValue'");
                        logMessage("BITRIX24_ASSIGNED_BY_ID_FOUND | raw='$fieldValue'", $this->config['global_log'], $this->config);
                    }
                }
            } else {
                // error_log("🔍 BITRIX24: не найдено полей вообще");
            }
        }
        
        // error_log("🔍 BITRIX24 ПОЛЯ: " . json_encode($fields));
        return $fields;
    }
    
    /**
     * Извлекает телефон из полей
     */
    private function extractPhoneFromFields($fields) {
        $phone = $fields['PHONE'] ?? '';
        
        if (!empty($phone)) {
            // Очищаем телефон от лишних символов, но сохраняем +7
            $phone = preg_replace('/[^\d+]/', '', $phone);
            
            // ДИАГНОСТИКА
            // error_log("🔍 BITRIX24 ТЕЛЕФОН: исходный='{$fields['PHONE']}', очищенный='$phone'");
            $len = strlen($phone);
            logMessage("BITRIX24_PHONE_CLEAN | cleaned='$phone' | len=$len", $this->config['global_log'], $this->config);
            
            // Проверяем что телефон не пустой и содержит достаточно цифр
            if (strlen($phone) >= 10) {
                // Если начинается с +7, форматируем
                if (strpos($phone, '+7') === 0 && strlen($phone) >= 12) {
                    $phone = '+7 (' . substr($phone, 2, 3) . ') ' . substr($phone, 5, 3) . '-' . substr($phone, 8, 2) . '-' . substr($phone, 10, 2);
                }
                // Если начинается с 7, добавляем +
                elseif (strpos($phone, '7') === 0 && strlen($phone) >= 11) {
                    $phone = '+7 (' . substr($phone, 1, 3) . ') ' . substr($phone, 4, 3) . '-' . substr($phone, 7, 2) . '-' . substr($phone, 9, 2);
                }
                // Если начинается с 8, заменяем на +7
                elseif (strpos($phone, '8') === 0 && strlen($phone) >= 11) {
                    $phone = '+7 (' . substr($phone, 1, 3) . ') ' . substr($phone, 4, 3) . '-' . substr($phone, 7, 2) . '-' . substr($phone, 9, 2);
                }
            } else {
                // error_log("🔍 BITRIX24 ТЕЛЕФОН: слишком короткий '$phone'");
                $phone = '';
            }
        }
        
        return $phone;
    }
    
    /**
     * Извлекает имя из полей
     */
    private function extractNameFromFields($fields) {
        $name = $fields['NAME'] ?? '';
        logMessage("NAME_EXTRACTION_RESULT | Type: bitrix24 | Field: NAME | Value: '$name'", $this->config['global_log'], $this->config);
        return $name;
    }
    
    /**
     * Извлекает UTM из комментариев
     */
    private function extractUtmFromComments($comments) {
        $utm = [
            'utm_source' => '',
            'utm_medium' => '',
            'utm_campaign' => '',
            'utm_content' => '',
            'utm_term' => ''
        ];
        
        if (empty($comments)) {
            return $utm;
        }
        
        // Ищем UTM параметры в комментариях
        if (preg_match('/utm_source=([^&\s]+)/', $comments, $matches)) {
            $utm['utm_source'] = $matches[1];
        }
        if (preg_match('/utm_medium=([^&\s]+)/', $comments, $matches)) {
            $utm['utm_medium'] = $matches[1];
        }
        if (preg_match('/utm_campaign=([^&\s]+)/', $comments, $matches)) {
            $utm['utm_campaign'] = $matches[1];
        }
        if (preg_match('/utm_content=([^&\s]+)/', $comments, $matches)) {
            $utm['utm_content'] = $matches[1];
        }
        if (preg_match('/utm_term=([^&\s]+)/', $comments, $matches)) {
            $utm['utm_term'] = $matches[1];
        }
        
        return $utm;
    }
    
    /**
     * Извлекает город из SOURCE_DESCRIPTION
     */
    private function extractCityFromSourceDescription($sourceDescription) {
        if (empty($sourceDescription)) {
            return '';
        }
        
        // Ищем город после "*"
        if (preg_match('/\*([^*]+)$/', $sourceDescription, $matches)) {
            return trim($matches[1]);
        }
        
        return '';
    }
    
    /**
     * Формирует комментарий
     */
    private function buildComment($fields, $rawBody, $rawHeaders) {
        $lines = [];
        $lines[] = "Данные из Bitrix24 Webhook:";
        
        // Добавляем сырые данные если есть
        if (!empty($rawBody)) {
            $lines[] = "";
            $lines[] = "Сырые данные запроса:";
            $lines[] = urldecode($rawBody);
        }
        
        // Добавляем заголовки если есть
        if (!empty($rawHeaders)) {
            $lines[] = "";
            $lines[] = "Заголовки запроса:";
            foreach ($rawHeaders as $header => $value) {
                if (!empty($value)) {
                    $lines[] = "$header: $value";
                }
            }
        }
        
        $lines[] = "";
        $lines[] = "Обработанные данные:";
        
        // Добавляем основные поля
        if (!empty($fields['TITLE'])) {
            $lines[] = "Заголовок: " . $fields['TITLE'];
        }
        if (!empty($fields['NAME'])) {
            $lines[] = "Имя: " . $fields['NAME'];
        }
        if (!empty($fields['PHONE'])) {
            $lines[] = "Телефон: " . $fields['PHONE'];
        }
        if (!empty($fields['COMMENTS'])) {
            $lines[] = "Комментарии: " . $fields['COMMENTS'];
        }
        if (!empty($fields['SOURCE_DESCRIPTION'])) {
            $lines[] = "Источник: " . $fields['SOURCE_DESCRIPTION'];
        }
        
        return implode("\n", $lines);
    }
}
