<?php
/**
 * chat_notifications.php
 * Функции для отправки уведомлений в чаты Битрикс24 через нативный API
 */

require_once(__DIR__ . '/bitrix_init.php');

// Логирование: используем общий logMessage из процессора, а при его отсутствии — минимальный фолбэк
if (!function_exists('logMessage')) {
    function logMessage($message, $logFile, $config = []) {
        $file = $logFile ?? ($config['global_log'] ?? 'calltouch_common.log');
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents($file, "[$timestamp] $message\n", FILE_APPEND);
    }
}

/**
 * Отправляет сообщение в чат Битрикс24 через нативный API
 * 
 * @param string $chatId ID чата
 * @param string $message Текст сообщения
 * @param array $config Конфигурация
 * @return bool Успешность отправки
 */
function sendChatMessageDirect($chatId, $message, $config) {
    try {
        CModule::IncludeModule("im");
        
        $arFields = [
            'DIALOG_ID' => $chatId,
            'MESSAGE' => $message,
            'SYSTEM' => 'Y', // Системное сообщение
        ];
        
        $messageId = CIMMessenger::Add($arFields);
        
        if ($messageId) {
            logMessage("sendChatMessageDirect: сообщение успешно отправлено в чат ID: $chatId", $config['global_log'] ?? 'calltouch_common.log', $config);
            return true;
        } else {
            // Получаем ошибку через глобальный объект APPLICATION
            $errorMsg = 'Неизвестная ошибка';
            if (isset($GLOBALS['APPLICATION']) && is_object($GLOBALS['APPLICATION'])) {
                $error = $GLOBALS['APPLICATION']->GetException();
                if ($error && is_object($error)) {
                    $errorMsg = $error->GetString();
                }
            }
            logMessage("sendChatMessageDirect: ошибка отправки сообщения в чат ID: $chatId, ошибка: $errorMsg", $config['global_log'] ?? 'calltouch_common.log', $config);
            return false;
        }
        
    } catch (Exception $e) {
        logMessage("sendChatMessageDirect: исключение при отправке сообщения в чат: " . $e->getMessage(), $config['global_log'] ?? 'calltouch_common.log', $config);
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
    
    // Попробуем дополнить причину деталями о ненайденном элементе из файла ошибок
    $diagnostic = '';
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

    // Убираем 'callerphone=...' из имени файла и причины
    $cleanFileName = preg_replace('/;\s*callerphone=\S+/i', '', $fileName);
    $cleanReason = preg_replace('/;\s*callerphone=\S+/i', '', $reason);

    // Формируем сообщение
    $message = "🚨 Ошибка по calltouch\n\n";
    $message .= "📁 Файл: $cleanFileName\n";
    $message .= "❌ Причина: $cleanReason" . $diagnostic . "\n";
    $message .= "⏰ Время: " . date('d.m.Y H:i:s') . "\n\n";
    $queueLink = 'https://bitrix.dreamteamcompany.ru/bitrix/admin/fileman_admin.php?PAGEN_1=1&SIZEN_1=20&lang=ru&site=s1&path=%2Flocal%2Fhandlers%2Fcalltouch%2Fcalltouch_native%2Fqueue_errors&show_perms_for=0&fu_action=';
    $message .= "Требуется ручная обработка файла из папки [URL=" . $queueLink . "]queue_errors[/URL].";

    // Формируем базовый URL портала
    $baseHost = 'https://bitrix.dreamteamcompany.ru';

    // Кнопка 1: добавить элемент в список 54 (NAME + PROPERTY_199)
    if ($nameCandidate !== '' && $siteId !== '') {
        $addUrl = $baseHost . '/local/handlers/calltouch/calltouch_native/add_iblock54.php?name='
               . rawurlencode($nameCandidate) . '&siteId=' . rawurlencode($siteId)
               . ($siteName !== '' ? ('&siteName=' . rawurlencode($siteName)) : '');
        $message .= "\n[URL=" . $addUrl . "]Добавить элемент в список 54[/URL]";
        // Кнопка: Добавить в исключения (PROPERTY_379 = нет (133))
        $excludeUrl = $addUrl . '&exclude=1';
        $message .= "\n[URL=" . $excludeUrl . "]Добавить в исключения[/URL]";
    }

    // Кнопка 2: вернуть файл в очередь и запустить обработку
    $rqUrl = $baseHost . '/local/handlers/calltouch/calltouch_native/requeue_and_process.php?file=' . rawurlencode($fileName);
    $message .= "\n[URL=" . $rqUrl . "]Обработать файл очереди[/URL]";
    
    // Отправляем сообщение
    return sendChatMessageDirect($chatId, $message, $config);
}

/**
 * Отправляет код доступа к веб-интерфейсу управления очередью в чат
 * 
 * @param string $accessKey Код доступа
 * @param array $config Конфигурация
 * @return bool Успешность отправки
 */
function sendAccessKeyNotification($accessKey, $config) {
    // Получаем ID чата из конфигурации
    $chatNotifications = $config['chat_notifications'] ?? [];
    $chatId = $chatNotifications['error_chat_id'] ?? null;
    
    if (!$chatId) {
        logMessage("ID чата 'Ошибки по рекламе' не настроен в конфигурации (chat_notifications.error_chat_id)", $config['global_log'] ?? 'calltouch_common.log', $config);
        return false;
    }
    
    // Формируем базовый URL портала
    $baseHost = 'https://bitrix.dreamteamcompany.ru';
    $queueManagerUrl = $baseHost . '/local/handlers/calltouch/calltouch_native/queue_manager.php?key=' . urlencode($accessKey);
    
    // Формируем сообщение
    $message = "🔑 Код доступа к интерфейсу управления очередью CallTouch\n\n";
    $message .= "📋 Код доступа: " . $accessKey . "\n\n";
    $message .= "🔗 [URL=" . $queueManagerUrl . "]Открыть интерфейс управления очередью[/URL]\n\n";
    $message .= "⏰ Код действителен до конца дня. Обновляется ежедневно.\n\n";
    $message .= "💡 Сохраните этот код для доступа к интерфейсу.";
    
    // Логируем попытку отправки
    logMessage("sendAccessKeyNotification: отправка кода доступа в чат ID: $chatId", $config['global_log'] ?? 'calltouch_common.log', $config);
    
    // Отправляем сообщение
    $result = sendChatMessageDirect($chatId, $message, $config);
    
    if ($result) {
        logMessage("sendAccessKeyNotification: код доступа успешно отправлен в чат", $config['global_log'] ?? 'calltouch_common.log', $config);
    } else {
        logMessage("sendAccessKeyNotification: ошибка отправки кода доступа в чат", $config['global_log'] ?? 'calltouch_common.log', $config);
    }
    
    return $result;
}

