<?php
// chat_notifications.php
// Функции для отправки уведомлений в чаты Битрикс24

// Логирование: используем общий logMessage из процессора, а при его отсутствии — минимальный фолбэк
if (file_exists(__DIR__ . '/logger_and_queue.php')) {
    require_once __DIR__ . '/logger_and_queue.php';
} elseif (!function_exists('logMessage')) {
    function logMessage($message, $logFile, $config = []) {
        $file = $logFile ?? ($config['global_log'] ?? 'calltouch_common.log');
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

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
    $chatNotifications = $config['chat_notifications'] ?? [];
    $chatId = $chatNotifications['error_chat_id'] ?? null;
    
    if (!$chatId) {
        logMessage("ID чата 'Ошибки по рекламе' не настроен в конфигурации (chat_notifications.error_chat_id)", $config['global_log'] ?? 'calltouch_common.log', $config);
        return false;
    }
    
    // Получаем URL вебхука для отправки уведомления
    $portalWebhooks = $chatNotifications['portal_webhooks'] ?? [];
    $webhookUrl = $portalWebhooks['dreamteamcompany'] ?? null;
    
    if (!$webhookUrl) {
        logMessage("URL вебхука не настроен в конфигурации (chat_notifications.portal_webhooks.dreamteamcompany)", $config['global_log'] ?? 'calltouch_common.log', $config);
        return false;
    }
    
    // Попробуем дополнить причину деталями о ненайденном элементе из файла ошибок
    $diagnostic = '';
    // Путь к папке ошибок: сначала из конфига, затем дефолт
    $errorsDir = $config['errors_dir'] ?? (__DIR__ . '/queue_errors');
    $errorFilePath = rtrim($errorsDir, '/\\') . '/' . $fileName;
    if (is_file($errorFilePath)) {
        $raw = @file_get_contents($errorFilePath);
        $data = $raw ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $siteId = (string)($data['siteId'] ?? '');
            $subPoolName = (string)($data['subPoolName'] ?? '');
            $hostname = (string)($data['hostname'] ?? '');
            $url = (string)($data['url'] ?? '');
            $callUrl = (string)($data['callUrl'] ?? '');
            $siteName = (string)($data['siteName'] ?? '');
            $callerPhone = (string)($data['callerphone'] ?? ($data['phone'] ?? ''));

            $nameCandidate = '';
            if ($subPoolName !== '') {
                $nameCandidate = $subPoolName;
            } elseif ($hostname !== '') {
                $nameCandidate = $hostname;
            } else {
                $extractHost = function (string $maybeUrl): string {
                    if ($maybeUrl === '') return '';
                    $p = @parse_url($maybeUrl);
                    return (is_array($p) && !empty($p['host'])) ? $p['host'] : '';
                };
                $nameCandidate = $extractHost($url);
                if ($nameCandidate === '') {
                    $nameCandidate = $extractHost($callUrl);
                }
                if ($nameCandidate === '' && $siteName !== '') {
                    $nameCandidate = $siteName;
                }
            }

            // Фильтр: подавляем отправку уведомления для пустых/тестовых запросов
            $cleanReasonProbe = preg_replace('/;\s*callerphone=\S+/i', '', (string)$reason);
            $isWrongPhase = stripos($cleanReasonProbe, 'wrong_phase') === 0;
            $allEmpty = ($nameCandidate === '' && $siteId === '' && $siteName === '' && $callerPhone === '');
            if ($allEmpty || ($isWrongPhase && $callerPhone === '')) {
                logMessage("sendFailedFileNotification: подавлено уведомление (пустой/тестовый запрос): file=$fileName, reason=$cleanReasonProbe", $config['global_log'] ?? 'calltouch_common.log', $config);
                return false;
            }

            if ($nameCandidate !== '' || $siteId !== '' || $siteName !== '' || $callerPhone !== '') {
                $iblockLink = 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/view/0/?list_section_id=';
                $diagnostic = "\nℹ️ Детали: не найден [URL=" . $iblockLink . "]элемент инфоблока 54[/URL]"
                    . ($nameCandidate !== '' ? ", NAME='" . $nameCandidate . "'" : '')
                    . ($siteId !== '' ? ", PROPERTY_199='" . $siteId . "'" : '')
                    . ($siteName !== '' ? ", siteName='" . $siteName . "'" : '')
                    . ($callerPhone !== '' ? ", callerphone='" . $callerPhone . "'" : '')
                    . ".";
            }
        }
    }

    // Убираем 'callerphone=...' из имени файла и причины (оставляем только в деталях)
    $cleanFileName = preg_replace('/;\s*callerphone=\S+/i', '', $fileName);
    $cleanReason = preg_replace('/;\s*callerphone=\S+/i', '', $reason);

    // Формируем сообщение
    $message = "🚨 Ошибка по calltouch\n\n";
    $message .= "📁 Файл: $cleanFileName\n";
    $message .= "❌ Причина: $cleanReason" . $diagnostic . "\n";
    $message .= "⏰ Время: " . date('d.m.Y H:i:s') . "\n\n";
    $queueLink = 'https://bitrix.dreamteamcompany.ru/bitrix/admin/fileman_admin.php?PAGEN_1=1&SIZEN_1=20&lang=ru&site=s1&path=%2Flocal%2Fhandlers%2Fcalltouch%2Fqueue_errors&show_perms_for=0&fu_action=';
    $message .= "Требуется ручная обработка файла из папки [URL=" . $queueLink . "]queue_errors[/URL].";

    // Формируем абсолютный базовый URL хоста по конфигу портала (надежнее, чем HTTP_HOST)
    $portalWebhooks = $chatNotifications['portal_webhooks'] ?? [];
    $portalUrl = $portalWebhooks['dreamteamcompany'] ?? '';
    $baseHost = '';
    if ($portalUrl !== '') {
        $pu = parse_url($portalUrl);
        if (!empty($pu['scheme']) && !empty($pu['host'])) {
            $baseHost = $pu['scheme'] . '://' . $pu['host'];
        }
    }
    if ($baseHost === '') {
        $baseHost = 'https://bitrix.dreamteamcompany.ru';
    }

    // Кнопка 1: добавить элемент в список 54 (NAME + PROPERTY_199)
    if ($nameCandidate !== '' && $siteId !== '') {
        $addUrl = $baseHost . '/local/handlers/calltouch/add_iblock54.php?name='
               . rawurlencode($nameCandidate) . '&siteId=' . rawurlencode($siteId)
               . ($siteName !== '' ? ('&siteName=' . rawurlencode($siteName)) : '');
        $message .= "\n[URL=" . $addUrl . "]Добавить элемент в список 54[/URL]";
        // Кнопка: Добавить в исключения (PROPERTY_379 = нет (133))
        $excludeUrl = $addUrl . '&exclude=1';
        $message .= "\n[URL=" . $excludeUrl . "]Добавить в исключения[/URL]";
    }

    // Кнопка 2: вернуть файл в очередь и запустить обработку
    $rqUrl = $baseHost . '/local/handlers/calltouch/requeue_and_process.php?file=' . rawurlencode($fileName);
    $message .= "\n[URL=" . $rqUrl . "]Обработать файл очереди[/URL]";
    
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
