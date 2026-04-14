<?php
// start_auto_sync_web.php
// Веб-интерфейс для запуска автоматической обработки

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Автоматическая синхронная обработка</h1>";
echo "<p>Время: " . date('Y-m-d H:i:s') . "</p>";

// Запускаем автоматическую обработку
require_once __DIR__ . '/auto_sync.php';

echo "<p style='color: green;'>Автоматическая обработка запущена!</p>";

// Проверяем статус
$rawDir = $config['queue_dir'] . '/raw';
$detectedDir = $config['queue_dir'] . '/detected';
$normalizedDir = $config['queue_dir'] . '/normalized';

$rawCount = is_dir($rawDir) ? count(glob($rawDir . '/*.json')) : 0;
$detectedCount = is_dir($detectedDir) ? count(glob($detectedDir . '/*.json')) : 0;
$normalizedCount = is_dir($normalizedDir) ? count(glob($normalizedDir . '/*.json')) : 0;

echo "<h2>Статус очередей:</h2>";
echo "<ul>";
echo "<li>Raw: $rawCount файлов</li>";
echo "<li>Detected: $detectedCount файлов</li>";
echo "<li>Normalized: $normalizedCount файлов</li>";
echo "</ul>";

// Показываем последние записи из лога автоматической обработки
$autoSyncLog = $config['logs_dir'] . '/auto_sync.log';
if (file_exists($autoSyncLog)) {
    echo "<h2>Лог автоматической обработки:</h2>";
    $logContent = file_get_contents($autoSyncLog);
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
echo "<p><a href='check_status_web.php'>Проверить статус системы</a></p>";
echo "<p><a href='index.php'>На главную</a></p>";
