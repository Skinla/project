<?php
// requeue_and_process.php — вернуть файл из queue_errors обратно в queue и запустить обработку

header('Content-Type: text/html; charset=utf-8');

$baseDir = __DIR__;
$configPath = $baseDir . '/calltouch_config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) { $config = []; }

$file = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
if ($file === '') {
    http_response_code(400);
    echo '<p>Missing required param: file</p>';
    exit;
}

$errorsDir = $config['errors_dir'] ?? ($baseDir . '/queue_errors');
$queueDir  = $baseDir . '/queue';
if (!is_dir($queueDir)) { @mkdir($queueDir, 0777, true); }

$src = rtrim($errorsDir, "/\\") . '/' . basename($file);
// Нормализуем имя файла: берём хвост, начиная с 'ct_' (оригинальное имя из очереди)
$baseName = basename($file);
$normalized = $baseName;
if (preg_match('/(ct_\d{8}_\d{6}_[0-9a-f]+\.json)$/i', $baseName, $m)) {
    $normalized = $m[1];
}
// Если не удалось распознать шаблон, ограничим длину имени
if (strlen($normalized) > 120) {
    $extPos = strrpos($normalized, '.');
    $ext = $extPos !== false ? substr($normalized, $extPos) : '';
    $nameOnly = $extPos !== false ? substr($normalized, 0, $extPos) : $normalized;
    $normalized = substr($nameOnly, -100) . $ext;
}
$dst = rtrim($queueDir, "/\\") . '/' . $normalized;

if (!is_file($src)) {
    http_response_code(404);
    echo '<p>Файл ошибки не найден: ' . htmlspecialchars($src) . '</p>';
    exit;
}

if (!@rename($src, $dst)) {
    http_response_code(500);
    echo '<p>Не удалось переместить файл в очередь.</p>';
    exit;
}

echo '<p>Файл возвращён в очередь: ' . htmlspecialchars(basename($dst)) . '</p>';

// Запуск обработчика в фоне (как в gateway)
$cmd = sprintf(
    'php %s %s > /dev/null 2>&1 &',
    escapeshellarg($baseDir . '/calltouch_processor.php'),
    escapeshellarg($dst)
);
@exec($cmd);

echo '<p>Обработка очереди запущена.</p>';
echo '<p><a href="calltouch_processor.php?run=all">Запустить обработку через браузер</a> (опционально)</p>';
exit;


