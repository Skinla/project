<?php
/**
 * calltouch_gateway.php
 *
 * 1) Принимает запрос от CallTouch (POST).
 * 2) Сохраняет JSON-файл в папку calltouch_native/queue/.
 * 3) Сразу возвращает 200 OK (быстрая реакция).
 * 4) Запускает calltouch_native/calltouch_processor.php в фоне, передавая имя файла.
 */

$projectDir = __DIR__ . '/calltouch_native';
$queueDir = $projectDir . '/queue';
if (!is_dir($queueDir)) {
    mkdir($queueDir, 0777, true);
}

// Принимаем POST
$data = $_POST;

// Имя файла
$filename = sprintf(
    "%s/ct_%s_%s.json",
    $queueDir,
    date('Ymd_His'),
    uniqid()
);

// Сохраняем
file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// Отвечаем 200 OK
http_response_code(200);
echo "OK";

// Запуск "calltouch_processor.php" в фоне, передавая путь к файлу
$logFile = $projectDir . '/calltouch_logs/gateway_exec.log';

// Создаем папку для логов если её нет
$logsDir = dirname($logFile);
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}

// Проверяем существование скрипта
$scriptPath = $projectDir . '/calltouch_processor.php';
$scriptPathReal = realpath($scriptPath);

// Логируем информацию о путях для отладки
$debugInfo = date('Y-m-d H:i:s') . " [GATEWAY] Debug info:\n";
$debugInfo .= "  ProjectDir: $projectDir\n";
$debugInfo .= "  ScriptPath: $scriptPath\n";
$debugInfo .= "  ScriptPathReal: " . ($scriptPathReal ?: 'NULL') . "\n";
$debugInfo .= "  File exists: " . (file_exists($scriptPath) ? 'YES' : 'NO') . "\n";
$debugInfo .= "  Is file: " . (is_file($scriptPath) ? 'YES' : 'NO') . "\n";
$debugInfo .= "  Is readable: " . (is_readable($scriptPath) ? 'YES' : 'NO') . "\n";
@file_put_contents($logFile, $debugInfo, FILE_APPEND | LOCK_EX);

// Используем реальный путь если он есть, иначе исходный
$scriptPath = $scriptPathReal ?: $scriptPath;

// Определяем путь к PHP
$phpPath = 'php';
// Пробуем найти PHP через which (Linux) или where (Windows)
if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
    // Linux/Unix - используем which
    $whichOutput = [];
    @exec('which php', $whichOutput);
    if (!empty($whichOutput[0]) && file_exists($whichOutput[0])) {
        $phpPath = trim($whichOutput[0]);
    } elseif (file_exists('/usr/bin/php')) {
        $phpPath = '/usr/bin/php';
    } elseif (file_exists('/usr/local/bin/php')) {
        $phpPath = '/usr/local/bin/php';
    }
} else {
    // Windows - используем where или проверяем стандартные пути
    $whereOutput = [];
    @exec('where php', $whereOutput);
    if (!empty($whereOutput[0]) && file_exists($whereOutput[0])) {
        $phpPath = trim($whereOutput[0]);
    }
}

// Используем CLI запуск через exec, так как HTTP запрос требует авторизацию Bitrix
// CLI режим не требует авторизации
$useCli = true; // Используем CLI запуск

if ($useCli && file_exists($scriptPath)) {
    // Используем CLI запуск через exec - это работает без авторизации Bitrix
    // Формируем команду для CLI запуска
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        $cmd = sprintf(
            'start /B %s %s %s >> %s 2>&1',
            escapeshellarg($phpPath),
            escapeshellarg($scriptPath),
            escapeshellarg($filename),
            escapeshellarg($logFile)
        );
    } else {
        // Linux/Unix - используем nohup для надежности
        $cmd = sprintf(
            'cd %s && nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg(dirname($scriptPath)),
            escapeshellarg($phpPath),
            escapeshellarg(basename($scriptPath)),
            escapeshellarg($filename),
            escapeshellarg($logFile)
        );
    }

    // Логируем попытку запуска
    $logMsg = date('Y-m-d H:i:s') . " [GATEWAY] Executing CLI: $cmd\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] PHP Path: $phpPath\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Script Path: $scriptPath\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] File: $filename\n";
    @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);

    // Запускаем команду
    $output = [];
    $returnVar = 0;
    @exec($cmd, $output, $returnVar);

    // Логируем результат
    $resultMsg = date('Y-m-d H:i:s') . " [GATEWAY] Return code: $returnVar\n";
    if ($returnVar !== 0) {
        $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Error: Return code $returnVar\n";
        if (!empty($output)) {
            $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Output: " . implode("\n", $output) . "\n";
        }
    } else {
        $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Success: Command executed\n";
    }
    @file_put_contents($logFile, $resultMsg, FILE_APPEND | LOCK_EX);
} elseif (!file_exists($scriptPath)) {
    // Файл не найден - используем HTTP как fallback
    $internalKey = md5('calltouch_internal_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));
    $processorUrl = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . 
                    $_SERVER['HTTP_HOST'] . 
                    '/local/handlers/calltouch/calltouch_native/calltouch_processor.php?mode=file&filepath=' . 
                    urlencode($filename) . '&internal_key=' . urlencode($internalKey);
    
    $logMsg = date('Y-m-d H:i:s') . " [GATEWAY] Script not found, using HTTP fallback: $processorUrl\n";
    @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
    
    if (function_exists('curl_init')) {
        $ch = curl_init($processorUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        @curl_exec($ch);
        curl_close($ch);
    }
} else {
    // Формируем команду с абсолютными путями
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        $cmd = sprintf(
            'start /B %s %s %s >> %s 2>&1',
            escapeshellarg($phpPath),
            escapeshellarg($scriptPath),
            escapeshellarg($filename),
            escapeshellarg($logFile)
        );
    } else {
        // Linux/Unix - используем nohup для надежности
        $cmd = sprintf(
            'cd %s && nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg(dirname($scriptPath)),
            escapeshellarg($phpPath),
            escapeshellarg(basename($scriptPath)),
            escapeshellarg($filename),
            escapeshellarg($logFile)
        );
    }

    // Логируем попытку запуска
    $logMsg = date('Y-m-d H:i:s') . " [GATEWAY] Executing: $cmd\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] PHP Path: $phpPath\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Script Path: $scriptPath\n";
    $logMsg .= date('Y-m-d H:i:s') . " [GATEWAY] File: $filename\n";
    @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);

    // Запускаем команду
    $output = [];
    $returnVar = 0;
    @exec($cmd, $output, $returnVar);

    // Логируем результат
    $resultMsg = date('Y-m-d H:i:s') . " [GATEWAY] Return code: $returnVar\n";
    if ($returnVar !== 0) {
        $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Error: Return code $returnVar\n";
        if (!empty($output)) {
            $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Output: " . implode("\n", $output) . "\n";
        }
    } else {
        $resultMsg .= date('Y-m-d H:i:s') . " [GATEWAY] Success: Command executed\n";
    }
    @file_put_contents($logFile, $resultMsg, FILE_APPEND | LOCK_EX);
    
    // Альтернативный способ: если exec не работает, можно использовать HTTP запрос
    // Раскомментируйте, если exec не работает:
    /*
    $processorUrl = 'http' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 's' : '') . '://' . 
                    $_SERVER['HTTP_HOST'] . 
                    '/local/handlers/calltouch/calltouch_native/calltouch_processor.php?mode=file&filepath=' . 
                    urlencode($filename);
    $ch = curl_init($processorUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Не ждем ответа
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    @curl_exec($ch);
    curl_close($ch);
    */
}
