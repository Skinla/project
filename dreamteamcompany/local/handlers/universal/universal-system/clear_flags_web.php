<?php
// clear_flags_web.php
// Очистка флагов для повторного запуска

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Очистка флагов</h1>";
echo "<p>Время: " . date('Y-m-d H:i:s') . "</p>";

// Очищаем флаги
$completionFile = __DIR__ . '/background_sync_completed.flag';
$stopFile = __DIR__ . '/stop_daemon.flag';

$cleared = [];

if (file_exists($completionFile)) {
    unlink($completionFile);
    $cleared[] = "background_sync_completed.flag";
}

if (file_exists($stopFile)) {
    unlink($stopFile);
    $cleared[] = "stop_daemon.flag";
}

if (empty($cleared)) {
    echo "<p>Флаги не найдены для очистки.</p>";
} else {
    echo "<p style='color: green;'>Очищены флаги:</p>";
    echo "<ul>";
    foreach ($cleared as $flag) {
        echo "<li>$flag</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='start_background_sync_web.php'>Запустить фоновую обработку</a></p>";
echo "<p><a href='check_daemon_web.php'>Проверить статус демона</a></p>";
echo "<p><a href='index.php'>На главную</a></p>";
