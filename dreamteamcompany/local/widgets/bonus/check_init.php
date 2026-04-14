<?php
/**
 * Проверка содержимого init.php
 * Открой этот файл в браузере для просмотра init.php
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Проверка init.php</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #333; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; white-space: pre-wrap; word-wrap: break-word; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        .section { margin: 20px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📄 Содержимое init.php</h1>
        
        <?php
        $initPath = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/init.php';
        
        if (file_exists($initPath)) {
            echo '<div class="success">✅ Файл найден: <code>' . htmlspecialchars($initPath) . '</code></div>';
            
            $content = file_get_contents($initPath);
            $size = filesize($initPath);
            $lines = substr_count($content, "\n") + 1;
            
            echo '<div class="info">';
            echo '<strong>Информация о файле:</strong><br>';
            echo 'Размер: ' . number_format($size) . ' байт<br>';
            echo 'Строк: ' . $lines . '<br>';
            echo 'Последнее изменение: ' . date('Y-m-d H:i:s', filemtime($initPath));
            echo '</div>';
            
            // Проверяем наличие кода виджета
            echo '<div class="section">';
            echo '<h2>🔍 Поиск кода виджета</h2>';
            
            $checks = [
                'BONUS_WIDGET_EVENT_ATTACHED' => 'Константа BONUS_WIDGET_EVENT_ATTACHED',
                'bonus-widget-loader.js' => 'Путь к bonus-widget-loader.js',
                'OnEndBufferContent' => 'Обработчик OnEndBufferContent',
                'intranet-user-profile-bonus-result' => 'ID контейнера виджета',
                'EventManager' => 'Класс EventManager',
                'Asset' => 'Класс Asset',
            ];
            
            $found = [];
            $notFound = [];
            
            foreach ($checks as $search => $description) {
                if (strpos($content, $search) !== false) {
                    $found[] = $description;
                } else {
                    $notFound[] = $description;
                }
            }
            
            if (count($found) >= 3) {
                echo '<div class="success">✅ Код виджета найден! Найдено признаков: ' . count($found) . ' из ' . count($checks) . '</div>';
                echo '<div class="info">Найдено: ' . implode(', ', $found) . '</div>';
            } else {
                echo '<div class="error">❌ Код виджета НЕ найден! Найдено признаков: ' . count($found) . ' из ' . count($checks) . '</div>';
                if (!empty($found)) {
                    echo '<div class="info">Частично найдено: ' . implode(', ', $found) . '</div>';
                }
                if (!empty($notFound)) {
                    echo '<div class="info">Не найдено: ' . implode(', ', $notFound) . '</div>';
                }
            }
            echo '</div>';
            
            // Показываем содержимое файла
            echo '<div class="section">';
            echo '<h2>📋 Полное содержимое файла</h2>';
            echo '<pre>' . htmlspecialchars($content) . '</pre>';
            echo '</div>';
            
            // Показываем последние строки
            echo '<div class="section">';
            echo '<h2>📝 Последние 30 строк файла</h2>';
            $linesArray = explode("\n", $content);
            $lastLines = array_slice($linesArray, -30);
            echo '<pre>' . htmlspecialchars(implode("\n", $lastLines)) . '</pre>';
            echo '</div>';
            
        } else {
            echo '<div class="error">❌ Файл не найден!</div>';
            echo '<div class="info">Ожидаемый путь: <code>' . htmlspecialchars($initPath) . '</code></div>';
            echo '<div class="info">Проверь, что файл существует и путь правильный</div>';
        }
        ?>
        
        <div class="section">
            <h2>💡 Что делать дальше</h2>
            <div class="info">
                <ol>
                    <li>Если код виджета не найден, добавь его в конец файла <code>/local/php_interface/init.php</code></li>
                    <li>Скопируй код из файла <code>init.php</code> в этом проекте</li>
                    <li>Или используй пример из <code>init_integration_example.php</code></li>
                    <li>После добавления кода обнови эту страницу</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>

