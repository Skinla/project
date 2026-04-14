<?php
// check_files.php
// Простая проверка всех файлов проекта

header('Content-Type: text/html; charset=utf-8');

$root = __DIR__;

echo "<h1>Проверка файлов проекта</h1>";

$files = [
    'webhook.php' => '../webhook.php',
    'config.php' => 'config.php',
    'error_handler.php' => 'error_handler.php',
    'data_type_detector.php' => 'data_type_detector.php',
    'queue_manager.php' => 'queue_manager.php',
    'retry-raw-errors.php' => 'retry-raw-errors.php',
    'add-to-iblock.php' => 'add-to-iblock.php',
    'add-to-exceptions.php' => 'add-to-exceptions.php',
    'normalizers/generic_normalizer.php' => 'normalizers/generic_normalizer.php'
];

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Файл</th><th>Путь</th><th>Существует</th><th>Размер</th><th>Дата изменения</th></tr>";

foreach ($files as $name => $path) {
    $fullPath = $root . '/' . $path;
    $exists = file_exists($fullPath);
    $size = $exists ? filesize($fullPath) : 0;
    $mtime = $exists ? date('Y-m-d H:i:s', filemtime($fullPath)) : 'N/A';
    $color = $exists ? 'green' : 'red';
    
    echo "<tr>";
    echo "<td>$name</td>";
    echo "<td>$path</td>";
    echo "<td style='color: $color;'>" . ($exists ? 'ДА' : 'НЕТ') . "</td>";
    echo "<td>$size байт</td>";
    echo "<td>$mtime</td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Проверка папок очереди:</h2>";

$queueDirs = [
    'queue/raw',
    'queue/detected', 
    'queue/normalized',
    'queue/processed',
    'queue/failed',
    'queue/queue_errors',
    'queue/raw/raw_errors'
];

echo "<ul>";
foreach ($queueDirs as $dir) {
    $fullPath = $root . '/' . $dir;
    $exists = is_dir($fullPath);
    $writable = $exists ? is_writable($fullPath) : false;
    $color = $exists ? ($writable ? 'green' : 'orange') : 'red';
    
    echo "<li style='color: $color;'>$dir: " . ($exists ? ($writable ? 'существует, доступен для записи' : 'существует, НЕТ прав на запись') : 'НЕ СУЩЕСТВУЕТ') . "</li>";
}
echo "</ul>";

echo "<h2>Быстрая проверка ключевых изменений:</h2>";

// Проверяем webhook.php
$webhookPath = $root . '/../webhook.php';
if (file_exists($webhookPath)) {
    $webhook = file_get_contents($webhookPath);
    $hasRawBody = strpos($webhook, 'raw_body') !== false;
    $hasRawHeaders = strpos($webhook, 'raw_headers') !== false;
    $hasParsedData = strpos($webhook, 'parsed_data') !== false;
    
    echo "<p><strong>webhook.php:</strong></p>";
    echo "<ul>";
    echo "<li style='color: " . ($hasRawBody ? 'green' : 'red') . ";'>raw_body: " . ($hasRawBody ? 'ДА' : 'НЕТ') . "</li>";
    echo "<li style='color: " . ($hasRawHeaders ? 'green' : 'red') . ";'>raw_headers: " . ($hasRawHeaders ? 'ДА' : 'НЕТ') . "</li>";
    echo "<li style='color: " . ($hasParsedData ? 'green' : 'red') . ";'>parsed_data: " . ($hasParsedData ? 'ДА' : 'НЕТ') . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color: red;'>webhook.php не найден</p>";
}

// Проверяем error_handler.php
$ehPath = $root . '/error_handler.php';
if (file_exists($ehPath)) {
    $eh = file_get_contents($ehPath);
    $hasCopyToErrorQueue = strpos($eh, 'copyToErrorQueue') !== false;
    $hasAddToExceptions = strpos($eh, 'addToExceptions') !== false;
    $hasMoveToRawErrors = strpos($eh, 'moveToRawErrors') !== false;
    $hasRawErrors = strpos($eh, 'raw_errors') !== false;
    
    echo "<p><strong>error_handler.php:</strong></p>";
    echo "<ul>";
    echo "<li style='color: " . ($hasCopyToErrorQueue ? 'green' : 'red') . ";'>copyToErrorQueue: " . ($hasCopyToErrorQueue ? 'ДА' : 'НЕТ') . "</li>";
    echo "<li style='color: " . ($hasAddToExceptions ? 'green' : 'red') . ";'>addToExceptions: " . ($hasAddToExceptions ? 'ДА' : 'НЕТ') . "</li>";
    echo "<li style='color: " . ($hasMoveToRawErrors ? 'green' : 'red') . ";'>moveToRawErrors: " . ($hasMoveToRawErrors ? 'ДА' : 'НЕТ') . "</li>";
    echo "<li style='color: " . ($hasRawErrors ? 'green' : 'red') . ";'>raw_errors: " . ($hasRawErrors ? 'ДА' : 'НЕТ') . "</li>";
    echo "</ul>";
} else {
    echo "<p style='color: red;'>error_handler.php не найден</p>";
}
?>
