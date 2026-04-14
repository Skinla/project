<?php
// auto_sync.php
// Автоматический запуск синхронной обработки

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

// Функция логирования
function logAutoSync($message, $config) {
    $timestamp = date('[Y-m-d H:i:s] ');
    $logFile = $config['logs_dir'] . '/auto_sync.log';
    file_put_contents($logFile, $timestamp . $message . "\n", FILE_APPEND);
}

// Проверяем, нужно ли запускать обработку
$rawDir = $config['queue_dir'] . '/raw';
$detectedDir = $config['queue_dir'] . '/detected';
$normalizedDir = $config['queue_dir'] . '/normalized';

$rawCount = is_dir($rawDir) ? count(glob($rawDir . '/*.json')) : 0;
$detectedCount = is_dir($detectedDir) ? count(glob($detectedDir . '/*.json')) : 0;
$normalizedCount = is_dir($normalizedDir) ? count(glob($normalizedDir . '/*.json')) : 0;

$totalFiles = $rawCount + $detectedCount + $normalizedCount;

if ($totalFiles > 0) {
    logAutoSync("Найдено файлов для обработки: Raw=$rawCount, Detected=$detectedCount, Normalized=$normalizedCount", $config);
    
    // Проверяем, не запущена ли уже обработка
    $completionFile = __DIR__ . '/background_sync_completed.flag';
    if (file_exists($completionFile)) {
        // Очищаем флаг для повторного запуска
        unlink($completionFile);
        logAutoSync("Очищен флаг завершения для повторного запуска", $config);
    }
    
    // Запускаем фоновую обработку
    $command = "php " . __DIR__ . "/background_sync.php > /dev/null 2>&1 &";
    exec($command);
    
    logAutoSync("Запущена фоновая обработка: $command", $config);
} else {
    logAutoSync("Нет файлов для обработки", $config);
}
