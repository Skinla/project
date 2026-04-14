<?php
/**
 * calltouch_processor.php
 * Основной процессор очереди с использованием нативного API Bitrix24
 * 
 * Обрабатывает файлы из очереди и создает/обновляет лиды в Bitrix24
 */

// Загружаем конфиг для определения путей к логам
$configFilePath = __DIR__ . '/calltouch_config.php';
$calltouchConfig = [];
if (file_exists($configFilePath)) {
    $calltouchConfig = include $configFilePath;
}
if (!is_array($calltouchConfig)) {
    $calltouchConfig = [];
}

// Определяем пути к логам из конфига или используем значения по умолчанию
$logsDir = $calltouchConfig['logs_dir'] ?? (__DIR__ . '/calltouch_logs');
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}

// Создаем лог-файл сразу, до инициализации Bitrix
$earlyLogFile = $logsDir . '/processor_start.log';
$maxLogSize = $calltouchConfig['max_log_size'] ?? (2 * 1024 * 1024);
// Ротация earlyLogFile
if (file_exists($earlyLogFile) && filesize($earlyLogFile) > $maxLogSize) {
    @file_put_contents($earlyLogFile, "");
}
@file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] Script started\n", FILE_APPEND | LOCK_EX);
@file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] PHP SAPI: " . php_sapi_name() . "\n", FILE_APPEND | LOCK_EX);
@file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] GET params: " . print_r($_GET, true) . "\n", FILE_APPEND | LOCK_EX);
@file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] SERVER: " . print_r(['REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '', 'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? ''], true) . "\n", FILE_APPEND | LOCK_EX);

// Подключаем Bitrix ядро
try {
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] Loading bitrix_init.php\n", FILE_APPEND | LOCK_EX);
    require_once(__DIR__ . '/bitrix_init.php');
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] Bitrix initialized successfully\n", FILE_APPEND | LOCK_EX);
} catch (Exception $e) {
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [ERROR] Bitrix init failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [ERROR] Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
    exit("Bitrix initialization failed: " . $e->getMessage() . "\n");
} catch (Error $e) {
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [ERROR] Bitrix init fatal error: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [ERROR] Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
    exit("Bitrix initialization fatal error: " . $e->getMessage() . "\n");
}

// Подключаем вспомогательные функции
try {
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] Loading helper functions\n", FILE_APPEND | LOCK_EX);
    require_once(__DIR__ . '/helper_functions.php');
    require_once(__DIR__ . '/iblock_functions.php');
    require_once(__DIR__ . '/lead_functions.php');
    require_once(__DIR__ . '/lead_prepare.php');
    require_once(__DIR__ . '/chat_notifications.php');
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] All functions loaded\n", FILE_APPEND | LOCK_EX);
} catch (Exception $e) {
    @file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [ERROR] Functions load failed: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    exit("Functions load failed: " . $e->getMessage() . "\n");
}

// --------------------------------------------------
// 1. Режим запуска, список файлов
// --------------------------------------------------
$isCli = (php_sapi_name() === 'cli');

// Обработка аргументов командной строки (CLI режим)
if ($isCli && !empty($argv[1])) {
    $_GET['mode'] = 'file';
    $_GET['filepath'] = $argv[1];
}

$runAll = $_GET['run'] ?? '';
$mode   = $_GET['mode'] ?? '';

@file_put_contents($earlyLogFile, date('Y-m-d H:i:s') . " [START] Mode: $mode, RunAll: $runAll\n", FILE_APPEND | LOCK_EX);

$queueDir = __DIR__ . '/queue';
if (!is_dir($queueDir)) {
    @mkdir($queueDir, 0777, true);
    if (!is_dir($queueDir)) {
        exit("Queue folder not found and cannot be created.\n");
    }
}

