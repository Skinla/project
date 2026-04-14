<?php
// queue_manager.php
// Менеджер очередей для управления файлами на разных этапах

require_once __DIR__ . '/logger_and_queue.php';
require_once __DIR__ . '/data_type_detector.php';
require_once __DIR__ . '/normalizers/normalizer_factory.php';
require_once __DIR__ . '/lead_processor.php';

/**
 * Обрабатывает все этапы очереди
 */
function processAllQueues($config) {
    logMessage("queue_manager: начинаем обработку всех очередей", $config['global_log'], $config);
    
    // Этап 1: Определение типа данных
    processRawFiles($config);
    
    // Этап 2: Нормализация данных
    processDetectedFiles($config);
    
    // Этап 3: Создание лидов
    processNormalizedFiles($config);
    
    logMessage("queue_manager: обработка всех очередей завершена", $config['global_log'], $config);
}

/**
 * Обрабатывает файлы с определенным типом и нормализует их
 */
function processDetectedFiles($config) {
    $detectedDir = $config['queue_dir'] . '/detected';
    $normalizedDir = $config['queue_dir'] . '/normalized';
    
    // Создаем папки если их нет
    if (!is_dir($detectedDir)) {
        mkdir($detectedDir, 0777, true);
    }
    if (!is_dir($normalizedDir)) {
        mkdir($normalizedDir, 0777, true);
    }
    
    $detectedFiles = glob($detectedDir . '/*.json');
    
    if (empty($detectedFiles)) {
        logMessage("queue_manager: нет файлов в папке detected", $config['global_log'], $config);
        return;
    }
    
    logMessage("queue_manager: найдено " . count($detectedFiles) . " файлов для нормализации", $config['global_log'], $config);
    
    foreach ($detectedFiles as $detectedFile) {
        try {
            $detectedData = json_decode(file_get_contents($detectedFile), true);
            
            if (!$detectedData) {
                logMessage("queue_manager: ошибка декодирования JSON в файле " . basename($detectedFile), $config['global_log'], $config);
                // Копируем проблемный detected-файл в очередь ошибок, оригинал оставляем
                copyToQueueErrors($detectedFile, $config);
                continue;
            }
            
            $normalizerFile = $detectedData['normalizer_file'] ?? 'generic_normalizer.php';
            $rawData = $detectedData['raw_data'] ?? [];
            
            // Создаем нормализатор по имени файла
            $normalizer = NormalizerFactory::createNormalizerByFile($normalizerFile, $config);
            
            // Нормализуем данные
            $normalizedData = $normalizer->normalize($rawData);

            // Прокидываем ссылку на исходный raw-файл в нормализованные данные
            if (isset($detectedData['raw_file_path'])) {
                $normalizedData['raw_file_path'] = $detectedData['raw_file_path'];
            }
            if (isset($detectedData['raw_file_name'])) {
                $normalizedData['raw_file_name'] = $detectedData['raw_file_name'];
            }
            // Для трассировки сохраняем имя detected-файла
            $normalizedData['detected_file_name'] = basename($detectedFile);
            
            // Сохраняем нормализованные данные
            $normalizedFile = saveToQueue($normalizedData, $normalizedDir, 'normalized_');
            
            // Удаляем файл с определенным типом
            unlink($detectedFile);
            
            logMessage("queue_manager: данные нормализованы (нормализатор: $normalizerFile) для файла " . basename($detectedFile) . " -> " . basename($normalizedFile), $config['global_log'], $config);
            
        } catch (Exception $e) {
            logMessage("queue_manager: ошибка нормализации файла " . basename($detectedFile) . ": " . $e->getMessage(), $config['global_log'], $config);
            // Копируем проблемный detected-файл в очередь ошибок, оригинал оставляем
            copyToQueueErrors($detectedFile, $config);
        }
    }
}

/**
 * Получает статистику по всем очередям
 */
function getQueueStats($config) {
    $stats = [
        'raw' => 0,
        'detected' => 0,
        'normalized' => 0,
        'processed' => 0,
        'duplicates' => 0,
        'failed' => 0
    ];
    
    $queueDirs = [
        'raw' => $config['queue_dir'] . '/raw',
        'detected' => $config['queue_dir'] . '/detected',
        'normalized' => $config['queue_dir'] . '/normalized',
        'processed' => $config['queue_dir'] . '/processed',
        'duplicates' => $config['queue_dir'] . '/duplicates',
        'failed' => $config['queue_dir'] . '/failed'
    ];
    
    foreach ($queueDirs as $stage => $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*.json');
            $stats[$stage] = count($files);
        }
    }
    
    return $stats;
}

/**
 * Очищает старые файлы из очередей
 */
