<?php
// debug_error_handler.php
// Отладочный скрипт для проверки содержимого error_handler.php

header('Content-Type: text/html; charset=utf-8');

$filePath = __DIR__ . '/error_handler.php';

echo "<h1>Отладка error_handler.php</h1>";

if (!file_exists($filePath)) {
    echo "<p style='color: red;'>Файл error_handler.php не найден по пути: $filePath</p>";
    exit;
}

$content = file_get_contents($filePath);
$size = strlen($content);

echo "<p><strong>Размер файла:</strong> $size байт</p>";
echo "<p><strong>Дата изменения:</strong> " . date('Y-m-d H:i:s', filemtime($filePath)) . "</p>";

echo "<h2>Поиск ключевых методов:</h2>";

$methods = [
    'copyToErrorQueue' => 'function copyToErrorQueue',
    'addToExceptions' => 'function addToExceptions', 
    'moveToRawErrors' => 'function moveToRawErrors',
    'handleError' => 'function handleError'
];

foreach ($methods as $method => $search) {
    $found = strpos($content, $search) !== false;
    $color = $found ? 'green' : 'red';
    echo "<p style='color: $color;'><strong>$method:</strong> " . ($found ? 'НАЙДЕН' : 'НЕ НАЙДЕН') . "</p>";
}

echo "<h2>Поиск ключевых строк:</h2>";

$strings = [
    'raw_errors' => 'raw_errors',
    'retry-raw-errors.php' => 'retry-raw-errors.php',
    'queue_errors' => 'queue_errors'
];

foreach ($strings as $name => $search) {
    $found = strpos($content, $search) !== false;
    $color = $found ? 'green' : 'red';
    echo "<p style='color: $color;'><strong>$name:</strong> " . ($found ? 'НАЙДЕН' : 'НЕ НАЙДЕН') . "</p>";
}

echo "<h2>Первые 500 символов файла:</h2>";
echo "<pre>" . htmlspecialchars(substr($content, 0, 500)) . "</pre>";

echo "<h2>Последние 500 символов файла:</h2>";
echo "<pre>" . htmlspecialchars(substr($content, -500)) . "</pre>";

echo "<h2>Поиск всех функций в файле:</h2>";
preg_match_all('/function\s+(\w+)\s*\(/', $content, $matches);
if (!empty($matches[1])) {
    echo "<ul>";
    foreach ($matches[1] as $function) {
        echo "<li>$function</li>";
    }
    echo "</ul>";
} else {
    echo "<p>Функции не найдены</p>";
}
?>