// Конфиг уже загружен выше, используем его
// Определяем пути к логам из конфига или используем значения по умолчанию
$logsDir = $calltouchConfig['logs_dir'] ?? (__DIR__ . '/calltouch_logs');
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
$commonLogFile = $logsDir . '/' . ($calltouchConfig['global_log'] ?? 'calltouch_common.log');
$siteLogsDir = $logsDir;
$maxSiteLogSize = $calltouchConfig['max_log_size'] ?? (2 * 1024 * 1024);
// Ротация commonLogFile
if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
    @file_put_contents($commonLogFile, "");
}

// --------------------------------------------------
// 2. Список файлов
// --------------------------------------------------
$files = [];

// Режим обработки конкретного файла (из аргумента CLI или параметра filepath)
if ($mode === 'file') {
    $fpath = $_GET['filepath'] ?? '';
    if ($fpath) {
        // Если передан абсолютный путь - используем его
        if (is_file($fpath)) {
            $files[] = $fpath;
        } else {
            // Поддержка относительных путей и расширенных имён из ошибок
            $base = basename($fpath);
            if (preg_match('/(ct_\d{8}_\d{6}_[0-9a-f]+\.json)$/i', $base, $m)) {
                $base = $m[1];
            }
            $full = $queueDir . '/' . $base;
            if (is_file($full)) {
                $files[] = $full;
            }
        }
    }
} 
// Режим обработки всех файлов или конкретного файла через HTTP
elseif ($runAll === 'all') {
    $fileParam = $_GET['file'] ?? '';
    if ($fileParam) {
        // Обработка конкретного файла через HTTP параметр
        $full = $queueDir . '/' . basename($fileParam);
        if (is_file($full)) {
            $files[] = $full;
        }
    } else {
        // Обработка всех файлов из очереди
        $files = glob($queueDir . '/*.json');
        $files = $files ?: [];
    }
} 
// Если запущен через exec без параметров или в CLI без аргументов - обрабатываем все файлы
elseif ($isCli && empty($runAll) && empty($mode)) {
    $files = glob($queueDir . '/*.json');
    $files = $files ?: [];
} 
// Если ничего не указано - выводим справку
else {
    $errorMsg = "Usage:\n";
    $errorMsg .= "  CLI: php calltouch_processor.php [filepath]\n";
    $errorMsg .= "  HTTP: ?run=all [&file=filename.json]\n";
    $errorMsg .= "  HTTP: ?mode=file&filepath=filename.json\n";
    exit($errorMsg);
}

if (empty($files)) {
    // Логируем попытку обработки без файлов
    $logMsg = sprintf(
        "No files to process. Mode: %s, RunAll: %s, IsCLI: %s, QueueDir: %s",
        $mode ?: 'none',
        $runAll ?: 'none',
        $isCli ? 'yes' : 'no',
        $queueDir
    );
    // Ротация перед записью
    if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
        @file_put_contents($commonLogFile, "");
    }
    @file_put_contents($commonLogFile, date('Y-m-d H:i:s') . " [INFO] " . $logMsg . "\n", FILE_APPEND | LOCK_EX);
    exit("No files to process.\n");
}

    // Логируем начало обработки
$logMsg = sprintf(
    "Starting processing. Files: %d, Mode: %s, RunAll: %s, IsCLI: %s",
    count($files),
    $mode ?: 'none',
    $runAll ?: 'none',
    $isCli ? 'yes' : 'no'
);
// Ротация перед записью
if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
    @file_put_contents($commonLogFile, "");
}
@file_put_contents($commonLogFile, date('Y-m-d H:i:s') . " [INFO] " . $logMsg . "\n", FILE_APPEND | LOCK_EX);

// --------------------------------------------------
// 3. Очистка старых файлов ошибок
// --------------------------------------------------
cleanupOldErrorFiles($calltouchConfig);

