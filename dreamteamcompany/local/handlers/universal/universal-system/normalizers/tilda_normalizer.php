<?php
// normalizers/tilda_normalizer.php
// Нормализатор для данных от Тильда

require_once __DIR__ . '/base_normalizer.php';

class TildaNormalizer extends BaseNormalizer {
    
    public function normalize($rawData) {
        try {
            // Извлекаем данные из новой структуры
            $parsedData = $rawData['parsed_data'] ?? $rawData; // fallback для старых файлов
            
            $normalized = $this->createBaseStructure($rawData);
            
            // Извлекаем телефон из parsed_data
            $normalized['phone'] = $this->extractPhone($parsedData);
            
            // Извлекаем имя из parsed_data
            $normalized['name'] = $this->extractName($parsedData);
            
            // Извлекаем UTM из parsed_data
            $utm = $this->extractUtm($parsedData);
            $normalized = array_merge($normalized, $utm);
            
            // Формируем комментарий из данных Тильда (используем parsed_data)
            $normalized['comment'] = $this->buildCommentFromTilda($parsedData);
            
            // Название формы из parsed_data
            $normalized['form_name'] = $this->extractFormName($parsedData);
            
            return $normalized;
            
        } catch (Exception $e) {
            $this->logError($e->getMessage(), $rawData);
            throw $e;
        }
    }
    
    /**
     * Формирует комментарий из данных Тильда
     */
    private function buildCommentFromTilda($data) {
        $lines = [];
        $lines[] = "Данные из формы Тильда:";
        
        // Обрабатываем __submission данные
        if (!empty($data['__submission']) && is_array($data['__submission'])) {
            $lines[] = "--- submission ---";
            foreach ($data['__submission'] as $key => $value) {
                if (is_string($value) && !empty($value)) {
                    $lines[] = "$key: $value";
                }
            }
        }
        
        // Обрабатываем основные поля формы
        $excludeFields = ['phone', 'Phone', 'PHONE', 'name', 'Name', 'NAME', 'source_domain', 'formname'];
        foreach ($data as $key => $value) {
            if (is_string($value) && !empty($value) && !in_array($key, $excludeFields)) {
                $lines[] = "$key: $value";
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Извлекает название формы
     */
    private function extractFormName($data) {
        // Проверяем formname
        if (isset($data['formname']) && !empty($data['formname'])) {
            return $data['formname'];
        }
        
        // Проверяем в __submission
        if (isset($data['__submission']['formname']) && !empty($data['__submission']['formname'])) {
            return $data['__submission']['formname'];
        }
        
        return 'Тильда форма';
    }
    
    /**
     * Переопределяем извлечение телефона для Тильда
     */
    protected function extractPhone($data) {
        // Сначала пробуем стандартные поля
        $phone = parent::extractPhone($data);
        if ($phone) {
            return $phone;
        }
        
        // Проверяем в __submission
        if (!empty($data['__submission'])) {
            foreach ($data['__submission'] as $key => $value) {
                if (is_string($value) && preg_match('/^phone/i', $key) && !empty($value)) {
                    return preg_replace('/\D+/', '', $value);
                }
            }
        }
        
        return '';
    }
    
    /**
     * Переопределяем извлечение имени для Тильда
     */
    protected function extractName($data) {
        // Сначала пробуем стандартные поля
        $name = parent::extractName($data);
        if ($name) {
            return $name;
        }
        
        // Проверяем в __submission
        if (!empty($data['__submission'])) {
            foreach ($data['__submission'] as $key => $value) {
                if (is_string($value) && preg_match('/^name/i', $key) && !empty($value)) {
                    return trim($value);
                }
            }
        }
        
        return '';
    }
}
