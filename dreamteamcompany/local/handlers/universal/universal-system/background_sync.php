<?php
// background_sync.php
// Фоновая синхронная обработка (работает независимо от браузера)

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

// Игнорируем закрытие соединения с браузером
ignore_user_abort(true);
set_time_limit(0);

// Функция логирования
function logBackground($message, $config) {
    $timestamp = date('[Y-m-d H:i:s] ');
    $logFile = $config['logs_dir'] . '/background_sync.log';
    file_put_contents($logFile, $timestamp . $message . "\n", FILE_APPEND);
    echo $timestamp . $message . "\n";
}

logBackground("=== ЗАПУСК ФОНОВОЙ СИНХРОННОЙ ОБРАБОТКИ ===", $config);

// Обрабатываем все очереди синхронно
require_once __DIR__ . '/queue_manager.php';

try {
    processAllQueues($config);
    logBackground("=== ФОНОВАЯ ОБРАБОТКА ЗАВЕРШЕНА УСПЕШНО ===", $config);
} catch (Exception $e) {
    logBackground("=== ОШИБКА В ФОНОВОЙ ОБРАБОТКЕ: " . $e->getMessage() . " ===", $config);
}

// Создаем файл-сигнал завершения
$completionFile = __DIR__ . '/background_sync_completed.flag';
file_put_contents($completionFile, date('Y-m-d H:i:s'));

logBackground("=== ФОНОВАЯ ОБРАБОТКА ЗАВЕРШЕНА ===", $config);
