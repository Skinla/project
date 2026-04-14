<?php
// chat_notifications.php
// Функции для отправки уведомлений в чаты Битрикс24

require_once __DIR__ . '/logger_and_queue.php';

/**
 * Отправляет сообщение в чат Битрикс24 через REST API
 * 
 * @param string $webhookUrl URL вебхука портала
 * @param string $chatId ID чата
 * @param string $message Текст сообщения
 * @param array $config Конфигурация
 * @return bool Успешность отправки
 */
function sendChatMessage($webhookUrl, $chatId, $message, $config) {
    try {
        // Подготавливаем данные для отправки
        $postFields = [
            'DIALOG_ID' => $chatId,
            'MESSAGE' => $message,
            'SYSTEM' => 'Y' // Системное сообщение
        ];
        
        // Формируем URL для метода im.message.add
        $url = $webhookUrl . 'im.message.add.json';
        
        // Выполняем запрос через cURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            logMessage("Ошибка cURL при отправке сообщения в чат: " . $error, $config['global_log'], $config);
            return false;
        }
        
        if ($httpCode !== 200) {
            logMessage("HTTP ошибка при отправке сообщения в чат: код $httpCode", $config['global_log'], $config);
            return false;
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("Ошибка декодирования JSON ответа от чата: " . json_last_error_msg(), $config['global_log'], $config);
            return false;
        }
        
        if (isset($response['error'])) {
            logMessage("Ошибка API при отправке сообщения в чат: " . $response['error']['message'], $config['global_log'], $config);
            return false;
        }
        
        if (isset($response['result'])) {
            logMessage("Сообщение успешно отправлено в чат ID: $chatId", $config['global_log'], $config);
            return true;
        }
        
        logMessage("Неожиданный ответ от API чата: " . $result, $config['global_log'], $config);
        return false;
        
    } catch (Exception $e) {
        logMessage("Исключение при отправке сообщения в чат: " . $e->getMessage(), $config['global_log'], $config);
        return false;
    }
}

/**
 * Отправляет уведомление об ошибке обработки файла в чат "Ошибки по рекламе"
 * 
 * @param string $fileName Имя файла
 * @param string $reason Причина ошибки
 * @param array $config Конфигурация
 * @return bool Успешность отправки
 */
function sendFailedFileNotification($fileName, $reason, $config) {
    // Получаем ID чата из конфигурации
    $chatId = $config['error_chat_id'] ?? null;
    
    if (!$chatId) {
        logMessage("ID чата 'Ошибки по рекламе' не настроен в конфигурации", $config['global_log'], $config);
        return false;
    }
    
    // Получаем URL вебхука для отправки уведомления
    $webhookUrl = $config['portal_webhooks']['dreamteamcompany'] ?? null;
    
    if (!$webhookUrl) {
        logMessage("URL вебхука не настроен в конфигурации", $config['global_log'], $config);
        return false;
    }
    
    // Формируем сообщение
    $message = "🚨 Ошибка по рекламе\n\n";
    $message .= "📁 Файл: $fileName\n";
    $message .= "❌ Причина: $reason\n";
    $message .= "⏰ Время: " . date('d.m.Y H:i:s') . "\n";
    $message .= "🔗 Портал: https://bitrix.dreamteamcompany.ru\n\n";
    $message .= "Требуется ручная обработка файла из папки failed.";
    
    // Отправляем сообщение
    return sendChatMessage($webhookUrl, $chatId, $message, $config);
}

/**
 * Получает список доступных чатов (для отладки)
 * 
 * @param string $webhookUrl URL вебхука портала
 * @param array $config Конфигурация
 * @return array|false Список чатов или false при ошибке
 */
function getChatList($webhookUrl, $config) {
    try {
        $url = $webhookUrl . 'im.dialog.get.json';
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            logMessage("Ошибка cURL при получении списка чатов: " . $error, $config['global_log'], $config);
            return false;
        }
        
        if ($httpCode !== 200) {
            logMessage("HTTP ошибка при получении списка чатов: код $httpCode", $config['global_log'], $config);
            return false;
        }
        
        $response = json_decode($result, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            logMessage("Ошибка декодирования JSON ответа от списка чатов: " . json_last_error_msg(), $config['global_log'], $config);
            return false;
        }
        
        if (isset($response['error'])) {
            logMessage("Ошибка API при получении списка чатов: " . $response['error']['message'], $config['global_log'], $config);
            return false;
        }
        
        return $response['result'] ?? [];
        
    } catch (Exception $e) {
        logMessage("Исключение при получении списка чатов: " . $e->getMessage(), $config['global_log'], $config);
        return false;
    }
}
