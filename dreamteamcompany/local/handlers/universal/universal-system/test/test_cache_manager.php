<?php
// Тест для CacheManager без Bitrix

// Определяем, запущено ли из браузера или CLI
$isCli = php_sapi_name() === 'cli';

// Функция для вывода (работает и в браузере, и в CLI)
function testOutput($message, $isCli) {
    if ($isCli) {
        echo $message;
    } else {
        echo htmlspecialchars($message) . "<br>\n";
    }
}

// HTML заголовок для браузера
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html>\n<html><head><meta charset='utf-8'><title>Тест CacheManager</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .success{color:green;} .error{color:red;}</style></head><body>\n";
}

try {
    // Проверяем, где находится файл - в корне или в universal-system
    $cacheManagerPath = __DIR__ . '/../cache_manager.php';
    if (!file_exists($cacheManagerPath)) {
        $cacheManagerPath = __DIR__ . '/../../cache_manager.php';
    }
    
    if (!file_exists($cacheManagerPath)) {
        throw new Exception("Файл cache_manager.php не найден. Проверенные пути: " . __DIR__ . '/../cache_manager.php, ' . __DIR__ . '/../../cache_manager.php');
    }
    
    require_once $cacheManagerPath;
} catch (Exception $e) {
    testOutput("✗ ОШИБКА загрузки cache_manager.php: " . $e->getMessage(), $isCli);
    if (!$isCli) echo "</body></html>";
    exit(1);
}

// Создаем тестовую конфигурацию
$config = [
    'logs_dir' => __DIR__ . '/test_cache',
    'cache_dir' => __DIR__ . '/test_cache/cache',
    'cache_ttl' => 60 // 1 минута для теста
];

// Создаем директорию для тестов
if (!is_dir($config['cache_dir'])) {
    @mkdir($config['cache_dir'], 0777, true);
}

testOutput("=== Тест CacheManager ===" . ($isCli ? "\n\n" : ""), $isCli);

try {
    // Тест 1: Создание экземпляра
    testOutput("Тест 1: Создание экземпляра CacheManager..." . ($isCli ? "\n" : ""), $isCli);
    $cache = new CacheManager($config);
    testOutput("✓ Успешно" . ($isCli ? "\n\n" : ""), $isCli);
    
    // Тест 2: Сохранение и получение значения
    testOutput("Тест 2: Сохранение и получение значения..." . ($isCli ? "\n" : ""), $isCli);
    $key = 'test_key';
    $value = 'test_value';
    $cache->set($key, $value, 60);
    $cached = $cache->get($key);
    if ($cached === $value) {
        testOutput("✓ Успешно: значение сохранено и получено корректно" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: значение не совпадает (ожидалось: '$value', получено: " . var_export($cached, true) . ")" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 3: Проверка TTL
    testOutput("Тест 3: Проверка TTL (ожидание 2 секунды)..." . ($isCli ? "\n" : ""), $isCli);
    $cache->set('ttl_test', 'value', 1); // 1 секунда
    sleep(2);
    $expired = $cache->get('ttl_test');
    if ($expired === null) {
        testOutput("✓ Успешно: TTL работает корректно (значение истекло)" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: TTL не работает (значение не истекло)" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 4: Отрицательные результаты
    testOutput("Тест 4: Кэширование отрицательных результатов..." . ($isCli ? "\n" : ""), $isCli);
    $cache->set('negative', 'NULL', 60);
    $negative = $cache->get('negative');
    if ($negative === 'NULL') {
        testOutput("✓ Успешно: отрицательные результаты кэшируются" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: отрицательные результаты не кэшируются" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 5: Очистка кэша
    testOutput("Тест 5: Очистка кэша..." . ($isCli ? "\n" : ""), $isCli);
    $cache->set('clear_test', 'value', 60);
    $cache->clear();
    $cleared = $cache->get('clear_test');
    if ($cleared === null) {
        testOutput("✓ Успешно: кэш очищен" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: кэш не очищен" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 6: Генерация ключей
    testOutput("Тест 6: Генерация ключей кэша..." . ($isCli ? "\n" : ""), $isCli);
    $key1 = CacheManager::getIblock54Key('normalizer', 'test.com');
    $key2 = CacheManager::getIblock54Key('element', 'test.com');
    if ($key1 === 'iblock54:normalizer:test.com' && $key2 === 'iblock54:element:test.com') {
        testOutput("✓ Успешно: ключи генерируются корректно" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: ключи генерируются неправильно (key1: '$key1', key2: '$key2')" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 7: Поврежденный файл кэша
    testOutput("Тест 7: Обработка поврежденного файла кэша..." . ($isCli ? "\n" : ""), $isCli);
    $badFile = $config['cache_dir'] . '/' . md5('bad_key') . '.cache';
    @file_put_contents($badFile, 'invalid json{');
    $badValue = $cache->get('bad_key');
    if ($badValue === null && !file_exists($badFile)) {
        testOutput("✓ Успешно: поврежденный файл удален" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: поврежденный файл не обработан (файл существует: " . (file_exists($badFile) ? 'да' : 'нет') . ")" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Очистка тестовых файлов
    $cache->clear();
    if (is_dir($config['cache_dir'])) {
        @rmdir($config['cache_dir']);
    }
    if (is_dir($config['logs_dir'])) {
        @rmdir($config['logs_dir']);
    }
    
    testOutput("=== Все тесты завершены ===" . ($isCli ? "\n" : ""), $isCli);
    
} catch (Exception $e) {
    testOutput("✗ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . ($isCli ? "\n" : ""), $isCli);
    testOutput("Файл: " . $e->getFile() . ($isCli ? "\n" : ""), $isCli);
    testOutput("Строка: " . $e->getLine() . ($isCli ? "\n" : ""), $isCli);
} catch (Throwable $e) {
    testOutput("✗ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . ($isCli ? "\n" : ""), $isCli);
    testOutput("Файл: " . $e->getFile() . ($isCli ? "\n" : ""), $isCli);
    testOutput("Строка: " . $e->getLine() . ($isCli ? "\n" : ""), $isCli);
}

// Закрываем HTML для браузера
if (!$isCli) {
    echo "</body></html>";
}

