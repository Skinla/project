<?php
// check_status_web.php
// Проверка статуса всей системы

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Статус системы</h1>";
echo "<p>Время: " . date('Y-m-d H:i:s') . "</p>";

// Проверяем статус демона
$stopFile = __DIR__ . '/stop_daemon.flag';
$daemonStatus = file_exists($stopFile) ? "Остановлен" : "Работает";
$statusColor = file_exists($stopFile) ? "red" : "green";

echo "<h2>Демон</h2>";
echo "<p>Статус: <span style='color: $statusColor;'>$daemonStatus</span></p>";

// Проверяем фоновую обработку
$completionFile = __DIR__ . '/background_sync_completed.flag';
if (file_exists($completionFile)) {
    echo "<p style='color: green;'>Фоновая обработка завершена: " . file_get_contents($completionFile) . "</p>";
} else {
    echo "<p style='color: orange;'>Фоновая обработка не запущена</p>";
}

// Проверяем количество файлов в очередях
$rawDir = $config['queue_dir'] . '/raw';
$detectedDir = $config['queue_dir'] . '/detected';
$normalizedDir = $config['queue_dir'] . '/normalized';
$processedDir = $config['queue_dir'] . '/processed';

$rawCount = is_dir($rawDir) ? count(glob($rawDir . '/*.json')) : 0;
$detectedCount = is_dir($detectedDir) ? count(glob($detectedDir . '/*.json')) : 0;
$normalizedCount = is_dir($normalizedDir) ? count(glob($normalizedDir . '/*.json')) : 0;
$processedCount = is_dir($processedDir) ? count(glob($processedDir . '/*.json')) : 0;

echo "<h2>Очереди</h2>";
echo "<ul>";
echo "<li>Raw: $rawCount файлов</li>";
echo "<li>Detected: $detectedCount файлов</li>";
echo "<li>Normalized: $normalizedCount файлов</li>";
echo "<li>Processed: $processedCount файлов</li>";
echo "</ul>";

// Проверяем последние записи в глобальном логе
$globalLog = $config['logs_dir'] . '/global.log';
if (file_exists($globalLog)) {
    echo "<h2>Последние записи в глобальном логе</h2>";
    $logContent = file_get_contents($globalLog);
    $lastLines = array_slice(explode("\n", $logContent), -15);
    echo "<pre style='background: #f0f0f0; padding: 10px; max-height: 400px; overflow-y: scroll;'>";
    foreach ($lastLines as $line) {
        if (!empty(trim($line))) {
            echo htmlspecialchars($line) . "\n";
        }
    }
    echo "</pre>";
}

// Проверяем лог фоновой обработки
$backgroundLog = $config['logs_dir'] . '/background_sync.log';
if (file_exists($backgroundLog)) {
    echo "<h2>Лог фоновой обработки</h2>";
    $logContent = file_get_contents($backgroundLog);
    $lastLines = array_slice(explode("\n", $logContent), -10);
    echo "<pre style='background: #f0f0f0; padding: 10px; max-height: 300px; overflow-y: scroll;'>";
    foreach ($lastLines as $line) {
        if (!empty(trim($line))) {
            echo htmlspecialchars($line) . "\n";
        }
    }
    echo "</pre>";
}

echo "<hr>";
echo "<p><a href='index.php'>На главную</a></p>";
echo "<p><a href='clear_flags_web.php'>Очистить флаги</a></p>";
echo "<p><a href='start_background_sync_web.php'>Запустить фоновую обработку снова</a></p>";
