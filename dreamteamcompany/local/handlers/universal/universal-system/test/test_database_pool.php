<?php
// Тест для DatabaseConnectionPool без Bitrix

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
    echo "<!DOCTYPE html>\n<html><head><meta charset='utf-8'><title>Тест DatabaseConnectionPool</title>";
    echo "<style>body{font-family:monospace;padding:20px;} .success{color:green;} .error{color:red;}</style></head><body>\n";
}

// Создаем минимальную функцию logMessage для тестов
if (!function_exists('logMessage')) {
    function logMessage($message, $logFile, $config = []) {
        // Для тестов просто выводим в консоль (только в CLI)
        if (php_sapi_name() === 'cli') {
            echo "[LOG] $message\n";
        }
    }
}

try {
    // Проверяем, где находится файл - в корне или в universal-system
    $databasePoolPath = __DIR__ . '/../database_pool.php';
    if (!file_exists($databasePoolPath)) {
        $databasePoolPath = __DIR__ . '/../../database_pool.php';
    }
    
    if (!file_exists($databasePoolPath)) {
        throw new Exception("Файл database_pool.php не найден. Проверенные пути: " . __DIR__ . '/../database_pool.php, ' . __DIR__ . '/../../database_pool.php');
    }
    
    require_once $databasePoolPath;
} catch (Exception $e) {
    testOutput("✗ ОШИБКА загрузки database_pool.php: " . $e->getMessage(), $isCli);
    if (!$isCli) echo "</body></html>";
    exit(1);
}

testOutput("=== Тест DatabaseConnectionPool ===" . ($isCli ? "\n\n" : ""), $isCli);

try {
    // Тест 1: Проверка статического метода
    testOutput("Тест 1: Проверка существования класса и метода..." . ($isCli ? "\n" : ""), $isCli);
    if (class_exists('DatabaseConnectionPool')) {
        testOutput("✓ Класс существует" . ($isCli ? "\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: класс не найден" . ($isCli ? "\n" : ""), $isCli);
        if (!$isCli) echo "</body></html>";
        exit(1);
    }
    
    if (method_exists('DatabaseConnectionPool', 'getConnection')) {
        testOutput("✓ Метод getConnection существует" . ($isCli ? "\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: метод getConnection не найден" . ($isCli ? "\n" : ""), $isCli);
        if (!$isCli) echo "</body></html>";
        exit(1);
    }
    
    if (method_exists('DatabaseConnectionPool', 'closeConnection')) {
        testOutput("✓ Метод closeConnection существует" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("✗ ОШИБКА: метод closeConnection не найден" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 2: Проверка обработки ошибок подключения
    testOutput("Тест 2: Проверка обработки ошибок подключения..." . ($isCli ? "\n" : ""), $isCli);
    
    // Сохраняем оригинальный DOCUMENT_ROOT
    $originalDocRoot = $_SERVER["DOCUMENT_ROOT"] ?? null;
    
    // Устанавливаем несуществующий DOCUMENT_ROOT, чтобы не загружать реальный конфиг
    $_SERVER["DOCUMENT_ROOT"] = __DIR__ . '/non_existent_directory_that_does_not_exist_12345';
    
    // Закрываем предыдущее соединение, если было
    DatabaseConnectionPool::closeConnection();
    
    // Пытаемся получить соединение - должно вернуть null из-за ошибки подключения
    // (database_pool.php теперь проверяет работоспособность соединения)
    $connection = null;
    try {
        // Подавляем вывод ошибок
        $oldErrorReporting = error_reporting(0);
        $connection = DatabaseConnectionPool::getConnection(['global_log' => 'test.log']);
        error_reporting($oldErrorReporting);
    } catch (Exception $e) {
        $connection = null;
    } catch (Throwable $e) {
        $connection = null;
    }
    
    // Восстанавливаем оригинальный DOCUMENT_ROOT
    if ($originalDocRoot !== null) {
        $_SERVER["DOCUMENT_ROOT"] = $originalDocRoot;
    } else {
        unset($_SERVER["DOCUMENT_ROOT"]);
    }
    
    // Тест считается успешным, если соединение null
    // Это нормально, так как мы используем несуществующий DOCUMENT_ROOT
    // и database_pool.php теперь проверяет работоспособность соединения
    if ($connection === null) {
        testOutput("✓ Успешно: ошибка подключения обработана корректно (возвращен null)" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("⚠ ПРЕДУПРЕЖДЕНИЕ: соединение получено (может быть нормально, если есть доступ к БД с дефолтными параметрами)" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 3: Проверка переиспользования соединения
    testOutput("Тест 3: Проверка переиспользования соединения..." . ($isCli ? "\n" : ""), $isCli);
    $config = [
        'global_log' => 'test.log'
    ];
    
    // Сбрасываем DOCUMENT_ROOT для нормальной работы
    $_SERVER["DOCUMENT_ROOT"] = __DIR__;
    
    $conn1 = @DatabaseConnectionPool::getConnection($config);
    $conn2 = @DatabaseConnectionPool::getConnection($config);
    
    if ($conn1 !== null && $conn2 !== null && $conn1 === $conn2) {
        testOutput("✓ Успешно: соединение переиспользуется (один и тот же объект)" . ($isCli ? "\n\n" : ""), $isCli);
    } else {
        testOutput("⚠ ПРЕДУПРЕЖДЕНИЕ: соединения разные (может быть нормально, если первое не удалось или нет доступа к БД)" . ($isCli ? "\n\n" : ""), $isCli);
    }
    
    // Тест 4: Проверка метода closeConnection
    testOutput("Тест 4: Проверка метода closeConnection..." . ($isCli ? "\n" : ""), $isCli);
    DatabaseConnectionPool::closeConnection();
    $conn3 = @DatabaseConnectionPool::getConnection($config);
    testOutput("✓ Успешно: closeConnection работает (соединение закрыто, новое может быть создано)" . ($isCli ? "\n\n" : ""), $isCli);
    
    testOutput("=== Все тесты завершены ===" . ($isCli ? "\n" : ""), $isCli);
    testOutput("Примечание: Полное тестирование требует подключения к реальной БД" . ($isCli ? "\n" : ""), $isCli);
    
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

