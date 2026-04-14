<?php
/**
 * Вспомогательные функции для проекта
 */

/**
 * Ротация лог-файла при превышении размера
 */
function rotateLogFile($logFile, $maxSize = 2 * 1024 * 1024) {
    if (file_exists($logFile) && filesize($logFile) > $maxSize) {
        @file_put_contents($logFile, "");
    }
}

/**
 * Логирование сообщений
 */
function logMessage($message, $logFile, $config = []) {
    // Если logFile - относительный путь, добавляем путь к логам из конфига
    if (!empty($config['logs_dir']) && !empty($logFile) && $logFile[0] !== '/') {
        $logsDir = $config['logs_dir'];
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0777, true);
        }
        $logFile = rtrim($logsDir, '/\\') . '/' . $logFile;
    }
    
    // Ротация лога перед записью
    $maxLogSize = $config['max_log_size'] ?? (2 * 1024 * 1024);
    rotateLogFile($logFile, $maxLogSize);
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

/**
 * Вычисляет дату-от с учётом периода. Возвращает строку в формате Y-m-d H:i:s или null для all
 */
function computeDateFromByPeriod(string $period): ?string {
    $period = strtolower(trim($period));
    $now = new DateTime('now');
    switch ($period) {
        case '30m':
            $dt = (clone $now)->modify('-30 minutes');
            break;
        case '1d':
            $dt = (clone $now)->modify('-1 day');
            break;
        case '30d':
            $dt = (clone $now)->modify('-30 days');
            break;
        case '1m':
            $dt = (clone $now)->modify('-1 month');
            break;
        case '3m':
            $dt = (clone $now)->modify('-3 months');
            break;
        case 'ytd':
            $dt = new DateTime(date('Y-01-01 00:00:00'));
            break;
        case 'all':
            return null;
        default:
            // fallback по умолчанию 30d
            $dt = (clone $now)->modify('-30 days');
    }
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Извлекаем только домен без протокола и параметров
 */
function getDomainUrl($fullUrl) {
    if (!$fullUrl) return $fullUrl;
    $parts = parse_url($fullUrl);
    if (!empty($parts['host'])) {
        return $parts['host']; // Возвращаем только домен без протокола
    }
    return $fullUrl;
}

/**
 * Возвращает предпочитаемый домен источника по данным CallTouch
 * Порядок приоритета:
 * 1) hostname (если задан и похож на домен)
 * 2) url
 * 3) callUrl
 * 4) siteName (если похоже на домен)
 * 5) subPoolName (как последний fallback)
 */
function getPreferredSourceDomain(array $data) {
    $looksLikeDomain = function(string $value): bool {
        $v = trim($value);
        // Признак домена: есть точка и только допустимые символы (включая кириллицу)
        return (bool)preg_match('/^[a-zа-я0-9.-]+\.[a-zа-я]{2,}$/iu', $v);
    };

    // Логирование отключено для этой функции (слишком много вызовов)
    // logMessage("getPreferredSourceDomain: hostname=" . ($data['hostname'] ?? 'empty') . ", url=" . ($data['url'] ?? 'empty') . ", callUrl=" . ($data['callUrl'] ?? 'empty') . ", siteName=" . ($data['siteName'] ?? 'empty') . ", subPoolName=" . ($data['subPoolName'] ?? 'empty'), 'calltouch_common.log', []);

    if (!empty($data['hostname']) && $looksLikeDomain((string)$data['hostname'])) {
        // logMessage("getPreferredSourceDomain: выбран hostname=" . $data['hostname'], 'calltouch_common.log', []);
        return strtolower((string)$data['hostname']);
    }

    if (!empty($data['url'])) {
        $url = (string)$data['url'];
        if ($looksLikeDomain($url) || strpos($url, 'http') === 0) {
            $domain = strtolower(getDomainUrl($url));
            // logMessage("getPreferredSourceDomain: выбран url=" . $domain, 'calltouch_common.log', []);
            return $domain;
        }
    }

    if (!empty($data['callUrl'])) {
        $callUrl = (string)$data['callUrl'];
        if ($looksLikeDomain($callUrl) || strpos($callUrl, 'http') === 0) {
            $domain = strtolower(getDomainUrl($callUrl));
            // logMessage("getPreferredSourceDomain: выбран callUrl=" . $domain, 'calltouch_common.log', []);
            return $domain;
        }
    }

    if (!empty($data['siteName'])) {
        $siteName = (string)$data['siteName'];
        $host = getDomainUrl($siteName);
        if ($looksLikeDomain($host)) {
            // logMessage("getPreferredSourceDomain: выбран siteName(host)=" . strtolower($host), 'calltouch_common.log', []);
            return strtolower($host);
        }
        if ($looksLikeDomain($siteName)) {
            // logMessage("getPreferredSourceDomain: выбран siteName=" . strtolower($siteName), 'calltouch_common.log', []);
            return strtolower($siteName);
        }
    }

    if (!empty($data['subPoolName'])) {
        // logMessage("getPreferredSourceDomain: выбран subPoolName=" . $data['subPoolName'], 'calltouch_common.log', []);
        return (string)$data['subPoolName'];
    }

    // logMessage("getPreferredSourceDomain: возвращаем 'unknown'", 'calltouch_common.log', []);
    return 'unknown';
}

/**
 * Нормализация телефона (8->+7, 7->+7, remove non-digits)
 * Всегда возвращает формат +7XXXXXXXXXX (только цифры и +)
 * Если формат не распознан, возвращает пустую строку
 */
if (!function_exists('normalizePhone')) {
function normalizePhone($raw) {
    if (empty($raw)) {
        return $raw;
    }
    
    // Извлекаем только цифры
    $digits = preg_replace('/\D+/', '', $raw);
    $digitsLen = strlen($digits);
    
    // Если уже начинается с +7 и содержит 10 цифр после +7, возвращаем как есть
    $cleaned = preg_replace('/[^\d+]/', '', $raw);
    if (preg_match('/^\+7\d{10}$/', $cleaned)) {
        return $cleaned;
    }
    
    // Обрабатываем 11-значные номера (начинаются с 8 или 7)
    if ($digitsLen === 11) {
        $firstChar = $digits[0];
        if ($firstChar === '7' || $firstChar === '8') {
            // Всегда возвращаем +7 для российских номеров
            return '+7' . substr($digits, 1);
        }
    }
    
    // Обрабатываем 10-значные номера (без кода страны)
    if ($digitsLen === 10) {
        return '+7' . $digits;
    }
    
    // Если формат не распознан, возвращаем пустую строку (не исходное значение)
    return '';
}
} // end if (!function_exists('normalizePhone'))

/**
 * Добавляем UTM-метки (utm_source, utm_medium, etc.)
 * Если есть в $data, переносим в поля Bitrix.
 */
function addUtmParameters(&$fields, $data) {
    // Маппинг UTM полей на поля Bitrix
    $utmMapping = [
        'utm_source' => 'UTM_SOURCE',
        'utm_medium' => 'UTM_MEDIUM', 
        'utm_campaign' => 'UTM_CAMPAIGN',
        'utm_content' => 'UTM_CONTENT',
        'utm_term' => 'UTM_TERM'
    ];
    
    foreach ($data as $k => $v) {
        if (stripos($k, 'utm_') === 0 && !empty($v)) {
            $utmKey = strtolower($k);
            if (isset($utmMapping[$utmKey])) {
                $fields[$utmMapping[$utmKey]] = $v;
                // logMessage("addUtmParameters: добавлено UTM поле {$utmMapping[$utmKey]} = $v", 'calltouch_common.log', []);
            } else {
                // Для других UTM полей используем верхний регистр
                $fields[strtoupper($k)] = $v;
                // logMessage("addUtmParameters: добавлено UTM поле " . strtoupper($k) . " = $v", 'calltouch_common.log', []);
            }
        }
    }
}

/**
 * Если [callUrl] пусто, а [subPoolName] есть,
 * подставляем subPoolName в callUrl.
 * Если [url] пуст, тоже подставляем subPoolName.
 */
function fixCallUrlAndUrl(&$data) {
    if (empty($data['callUrl']) && !empty($data['subPoolName'])) {
        $data['callUrl'] = $data['subPoolName'];
    }
    if (empty($data['url']) && !empty($data['subPoolName'])) {
        $data['url'] = $data['subPoolName'];
    }
}

/**
 * Перемещение файла в папку ошибок
 */
function moveFileToErrors($filePath, $errorType, $config = []) {
    // Используем errors_dir из конфига, иначе локальную папку
    $errorDir = $config['errors_dir'] ?? (__DIR__ . '/queue_errors');
    if (!is_dir($errorDir)) {
        mkdir($errorDir, 0777, true);
    }
    
    $fileName = basename($filePath);
    // Для имени файла используем безопасный короткий префикс причины
    $safeType = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)$errorType);
    // Если есть подробности вида "reason; details..." — обрежем по первой точке с запятой
    $semiPos = strpos($safeType, '_');
    if ($semiPos !== false) {
        $safeType = substr($safeType, 0, $semiPos);
    }
    if (strlen($safeType) > 40) {
        $safeType = substr($safeType, 0, 40);
    }
    $errorFileName = date('Ymd_His') . '_' . $safeType . '_' . $fileName;
    // Гарантируем уникальность и адекватную длину
    if (strlen($errorFileName) > 200) {
        $extPos = strrpos($fileName, '.');
        $ext = $extPos !== false ? substr($fileName, $extPos) : '';
        $base = $extPos !== false ? substr($fileName, 0, $extPos) : $fileName;
        $base = substr($base, -100);
        $errorFileName = date('Ymd_His') . '_' . $safeType . '_' . $base . $ext;
    }
    $errorFilePath = $errorDir . '/' . $errorFileName;
    
    // Перемещение с fallback на copy+unlink
    $moved = @rename($filePath, $errorFilePath);
    if (!$moved) {
        $moved = @copy($filePath, $errorFilePath);
        if ($moved) { @unlink($filePath); }
    }
    if ($moved) {
        logMessage("moveFileToErrors: файл $fileName перемещен в ошибки ($errorType)", $config['global_log'] ?? 'calltouch_common.log', $config);
        
        // Уведомление в чат об ошибке (если включено в конфиге)
        $errorHandling = $config['error_handling'] ?? [];
        $sendNotifications = (bool)($errorHandling['send_chat_notifications'] ?? true);
        
        if ($sendNotifications && function_exists('sendFailedFileNotification')) {
            $reason = $errorType;
            sendFailedFileNotification($errorFileName, $reason, $config);
        }
        
        return true;
    } else {
        logMessage("moveFileToErrors: ОШИБКА перемещения файла $fileName", $config['global_log'] ?? 'calltouch_common.log', $config);
        return false;
    }
}

