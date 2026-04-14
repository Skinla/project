<?php
/**
 * clear_normalizer_cache.php
 * Скрипт для очистки кэша нормализаторов
 * Запустить через браузер или CLI
 */

// Определяем DOCUMENT_ROOT
if (empty($_SERVER["DOCUMENT_ROOT"])) {
    $_SERVER["DOCUMENT_ROOT"] = realpath(__DIR__ . '/../..');
}

// Устанавливаем константы ДО подключения prolog
if (!defined('BX_SKIP_POST_UNPACK')) {
    define('BX_SKIP_POST_UNPACK', true);
}
if (!defined('BX_CRONTAB')) {
    define('BX_CRONTAB', true);
}
if (!defined('BX_NO_ACCELERATOR_RESET')) {
    define('BX_NO_ACCELERATOR_RESET', true);
}
if (!defined('NO_KEEP_STATISTIC')) {
    define('NO_KEEP_STATISTIC', true);
}
if (!defined('NOT_CHECK_PERMISSIONS')) {
    define('NOT_CHECK_PERMISSIONS', true);
}

// Подключаем Bitrix API
$prologPath = $_SERVER["DOCUMENT_ROOT"] . '/bitrix/modules/main/include/prolog_before.php';
if (file_exists($prologPath)) {
    require_once $prologPath;
} else {
    die("Ошибка: не найден prolog_before.php по пути: $prologPath\n");
}

CModule::IncludeModule("main");

// Проверяем, запущен ли скрипт через CLI или браузер
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html><head><meta charset='utf-8'><title>Очистка кэша нормализаторов</title>";
    echo "<style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; }
        hr { margin: 20px 0; }
        a { color: #0066cc; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style></head><body>\n";
}

echo $isCli ? "" : "<h1>Очистка кэша нормализаторов</h1>\n";
echo ($isCli ? "" : "<p>") . "Время: " . date('Y-m-d H:i:s') . ($isCli ? "\n" : "</p>\n");
echo ($isCli ? "" : "<hr>\n");

$results = [];
$errors = [];

