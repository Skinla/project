<?php
/**
 * Веб-интерфейс для управления очередью CallTouch
 * Доступ: /local/handlers/calltouch/calltouch_native/queue_manager.php
 */

// Запускаем сессию для авторизации
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Загружаем конфигурацию ПЕРЕД инициализацией Bitrix (нужно для отправки сообщений)
$configPath = __DIR__ . '/calltouch_config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) { $config = []; }

// Для веб-интерфейса инициализируем Bitrix только при необходимости (для отправки сообщений)
// Это позволяет избежать редиректа на страницу авторизации
$bitrixInitialized = false;

// Генерируем ключ для доступа
$accessKey = md5('calltouch_queue_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));

// Файл для хранения даты последней отправки ключа
$accessKeySentFile = __DIR__ . '/access_key_sent.json';

/**
 * Проверяет, была ли отправка ключа сегодня
 * @return bool true если отправлялось сегодня, false если нет
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
    
    // file_put_contents автоматически создаст файл, если его нет
    // Но для надежности убедимся, что директория существует
    $dir = dirname($sentFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    
    @file_put_contents($sentFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Простая проверка авторизации (можно улучшить)
$isAuthorized = false;
$shouldSendKey = false;

if (isset($_GET['key'])) {
    $expectedKey = md5('calltouch_queue_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));
    $isAuthorized = ($_GET['key'] === $expectedKey);
    
    // Если ключ неверный и еще не отправлялось сегодня - отправляем правильный ключ
    if (!$isAuthorized && !wasAccessKeySentToday($accessKeySentFile)) {
        $shouldSendKey = true;
    }
} elseif (isset($_SESSION['calltouch_authorized'])) {
    $isAuthorized = true;
} else {
    // Первое обращение без ключа - отправляем ключ в чат, если еще не отправлялось сегодня
    if (!wasAccessKeySentToday($accessKeySentFile)) {
        $shouldSendKey = true;
    }
}

// Отправляем ключ доступа в чат при первом обращении или при смене ключа (если еще не отправлялось сегодня)
// Инициализируем Bitrix только для отправки сообщений
if ($shouldSendKey && !$bitrixInitialized) {
    // Инициализируем Bitrix для отправки сообщений
    require_once(__DIR__ . '/bitrix_init.php');
    require_once(__DIR__ . '/chat_notifications.php');
    $bitrixInitialized = true;
    
    // Отправляем код доступа
    $sent = @sendAccessKeyNotification($accessKey, $config);
    
    // Если отправка успешна, сохраняем дату отправки
    if ($sent) {
        markAccessKeyAsSent($accessKeySentFile);
    }
}

// Определяем пути к папкам (нужно для обработки действий)
$queueDir = __DIR__ . '/queue';
$errorsDir = __DIR__ . '/queue_errors';

// Обработка действий
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

if ($action === 'process_all' && $isAuthorized) {
    // Запускаем обработку всех файлов через CLI в фоне
    $scriptPath = __DIR__ . '/calltouch_processor.php';
    $phpPath = 'php';
    
    // Определяем путь к PHP
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $whichOutput = [];
        @exec('which php', $whichOutput);
        if (!empty($whichOutput[0]) && file_exists($whichOutput[0])) {
            $phpPath = trim($whichOutput[0]);
        }
    }
    
    // Запускаем через CLI в фоне
    $cmd = sprintf(
        'cd %s && nohup %s %s >> %s 2>&1 &',
        escapeshellarg(__DIR__),
        escapeshellarg($phpPath),
        escapeshellarg(basename($scriptPath)),
        escapeshellarg(__DIR__ . '/calltouch_logs/gateway_exec.log')
    );
    @exec($cmd);
    
    $message = 'Обработка всех файлов запущена в фоновом режиме';
    $messageType = 'success';
    $_SESSION['calltouch_authorized'] = true;
} elseif ($action === 'process_file' && $isAuthorized && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    
    // Запускаем обработку конкретного файла через CLI в фоне
    $scriptPath = __DIR__ . '/calltouch_processor.php';
    $filePath = __DIR__ . '/queue/' . $filename;
    $phpPath = 'php';
    
    // Определяем путь к PHP
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $whichOutput = [];
        @exec('which php', $whichOutput);
        if (!empty($whichOutput[0]) && file_exists($whichOutput[0])) {
            $phpPath = trim($whichOutput[0]);
        }
    }
    
    // Проверяем существование файла
    if (!file_exists($filePath)) {
        $message = "Файл $filename не найден в очереди";
        $messageType = 'error';
    } else {
        // Запускаем через CLI в фоне
        $cmd = sprintf(
            'cd %s && nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg(__DIR__),
            escapeshellarg($phpPath),
            escapeshellarg(basename($scriptPath)),
            escapeshellarg($filePath),
            escapeshellarg(__DIR__ . '/calltouch_logs/gateway_exec.log')
        );
        @exec($cmd);
        
        $message = "Обработка файла $filename запущена в фоновом режиме";
        $messageType = 'success';
    }
    $_SESSION['calltouch_authorized'] = true;
} elseif ($action === 'requeue' && $isAuthorized && isset($_GET['file'])) {
    $filename = basename($_GET['file']);
    $requeueUrl = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' .
                  $_SERVER['HTTP_HOST'] .
                  '/local/handlers/calltouch/calltouch_native/requeue_and_process.php?file=' . urlencode($filename);
    
    $ch = curl_init($requeueUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    @curl_exec($ch);
    curl_close($ch);
    
    $message = "Файл $filename перемещен обратно в очередь и запущен на обработку";
    $messageType = 'success';
    $_SESSION['calltouch_authorized'] = true;
} elseif ($action === 'delete' && $isAuthorized && isset($_GET['file']) && isset($_GET['type'])) {
    $filename = basename($_GET['file']);
    $type = $_GET['type']; // 'queue' или 'error'
    
    if ($type === 'queue') {
        $filePath = $queueDir . '/' . $filename;
    } elseif ($type === 'error') {
        $filePath = $errorsDir . '/' . $filename;
    } else {
        $message = "Неверный тип файла";
        $messageType = 'error';
    }
    
    if (isset($filePath) && file_exists($filePath)) {
        if (@unlink($filePath)) {
            $message = "Файл $filename успешно удален";
            $messageType = 'success';
        } else {
            $message = "Не удалось удалить файл $filename";
            $messageType = 'error';
        }
    } else {
        $message = "Файл $filename не найден";
        $messageType = 'error';
    }
    $_SESSION['calltouch_authorized'] = true;
} elseif ($action === 'delete_all' && $isAuthorized && isset($_GET['type'])) {
    $type = $_GET['type']; // 'queue' или 'error'
    $deletedCount = 0;
    $errorsCount = 0;
    
    if ($type === 'queue') {
        $files = glob($queueDir . '/*.json');
        $files = $files ?: [];
        foreach ($files as $file) {
            if (@unlink($file)) {
                $deletedCount++;
            } else {
                $errorsCount++;
            }
        }
        if ($errorsCount > 0) {
            $message = "Удалено файлов: $deletedCount, ошибок: $errorsCount";
            $messageType = 'error';
        } else {
            $message = "Успешно удалено файлов: $deletedCount";
            $messageType = 'success';
        }
    } elseif ($type === 'error') {
        $files = glob($errorsDir . '/*.json');
        $files = $files ?: [];
        foreach ($files as $file) {
            if (@unlink($file)) {
                $deletedCount++;
            } else {
                $errorsCount++;
            }
        }
        if ($errorsCount > 0) {
            $message = "Удалено файлов: $deletedCount, ошибок: $errorsCount";
            $messageType = 'error';
        } else {
            $message = "Успешно удалено файлов: $deletedCount";
            $messageType = 'success';
        }
    } else {
        $message = "Неверный тип файла";
        $messageType = 'error';
    }
    $_SESSION['calltouch_authorized'] = true;
} elseif ($action === 'view' && $isAuthorized && isset($_GET['file']) && isset($_GET['type'])) {
    $filename = basename($_GET['file']);
    $type = $_GET['type']; // 'queue' или 'error'
    
    if ($type === 'queue') {
        $filePath = $queueDir . '/' . $filename;
    } elseif ($type === 'error') {
        $filePath = $errorsDir . '/' . $filename;
    } else {
        die('Неверный тип файла');
    }
    
    if (!file_exists($filePath)) {
        die('Файл не найден');
    }
    
    $fileContent = @file_get_contents($filePath);
    if ($fileContent === false) {
        die('Не удалось прочитать файл');
    }
    
    // Пытаемся определить, является ли файл JSON
    $isJson = false;
    $jsonData = null;
    if (pathinfo($filename, PATHINFO_EXTENSION) === 'json') {
        $jsonData = @json_decode($fileContent, true);
        $isJson = ($jsonData !== null && json_last_error() === JSON_ERROR_NONE);
    }
    
    // Устанавливаем заголовки для отображения
    header('Content-Type: text/html; charset=utf-8');
    
    ?>
    <!DOCTYPE html>
    <html lang="ru">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Просмотр файла: <?php echo htmlspecialchars($filename); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #f5f5f5;
                padding: 20px;
                color: #333;
            }
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                padding: 30px;
            }
            h1 {
                margin-bottom: 20px;
                color: #2c3e50;
            }
            .back-btn {
                display: inline-block;
                padding: 10px 20px;
                background: #2196F3;
                color: white;
                text-decoration: none;
                border-radius: 4px;
                margin-bottom: 20px;
            }
            .back-btn:hover {
                background: #1976D2;
            }
            .file-info {
                background: #f9f9f9;
                padding: 15px;
                border-radius: 4px;
                margin-bottom: 20px;
                font-size: 14px;
                color: #666;
            }
            .file-content {
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                overflow-x: auto;
            }
            pre {
                margin: 0;
                font-family: 'Courier New', Courier, monospace;
                font-size: 13px;
                line-height: 1.5;
                white-space: pre-wrap;
                word-wrap: break-word;
            }
            .json-formatted {
                background: #2d2d2d;
                color: #f8f8f2;
                padding: 20px;
                border-radius: 4px;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <a href="?key=<?php echo htmlspecialchars($accessKey); ?>" class="back-btn">← Назад к списку</a>
            <h1>📄 <?php echo htmlspecialchars($filename); ?></h1>
            <div class="file-info">
                <strong>Путь:</strong> <?php echo htmlspecialchars($filePath); ?><br>
                <strong>Размер:</strong> <?php echo number_format(filesize($filePath) / 1024, 2); ?> KB<br>
                <strong>Изменен:</strong> <?php echo date('Y-m-d H:i:s', filemtime($filePath)); ?>
            </div>
            <div class="file-content">
                <?php if ($isJson): ?>
                    <pre class="json-formatted"><?php echo htmlspecialchars(json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
                <?php else: ?>
                    <pre><?php echo htmlspecialchars($fileContent); ?></pre>
                <?php endif; ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$queueFiles = [];
if (is_dir($queueDir)) {
    $queueFiles = glob($queueDir . '/*.json');
    $queueFiles = $queueFiles ?: [];
    // Сортируем по времени изменения (новые первыми)
    usort($queueFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
}

$errorFiles = [];
if (is_dir($errorsDir)) {
    $errorFiles = glob($errorsDir . '/*.json');
    $errorFiles = $errorFiles ?: [];
    // Сортируем по времени изменения (новые первыми)
    usort($errorFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление очередью CallTouch</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            margin-bottom: 30px;
            color: #2c3e50;
        }
        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #4caf50;
        }
        .message.error {
            background: #ffebee;
            color: #c62828;
            border-left-color: #f44336;
        }
        .section {
            margin-bottom: 40px;
        }
        .section h2 {
            margin-bottom: 15px;
            color: #34495e;
            font-size: 1.3em;
        }
        .actions {
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
            margin-bottom: 10px;
        }
        .btn:hover {
            background: #1976D2;
        }
        .btn-danger {
            background: #f44336;
        }
        .btn-danger:hover {
            background: #d32f2f;
        }
        .btn-success {
            background: #4caf50;
        }
        .btn-success:hover {
            background: #45a049;
        }
        .file-list {
            background: #f9f9f9;
            border-radius: 4px;
            padding: 15px;
            max-height: 400px;
            overflow-y: auto;
        }
        .file-item {
            padding: 10px;
            margin-bottom: 8px;
            background: white;
            border-radius: 4px;
            border-left: 3px solid #2196F3;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .file-item:hover {
            background: #f0f0f0;
        }
        .file-info {
            flex: 1;
        }
        .file-name {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .file-meta {
            font-size: 12px;
            color: #7f8c8d;
        }
        .file-actions {
            display: flex;
            gap: 10px;
        }
        .file-actions .btn {
            padding: 6px 12px;
            font-size: 12px;
            margin: 0;
        }
        .empty {
            text-align: center;
            color: #95a5a6;
            padding: 20px;
        }
        .stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            flex: 1;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-card h3 {
            font-size: 2em;
            margin-bottom: 5px;
        }
        .stat-card p {
            opacity: 0.9;
        }
        .access-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .access-info code {
            background: #fff;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Управление очередью CallTouch</h1>
        
        <?php if ($isAuthorized): ?>
            <div style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                <a href="config_manager.php?key=<?php echo htmlspecialchars($accessKey); ?>" style="color: #0066cc; text-decoration: none; font-weight: bold;">⚙️ Настройки конфигурации</a>
            </div>
        <?php endif; ?>
        
        <?php if (!$isAuthorized): ?>
            <div class="access-info">
                <p><strong>🔒 Доступ запрещен</strong></p>
                <p>Для доступа к интерфейсу требуется код доступа.</p>
                <p style="margin-top: 10px; font-size: 0.9em; color: #666;">
                    Код доступа отправляется в чат "Ошибки по рекламе" при первом обращении или при смене ключа.
                </p>
                <p style="margin-top: 10px; font-size: 0.9em; color: #666;">
                    Если у вас нет кода доступа, обратитесь к администратору.
                </p>
            </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType === 'error' ? 'error' : ''; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($isAuthorized): ?>
            <div class="stats">
                <div class="stat-card">
                    <h3><?php echo count($queueFiles); ?></h3>
                    <p>Файлов в очереди</p>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h3><?php echo count($errorFiles); ?></h3>
                    <p>Файлов с ошибками</p>
                </div>
            </div>
            
            <div class="section">
                <h2>📁 Очередь обработки</h2>
                <div class="actions">
                    <a href="?action=process_all&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-success" onclick="return confirm('Обработать все файлы в очереди?');">
                        ▶️ Обработать все файлы
                    </a>
                    <?php if (!empty($queueFiles)): ?>
                        <a href="?action=delete_all&type=queue&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-danger" onclick="return confirm('⚠️ ВНИМАНИЕ!\n\nВы уверены, что хотите удалить ВСЕ файлы из очереди?\n\nЭто действие нельзя отменить!\n\nФайлов к удалению: <?php echo count($queueFiles); ?>');">
                            🗑️ Удалить все (<?php echo count($queueFiles); ?>)
                        </a>
                    <?php endif; ?>
                </div>
                <div class="file-list">
                    <?php if (empty($queueFiles)): ?>
                        <div class="empty">Очередь пуста</div>
                    <?php else: ?>
                        <?php foreach ($queueFiles as $file): ?>
                            <?php
                            $filename = basename($file);
                            $fileSize = filesize($file);
                            $fileTime = filemtime($file);
                            $fileDate = date('Y-m-d H:i:s', $fileTime);
                            ?>
                            <div class="file-item">
                                <div class="file-info">
                                    <div class="file-name"><?php echo htmlspecialchars($filename); ?></div>
                                    <div class="file-meta">
                                        Размер: <?php echo number_format($fileSize / 1024, 2); ?> KB | 
                                        Создан: <?php echo $fileDate; ?>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="?action=view&file=<?php echo urlencode($filename); ?>&type=queue&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn" target="_blank">
                                        👁️ Открыть
                                    </a>
                                    <a href="?action=process_file&file=<?php echo urlencode($filename); ?>&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn">
                                        ▶️ Обработать
                                    </a>
                                    <a href="?action=delete&file=<?php echo urlencode($filename); ?>&type=queue&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-danger" onclick="return confirm('Удалить файл <?php echo htmlspecialchars($filename); ?>? Это действие нельзя отменить.');">
                                        🗑️ Удалить
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="section">
                <h2>❌ Файлы с ошибками</h2>
                <div class="actions">
                    <?php if (!empty($errorFiles)): ?>
                        <a href="?action=delete_all&type=error&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-danger" onclick="return confirm('⚠️ ВНИМАНИЕ!\n\nВы уверены, что хотите удалить ВСЕ файлы с ошибками?\n\nЭто действие нельзя отменить!\n\nФайлов к удалению: <?php echo count($errorFiles); ?>');">
                            🗑️ Удалить все (<?php echo count($errorFiles); ?>)
                        </a>
                    <?php endif; ?>
                </div>
                <div class="file-list">
                    <?php if (empty($errorFiles)): ?>
                        <div class="empty">Нет файлов с ошибками</div>
                    <?php else: ?>
                        <?php foreach ($errorFiles as $file): ?>
                            <?php
                            $filename = basename($file);
                            $fileSize = filesize($file);
                            $fileTime = filemtime($file);
                            $fileDate = date('Y-m-d H:i:s', $fileTime);
                            ?>
                            <div class="file-item" style="border-left-color: #f44336;">
                                <div class="file-info">
                                    <div class="file-name"><?php echo htmlspecialchars($filename); ?></div>
                                    <div class="file-meta">
                                        Размер: <?php echo number_format($fileSize / 1024, 2); ?> KB | 
                                        Создан: <?php echo $fileDate; ?>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <a href="?action=view&file=<?php echo urlencode($filename); ?>&type=error&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn" target="_blank">
                                        👁️ Открыть
                                    </a>
                                    <a href="?action=requeue&file=<?php echo urlencode($filename); ?>&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-success">
                                        🔄 Вернуть в очередь
                                    </a>
                                    <a href="?action=delete&file=<?php echo urlencode($filename); ?>&type=error&key=<?php echo htmlspecialchars($accessKey); ?>" class="btn btn-danger" onclick="return confirm('Удалить файл <?php echo htmlspecialchars($filename); ?>? Это действие нельзя отменить.');">
                                        🗑️ Удалить
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