// --------------------------------------------------
// 4. Основной перебор
// --------------------------------------------------
foreach ($files as $jsonFilePath) {
    $fileBase = basename($jsonFilePath);
    $raw = file_get_contents($jsonFilePath);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        moveFileToErrors($jsonFilePath, 'invalid_json', $calltouchConfig);
        continue;
    }

    // Новая логика fixCallUrlAndUrl:
    fixCallUrlAndUrl($data);

    $leadtype  = $data['leadtype']   ?? '';
    $callPhase = $data['callphase']  ?? '';

    if (empty($data['callerphone']) && empty($data['phonenumber'])) {
        moveFileToErrors($jsonFilePath, 'no_phone; callerphone=' . ($data['callerphone'] ?? ''), $calltouchConfig);
        continue;
    }

    // Нормализация - всегда формат +7XXXXXXXXXX (только цифры и +)
    $callerRaw = $data['callerphone'] ?? '';
    $phone     = normalizePhone($callerRaw);
    
    // ПРИНУДИТЕЛЬНАЯ ПРОВЕРКА: если после нормализации нет +7, добавляем принудительно
    if (empty($phone)) {
        // Если normalizePhone вернул пустую строку, пытаемся нормализовать вручную
        $phoneDigits = preg_replace('/\D+/', '', $callerRaw);
        $digitsLen = strlen($phoneDigits);
        if ($digitsLen === 11 && ($phoneDigits[0] === '7' || $phoneDigits[0] === '8')) {
            $phone = '+7' . substr($phoneDigits, 1);
        } elseif ($digitsLen === 10) {
            $phone = '+7' . $phoneDigits;
        }
    }
    
    // Финальная проверка: телефон должен начинаться с +7 и содержать 10 цифр после +7
    if (!empty($phone) && !preg_match('/^\+7\d{10}$/', $phone)) {
        $phoneDigits = preg_replace('/\D+/', '', $phone);
        $digitsLen = strlen($phoneDigits);
        if ($digitsLen === 11 && $phoneDigits[0] === '7') {
            $phone = '+7' . substr($phoneDigits, 1);
        } elseif ($digitsLen === 10) {
            $phone = '+7' . $phoneDigits;
        } else {
            $phone = ''; // Не сохраняем некорректный телефон
        }
    }
    
    // Детальное логирование для отладки
    $digits = preg_replace('/\D+/', '', $callerRaw);
    $digitsLen = strlen($digits);
    $firstChar = $digitsLen > 0 ? substr($digits, 0, 1) : 'empty';
    $data['callerphone'] = $phone;
    // Логируем нормализацию телефона
    // Ротация перед записью
    if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
        @file_put_contents($commonLogFile, "");
    }
    @file_put_contents($commonLogFile, date('Y-m-d H:i:s') . " [DEBUG] Phone normalization: raw='$callerRaw' => digits='$digits' (len=$digitsLen, first='$firstChar') => normalized='$phone'\n", FILE_APPEND | LOCK_EX);

    $dialedRaw = $data['phonenumber'] ?? '';
    $dialedNumber = normalizePhone($dialedRaw);
    $data['phonenumber'] = $dialedNumber;

    // lead fields
    $leadConf = $calltouchConfig['lead'] ?? [];

    // Если leadtype!='request', проверяем фазу по конфигу (allowed_callphases)
    $configuredPhases = $calltouchConfig['allowed_callphases'] ?? [];
    $phasesToAllow = (is_array($configuredPhases) && !empty($configuredPhases))
        ? array_values(array_unique(array_map('strval', $configuredPhases)))
        : ['callconnected','callcompleted','calldisconnected','calllost'];
    if ($leadtype !== 'request') {
        if (!in_array($callPhase, $phasesToAllow, true)) {
            // Ротация перед записью
            if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
                @file_put_contents($commonLogFile, "");
            }
            file_put_contents(
                $commonLogFile,
                "[".date('Y-m-d H:i:s')."] file=$fileBase, callphase=$callPhase (allowed=".implode(',', $phasesToAllow).") => skip\n",
                FILE_APPEND
            );

            $siteLogFile = $siteLogsDir . '/calltouch_site_' . ($data['siteId'] ?? '') . '.log';
            if (!file_exists($siteLogFile)) {
                touch($siteLogFile);
            }
            if (file_exists($siteLogFile) && filesize($siteLogFile) > $maxSiteLogSize) {
                file_put_contents($siteLogFile, "");
            }
            $detail  = "==== " . date('Y-m-d H:i:s') . " ====\n"
                     . "File: $fileBase\n"
                     . "callphase=$callPhase (allowed=".implode(',', $phasesToAllow).") => skip creation.\n"
                     . "POST:\n" . print_r($data,true) . "\n\n";
            file_put_contents($siteLogFile, $detail, FILE_APPEND);

            moveFileToErrors($jsonFilePath, 'wrong_phase; callerphone=' . ($data['callerphone'] ?? ''), $calltouchConfig);
            continue;
        }
    }

    // Создаём/обновляем лид через нативный API
    $leadResult = createLeadDirectViaNativeFromCallTouch($data, $calltouchConfig);

    // Специальная обработка ошибки пары NAME+PROPERTY_199
    if (is_array($leadResult) && !empty($leadResult['ERROR_TYPE']) && $leadResult['ERROR_TYPE'] === 'name_property199_pair_not_found') {
        $reason = 'name_property199_pair_not_found, ' . ($leadResult['NAME'] ?? '') . ', ' . ($data['callerphone'] ?? $phone);
        moveFileToErrors($jsonFilePath, $reason, $calltouchConfig);
        continue;
    }

    // Извлекаем ID лида из результата
    $leadId = (is_array($leadResult) && !empty($leadResult['ID'])) ? (int)$leadResult['ID'] : ((is_numeric($leadResult) ? (int)$leadResult : 0));
    if (!$leadId) {
        // Ротация перед записью
        if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
            @file_put_contents($commonLogFile, "");
        }
        file_put_contents(
            $commonLogFile,
            "[".date('Y-m-d H:i:s')."] file=$fileBase => error create/update lead, callerphone=" . ($data['callerphone'] ?? $phone) . "\n",
            FILE_APPEND
        );
        moveFileToErrors($jsonFilePath, 'lead_creation_failed; callerphone=' . ($data['callerphone'] ?? $phone), $calltouchConfig);
        continue;
    }

    // Наблюдатели обрабатываются внутри функций создания/обновления лида

    // Лог
    // Ротация перед записью
    if (file_exists($commonLogFile) && filesize($commonLogFile) > $maxSiteLogSize) {
        @file_put_contents($commonLogFile, "");
    }
    file_put_contents(
        $commonLogFile,
        sprintf(
            "[%s] file=%s, siteId=%s, callphase=%s, entityType=lead, entityId=%s, dialedNumber=%s, phone=%s, leadtype=%s\n",
            date('Y-m-d H:i:s'),
            $fileBase,
            ($data['siteId'] ?? ''),
            $callPhase,
            $leadId,
            $dialedNumber,
            $phone,
            $leadtype
        ),
        FILE_APPEND
    );

    // Доп. лог по сайту
    $siteId = $data['siteId'] ?? '';
    $siteLogFile = $siteLogsDir . '/calltouch_site_'.$siteId.'.log';
    if (!file_exists($siteLogFile)) {
        touch($siteLogFile);
    }
    if (file_exists($siteLogFile) && filesize($siteLogFile) > $maxSiteLogSize) {
        file_put_contents($siteLogFile, "");
    }
    $detail  = "==== " . date('Y-m-d H:i:s') . " ====\n"
             . "File: $fileBase\n"
             . "EntityType=lead, EntityId=$leadId\n"
             . "dialedNumber=$dialedNumber\n"
             . "callerPhone=$phone\n"
             . "callphase=$callPhase\n"
             . "leadtype=$leadtype\n"
             . "POST:\n" . print_r($data,true) . "\n\n";
    file_put_contents($siteLogFile,$detail,FILE_APPEND);

    // Удаляем после успешной обработки
    removeFileWithLog($jsonFilePath, 'successful_processing', $calltouchConfig);
}

echo "Done\n";

