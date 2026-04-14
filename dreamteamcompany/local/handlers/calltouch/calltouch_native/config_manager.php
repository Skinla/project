<?php
/**
 * Веб-интерфейс для управления конфигурацией CallTouch
 * Доступ: /local/handlers/calltouch/calltouch_native/config_manager.php
 */

// Запускаем сессию для авторизации
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Загружаем конфигурацию ПЕРЕД инициализацией Bitrix
$configPath = __DIR__ . '/calltouch_config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) { $config = []; }

// Для веб-интерфейса инициализируем Bitrix только при необходимости
$bitrixInitialized = false;

// Генерируем ключ для доступа (тот же, что и в queue_manager)
$accessKey = md5('calltouch_queue_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));

// Файл для хранения даты последней отправки ключа
$accessKeySentFile = __DIR__ . '/access_key_sent.json';

/**
 * Проверяет, была ли отправка ключа сегодня
 */
function wasAccessKeySentToday($sentFile) {
    if (!file_exists($sentFile)) {
        return false;
    }
    $data = @json_decode(@file_get_contents($sentFile), true);
    if (!is_array($data) || empty($data['date'])) {
        return false;
    }
    $lastSentDate = $data['date'];
    $today = date('Y-m-d');
    return ($lastSentDate === $today);
}

/**
 * Сохраняет дату отправки ключа
 */
function markAccessKeyAsSent($sentFile) {
    $data = [
        'date' => date('Y-m-d'),
        'timestamp' => time()
    ];
    $dir = dirname($sentFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents($sentFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Простая проверка авторизации
$isAuthorized = false;
$shouldSendKey = false;

if (isset($_GET['key'])) {
    $expectedKey = md5('calltouch_queue_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));
    $isAuthorized = ($_GET['key'] === $expectedKey);
    if (!$isAuthorized && !wasAccessKeySentToday($accessKeySentFile)) {
        $shouldSendKey = true;
    }
} elseif (isset($_SESSION['calltouch_authorized'])) {
    $isAuthorized = true;
} else {
    if (!wasAccessKeySentToday($accessKeySentFile)) {
        $shouldSendKey = true;
    }
}

// Отправляем ключ доступа в чат при необходимости
if ($shouldSendKey && !$bitrixInitialized) {
    require_once(__DIR__ . '/bitrix_init.php');
    require_once(__DIR__ . '/chat_notifications.php');
    $bitrixInitialized = true;
    $sent = @sendAccessKeyNotification($accessKey, $config);
    if ($sent) {
        markAccessKeyAsSent($accessKeySentFile);
    }
}

// Обработка сохранения конфигурации
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthorized && isset($_POST['save_config'])) {
    // Собираем новую конфигурацию из POST данных
    $newConfig = [];
    
    // Настройки лида
    $newConfig['lead'] = [
        'STATUS_ID' => $_POST['lead_status_id'] ?? 'NEW',
        'TITLE' => $_POST['lead_title'] ?? 'Звонок с сайта {url}',
    ];
    
    // Папки и логи
    $newConfig['logs_dir'] = $_POST['logs_dir'] ?? __DIR__ . '/calltouch_logs';
    $newConfig['errors_dir'] = $_POST['errors_dir'] ?? __DIR__ . '/queue_errors';
    $newConfig['global_log'] = $_POST['global_log'] ?? 'calltouch_common.log';
    $newConfig['max_log_size'] = (int)($_POST['max_log_size'] ?? 2 * 1024 * 1024);
    $newConfig['use_lead_direct'] = isset($_POST['use_lead_direct']);
    
    // Обрабатываемые события
    $allowedPhases = [];
    if (isset($_POST['allowed_callphases']) && is_array($_POST['allowed_callphases'])) {
        $allowedPhases = array_map('trim', $_POST['allowed_callphases']);
        $allowedPhases = array_filter($allowedPhases);
    }
    if (empty($allowedPhases)) {
        $allowedPhases = ['callcompleted'];
    }
    $newConfig['allowed_callphases'] = array_values($allowedPhases);
    
    // Дедупликация
    $newConfig['deduplication'] = [
        'enabled' => isset($_POST['deduplication_enabled']),
        'title_keywords' => [],
        'period' => $_POST['deduplication_period'] ?? '30d',
    ];
    if (isset($_POST['deduplication_keywords']) && !empty(trim($_POST['deduplication_keywords']))) {
        $keywords = explode("\n", $_POST['deduplication_keywords']);
        $keywords = array_map('trim', $keywords);
        $keywords = array_filter($keywords);
        $newConfig['deduplication']['title_keywords'] = array_values($keywords);
    }
    
    // ctCallerId
    $newConfig['ctCallerId'] = [
        'enabled' => isset($_POST['ctcallerid_enabled']),
        'retention' => $_POST['ctcallerid_retention'] ?? '30m',
        'index_file' => $_POST['ctcallerid_index_file'] ?? __DIR__ . '/ctcallerid_index.json',
    ];
    
    // Обработка ошибок
    $newConfig['error_handling'] = [
        'move_to_errors' => isset($_POST['error_move_to_errors']),
        'max_error_files' => (int)($_POST['error_max_files'] ?? 1000),
        'cleanup_old_errors' => isset($_POST['error_cleanup']),
        'error_retention_days' => (int)($_POST['error_retention_days'] ?? 30),
        'log_api_errors' => isset($_POST['error_log_api']),
        'send_chat_notifications' => isset($_POST['error_send_chat']),
    ];
    
    // Уведомления в чат
    $newConfig['chat_notifications'] = [
        'error_chat_id' => $_POST['chat_error_chat_id'] ?? 'chat69697',
    ];
    
    // Инфоблоки
    $newConfig['iblock'] = [
        'iblock_54_id' => (int)($_POST['iblock_54_id'] ?? 54),
        'iblock_19_id' => (int)($_POST['iblock_19_id'] ?? 19),
        'iblock_22_id' => (int)($_POST['iblock_22_id'] ?? 22),
        'iblock_type_id' => $_POST['iblock_type_id'] ?? 'lists_socnet',
        'socnet_group_id' => (int)($_POST['socnet_group_id'] ?? 1),
    ];
    
    // Сохраняем конфигурацию
    $configContent = "<?php\n";
    $configContent .= "/**\n";
    $configContent .= " * Конфигурация системы интеграции CallTouch с Bitrix24\n";
    $configContent .= " * Версия с нативным API (без вебхуков)\n";
    $configContent .= " * Автоматически сгенерировано: " . date('Y-m-d H:i:s') . "\n";
    $configContent .= " */\n";
    $configContent .= "return " . var_export($newConfig, true) . ";\n";
    
    // Создаем резервную копию
    $backupPath = $configPath . '.backup.' . date('YmdHis');
    if (file_exists($configPath)) {
        @copy($configPath, $backupPath);
    }
    
    // Сохраняем новый конфиг
    if (@file_put_contents($configPath, $configContent, LOCK_EX)) {
        $message = 'Конфигурация успешно сохранена! Резервная копия: ' . basename($backupPath);
        $messageType = 'success';
        $config = $newConfig; // Обновляем текущий конфиг
    } else {
        $message = 'Ошибка при сохранении конфигурации. Проверьте права доступа к файлу.';
        $messageType = 'error';
    }
    
    $_SESSION['calltouch_authorized'] = true;
}

// Если авторизованы, сохраняем в сессию
if ($isAuthorized) {
    $_SESSION['calltouch_authorized'] = true;
}

// Получаем текущие значения для формы
$leadConfig = $config['lead'] ?? [];
$dedupConfig = $config['deduplication'] ?? [];
$ctCallerIdConfig = $config['ctCallerId'] ?? [];
$errorConfig = $config['error_handling'] ?? [];
$chatConfig = $config['chat_notifications'] ?? [];
$iblockConfig = $config['iblock'] ?? [];

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Настройки конфигурации CallTouch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
        }
        .nav-links {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }
        .nav-links a {
            color: #0066cc;
            text-decoration: none;
            margin-right: 15px;
        }
        .nav-links a:hover {
            text-decoration: underline;
        }
        .access-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .message.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .message.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .form-section {
            margin-bottom: 30px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        .form-section h2 {
            color: #333;
            margin-bottom: 15px;
            font-size: 1.3em;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }
        .form-group .help-text {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
            font-style: italic;
        }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group textarea {
            min-height: 80px;
            font-family: monospace;
        }
        .form-group input[type="checkbox"] {
            margin-right: 8px;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .checkbox-group label {
            font-weight: normal;
            margin-bottom: 0;
        }
        .phase-checkbox {
            display: inline-block;
            margin-right: 15px;
            margin-bottom: 10px;
        }
        .phase-checkbox input[type="checkbox"] {
            margin-right: 5px;
        }
        .submit-btn {
            background: #28a745;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 20px;
        }
        .submit-btn:hover {
            background: #218838;
        }
        .submit-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Настройки конфигурации CallTouch</h1>
        
        <div class="nav-links">
            <a href="queue_manager.php?key=<?php echo htmlspecialchars($accessKey); ?>">← Управление очередью</a>
        </div>
        
        <?php if (!$isAuthorized): ?>
            <div class="access-info">
                <p><strong>🔒 Доступ запрещен</strong></p>
                <p>Для доступа к интерфейсу требуется код доступа.</p>
                <p style="margin-top: 10px; font-size: 0.9em; color: #666;">
                    Код доступа отправляется в чат "Ошибки по рекламе" при первом обращении или при смене ключа.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType === 'error' ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($isAuthorized): ?>
            <form method="POST" action="">
                <input type="hidden" name="save_config" value="1">
                
                <!-- Настройки лида -->
                <div class="form-section">
                    <h2>📋 Настройки лида</h2>
                    
                    <div class="form-group">
                        <label>Статус лида по умолчанию (STATUS_ID)</label>
                        <input type="text" name="lead_status_id" value="<?php echo htmlspecialchars($leadConfig['STATUS_ID'] ?? 'NEW'); ?>" required>
                        <div class="help-text">Статус, который будет присвоен новому лиду при создании (например: NEW, IN_PROCESS, CONVERTED)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Шаблон названия лида (TITLE)</label>
                        <input type="text" name="lead_title" value="<?php echo htmlspecialchars($leadConfig['TITLE'] ?? 'Звонок с сайта {url}'); ?>" required>
                        <div class="help-text">Шаблон названия лида. {url} будет заменен на URL источника звонка</div>
                    </div>
                </div>
                
                <!-- Папки и логи -->
                <div class="form-section">
                    <h2>📁 Папки и логи</h2>
                    
                    <div class="form-group">
                        <label>Папка для логов (logs_dir)</label>
                        <input type="text" name="logs_dir" value="<?php echo htmlspecialchars($config['logs_dir'] ?? __DIR__ . '/calltouch_logs'); ?>" required>
                        <div class="help-text">Абсолютный путь к папке, где хранятся лог-файлы</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Папка для ошибок (errors_dir)</label>
                        <input type="text" name="errors_dir" value="<?php echo htmlspecialchars($config['errors_dir'] ?? __DIR__ . '/queue_errors'); ?>" required>
                        <div class="help-text">Абсолютный путь к папке, куда перемещаются файлы с ошибками</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Основной лог-файл (global_log)</label>
                        <input type="text" name="global_log" value="<?php echo htmlspecialchars($config['global_log'] ?? 'calltouch_common.log'); ?>" required>
                        <div class="help-text">Имя основного лог-файла (относительно logs_dir)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Максимальный размер лога (max_log_size, байты)</label>
                        <input type="number" name="max_log_size" value="<?php echo htmlspecialchars($config['max_log_size'] ?? 2097152); ?>" required>
                        <div class="help-text">Максимальный размер лог-файла в байтах (по умолчанию 2 МБ = 2097152)</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="use_lead_direct" id="use_lead_direct" <?php echo ($config['use_lead_direct'] ?? true) ? 'checked' : ''; ?>>
                            <label for="use_lead_direct">Использовать логику lead_direct (получение данных из инфоблока 54)</label>
                        </div>
                        <div class="help-text">Если включено, данные для лида берутся из инфоблока 54 по паре NAME + PROPERTY_199</div>
                    </div>
                </div>
                
                <!-- Обрабатываемые события -->
                <div class="form-section">
                    <h2>📞 Обрабатываемые события CallTouch</h2>
                    
                    <div class="form-group">
                        <label>Выберите события для обработки (callphase):</label>
                        <div>
                            <div class="phase-checkbox">
                                <input type="checkbox" name="allowed_callphases[]" value="callconnected" id="phase_connected" <?php echo in_array('callconnected', $config['allowed_callphases'] ?? []) ? 'checked' : ''; ?>>
                                <label for="phase_connected">callconnected (звонок подключен)</label>
                            </div>
                            <div class="phase-checkbox">
                                <input type="checkbox" name="allowed_callphases[]" value="callcompleted" id="phase_completed" <?php echo in_array('callcompleted', $config['allowed_callphases'] ?? ['callcompleted']) ? 'checked' : ''; ?>>
                                <label for="phase_completed">callcompleted (звонок завершен) ⭐</label>
                            </div>
                            <div class="phase-checkbox">
                                <input type="checkbox" name="allowed_callphases[]" value="calldisconnected" id="phase_disconnected" <?php echo in_array('calldisconnected', $config['allowed_callphases'] ?? []) ? 'checked' : ''; ?>>
                                <label for="phase_disconnected">calldisconnected (звонок отключен)</label>
                            </div>
                            <div class="phase-checkbox">
                                <input type="checkbox" name="allowed_callphases[]" value="calllost" id="phase_lost" <?php echo in_array('calllost', $config['allowed_callphases'] ?? []) ? 'checked' : ''; ?>>
                                <label for="phase_lost">calllost (звонок потерян)</label>
                            </div>
                        </div>
                        <div class="help-text">Выберите события CallTouch, которые должны обрабатываться. Рекомендуется обрабатывать только "callcompleted" (завершение звонка)</div>
                    </div>
                </div>
                
                <!-- Дедупликация -->
                <div class="form-section">
                    <h2>🔍 Дедупликация лидов</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="deduplication_enabled" id="deduplication_enabled" <?php echo ($dedupConfig['enabled'] ?? true) ? 'checked' : ''; ?>>
                            <label for="deduplication_enabled">Включить поиск дублей по телефону</label>
                        </div>
                        <div class="help-text">
                            Если включено, система будет искать существующие лиды по телефону и обновлять их вместо создания новых.<br>
                            <strong>Механизм поиска:</strong> система ищет лиды, у которых телефон совпадает И заголовок содержит одно из ключевых слов (см. ниже).
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Ключевые слова для поиска дублей (каждое с новой строки)</label>
                        <textarea name="deduplication_keywords"><?php echo htmlspecialchars(implode("\n", $dedupConfig['title_keywords'] ?? [])); ?></textarea>
                        <div class="help-text">
                            <strong>Поиск идет по паре: номер телефона + ключевое слово в заголовке.</strong><br>
                            Система ищет лиды, у которых:<br>
                            • Телефон совпадает с телефоном из запроса<br>
                            • И заголовок содержит одно из указанных ключевых слов<br><br>
                            Ключевые слова проверяются по очереди. Если найдено совпадение по одному слову - лид считается дублем.<br>
                            Каждое ключевое слово указывается с новой строки.
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Период поиска дублей (period)</label>
                        <select name="deduplication_period">
                            <option value="30m" <?php echo ($dedupConfig['period'] ?? '30d') === '30m' ? 'selected' : ''; ?>>30 минут</option>
                            <option value="1d" <?php echo ($dedupConfig['period'] ?? '30d') === '1d' ? 'selected' : ''; ?>>1 день</option>
                            <option value="30d" <?php echo ($dedupConfig['period'] ?? '30d') === '30d' ? 'selected' : ''; ?>>30 дней</option>
                            <option value="1m" <?php echo ($dedupConfig['period'] ?? '30d') === '1m' ? 'selected' : ''; ?>>1 месяц</option>
                            <option value="3m" <?php echo ($dedupConfig['period'] ?? '30d') === '3m' ? 'selected' : ''; ?>>3 месяца</option>
                            <option value="ytd" <?php echo ($dedupConfig['period'] ?? '30d') === 'ytd' ? 'selected' : ''; ?>>С начала года</option>
                            <option value="all" <?php echo ($dedupConfig['period'] ?? '30d') === 'all' ? 'selected' : ''; ?>>За весь период</option>
                        </select>
                        <div class="help-text">Период, за который искать дубли (относительно текущего времени)</div>
                    </div>
                </div>
                
                <!-- ctCallerId -->
                <div class="form-section">
                    <h2>🆔 Обработка по ctCallerId</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="ctcallerid_enabled" id="ctcallerid_enabled" <?php echo ($ctCallerIdConfig['enabled'] ?? true) ? 'checked' : ''; ?>>
                            <label for="ctcallerid_enabled">Включить обработку по ctCallerId</label>
                        </div>
                        <div class="help-text">Если включено, при обнаружении ctCallerId в индексе обработка пропускается (лид уже существует)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Время хранения записей (retention)</label>
                        <select name="ctcallerid_retention">
                            <option value="30m" <?php echo ($ctCallerIdConfig['retention'] ?? '30m') === '30m' ? 'selected' : ''; ?>>30 минут</option>
                            <option value="1d" <?php echo ($ctCallerIdConfig['retention'] ?? '30m') === '1d' ? 'selected' : ''; ?>>1 день</option>
                            <option value="7d" <?php echo ($ctCallerIdConfig['retention'] ?? '30m') === '7d' ? 'selected' : ''; ?>>7 дней</option>
                        </select>
                        <div class="help-text">Время хранения записи сопоставления ctCallerId → leadId в индексе</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Путь к файлу индекса (index_file)</label>
                        <input type="text" name="ctcallerid_index_file" value="<?php echo htmlspecialchars($ctCallerIdConfig['index_file'] ?? __DIR__ . '/ctcallerid_index.json'); ?>" required>
                        <div class="help-text">Абсолютный путь к JSON-файлу с индексом сопоставлений</div>
                    </div>
                </div>
                
                <!-- Обработка ошибок -->
                <div class="form-section">
                    <h2>⚠️ Обработка ошибок</h2>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="error_move_to_errors" id="error_move_to_errors" <?php echo ($errorConfig['move_to_errors'] ?? true) ? 'checked' : ''; ?>>
                            <label for="error_move_to_errors">Перемещать файлы с ошибками в отдельную папку</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Максимальное количество файлов ошибок</label>
                        <input type="number" name="error_max_files" value="<?php echo htmlspecialchars($errorConfig['max_error_files'] ?? 1000); ?>" required>
                        <div class="help-text">Максимальное количество файлов, которые могут храниться в папке ошибок</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="error_cleanup" id="error_cleanup" <?php echo ($errorConfig['cleanup_old_errors'] ?? true) ? 'checked' : ''; ?>>
                            <label for="error_cleanup">Очищать старые файлы ошибок</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Хранить файлы ошибок (дней)</label>
                        <input type="number" name="error_retention_days" value="<?php echo htmlspecialchars($errorConfig['error_retention_days'] ?? 30); ?>" required>
                        <div class="help-text">Количество дней, в течение которых хранятся файлы ошибок</div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="error_log_api" id="error_log_api" <?php echo ($errorConfig['log_api_errors'] ?? true) ? 'checked' : ''; ?>>
                            <label for="error_log_api">Записывать ошибки API в bitrix_api_error.log</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="error_send_chat" id="error_send_chat" <?php echo ($errorConfig['send_chat_notifications'] ?? true) ? 'checked' : ''; ?>>
                            <label for="error_send_chat">Отправлять уведомления об ошибках в чат</label>
                        </div>
                    </div>
                </div>
                
                <!-- Уведомления в чат -->
                <div class="form-section">
                    <h2>💬 Уведомления в чат</h2>
                    
                    <div class="form-group">
                        <label>ID чата для уведомлений об ошибках (error_chat_id)</label>
                        <input type="text" name="chat_error_chat_id" value="<?php echo htmlspecialchars($chatConfig['error_chat_id'] ?? 'chat69697'); ?>" required>
                        <div class="help-text">ID чата Bitrix24, куда отправляются уведомления об ошибках (например: chat69697)</div>
                    </div>
                </div>
                
                <!-- Инфоблоки -->
                <div class="form-section">
                    <h2>🗂️ Настройки инфоблоков</h2>
                    
                    <div class="form-group">
                        <label>ID инфоблока с конфигурацией источников (iblock_54_id)</label>
                        <input type="number" name="iblock_54_id" value="<?php echo htmlspecialchars($iblockConfig['iblock_54_id'] ?? 54); ?>" required>
                        <div class="help-text">ID инфоблока 54, где хранится конфигурация источников (пара NAME + PROPERTY_199)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>ID списка источников (iblock_19_id)</label>
                        <input type="number" name="iblock_19_id" value="<?php echo htmlspecialchars($iblockConfig['iblock_19_id'] ?? 19); ?>" required>
                        <div class="help-text">ID списка 19, где хранятся источники (SOURCE_ID)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>ID списка городов (iblock_22_id)</label>
                        <input type="number" name="iblock_22_id" value="<?php echo htmlspecialchars($iblockConfig['iblock_22_id'] ?? 22); ?>" required>
                        <div class="help-text">ID списка 22, где хранятся города (ASSIGNED_BY_ID)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Тип инфоблока для списков (iblock_type_id)</label>
                        <input type="text" name="iblock_type_id" value="<?php echo htmlspecialchars($iblockConfig['iblock_type_id'] ?? 'lists_socnet'); ?>" required>
                        <div class="help-text">Тип инфоблока для списков (обычно: lists_socnet)</div>
                    </div>
                    
                    <div class="form-group">
                        <label>ID рабочей группы (socnet_group_id)</label>
                        <input type="number" name="socnet_group_id" value="<?php echo htmlspecialchars($iblockConfig['socnet_group_id'] ?? 1); ?>" required>
                        <div class="help-text">ID рабочей группы Bitrix24, к которой привязаны списки</div>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn">💾 Сохранить конфигурацию</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

