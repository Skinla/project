<?php
/**
 * Обработчик события отправки системного WZ сообщения в чат с защитой от дублей
 * 
 * ИСПРАВЛЕНИЯ ДЛЯ ПРОДАКШЕНА:
 * 1. Добавлена проверка успешности создания директории кэша
 * 2. Использование чтения содержимого файла вместо filemtime() для надежности
 * 3. Неблокирующая блокировка с таймаутом (максимум 0.5 сек ожидания)
 * 4. Валидация leadId для безопасности имени файла
 * 5. Проверка успешности записи в файл блокировки
 * 6. Обработка исключений при работе с блокировкой
 * 7. Логирование всех ошибок работы с кэшем
 * 8. Fallback: при ошибках кэша БП запускается (лучше отправить, чем пропустить)
 */
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

// Подключаем обработчик события отправлено системное WZ сообщение в чат
AddEventHandler('im', 'OnAfterMessagesAdd', function($messageId, $messageFields) {
    try {
        if (!Loader::includeModule('im') || !Loader::includeModule('crm')) {
            return;
        }

        $text = trim((string)($messageFields['MESSAGE'] ?? ''));
        if ($text === '') return;

        // Ищем маркер ошибки
        if (!preg_match('/(^|\s)===[\s\S]*SYSTEM WZ[\s\S]*===/i', $text)) {
            return;
        }

        // Нормализуем переносы/пробелы, чтобы запись читалась в журнале
        $safeText = str_replace(["\r\n", "\r", "\n"], ' \\n ', $text);
        // На всякий — ограничим длину DESCRIPTION (журнал хранит ограниченно)
        if (function_exists('mb_substr')) {
            $safeText = mb_substr($safeText, 0, 1900);
        } else {
            $safeText = substr($safeText, 0, 1900);
        }

        // Составляем описание события
        $desc = sprintf(
            "MATCHED ERROR | ID=%s | CHAT=%s | AUTHOR=%s | TEXT=%s",
            (string)$messageId,
            (string)($messageFields['CHAT_ID'] ?? ''),
            (string)($messageFields['AUTHOR_ID'] ?? ''),
            $safeText
        );

        // Пишем в Журнал событий (Админка → Настройки → Журнал событий)
        \CEventLog::Add([
            'SEVERITY'      => 'INFO',                 // INFO | WARNING | ERROR
            'AUDIT_TYPE_ID' => 'WZ_SYSTEM_MARKER',     // ваш кастомный тип
            'MODULE_ID'     => 'im',                   // модуль-источник
            'ITEM_ID'       => (string)$messageId,     // ID сущности (сообщение)
            'DESCRIPTION'   => $desc,                  // текст события
            'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
            // 'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? null, // опционально
            // 'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null, // опционально
        ]);

        try {
            $leadId = 0;
            $chatId = (int)($messageFields['CHAT_ID'] ?? 0);

            // 1) Находим лид по чату открытых линий (старые версии: только поле CRM)
            if ($chatId > 0 && \Bitrix\Main\Loader::includeModule('imopenlines')) {
                $session = null;

                // Пытаемся через ORM (если поле есть)

                $session = \Bitrix\ImOpenLines\Model\SessionTable::getList([
                    'select' => ['ID', 'CHAT_ID', 'CRM_ACTIVITY_ID', 'CRM'],
                    'filter' => ['=CHAT_ID' => $chatId],
                    'order'  => ['ID' => 'DESC'],
                    'limit'  => 1,
                ])->fetch();


                if ($session) {
                    $sessionId  = (int)$session['ID'];
                    $activityId = (int)($session['CRM_ACTIVITY_ID'] ?? 0);

                    // 1) Основной путь: по CRM_ACTIVITY_ID → биндинги → LEAD
                    if ($activityId > 0 && \Bitrix\Main\Loader::includeModule('crm')) {
                        $bindings = \Bitrix\Crm\ActivityBindingTable::getList([
                            'select' => ['OWNER_ID', 'OWNER_TYPE_ID'],
                            'filter' => ['=ACTIVITY_ID' => $activityId],
                        ])->fetchAll();

                        foreach ($bindings as $b) {
                            if ((int)$b['OWNER_TYPE_ID'] === \CCrmOwnerType::Lead) {
                                $leadId = (int)$b['OWNER_ID'];
                                break;
                            }
                        }
                    }

                    // 2) Стартуем БП, если нашли лид
                    if ($leadId > 0 && \Bitrix\Main\Loader::includeModule('bizproc')) {
                        // Проверка защиты от дублей: один БП на лид в течение 10 секунд
                        // Используем реальный путь для безопасности
                        $scriptDir = defined('__DIR__') ? __DIR__ : dirname(__FILE__);
                        $cacheDir = rtrim($scriptDir, '/\\') . '/cache';
                        $shouldRun = false; // Инициализируем переменную
                        
                        // Проверяем и создаем директорию с проверкой успешности
                        $cacheDirOk = true;
                        if (!is_dir($cacheDir)) {
                            if (!@mkdir($cacheDir, 0755, true) || !is_dir($cacheDir)) {
                                // Не удалось создать директорию - логируем и запускаем БП (лучше отправить)
                                \CEventLog::Add([
                                    'SEVERITY'      => 'WARNING',
                                    'AUDIT_TYPE_ID' => 'WZ_CACHE_DIR_ERROR',
                                    'MODULE_ID'     => 'im',
                                    'ITEM_ID'       => (string)$messageId,
                                    'DESCRIPTION'   => "Cannot create cache directory: {$cacheDir}",
                                    'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                ]);
                                $cacheDirOk = false;
                            }
                        }

                        if ($cacheDirOk) {
                            // Периодическая очистка старых файлов блокировки (старше 60 секунд)
                            // Защита работает 10 секунд, файлы старше 60 секунд точно не нужны
                            // Очищаем с вероятностью 20% чтобы не замедлять каждый запрос, но чаще чем раньше
                            if (mt_rand(1, 5) === 1) {
                                $cleanupTime = time() - 60; // 60 секунд назад (с запасом после 10 сек защиты)
                                $files = @glob($cacheDir . '/wz_bp_lead_*.lock');
                                if ($files !== false && is_array($files)) {
                                    $deletedCount = 0;
                                    $maxDeletePerRun = 100; // Ограничение: не более 100 файлов за раз
                                    foreach ($files as $file) {
                                        if ($deletedCount >= $maxDeletePerRun) {
                                            break; // Прекращаем удаление после лимита
                                        }
                                        if (@filemtime($file) < $cleanupTime) {
                                            @unlink($file);
                                            $deletedCount++;
                                        }
                                    }
                                }
                            }
                            
                            // Валидация leadId для безопасности имени файла (повторная проверка на всякий случай)
                            $leadId = (int)$leadId;
                            if ($leadId <= 0) {
                                // Некорректный leadId - не запускаем БП и логируем
                                \CEventLog::Add([
                                    'SEVERITY'      => 'WARNING',
                                    'AUDIT_TYPE_ID' => 'WZ_INVALID_LEAD_ID',
                                    'MODULE_ID'     => 'im',
                                    'ITEM_ID'       => (string)$messageId,
                                    'DESCRIPTION'   => "Invalid LEAD ID: {$leadId}; BP not started",
                                    'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                ]);
                                $shouldRun = false;
                            } else {
                                $lockFile = $cacheDir . '/wz_bp_lead_' . $leadId . '.lock';
                                $now = time();
                                $shouldRun = false;

                                // Открываем файл для чтения/записи с блокировкой
                                $fp = @fopen($lockFile, 'c+');
                                if ($fp === false) {
                                    // Если не удалось открыть файл - запускаем БП (лучше отправить, чем пропустить)
                                    \CEventLog::Add([
                                        'SEVERITY'      => 'WARNING',
                                        'AUDIT_TYPE_ID' => 'WZ_LOCK_FILE_ERROR',
                                        'MODULE_ID'     => 'im',
                                        'ITEM_ID'       => (string)$messageId,
                                        'DESCRIPTION'   => "Cannot open lock file: {$lockFile}",
                                        'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                    ]);
                                    $shouldRun = true;
                                } else {
                                    // Используем неблокирующую блокировку с таймаутом
                                    $lockAcquired = false;
                                    $attempts = 0;
                                    $maxAttempts = 5; // Максимум 5 попыток по 0.1 сек = 0.5 сек максимум
                                    
                                    while ($attempts < $maxAttempts && !$lockAcquired) {
                                        if (flock($fp, LOCK_EX | LOCK_NB)) {
                                            $lockAcquired = true;
                                        } else {
                                            usleep(100000); // 0.1 секунды
                                            $attempts++;
                                        }
                                    }

                                    if ($lockAcquired) {
                                        try {
                                            // Читаем содержимое файла для проверки времени
                                            rewind($fp);
                                            $fileContent = @stream_get_contents($fp);
                                            
                                            if ($fileContent === false || $fileContent === '') {
                                                // Файл пустой или ошибка чтения - можно запускать
                                                $shouldRun = true;
                                            } else {
                                                // Парсим timestamp из файла
                                                $fileTime = (int)trim($fileContent);
                                                
                                                if ($fileTime <= 0) {
                                                    // Некорректное значение - можно запускать
                                                    $shouldRun = true;
                                                } else {
                                                    // Проверяем разницу времени
                                                    $diff = $now - $fileTime;
                                                    
                                                    if ($diff >= 10) {
                                                        // Прошло 10+ секунд - можно запускать снова
                                                        $shouldRun = true;
                                                    }
                                                    // Если diff < 10 - пропускаем запуск
                                                }
                                            }

                                            if ($shouldRun) {
                                                // Обновляем время файла (создаем или обновляем)
                                                rewind($fp);
                                                ftruncate($fp, 0);
                                                $written = @fwrite($fp, (string)$now);
                                                if ($written !== false) {
                                                    @fflush($fp);
                                                } else {
                                                    // Ошибка записи - логируем, но продолжаем
                                                    \CEventLog::Add([
                                                        'SEVERITY'      => 'WARNING',
                                                        'AUDIT_TYPE_ID' => 'WZ_LOCK_WRITE_ERROR',
                                                        'MODULE_ID'     => 'im',
                                                        'ITEM_ID'       => (string)$messageId,
                                                        'DESCRIPTION'   => "Cannot write to lock file: {$lockFile}",
                                                        'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                                    ]);
                                                }
                                            }

                                            // Снимаем блокировку
                                            flock($fp, LOCK_UN);
                                        } catch (\Throwable $lockException) {
                                            // Ошибка при работе с блокировкой - разблокируем и запускаем БП
                                            @flock($fp, LOCK_UN);
                                            \CEventLog::Add([
                                                'SEVERITY'      => 'WARNING',
                                                'AUDIT_TYPE_ID' => 'WZ_LOCK_EXCEPTION',
                                                'MODULE_ID'     => 'im',
                                                'ITEM_ID'       => (string)$messageId,
                                                'DESCRIPTION'   => 'Lock exception: ' . $lockException->getMessage(),
                                                'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                            ]);
                                            $shouldRun = true;
                                        }
                                    } else {
                                        // Не удалось получить блокировку за отведенное время - запускаем БП
                                        \CEventLog::Add([
                                            'SEVERITY'      => 'WARNING',
                                            'AUDIT_TYPE_ID' => 'WZ_LOCK_TIMEOUT',
                                            'MODULE_ID'     => 'im',
                                            'ITEM_ID'       => (string)$messageId,
                                            'DESCRIPTION'   => "Lock timeout for file: {$lockFile}",
                                            'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                        ]);
                                        $shouldRun = true;
                                    }
                                    
                                    // Закрываем файл только если он был успешно открыт
                                    // $fp уже проверен на false выше, но на всякий случай проверяем еще раз
                                    if ($fp !== false) {
                                        @fclose($fp);
                                    }
                                }
                            }
                        } else {
                            // Директория не создана - запускаем БП без проверки блокировки
                            $shouldRun = true;
                        }

                        // Запускаем БП только если прошла проверка блокировки и leadId валиден
                        if ($shouldRun && $leadId > 0) {
                            $bpTemplateId = 446; // ваш шаблон БП из ссылки
                            $documentId   = ['crm', 'CCrmDocumentLead', 'LEAD_' . $leadId];

                            // Если в шаблоне есть входные параметры — раскомментируйте и передайте нужные:
                            // $wfParams = [
                            //     'WZ_MESSAGE' => $safeText,                 // пример: текст сообщения
                            //     'WZ_CHAT_ID' => $chatId,
                            //     'WZ_MSG_ID'  => (string)$messageId,
                            //     'WZ_AUTHOR'  => (int)($messageFields['AUTHOR_ID'] ?? 0),
                            // ];
                            $wfParams = [];

                            $errors = [];
                            \CBPDocument::StartWorkflow($bpTemplateId, $documentId, $wfParams, $errors);

                            if (!empty($errors)) {
                                \CEventLog::Add([
                                    'SEVERITY'      => 'ERROR',
                                    'AUDIT_TYPE_ID' => 'WZ_BP_START_ERROR',
                                    'MODULE_ID'     => 'im',
                                    'ITEM_ID'       => (string)$messageId,
                                    'DESCRIPTION'   => 'BP START ERR for LEAD_' . $leadId . ': ' . implode('; ', array_map(
                                            static fn($e) => (is_array($e) && isset($e['message'])) ? $e['message'] : (string)$e,
                                            $errors
                                        )),
                                    'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                ]);
                            } else {
                                \CEventLog::Add([
                                    'SEVERITY'      => 'INFO',
                                    'AUDIT_TYPE_ID' => 'WZ_BP_STARTED',
                                    'MODULE_ID'     => 'im',
                                    'ITEM_ID'       => (string)$messageId,
                                    'DESCRIPTION'   => "Started BP {$bpTemplateId} for LEAD_{$leadId}; CHAT={$chatId}",
                                    'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                                ]);
                            }
                        } else {
                            // БП не запущен из-за защиты от дублей
                            \CEventLog::Add([
                                'SEVERITY'      => 'INFO',
                                'AUDIT_TYPE_ID' => 'WZ_BP_SKIPPED',
                                'MODULE_ID'     => 'im',
                                'ITEM_ID'       => (string)$messageId,
                                'DESCRIPTION'   => "BP skipped for LEAD_{$leadId} (duplicate protection: last run < 10 sec ago); CHAT={$chatId}",
                                'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                            ]);
                        }
                    } else {
                        // Не нашли лид — залогируем для отладки
                        \CEventLog::Add([
                            'SEVERITY'      => 'WARNING',
                            'AUDIT_TYPE_ID' => 'WZ_NO_LEAD_FOR_CHAT',
                            'MODULE_ID'     => 'im',
                            'ITEM_ID'       => (string)$messageId,
                            'DESCRIPTION'   => "Cannot resolve LEAD for CHAT={$chatId}; BP not started",
                            'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
                        ]);
                    }
                }
            }
        } catch (\Throwable $e) {
            \CEventLog::Add([
                'SEVERITY'      => 'ERROR',
                'AUDIT_TYPE_ID' => 'WZ_BP_START_EXCEPTION',
                'MODULE_ID'     => 'im',
                'ITEM_ID'       => (string)$messageId,
                'DESCRIPTION'   => 'EXCEPTION: ' . $e->getMessage(),
                'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
            ]);
        }
    } catch (\Throwable $e) {
        // а) штатный логгер ошибок ядра
        Application::getInstance()->getExceptionHandler()->writeToLog($e, 0, 'wz_ol_error_handler');

        // б) дублируем в Журнал событий с другим AUDIT_TYPE_ID
        \CEventLog::Add([
            'SEVERITY'      => 'ERROR',
            'AUDIT_TYPE_ID' => 'WZ_HANDLER_EXCEPTION',
            'MODULE_ID'     => 'im',
            'ITEM_ID'       => (string)$messageId,
            'DESCRIPTION'   => 'EXCEPTION: ' . $e->getMessage(),
            'USER_ID'       => (int)($messageFields['AUTHOR_ID'] ?? 0),
        ]);
    }
});
