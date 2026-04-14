<?php
// setup_cron.php
// Настройка автоматического запуска через cron

require_once __DIR__ . '/config.php';

// Конфигурация
$config = require __DIR__ . '/config.php';

echo "<h1>Настройка автоматического запуска</h1>";
echo "<p>Время: " . date('Y-m-d H:i:s') . "</p>";

// Получаем путь к PHP
$phpPath = PHP_BINARY;
$scriptPath = __DIR__ . '/auto_sync.php';

echo "<h2>Инструкции для настройки cron:</h2>";
echo "<p>Добавьте следующую строку в crontab:</p>";
echo "<pre style='background: #f0f0f0; padding: 10px;'>";
echo "# Запуск каждые 2 минуты\n";
echo "*/2 * * * * $phpPath $scriptPath\n";
echo "</pre>";

echo "<h2>Альтернативные варианты:</h2>";
echo "<ul>";
echo "<li><strong>Каждую минуту:</strong> <code>* * * * * $phpPath $scriptPath</code></li>";
echo "<li><strong>Каждые 5 минут:</strong> <code>*/5 * * * * $phpPath $scriptPath</code></li>";
echo "<li><strong>Каждые 10 минут:</strong> <code>*/10 * * * * $phpPath $scriptPath</code></li>";
echo "</ul>";

echo "<h2>Команды для настройки:</h2>";
echo "<pre style='background: #f0f0f0; padding: 10px;'>";
echo "# Открыть crontab для редактирования\n";
echo "crontab -e\n";
echo "\n";
echo "# Добавить строку:\n";
echo "*/2 * * * * $phpPath $scriptPath\n";
echo "\n";
echo "# Сохранить и выйти\n";
echo "# Проверить crontab:\n";
echo "crontab -l\n";
echo "</pre>";

echo "<h2>Проверка:</h2>";
echo "<p>После настройки cron проверьте лог автоматической обработки:</p>";
echo "<p><a href='logs/auto_sync.log' target='_blank'>Лог автоматической обработки</a></p>";

echo "<hr>";
echo "<p><a href='start_auto_sync_web.php'>Запустить автоматическую обработку вручную</a></p>";
echo "<p><a href='index.php'>На главную</a></p>";