function cleanupOldQueueFiles($config, $daysOld = 7) {
    $queueDirs = [
        'processed' => $config['queue_dir'] . '/processed',
        'duplicates' => $config['queue_dir'] . '/duplicates',
        'failed' => $config['queue_dir'] . '/failed'
    ];
    
    $cutoffTime = time() - ($daysOld * 24 * 60 * 60);
    $cleanedCount = 0;
    
    foreach ($queueDirs as $stage => $dir) {
        if (is_dir($dir)) {
            $files = glob($dir . '/*.json');
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                    $cleanedCount++;
                }
            }
        }
    }
    
    logMessage("queue_manager: очищено $cleanedCount старых файлов (старше $daysOld дней)", $config['global_log'], $config);
    
    return $cleanedCount;
}

/**
 * Повторная обработка файлов из папки failed
 */
function retryFailedFiles($config) {
    $failedDir = $config['queue_dir'] . '/failed';
    $rawDir = $config['queue_dir'] . '/raw';
    
    if (!is_dir($failedDir)) {
        logMessage("queue_manager: папка failed не существует", $config['global_log'], $config);
        return 0;
    }
    
    $failedFiles = glob($failedDir . '/*.json');
    
    if (empty($failedFiles)) {
        logMessage("queue_manager: нет файлов в папке failed", $config['global_log'], $config);
        return 0;
    }
    
    $retriedCount = 0;
    
    foreach ($failedFiles as $failedFile) {
        try {
            $failedData = json_decode(file_get_contents($failedFile), true);
            
            if (!$failedData) {
                continue;
            }
            
            // Определяем на каком этапе произошла ошибка
            if (isset($failedData['type'])) {
                // Ошибка на этапе нормализации - отправляем в detected
                $detectedDir = $config['queue_dir'] . '/detected';
                if (!is_dir($detectedDir)) {
                    mkdir($detectedDir, 0777, true);
                }
                
                $newFile = $detectedDir . '/' . basename($failedFile);
                file_put_contents($newFile, json_encode($failedData, JSON_UNESCAPED_UNICODE));
                
            } elseif (isset($failedData['normalized_data'])) {
                // Ошибка на этапе создания лида - отправляем в normalized
                $normalizedDir = $config['queue_dir'] . '/normalized';
                if (!is_dir($normalizedDir)) {
                    mkdir($normalizedDir, 0777, true);
                }
                
                $newFile = $normalizedDir . '/' . basename($failedFile);
                file_put_contents($newFile, json_encode($failedData['normalized_data'], JSON_UNESCAPED_UNICODE));
                
            } else {
                // Ошибка на этапе определения типа - отправляем в raw
                if (!is_dir($rawDir)) {
                    mkdir($rawDir, 0777, true);
                }
                
                $newFile = $rawDir . '/' . basename($failedFile);
                file_put_contents($newFile, json_encode($failedData, JSON_UNESCAPED_UNICODE));
            }
            
            // Удаляем из failed
            unlink($failedFile);
            $retriedCount++;
            
            logMessage("queue_manager: файл отправлен на повторную обработку: " . basename($failedFile), $config['global_log'], $config);
            
        } catch (Exception $e) {
            logMessage("queue_manager: ошибка при повторной обработке файла " . basename($failedFile) . ": " . $e->getMessage(), $config['global_log'], $config);
        }
    }
    
    logMessage("queue_manager: отправлено на повторную обработку $retriedCount файлов", $config['global_log'], $config);
    
    return $retriedCount;
}

// Поддержка запуска из CLI: php queue_manager.php
if (PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    
    if (isset($argv[1])) {
        $config = require __DIR__ . '/config.php';
        
        switch ($argv[1]) {
            case 'run':
                processAllQueues($config);
                echo "OK\n";
                break;
                
            case 'stats':
                $stats = getQueueStats($config);
                echo "Статистика очередей:\n";
                echo "  Raw: {$stats['raw']}\n";
                echo "  Detected: {$stats['detected']}\n";
                echo "  Normalized: {$stats['normalized']}\n";
                echo "  Processed: {$stats['processed']}\n";
                echo "  Duplicates: {$stats['duplicates']}\n";
                echo "  Failed: {$stats['failed']}\n";
                break;
                
            case 'cleanup':
                $days = isset($argv[2]) ? (int)$argv[2] : 7;
                $cleaned = cleanupOldQueueFiles($config, $days);
                echo "Очищено $cleaned файлов\n";
                break;
                
            case 'retry':
                $retried = retryFailedFiles($config);
                echo "Отправлено на повторную обработку $retried файлов\n";
                break;
                
            default:
                echo "Использование: php queue_manager.php [run|stats|cleanup|retry]\n";
                break;
        }
    }
}