/**
 * Удаление файла с логированием
 */
function removeFileWithLog($filePath, $reason, $config = []) {
    // При успешной обработке — удаляем
    $fileName = basename($filePath);
    if (@unlink($filePath)) {
        logMessage("removeFileWithLog: файл $fileName удален ($reason)", $config['global_log'] ?? 'calltouch_common.log', $config);
        return true;
    }
    logMessage("removeFileWithLog: ОШИБКА удаления файла $fileName", $config['global_log'] ?? 'calltouch_common.log', $config);
    return false;
}

/**
 * Очистка старых файлов ошибок
 */
function cleanupOldErrorFiles($config = []) {
    $errorHandling = $config['error_handling'] ?? [];
    
    if (!($errorHandling['cleanup_old_errors'] ?? false)) {
        return;
    }
    
    $errorDir = $config['errors_dir'] ?? __DIR__ . '/queue_errors';
    $retentionDays = $errorHandling['error_retention_days'] ?? 30;
    $maxFiles = $errorHandling['max_error_files'] ?? 1000;
    
    // Создаем папку если её нет
    if (!is_dir($errorDir)) {
        @mkdir($errorDir, 0777, true);
        if (!is_dir($errorDir)) {
            return; // Не удалось создать, пропускаем очистку
        }
    }
    
    $files = glob($errorDir . '/*.json');
    $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
    
    $deletedCount = 0;
    $totalFiles = count($files);
    
    // Сортируем файлы по времени модификации (старые первыми)
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    foreach ($files as $file) {
        $fileTime = filemtime($file);
        
        // Удаляем файлы старше retention_days
        if ($fileTime < $cutoffTime) {
            if (unlink($file)) {
                $deletedCount++;
            }
        }
        
        // Если файлов больше max_error_files, удаляем самые старые
        if ($totalFiles - $deletedCount > $maxFiles) {
            if (unlink($file)) {
                $deletedCount++;
            }
        }
    }
    
    if ($deletedCount > 0) {
        logMessage("cleanupOldErrorFiles: удалено $deletedCount файлов ошибок", $config['global_log'] ?? 'calltouch_common.log', $config);
    }
}

/**
 * Работа с индексом ctCallerId → leadId
 */
function loadCtCallerIdIndex(string $indexFile): array {
    if (!file_exists($indexFile)) {
        return [];
    }
    $raw = @file_get_contents($indexFile);
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function saveCtCallerIdIndex(string $indexFile, array $index): void {
    @file_put_contents($indexFile, json_encode($index, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function pruneCtCallerIdIndex(array &$index, string $retention): void {
    $from = computeDateFromByPeriod($retention);
    if ($from === null) {
        return; // all — ничего не чистим
    }
    $threshold = strtotime($from);
    foreach ($index as $key => $row) {
        $ts = isset($row['ts']) ? (int)$row['ts'] : 0;
        if ($ts < $threshold) {
            unset($index[$key]);
        }
    }
}

