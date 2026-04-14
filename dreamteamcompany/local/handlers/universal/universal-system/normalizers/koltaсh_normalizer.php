<?php
// normalizers/koltaсh_normalizer.php
// Нормализатор для данных от Колтач

require_once __DIR__ . '/base_normalizer.php';

class KoltaсhNormalizer extends BaseNormalizer {
    
    public function normalize($rawData) {
        try {
            $normalized = $this->createBaseStructure($rawData);
            
            // Извлекаем телефон
            $normalized['phone'] = $this->extractPhone($rawData);
            
            // Извлекаем имя
            $normalized['name'] = $this->extractName($rawData);
            
            // Извлекаем UTM
            $utm = $this->extractUtm($rawData);
            $normalized = array_merge($normalized, $utm);
            
            // Формируем комментарий из ответов Колтач
            $normalized['comment'] = $this->buildCommentFromAnswers($rawData);
            
            // Название формы
            $normalized['form_name'] = $this->extractFormName($rawData);
            
            return $normalized;
            
        } catch (Exception $e) {
            $this->logError($e->getMessage(), $rawData);
            throw $e;
        }
    }
    
    /**
     * Формирует комментарий из ответов Колтач
     */
    private function buildCommentFromAnswers($data) {
        $lines = [];
        $lines[] = "Ответы из формы Колтач:";
        
        // Обрабатываем блок answers
        if (!empty($data['answers']) && is_array($data['answers'])) {
            $lines[] = "--- answers ---";
            foreach ($data['answers'] as $item) {
                $question = $item['q'] ?? '';
                $answer = $item['a'] ?? '';
                
                // Если answer — массив, склеиваем
                if (is_array($answer)) {
                    $answer = implode(', ', $answer);
                }
                
                $question = strip_tags(trim($question));
                $answer = strip_tags(trim($answer));
                
                if ($question && $answer) {
                    $lines[] = "Вопрос: $question";
                    $lines[] = "Ответ: $answer";
                }
            }
        }
        
        // Обрабатываем блок raw (если есть)
        if (!empty($data['raw']) && is_array($data['raw'])) {
            $lines[] = "--- raw ---";
            foreach ($data['raw'] as $item) {
                $question = $item['q'] ?? '';
                $answer = $item['a'] ?? '';
                
                if (is_array($answer)) {
                    $answer = implode(', ', $answer);
                }
                
                $question = strip_tags(trim($question));
                $answer = strip_tags(trim($answer));
                
                if ($question && $answer) {
                    $lines[] = "Вопрос: $question";
                    $lines[] = "Ответ: $answer";
                }
            }
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Извлекает название формы
     */
    private function extractFormName($data) {
        // Проверяем различные поля для названия формы
        $formNameFields = ['formname', 'form_name', 'formName', 'quiz_name'];
        
        foreach ($formNameFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                return $data[$field];
            }
        }
        
        return 'Колтач форма';
    }
    
    /**
     * Переопределяем извлечение имени для Колтач
     */
    protected function extractName($data) {
        // Сначала пробуем стандартные поля
        $name = parent::extractName($data);
        if ($name) {
            return $name;
        }
        
        // Ищем имя в answers
        if (!empty($data['answers'])) {
            foreach ($data['answers'] as $answer) {
                if (isset($answer['q']) && isset($answer['a'])) {
                    $question = strtolower($answer['q']);
                    if (strpos($question, 'имя') !== false || 
                        strpos($question, 'name') !== false ||
                        strpos($question, 'как зовут') !== false) {
                        return trim($answer['a']);
                    }
                }
            }
        }
        
        return '';
    }
}
