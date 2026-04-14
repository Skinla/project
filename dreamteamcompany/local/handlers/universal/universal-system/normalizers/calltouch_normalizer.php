<?php
// normalizers/calltouch_normalizer.php
// Нормализатор для данных от CallTouch

require_once __DIR__ . '/base_normalizer.php';

class CallTouchNormalizer extends BaseNormalizer {
    
    public function normalize($rawData) {
        try {
            $normalized = $this->createBaseStructure($rawData);
            
            // Извлекаем телефон из CallTouch данных
            $normalized['phone'] = $this->extractPhone($rawData);
            
            // Извлекаем имя
            $normalized['name'] = $this->extractName($rawData);
            
            // Извлекаем UTM
            $utm = $this->extractUtm($rawData);
            $normalized = array_merge($normalized, $utm);
            
            // Формируем комментарий из данных CallTouch
            $normalized['comment'] = $this->buildCommentFromCallTouch($rawData);
            
            // Название формы
            $normalized['form_name'] = $this->extractFormName($rawData);
            
            // Дополнительные поля CallTouch
            $normalized['calltouch_data'] = $this->extractCallTouchSpecificData($rawData);
            
            return $normalized;
            
        } catch (Exception $e) {
            $this->logError($e->getMessage(), $rawData);
            throw $e;
        }
    }
    
    /**
     * Переопределяем извлечение телефона для CallTouch
     */
    protected function extractPhone($data) {
        // CallTouch использует поле callerphone
        if (isset($data['callerphone']) && !empty($data['callerphone'])) {
            return preg_replace('/\D+/', '', $data['callerphone']);
        }
        
        // Fallback на стандартные поля
        return parent::extractPhone($data);
    }
    
    /**
     * Переопределяем извлечение имени для CallTouch
     */
    protected function extractName($data) {
        // CallTouch может передавать имя в разных полях
        $nameFields = ['name', 'Name', 'callername', 'callerName'];
        
        foreach ($nameFields as $field) {
            if (isset($data[$field]) && !empty($data[$field]) && $data[$field] !== 'Неизвестно') {
                return trim($data[$field]);
            }
        }
        
        return parent::extractName($data);
    }
    
    /**
     * Формирует комментарий из данных CallTouch
     */
    private function buildCommentFromCallTouch($data) {
        $lines = [];
        $lines[] = "Данные из CallTouch:";
        
        // Основная информация о звонке
        if (isset($data['callphase'])) {
            $lines[] = "Фаза звонка: " . $data['callphase'];
        }
        
        if (isset($data['siteId'])) {
            $lines[] = "Site ID: " . $data['siteId'];
        }
        
        if (isset($data['subPoolName'])) {
            $lines[] = "Sub Pool: " . $data['subPoolName'];
        }
        
        if (isset($data['url'])) {
            $lines[] = "URL: " . $data['url'];
        }
        
        if (isset($data['callUrl'])) {
            $lines[] = "Call URL: " . $data['callUrl'];
        }
        
        // Время звонка
        if (isset($data['calltime'])) {
            $lines[] = "Время звонка: " . $data['calltime'];
        }
        
        if (isset($data['callDate'])) {
            $lines[] = "Дата звонка: " . $data['callDate'];
        }
        
        // Длительность звонка
        if (isset($data['callduration'])) {
            $lines[] = "Длительность: " . $data['callduration'] . " сек";
        }
        
        // Статус звонка
        if (isset($data['callstatus'])) {
            $lines[] = "Статус: " . $data['callstatus'];
        }
        
        // Дополнительные поля
        $excludeFields = [
            'callerphone', 'phone', 'Phone', 'PHONE', 'name', 'Name', 'NAME',
            'source_domain', 'utm_source', 'utm_medium', 'utm_campaign', 
            'utm_content', 'utm_term', 'siteId', 'subPoolName', 'url', 
            'callUrl', 'callphase', 'calltime', 'callDate', 'callduration', 
            'callstatus', 'ctCallerId'
        ];
        
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value) && !in_array($key, $excludeFields)) {
                if (strlen($value) < 500) {
                    $lines[] = "$key: $value";
                }
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Извлекает название формы/источника
     */
    private function extractFormName($data) {
        // CallTouch может передавать информацию об источнике
        if (isset($data['subPoolName']) && !empty($data['subPoolName'])) {
            return 'CallTouch: ' . $data['subPoolName'];
        }
        
        if (isset($data['url']) && !empty($data['url'])) {
            $parsed = parse_url($data['url']);
            if (isset($parsed['host'])) {
                return 'CallTouch: ' . $parsed['host'];
            }
        }
        
        return 'CallTouch звонок';
    }
    
    /**
     * Извлекает специфичные для CallTouch данные
     */
    private function extractCallTouchSpecificData($data) {
        $callTouchData = [];
        
        // Основные поля CallTouch
        if (isset($data['ctCallerId'])) {
            $callTouchData['ctCallerId'] = $data['ctCallerId'];
        }
        
        if (isset($data['siteId'])) {
            $callTouchData['siteId'] = $data['siteId'];
        }
        
        if (isset($data['subPoolName'])) {
            $callTouchData['subPoolName'] = $data['subPoolName'];
        }
        
        if (isset($data['callphase'])) {
            $callTouchData['callphase'] = $data['callphase'];
        }
        
        if (isset($data['url'])) {
            $callTouchData['url'] = $data['url'];
        }
        
        if (isset($data['callUrl'])) {
            $callTouchData['callUrl'] = $data['callUrl'];
        }
        
        if (isset($data['calltime'])) {
            $callTouchData['calltime'] = $data['calltime'];
        }
        
        if (isset($data['callDate'])) {
            $callTouchData['callDate'] = $data['callDate'];
        }
        
        if (isset($data['callduration'])) {
            $callTouchData['callduration'] = $data['callduration'];
        }
        
        if (isset($data['callstatus'])) {
            $callTouchData['callstatus'] = $data['callstatus'];
        }
        
        return $callTouchData;
    }
    
    /**
     * Переопределяем извлечение домена источника для CallTouch
     */
    protected function extractSourceDomain($data) {
        // CallTouch может передавать домен в разных полях
        if (isset($data['url']) && !empty($data['url'])) {
            $parsed = parse_url($data['url']);
            if (isset($parsed['host'])) {
                return $parsed['host'];
            }
        }
        
        if (isset($data['callUrl']) && !empty($data['callUrl'])) {
            $parsed = parse_url($data['callUrl']);
            if (isset($parsed['host'])) {
                return $parsed['host'];
            }
        }
        
        // Fallback на subPoolName
        if (isset($data['subPoolName']) && !empty($data['subPoolName'])) {
            return $data['subPoolName'];
        }
        
        return parent::extractSourceDomain($data);
    }
}
