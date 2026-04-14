<?php
/**
 * Диагностика виджета "Мои бонусы"
 * Открой этот файл в браузере для проверки
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Диагностика виджета "Мои бонусы"</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { color: #333; }
        h2 { color: #555; margin-top: 30px; border-bottom: 2px solid #2fc6f6; padding-bottom: 10px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 4px; margin: 10px 0; }
        .info { background: #e3f2fd; padding: 15px; border-radius: 4px; margin: 10px 0; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto; }
        code { background: #f0f0f0; padding: 2px 6px; border-radius: 3px; }
        .check-item { margin: 10px 0; padding: 10px; border-left: 4px solid #2fc6f6; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Диагностика виджета "Мои бонусы"</h1>
        
        <?php
        $errors = [];
        $warnings = [];
        $success = [];
        
        // Проверка 1: Файл init.php
        echo '<h2>1. Проверка init.php</h2>';
        $initPath = $_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/init.php';
        if (file_exists($initPath)) {
            echo '<div class="success">✅ Файл найден: <code>/local/php_interface/init.php</code></div>';
            $initContent = file_get_contents($initPath);
            $initSize = filesize($initPath);
            echo '<div class="info">Размер файла: ' . number_format($initSize) . ' байт</div>';
            
            // Проверяем различные признаки кода виджета
            $hasWidgetCode = false;
            $widgetChecks = [
                'BONUS_WIDGET_EVENT_ATTACHED' => 'Константа BONUS_WIDGET_EVENT_ATTACHED',
                'bonus-widget-loader.js' => 'Путь к bonus-widget-loader.js',
                'OnEndBufferContent' => 'Обработчик OnEndBufferContent',
                'intranet-user-profile-bonus-result' => 'ID контейнера виджета',
            ];
            
            $foundChecks = [];
            foreach ($widgetChecks as $search => $description) {
                if (strpos($initContent, $search) !== false) {
                    $foundChecks[] = $description;
                }
            }
            
            if (count($foundChecks) >= 2) {
                echo '<div class="success">✅ Код виджета найден в init.php</div>';
                echo '<div class="info">Найдены признаки: ' . implode(', ', $foundChecks) . '</div>';
                $success[] = 'init.php содержит код виджета';
                $hasWidgetCode = true;
            } else {
                echo '<div class="error">❌ Код виджета НЕ найден в init.php</div>';
                echo '<div class="info">Найдено признаков: ' . count($foundChecks) . ' из ' . count($widgetChecks) . '</div>';
                if (!empty($foundChecks)) {
                    echo '<div class="warning">Частично найдено: ' . implode(', ', $foundChecks) . '</div>';
                }
                echo '<div class="info">Добавь код виджета в конец файла <code>/local/php_interface/init.php</code></div>';
                echo '<div class="info"><strong>Последние 500 символов файла:</strong><pre>' . htmlspecialchars(substr($initContent, -500)) . '</pre></div>';
                $errors[] = 'Код виджета отсутствует в init.php';
            }
        } else {
            echo '<div class="error">❌ Файл init.php не найден!</div>';
            echo '<div class="info">Ожидаемый путь: <code>/local/php_interface/init.php</code></div>';
            echo '<div class="info">Проверь, что файл существует и путь правильный</div>';
            $errors[] = 'init.php не существует';
        }
        
        // Проверка 2: bonus-widget-loader.js
        echo '<h2>2. Проверка bonus-widget-loader.js</h2>';
        $loaderPath = $_SERVER['DOCUMENT_ROOT'] . '/local/templates/.default/js/bonus-widget-loader.js';
        $loaderPathAlt = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/js-component/loader.js';
        
        if (file_exists($loaderPath)) {
            echo '<div class="success">✅ Файл найден: <code>/local/templates/.default/js/bonus-widget-loader.js</code></div>';
            $success[] = 'bonus-widget-loader.js существует';
        } elseif (file_exists($loaderPathAlt)) {
            echo '<div class="warning">⚠️ Файл найден в альтернативном месте: <code>/local/widgets/bonus/js-component/loader.js</code></div>';
            echo '<div class="info">Обнови путь в init.php</div>';
            $warnings[] = 'bonus-widget-loader.js в нестандартном месте';
        } else {
            echo '<div class="error">❌ Файл bonus-widget-loader.js не найден!</div>';
            echo '<div class="info">Ожидаемые пути:<br>';
            echo '- <code>/local/templates/.default/js/bonus-widget-loader.js</code><br>';
            echo '- <code>/local/widgets/bonus/js-component/loader.js</code></div>';
            $errors[] = 'bonus-widget-loader.js не найден';
        }
        
        // Проверка 3: handler.php
        echo '<h2>3. Проверка handler.php</h2>';
        $handlerPath = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/js-component/handler.php';
        if (file_exists($handlerPath)) {
            echo '<div class="success">✅ Файл найден: <code>/local/widgets/bonus/js-component/handler.php</code></div>';
            $success[] = 'handler.php существует';
            
            // Проверяем доступность через URL
            $handlerUrl = '/local/widgets/bonus/js-component/handler.php?USER_ID=1';
            echo '<div class="info">Проверь доступность: <a href="' . htmlspecialchars($handlerUrl) . '" target="_blank">' . htmlspecialchars($handlerUrl) . '</a></div>';
        } else {
            echo '<div class="error">❌ Файл handler.php не найден!</div>';
            echo '<div class="info">Ожидаемый путь: <code>/local/widgets/bonus/js-component/handler.php</code></div>';
            $errors[] = 'handler.php не найден';
        }
        
        // Проверка 4: profile_widget_handler.php
        echo '<h2>4. Проверка profile_widget_handler.php</h2>';
        $profilePath = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/profile_widget_handler.php';
        if (file_exists($profilePath)) {
            echo '<div class="success">✅ Файл найден: <code>/local/widgets/bonus/profile_widget_handler.php</code></div>';
            $success[] = 'profile_widget_handler.php существует';
            
            // Проверяем доступность через URL
            $profileUrl = '/local/widgets/bonus/profile_widget_handler.php?USER_ID=1';
            echo '<div class="info">Проверь доступность: <a href="' . htmlspecialchars($profileUrl) . '" target="_blank">' . htmlspecialchars($profileUrl) . '</a></div>';
        } else {
            echo '<div class="error">❌ Файл profile_widget_handler.php не найден!</div>';
            echo '<div class="info">Ожидаемый путь: <code>/local/widgets/bonus/profile_widget_handler.php</code></div>';
            $errors[] = 'profile_widget_handler.php не найден';
        }
        
        // Проверка 5: config.php
        echo '<h2>5. Проверка config.php</h2>';
        $configPath = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/config.php';
        if (file_exists($configPath)) {
            echo '<div class="success">✅ Файл config.php найден</div>';
            $config = @include $configPath;
            if (is_array($config)) {
                echo '<div class="info"><strong>Текущая конфигурация:</strong><pre>' . htmlspecialchars(print_r($config, true)) . '</pre></div>';
                
                if (empty($config['entity_type_id']) || $config['entity_type_id'] === 'DYNAMIC_XXX') {
                    echo '<div class="warning">⚠️ entity_type_id не настроен (используется тестовое значение)</div>';
                    $warnings[] = 'entity_type_id не настроен';
                }
                if (empty($config['bonus_field']) || $config['bonus_field'] === 'UF_BONUS_AMOUNT') {
                    echo '<div class="warning">⚠️ bonus_field может быть не настроен</div>';
                }
            } else {
                echo '<div class="error">❌ config.php не возвращает массив</div>';
                $errors[] = 'config.php имеет неправильный формат';
            }
        } else {
            echo '<div class="warning">⚠️ Файл config.php не найден</div>';
            echo '<div class="info">Создай файл <code>/local/widgets/bonus/config.php</code> из примера <code>config.php.example</code></div>';
            $warnings[] = 'config.php отсутствует';
        }
        
        // Проверка 6: Модуль CRM
        echo '<h2>6. Проверка модуля CRM</h2>';
        if (Loader::includeModule('crm')) {
            echo '<div class="success">✅ Модуль CRM подключен</div>';
            $success[] = 'CRM модуль доступен';
        } else {
            echo '<div class="error">❌ Модуль CRM не подключен!</div>';
            echo '<div class="info">Виджет требует модуль CRM для работы со смарт-процессами</div>';
            $errors[] = 'CRM модуль недоступен';
        }
        
        // Проверка 7: Путь к шаблону
        echo '<h2>7. Проверка пути к шаблону</h2>';
        if (file_exists($initPath)) {
            $initContent = file_get_contents($initPath);
            if (preg_match('/\/local\/templates\/([^\/]+)\/js\/bonus-widget-loader\.js/', $initContent, $matches)) {
                $templateName = $matches[1];
                $templatePath = $_SERVER['DOCUMENT_ROOT'] . '/local/templates/' . $templateName;
                if (is_dir($templatePath)) {
                    echo '<div class="success">✅ Шаблон найден: <code>' . htmlspecialchars($templateName) . '</code></div>';
                    $success[] = 'Шаблон существует';
                } else {
                    echo '<div class="error">❌ Шаблон не найден: <code>' . htmlspecialchars($templateName) . '</code></div>';
                    echo '<div class="info">Проверь путь в init.php или создай папку шаблона</div>';
                    $errors[] = 'Шаблон не существует';
                }
            } else {
                echo '<div class="warning">⚠️ Не удалось определить шаблон из init.php</div>';
            }
        }
        
        // Итоги
        echo '<h2>📊 Итоги диагностики</h2>';
        
        if (empty($errors)) {
            echo '<div class="success">';
            echo '<strong>✅ Критических ошибок не найдено!</strong><br>';
            echo 'Все основные файлы на месте.';
            echo '</div>';
        } else {
            echo '<div class="error">';
            echo '<strong>❌ Найдено ошибок: ' . count($errors) . '</strong><br>';
            echo '<ul>';
            foreach ($errors as $error) {
                echo '<li>' . htmlspecialchars($error) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        if (!empty($warnings)) {
            echo '<div class="warning">';
            echo '<strong>⚠️ Предупреждений: ' . count($warnings) . '</strong><br>';
            echo '<ul>';
            foreach ($warnings as $warning) {
                echo '<li>' . htmlspecialchars($warning) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        if (!empty($success)) {
            echo '<div class="info">';
            echo '<strong>✅ Успешных проверок: ' . count($success) . '</strong>';
            echo '</div>';
        }
        
        // Рекомендации
        echo '<h2>💡 Рекомендации</h2>';
        echo '<div class="info">';
        echo '<ol>';
        echo '<li><strong>Проверь консоль браузера (F12)</strong> на странице профиля - должны быть логи <code>BonusWidget: ...</code></li>';
        echo '<li><strong>Проверь Network (F12 → Network)</strong> - должны быть запросы к:<br>';
        echo '   - bonus-widget-loader.js<br>';
        echo '   - js-component/handler.php<br>';
        echo '   - profile_widget_handler.php</li>';
        echo '<li><strong>Очисти кеш Bitrix24</strong> и браузера (Ctrl+F5)</li>';
        echo '<li><strong>Проверь права доступа</strong> к файлам (должны быть читаемыми)</li>';
        echo '</ol>';
        echo '</div>';
        ?>
    </div>
</body>
</html>
