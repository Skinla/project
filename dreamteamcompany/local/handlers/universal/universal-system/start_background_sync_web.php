<?php
// start_background_sync_web.php
// Веб-интерфейс для запуска фоновой синхронной обработки

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Фоновая синхронная обработка</h1>";
echo "<p>Время запуска: " . date('Y-m-d H:i:s') . "</p>";

// Проверяем, не запущена ли уже фоновая обработка
$completionFile = __DIR__ . '/background_sync_completed.flag';
$backgroundLog = $config['logs_dir'] . '/background_sync.log';

if (file_exists($completionFile)) {
    echo "<p style='color: green;'>Фоновая обработка уже завершена!</p>";
    echo "<p>Время завершения: " . file_get_contents($completionFile) . "</p>";
    
    // Показываем последние записи из лога
    if (file_exists($backgroundLog)) {
        echo "<h3>Последние записи из лога:</h3>";
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
} else {
    echo "<p style='color: orange;'>Фоновая обработка запускается...</p>";
    
    // Запускаем фоновую обработку
    echo "<script>";
    echo "setTimeout(function() {";
    echo "  window.location.href = 'start_background_sync_web.php';";
    echo "}, 2000);";
    echo "</script>";
    
    // Запускаем фоновую обработку
    $command = "php " . __DIR__ . "/background_sync.php > /dev/null 2>&1 &";
    exec($command);
    
    echo "<p>Команда запущена: $command</p>";
    echo "<p>Обработка будет продолжаться в фоне...</p>";
    echo "<p>Обновите страницу через несколько секунд для проверки статуса.</p>";
}

echo "<hr>";
echo "<p><a href='check_daemon_web.php'>Проверить статус демона</a></p>";
echo "<p><a href='stop_daemon_web.php'>Остановить демон</a></p>";
echo "<p><a href='index.php'>На главную</a></p>";
