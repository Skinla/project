<?php
// index.php
// Главная страница управления системой

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Управление системой обработки лидов</h1>";
echo "<p>Время: " . date('Y-m-d H:i:s') . "</p>";

echo "<h2>Статус системы</h2>";
echo "<p>Система работает через webhook - демон не нужен</p>";
echo "<p>Обработка запускается автоматически при получении данных</p>";

// Проверяем количество файлов в очередях
$rawDir = $config['queue_dir'] . '/raw';
$detectedDir = $config['queue_dir'] . '/detected';
$normalizedDir = $config['queue_dir'] . '/normalized';
$processedDir = $config['queue_dir'] . '/processed';

$rawCount = is_dir($rawDir) ? count(glob($rawDir . '/*.json')) : 0;
$detectedCount = is_dir($detectedDir) ? count(glob($detectedDir . '/*.json')) : 0;
$normalizedCount = is_dir($normalizedDir) ? count(glob($normalizedDir . '/*.json')) : 0;
$processedCount = is_dir($processedDir) ? count(glob($processedDir . '/*.json')) : 0;

echo "<h3>Очереди:</h3>";
echo "<ul>";
echo "<li>Raw: $rawCount файлов</li>";
echo "<li>Detected: $detectedCount файлов</li>";
echo "<li>Normalized: $normalizedCount файлов</li>";
echo "<li>Processed: $processedCount файлов</li>";
echo "</ul>";

// Проверяем последние логи
$globalLog = $config['logs_dir'] . '/global.log';
if (file_exists($globalLog)) {
    echo "<h3>Последние записи в логе:</h3>";
    $logContent = file_get_contents($globalLog);
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
echo "<h2>Управление</h2>";
echo "<p><a href='../control.php'>Контроль системы</a></p>";
echo "<p><a href='../manual_process.php'>Запустить обработку вручную</a></p>";
echo "<p><a href='../clear_all_locks.php'>Очистить все блокировки</a></p>";

echo "<hr>";
echo "<h2>Логи</h2>";
echo "<p><a href='logs/global.log' target='_blank'>Глобальный лог</a></p>";
echo "<p><a href='logs/errors.log' target='_blank'>Лог ошибок</a></p>";
echo "<p><a href='logs/processed_phones.log' target='_blank'>Лог обработанных телефонов</a></p>";
echo "<p><a href='logs/processing_phones.log' target='_blank'>Лог телефонов в обработке</a></p>";
echo "<p><a href='logs/duplicates.log' target='_blank'>Лог дублей</a></p>";
