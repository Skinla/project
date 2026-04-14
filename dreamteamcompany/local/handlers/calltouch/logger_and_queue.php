<?php
// logger_and_queue.php

if (!function_exists('logMessage')) {
function logMessage($message, $logFile, $config)
{
    $logsDir = $config['logs_dir'];
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0777, true);
    }
    $fullPath = $logsDir . '/' . $logFile;

    if (file_exists($fullPath) && filesize($fullPath) > $config['max_log_size']) {
        @unlink($fullPath);
    }

    $date = date('[Y-m-d H:i:s]');
    file_put_contents($fullPath, $date . ' ' . $message . PHP_EOL, FILE_APPEND);
    }
}

/**
 * Сохранить запрос в очередь
 */
function saveRequestToQueue($data, $config)
{
    $queueDir = $config['queue_dir'];
    
    // Проверяем и создаем директорию с правами
    if (!is_dir($queueDir)) {
        if (!mkdir($queueDir, 0777, true)) {
            $error = error_get_last();
            logMessage("Ошибка создания директории очереди: " . $error['message'], $config['global_log'], $config);
            return false;
    }
    }
    
    // Проверяем права доступа
    if (!is_writable($queueDir)) {
        if (!chmod($queueDir, 0777)) {
            $error = error_get_last();
            logMessage("Ошибка изменения прав доступа директории очереди: " . $error['message'], $config['global_log'], $config);
            return false;
        }
    }
    
    // Генерируем уникальное имя файла
    $maxAttempts = 5;
    $attempt = 0;
    $filePath = '';
    
    do {
        $timestamp = date('Ymd_His');
        $random = substr(md5(uniqid(mt_rand(), true)), 0, 13);
        $fileName = "{$timestamp}_{$random}.json";
        $filePath = $queueDir . '/' . $fileName;
        $attempt++;
        
        // Если файл уже существует, пробуем снова
        if (file_exists($filePath)) {
            if ($attempt >= $maxAttempts) {
                logMessage("Не удалось создать уникальное имя файла после $maxAttempts попыток", $config['global_log'], $config);
                return false;
            }
            usleep(1000); // Небольшая задержка между попытками
            continue;
        }
        
        // Пытаемся создать файл с блокировкой
        $fp = @fopen($filePath, 'x');
        if ($fp === false) {
            if ($attempt >= $maxAttempts) {
                logMessage("Не удалось создать файл очереди после $maxAttempts попыток", $config['global_log'], $config);
                return false;
            }
            usleep(1000);
            continue;
        }
        
        // Записываем данные
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (fwrite($fp, $jsonData) === false) {
            fclose($fp);
            unlink($filePath);
            logMessage("Ошибка записи в файл очереди", $config['global_log'], $config);
            return false;
        }
        
        fclose($fp);
        break;
        
    } while ($attempt < $maxAttempts);
    
    return $filePath;
}

/**
 * Получаем список .json-файлов из очереди
 */
function getQueueFiles($config)
{
    $queueDir = $config['queue_dir'];
    if (!is_dir($queueDir)) {
        return [];
    }
    $files = glob($queueDir . '/*.json');
    return $files ?: [];
}

/**
 * Удаляем файл из очереди
 */
function removeQueueFile($filepath)
{
    if (file_exists($filepath)) {
        unlink($filepath);
    }
}
function logRawRequest($rawData, $config)
{
    $logsDir = $config['logs_dir'];
    if (!is_dir($logsDir)) {
        mkdir($logsDir, 0777, true);
    }
    $rawLogFile = $logsDir . '/raw_requests.log';

    // Проверяем размер
    if (file_exists($rawLogFile) && filesize($rawLogFile) > 2 * 1024 * 1024) {
        @unlink($rawLogFile);
    }

    // Если файла нет, создаём
    if (!file_exists($rawLogFile)) {
        file_put_contents($rawLogFile, "=== Start of raw requests log ===\n");
    }

    $date = date('[Y-m-d H:i:s]');
    $record = $date . " " . $rawData . "\n";
    file_put_contents($rawLogFile, $record, FILE_APPEND);
}

/**
 * Перемещаем файл в папку failed
 */
if (!function_exists('moveToFailed')) {
function moveToFailed($filePath, $config, $reason = null) {
    $failedDir = $config['queue_dir'] . '/failed';
    
    // Check if failed directory exists and create it if needed
    if (!is_dir($failedDir)) {
        if (!mkdir($failedDir, 0777, true)) {
            $error = error_get_last();
            logMessage("Ошибка создания директории failed: " . $error['message'], $config['global_log'], $config);
            return false;
        }
        logMessage("Создана директория failed", $config['global_log'], $config);
    }
    
    $fileName = basename($filePath);
    $newPath = $failedDir . '/' . $fileName;
    
    // Check if source file exists
    if (!file_exists($filePath)) {
        logMessage("Файл не существует: $filePath", $config['global_log'], $config);
        return false;
    }

    // Check and fix file permissions
    if (!is_writable($filePath)) {
        if (!chmod($filePath, 0666)) {
            $error = error_get_last();
            logMessage("Ошибка изменения прав доступа файла: " . $error['message'], $config['global_log'], $config);
            return false;
        }
        logMessage("Изменены права доступа файла: $filePath", $config['global_log'], $config);
    }

    // Check and fix directory permissions
    if (!is_writable($failedDir)) {
        if (!chmod($failedDir, 0777)) {
            $error = error_get_last();
            logMessage("Ошибка изменения прав доступа директории: " . $error['message'], $config['global_log'], $config);
            return false;
        }
        logMessage("Изменены права доступа директории: $failedDir", $config['global_log'], $config);
    }
    
    // Check disk space
    $freeSpace = disk_free_space($failedDir);
    $fileSize = filesize($filePath);
    if ($freeSpace < $fileSize * 2) { // Leave some buffer
        logMessage("Недостаточно места на диске для перемещения файла", $config['global_log'], $config);
        return false;
    }
    
    // Move file
    if (!rename($filePath, $newPath)) {
        $error = error_get_last();
        logMessage("Ошибка перемещения файла в failed: " . $error['message'], $config['global_log'], $config);
        return false;
    }

    logMessage("Файл успешно перемещен в папку failed: $fileName", $config['global_log'], $config);
    
    // Отправляем уведомление в чат "Ошибки по рекламе"
    try {
        // Подключаем функции для работы с чатами
        if (file_exists(__DIR__ . '/chat_notifications.php')) {
            require_once __DIR__ . '/chat_notifications.php';
            
            // Определяем причину ошибки
            if (!$reason) {
                $reason = "Неизвестная ошибка";
                if (file_exists($newPath)) {
                    $fileContent = file_get_contents($newPath);
                    $data = json_decode($fileContent, true);
                    
                    if ($data) {
                        $domain = $data['source_domain'] ?? 'unknown.domain';
                        $reason = "Не найден элемент SP=1136 для домена '$domain'";
                    } else {
                        $reason = "Ошибка декодирования JSON";
                    }
                }
            }
            
            // Отправляем уведомление
            sendFailedFileNotification($fileName, $reason, $config);
        }
    } catch (Exception $e) {
        logMessage("Ошибка при отправке уведомления в чат: " . $e->getMessage(), $config['global_log'], $config);
    }
    
    return true;
    }
}