try {
    // Способ 1: Очистка через Bitrix Cache API
    if (class_exists('\Bitrix\Main\Data\Cache')) {
        $cache = \Bitrix\Main\Data\Cache::createInstance();
        
        if ($cache) {
            $cacheDirs = [
                '/normalizer' => 'Кэш нормализаторов',
                '/iblock54' => 'Кэш инфоблока 54',
                '/source_id' => 'Кэш источников (список 19)',
                '/assigned_by_id' => 'Кэш ответственных (список 22)',
            ];
            
            foreach ($cacheDirs as $dir => $description) {
                try {
                    // Очищаем всю директорию
                    $cleared = $cache->cleanDir($dir);
                    if ($cleared) {
                        $results[] = "✓ $description (директория: $dir) - очищен через API";
                    } else {
                        $results[] = "⚠ $description (директория: $dir) - пуст или уже был очищен";
                    }
                    
                    // Также пробуем очистить через clean() с базовым ключом
                    // Bitrix может хранить кэш в поддиректориях
                    $baseKeys = [
                        'normalizer_title_',
                        'normalizer_srcdesc_',
                        'normalizer_domain_',
                        'iblock54_domain_',
                        'source_id_',
                        'assigned_by_id_',
                    ];
                    
                    foreach ($baseKeys as $baseKey) {
                        try {
                            // Очищаем все ключи, начинающиеся с baseKey
                            $cache->clean($baseKey, $dir);
                        } catch (Throwable $e) {
                            // Игнорируем ошибки для отдельных ключей
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = "✗ Ошибка при очистке $dir: " . $e->getMessage();
                }
            }
        } else {
            $errors[] = "✗ Ошибка: не удалось создать экземпляр Cache";
        }
    } else {
        $errors[] = "✗ Ошибка: класс Cache не найден";
    }
    
    // Способ 2: Рекурсивный поиск и удаление файлов кэша
    $bitrixCacheDir = $_SERVER["DOCUMENT_ROOT"] . '/bitrix/cache';
    
    // Функция для рекурсивного удаления файлов
    $deleteCacheFiles = function($dir, $pattern = null) use (&$deleteCacheFiles) {
        $count = 0;
        $totalSize = 0;
        $deletedFiles = [];
        
        if (!is_dir($dir)) {
            return ['count' => 0, 'size' => 0, 'files' => []];
        }
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                // Проверяем паттерн, если указан
                if ($pattern === null || preg_match($pattern, basename($file))) {
                    $size = filesize($file);
                    if (@unlink($file)) {
                        $count++;
                        $totalSize += $size;
                        $deletedFiles[] = basename($file);
                    }
                }
            } elseif (is_dir($file)) {
                // Рекурсивно обрабатываем поддиректории
                $subResult = $deleteCacheFiles($file, $pattern);
                $count += $subResult['count'];
                $totalSize += $subResult['size'];
                $deletedFiles = array_merge($deletedFiles, $subResult['files']);
                
                // Удаляем пустую директорию
                @rmdir($file);
            }
        }
        
        return ['count' => $count, 'size' => $totalSize, 'files' => $deletedFiles];
    };
    
    if (is_dir($bitrixCacheDir)) {
        // Ищем все директории, которые могут содержать наш кэш
        $cacheDirsToClean = [
            'normalizer' => 'Кэш нормализаторов',
            'iblock54' => 'Кэш инфоблока 54',
            'source_id' => 'Кэш источников',
            'assigned_by_id' => 'Кэш ответственных',
        ];
        
        $totalDeleted = 0;
        $totalSize = 0;
        
        foreach ($cacheDirsToClean as $dirName => $description) {
            $cachePath = $bitrixCacheDir . '/' . $dirName;
            
            if (is_dir($cachePath)) {
                $result = $deleteCacheFiles($cachePath);
                
                if ($result['count'] > 0) {
                    $sizeMB = round($result['size'] / 1024 / 1024, 2);
                    $results[] = "✓ $description (файлы) - удалено файлов: {$result['count']}, размер: {$sizeMB} МБ";
                    $totalDeleted += $result['count'];
                    $totalSize += $result['size'];
                } else {
                    $results[] = "⚠ $description (файлы) - директория пуста";
                }
            } else {
                // Ищем файлы с ключами кэша в других местах
                // Bitrix может хранить кэш в поддиректориях на основе хеша
                $cacheKeys = [
                    'normalizer_title_',
                    'normalizer_srcdesc_',
                    'normalizer_domain_',
                    'iblock54_domain_',
                    'source_id_',
                    'assigned_by_id_',
                ];
                
                // Ищем файлы по всему кэшу
                $found = false;
                foreach ($cacheKeys as $keyPrefix) {
                    // Ищем файлы, содержащие этот префикс в имени
                    $pattern = '/' . preg_quote($keyPrefix, '/') . '/';
                    $result = $deleteCacheFiles($bitrixCacheDir, $pattern);
                    
                    if ($result['count'] > 0) {
                        $found = true;
                        $sizeMB = round($result['size'] / 1024 / 1024, 2);
                        $results[] = "✓ Найдены файлы кэша с ключом '$keyPrefix' - удалено: {$result['count']}, размер: {$sizeMB} МБ";
                        $totalDeleted += $result['count'];
                        $totalSize += $result['size'];
                    }
                }
                
                if (!$found) {
                    $results[] = "⚠ $description (файлы) - директория не существует и файлы не найдены: $cachePath";
                }
            }
        }
        
        // Показываем общую статистику
        if ($totalDeleted > 0) {
            $totalSizeMB = round($totalSize / 1024 / 1024, 2);
            $results[] = "✓ <strong>Всего удалено файлов: $totalDeleted, общий размер: {$totalSizeMB} МБ</strong>";
        }
        
        // Показываем структуру директорий кэша для диагностики
        if (!$isCli) {
            echo ($isCli ? "" : "<h3>Структура директорий кэша:</h3>\n<pre>\n");
            $dirs = glob($bitrixCacheDir . '/*', GLOB_ONLYDIR);
            if (!empty($dirs)) {
                foreach ($dirs as $dir) {
                    $dirName = basename($dir);
                    $fileCount = count(glob($dir . '/**/*', GLOB_BRACE));
                    echo ($isCli ? "" : "") . "  $dirName/ ($fileCount файлов)\n";
                }
            } else {
                echo ($isCli ? "" : "") . "  Директории не найдены\n";
            }
            echo ($isCli ? "" : "</pre>\n");
        }
    } else {
        $errors[] = "⚠ Директория кэша не найдена: $bitrixCacheDir";
    }
    
} catch (Throwable $e) {
    $errors[] = "✗ Критическая ошибка: " . $e->getMessage();
    $errors[] = "Трассировка: " . $e->getTraceAsString();
}

// Выводим результаты
if (!empty($results)) {
    echo ($isCli ? "" : "<h2>Результаты очистки:</h2>\n<ul>\n");
    foreach ($results as $result) {
        $class = strpos($result, '✓') !== false ? 'success' : 'warning';
        echo ($isCli ? "" : "<li class='$class'>") . $result . ($isCli ? "\n" : "</li>\n");
    }
    echo ($isCli ? "" : "</ul>\n");
}

if (!empty($errors)) {
    echo ($isCli ? "" : "<h2>Ошибки:</h2>\n<ul>\n");
    foreach ($errors as $error) {
        echo ($isCli ? "" : "<li class='error'>") . $error . ($isCli ? "\n" : "</li>\n");
    }
    echo ($isCli ? "" : "</ul>\n");
}

if (empty($results) && empty($errors)) {
    echo ($isCli ? "" : "<p class='warning'>") . "⚠ Не удалось выполнить очистку кэша" . ($isCli ? "\n" : "</p>\n");
}

echo ($isCli ? "" : "<hr>\n");
echo ($isCli ? "" : "<p>") . "Очистка завершена: " . date('Y-m-d H:i:s') . ($isCli ? "\n" : "</p>\n");

if (!$isCli) {
    echo "<hr>\n";
    echo "<p><a href='javascript:location.reload()'>🔄 Обновить страницу</a></p>\n";
    echo "<p><a href='index.php'>🏠 На главную</a></p>\n";
    echo "</body></html>\n";
}

