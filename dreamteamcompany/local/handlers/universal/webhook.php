<?php
// webhook.php
//
// Этот обработчик принимает входящие запросы (POST/JSON), определяет домен по нескольким источникам,
// сохраняет сырые данные в очередь, затем запускает обработку очереди через новую архитектуру.
// Используем модульную структуру: config.php, logger_and_queue.php, duplicate_checker.php, 
// queue_manager.php, data_type_detector.php, normalizers/, lead_processor.php.

// Обработка ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Проверяем наличие необходимых файлов
$requiredFiles = [
    'universal-system/config.php',
    'universal-system/logger_and_queue.php',
    'universal-system/duplicate_checker.php',
    'universal-system/queue_manager.php'
];

$missingFiles = [];
foreach ($requiredFiles as $file) {
    if (!file_exists(__DIR__ . '/' . $file)) {
        $missingFiles[] = $file;
    }
}

if (!empty($missingFiles)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required files: ' . implode(', ', $missingFiles),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

try {
require_once __DIR__ . '/universal-system/config.php';
require_once __DIR__ . '/universal-system/logger_and_queue.php';
require_once __DIR__ . '/universal-system/request_tracker.php';
require_once __DIR__ . '/universal-system/queue_manager.php';
require_once __DIR__ . '/universal-system/chat_notifications.php';
require_once __DIR__ . '/universal-system/error_handler.php';

$config = require __DIR__ . '/universal-system/config.php';
$errorHandler = new ErrorHandler($config);
    
    // Проверяем, что функции загружены
    if (!function_exists('logMessage')) {
        throw new Exception('Функция logMessage не загружена');
    }
    if (!function_exists('moveToFailed')) {
        throw new Exception('Функция moveToFailed не загружена');
    }
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => 'error',
        'message' => 'Configuration error: ' . $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// Обработка тестового запроса от Тильды для регистрации вебхука
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверяем POST параметр test
    if (isset($_POST['test']) && $_POST['test'] === 'test') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'message' => 'webhook_available']);
        exit;
    }
    
    // Проверяем JSON параметр test
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $jsonData = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($jsonData['test']) && $jsonData['test'] === 'test') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'ok', 'message' => 'webhook_available']);
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Для GET запросов тоже сохраняем данные и обрабатываем
    // Получаем данные из $_GET
    $inputData = $_GET;
    
    // Логируем GET запрос
    $getParams = http_build_query($_GET);
    logRawRequest($getParams, $config);
    
    // Определяем домен для GET запроса
    $domain = 'unknown.domain';
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $u = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($u['host'])) {
            $domain = $u['host'];
        }
    } elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
        $u = parse_url($_SERVER['HTTP_ORIGIN']);
        if (!empty($u['host'])) {
            $domain = $u['host'];
        }
    }
    
    // Если в GET есть параметр domain, используем его
    if (!empty($inputData['domain'])) {
        $domain = $inputData['domain'];
    }
    
    $inputData['source_domain'] = $domain;
    logMessage("GET запрос обработан, domain = $domain", $config['global_log'], $config);
    
    // Продолжаем обработку как обычный запрос
    goto process_request;
}



// Создаем лог-файл для отслеживания обработки очереди
$queueLogFile = $config['logs_dir'] . '/queue_processing.log';

// Функция для логирования обработки очереди
function logQueueProcessing($message, $logFile) {
    if (empty($logFile)) {
        // error_log("logQueueProcessing: пустой путь к файлу лога");
        return;
    }
    
    $timestamp = date('[Y-m-d H:i:s] ');
    file_put_contents($logFile, $timestamp . $message . "\n", FILE_APPEND);
}

process_request:

// --- Считываем сырое тело запроса ---
$rawBody = file_get_contents('php://input');
logRawRequest($rawBody, $config);

// Если это не GET запрос, инициализируем inputData
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    $inputData = [];
}

// 1) JSON только при явном JSON-контенте
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $dec = json_decode($rawBody, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($dec) && count($dec) > 0) {
        $inputData = $dec;
        logMessage("Parsed JSON body", $config['global_log'], $config);
    }
}

// 2) form-urlencoded
if (empty($inputData)
    && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/x-www-form-urlencoded') !== false
) {
    // Декодируем URL-encoded данные перед парсингом
    $decodedBody = urldecode($rawBody);
    parse_str($decodedBody, $parsed);
    if (!empty($parsed) && is_array($parsed)) {
        $inputData = $parsed;
        logMessage("Parsed form-urlencoded body (decoded)", $config['global_log'], $config);
        
        // ДИАГНОСТИКА: проверяем кодировку
        $phoneField = $inputData['Phone'] ?? '';
        if (!empty($phoneField)) {
            logMessage("🔍 WEBHOOK: телефон извлечен: '$phoneField'", $config['global_log'], $config);
        }
        
        // Проверяем, есть ли искаженные символы
        $hasQuestionMarks = false;
        foreach ($inputData as $key => $value) {
            if (is_string($value) && strpos($value, '?') !== false) {
                $hasQuestionMarks = true;
                break;
            }
        }
        
        if ($hasQuestionMarks) {
            logMessage("⚠️ WEBHOOK: обнаружены искаженные символы в данных", $config['global_log'], $config);
        }
    }
}

// 3) multipart/form-data или обычный $_POST
if (empty($inputData) && !empty($_POST)) {
    $inputData = $_POST;
    logMessage("Taken data from \$_POST", $config['global_log'], $config);
}

// 4) последний шанс — сохраняем сырой
if (empty($inputData)) {
    $inputData = ['rawBody' => $rawBody];
    logMessage("Empty inputData, storing rawBody", $config['global_log'], $config);
}

// --- Определяем домен (source_domain) ---
// Если это не GET запрос, определяем домен заново
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // Начальное значение
    $domain = 'unknown.domain';
} else {
    // Для GET запросов домен уже определен выше
    $domain = $inputData['source_domain'] ?? 'unknown.domain';
}

// Определяем домен только для POST запросов
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    // 1) Попытка: HTTP_REFERER
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $u = parse_url($_SERVER['HTTP_REFERER']);
        if (!empty($u['host'])) {
            $domain = $u['host'];
        }
    }
    // 2) Если не найден, HTTP_ORIGIN
    elseif (!empty($_SERVER['HTTP_ORIGIN'])) {
        $u = parse_url($_SERVER['HTTP_ORIGIN']);
        if (!empty($u['host'])) {
            $domain = $u['host'];
        }
    }

    // 3) Если всё ещё unknown, смотрим extra.href
    if ($domain === 'unknown.domain') {
        if (!empty($inputData['extra']['href'])) {
            $parsed = parse_url($inputData['extra']['href']);
            if (!empty($parsed['host'])) {
                $domain = $parsed['host'];
                logMessage("Domain взят из extra.href => $domain", $config['global_log'], $config);
            }
        }
    }

    // 4) Если всё ещё unknown, используем ASSIGNED_BY_ID
    if ($domain === 'unknown.domain') {
        if (!empty($inputData['ASSIGNED_BY_ID'])) {
            $domain = $inputData['ASSIGNED_BY_ID'];
            // Исправляем кодировку для кириллических доменов
            if (preg_match('/[а-яё]/ui', $domain)) {
                $domain = mb_convert_encoding($domain, 'UTF-8', 'UTF-8');
            }
            logMessage("Domain fallback from ASSIGNED_BY_ID => $domain", $config['global_log'], $config);
        }
    }

    // 5) Если всё ещё unknown, используем source_domain (если передан)
    if ($domain === 'unknown.domain') {
        if (!empty($inputData['source_domain'])) {
            $domain = $inputData['source_domain'];
            logMessage("Domain fallback from source_domain => $domain", $config['global_log'], $config);
        }
    }

    // 6) Если всё ещё unknown, пытаемся получить домен из __submission.source_url
    if ($domain === 'unknown.domain') {
        if (!empty($inputData['__submission']['source_url'])) {
            $maybeUrl = $inputData['__submission']['source_url'];
            $parsed = parse_url($maybeUrl);
            if (!empty($parsed['host'])) {
                $domain = $parsed['host'];
                logMessage("Domain fallback from __submission.source_url => $domain", $config['global_log'], $config);
            }
        }
    }
    // --- ДОБАВЛЕНО: если domain всё ещё unknown или mrqz.me, пробуем extra.referrer ---
    if ($domain === 'unknown.domain' || $domain === 'mrqz.me') {
        if (!empty($inputData['extra']['referrer'])) {
            $parsed = parse_url($inputData['extra']['referrer']);
            if (!empty($parsed['host'])) {
                $domain = $parsed['host'];
                logMessage("Domain fallback from extra.referrer => $domain", $config['global_log'], $config);
            }
        }
    }

    // --- ДОБАВЛЕНО: для CallTouch используем subPoolName как домен ---
    if ($domain === 'unknown.domain' && !empty($inputData['subPoolName'])) {
        $domain = $inputData['subPoolName'];
        logMessage("Domain fallback from CallTouch subPoolName => $domain", $config['global_log'], $config);
    }

    // --- ДОБАВЛЕНО: для CallTouch пробуем извлечь домен из URL ---
    if ($domain === 'unknown.domain' && !empty($inputData['url'])) {
        $parsed = parse_url($inputData['url']);
        if (!empty($parsed['host'])) {
            $domain = $parsed['host'];
            logMessage("Domain fallback from CallTouch URL => $domain", $config['global_log'], $config);
        }
    }
}
$inputData['source_domain'] = $domain;

// СКВОЗНОЕ ЛОГИРОВАНИЕ: Входящие данные
$phoneValue = $inputData['Phone'] ?? '';
$nameValue = $inputData['name'] ?? $inputData['Name'] ?? $inputData['NAME'] ?? '';
logMessage("RAW_DATA | Phone: '$phoneValue' | Name: '$nameValue' | Domain: $domain", $config['global_log'], $config);
logMessage("Определён domain = $domain", $config['global_log'], $config);

// --- Унификация телефона: сохраняем Phone ---
$phoneValue = '';
foreach ($inputData as $key => $value) {
    if (is_string($value) && preg_match('/^phone/i', $key)) {
        // Проверяем, что поле начинается с 'phone' в любом регистре
        if (!empty($value)) {
            $phoneValue = $value;
            break;
        }
    }
}
if (!$phoneValue && isset($inputData['contacts']['phone'])) {
    $phoneValue = $inputData['contacts']['phone'];
}
if (!$phoneValue && isset($inputData['callerphone'])) {
    $phoneValue = $inputData['callerphone'];
}
if ($phoneValue) {
    $inputData['Phone'] = $phoneValue;
}

// --- Валидация телефона (проверка на пустой телефон) ---
// Разрешаем Bitrix24 webhook без phone в body (телефон приходит в QUERY_STRING)
$qs = $_SERVER['QUERY_STRING'] ?? '';
$qsDecoded = urldecode($qs);
$hasBitrixPhoneInQuery = (
    strpos($qs, 'fields%5BPHONE%5D') !== false ||
    strpos($qsDecoded, 'fields[PHONE]') !== false
);

// Блокируем пустые телефоны только если это не Bitrix24 webhook c телефоном в QUERY_STRING
if (empty(trim($phoneValue)) && !$hasBitrixPhoneInQuery) {
    logMessage("WEBHOOK: Телефон пустой и не Bitrix24 webhook, пропускаем создание лида", $config['global_log'], $config);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'ok', 'message' => 'empty_phone']);
    exit;
}

// --- Сохраняем запрос в очередь raw ---
$rawDir = $config['queue_dir'] . '/raw';
if (!is_dir($rawDir)) {
    mkdir($rawDir, 0777, true);
}

// Сохраняем сырые данные + метаданные для обработки
$rawData = [
    'raw_body' => $rawBody,
    'raw_headers' => [
        'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? '',
        'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? '',
        'HTTP_ORIGIN' => $_SERVER['HTTP_ORIGIN'] ?? '',
        'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '',
        'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? ''
    ],
    'parsed_data' => $inputData,
    'source_domain' => $domain,
    'timestamp' => date('Y-m-d H:i:s')
];

// --- Проверка по request_id (после создания полного rawData) ---
$requestTracker = new RequestTracker($config);
$requestTracker->cleanupOldRecords();

// Генерируем request_id от полных сырых данных
$requestId = $requestTracker->generateRequestId($rawData);
logMessage("REQUEST_ID: сгенерирован request_id=$requestId", $config['global_log'], $config);

// Проверяем существование request_id
$requestCheck = $requestTracker->checkRequest($requestId);

if ($requestCheck['exists']) {
    if ($requestCheck['status'] === 'success') {
        // Лид уже создан, возвращаем lead_id
        $leadId = $requestCheck['lead_id'];
        logMessage("REQUEST_ID: request_id=$requestId уже обработан, lead_id=$leadId", $config['global_log'], $config);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'message' => 'already_processed', 'lead_id' => $leadId]);
        exit;
    } elseif ($requestCheck['status'] === 'processing') {
        // Уже обрабатывается, пропускаем
        logMessage("REQUEST_ID: request_id=$requestId уже в обработке, пропускаем", $config['global_log'], $config);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'ok', 'message' => 'processing']);
        exit;
    } elseif ($requestCheck['status'] === 'failed') {
        // Предыдущая попытка завершилась ошибкой, разрешаем повторную обработку
        logMessage("REQUEST_ID: request_id=$requestId имел статус 'failed', разрешаем повторную обработку", $config['global_log'], $config);
        // Продолжаем обработку - создаем новый request со статусом 'processing'
        $requestTracker->createRequest($requestId, $rawData);
    }
} else {
    // Создаем новый request со статусом 'processing'
    $requestTracker->createRequest($requestId, $rawData);
    logMessage("REQUEST_ID: создан новый request_id=$requestId со статусом 'processing'", $config['global_log'], $config);
}

// Добавляем request_id в rawData
$rawData['request_id'] = $requestId;

$filePath = saveToQueue($rawData, $rawDir, 'raw_');
logQueueProcessing("Файл помещен в очередь raw: " . basename($filePath), $config['logs_dir'] . '/queue_processing.log');

// --- Проверка количества файлов в raw и отправка уведомления ---
$rawFiles = glob($rawDir . '/raw_*.json');
$rawFilesCount = count($rawFiles);

// Отправляем уведомление если накопилось достаточно файлов
$notificationThreshold = $config['raw_files_notification_threshold'] ?? 10;
$notificationInterval = $config['notification_interval'] ?? 300;
if ($rawFilesCount >= $notificationThreshold) {
    // Проверяем, не отправляли ли мы уже уведомление
    $notificationFlagFile = $rawDir . '/.raw_notification_sent';
    $shouldNotify = true;
    
    if (file_exists($notificationFlagFile)) {
        $lastNotificationTime = filemtime($notificationFlagFile);
        // Проверяем интервал между уведомлениями
        if (time() - $lastNotificationTime < $notificationInterval) {
            $shouldNotify = false;
        }
    }
    
    if ($shouldNotify) {
        try {
            // Используем функцию из chat_notifications.php если доступна
            if (function_exists('sendChatMessage')) {
                $chatId = $config['error_chat_id'] ?? null;
                $webhookUrl = $config['portal_webhooks']['dreamteamcompany'] ?? null;
                
                if ($chatId && $webhookUrl) {
                    $message = "⚠️ Внимание!\n\nВ RAW $rawFilesCount файлов";
                    if (sendChatMessage($webhookUrl, $chatId, $message, $config)) {
                        // Помечаем время отправки уведомления
                        @file_put_contents($notificationFlagFile, date('Y-m-d H:i:s'));
                        logMessage("WEBHOOK: Отправлено уведомление в чат о накоплении $rawFilesCount файлов в RAW", $config['global_log'], $config);
                    }
                }
            } else {
                // Если функция недоступна, отправляем через прямой вызов API
                $chatId = $config['error_chat_id'] ?? null;
                $webhookUrl = $config['portal_webhooks']['dreamteamcompany'] ?? null;
                
                if ($chatId && $webhookUrl) {
                    $message = "⚠️ Внимание!\n\nВ RAW $rawFilesCount файлов";
                    
                    $postFields = [
                        'DIALOG_ID' => $chatId,
                        'MESSAGE' => $message,
                        'SYSTEM' => 'Y'
                    ];
                    
                    $url = $webhookUrl . 'im.message.add.json';
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_POST => true,
                        CURLOPT_POSTFIELDS => http_build_query($postFields),
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_SSL_VERIFYPEER => false,
                        CURLOPT_TIMEOUT => 10
                    ]);
                    
                    $result = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode === 200) {
                        // Помечаем время отправки уведомления
                        @file_put_contents($notificationFlagFile, date('Y-m-d H:i:s'));
                        logMessage("WEBHOOK: Отправлено уведомление в чат о накоплении $rawFilesCount файлов в RAW", $config['global_log'], $config);
                    }
                }
            }
        } catch (Exception $e) {
            logMessage("WEBHOOK: Ошибка при отправке уведомления о накоплении файлов в RAW: " . $e->getMessage(), $config['global_log'], $config);
        }
    }
} else {
    // Если файлов меньше 10, удаляем флаг уведомления (если есть)
    $notificationFlagFile = $rawDir . '/.raw_notification_sent';
    if (file_exists($notificationFlagFile)) {
        @unlink($notificationFlagFile);
    }
}

// --- Запуск синхронной обработки ---
logMessage("WEBHOOK: Проверяем возможность запуска обработки", $config['global_log'], $config);

$lockFile = __DIR__ . '/universal-system/webhook.lock';

// #region agent log
$debugLogPath = __DIR__ . '/../.cursor/debug.log';
$debugData = [
    'sessionId' => 'debug-session',
    'runId' => 'run1',
    'hypothesisId' => 'A',
    'location' => 'webhook.php:webhookLock',
    'message' => 'WEBHOOK_LOCK_CHECK_START',
    'data' => [
        'lockFile' => $lockFile,
        'lockExists' => file_exists($lockFile),
        'phone' => $phoneValue ?? '',
        'domain' => $domain ?? '',
        'timestamp' => microtime(true)
    ],
    'timestamp' => (int)(microtime(true) * 1000)
];
@file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
// #endregion

// Используем flock() для атомарной блокировки
$lockFp = @fopen($lockFile, 'c+');
if ($lockFp === false) {
    // #region agent log
    $debugData = [
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'A',
        'location' => 'webhook.php:webhookLock',
        'message' => 'WEBHOOK_LOCK_OPEN_FAILED',
        'data' => ['lockFile' => $lockFile],
        'timestamp' => (int)(microtime(true) * 1000)
    ];
    @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    // #endregion
    logMessage("WEBHOOK: Не удалось открыть lock-файл, пропускаем обработку", $config['global_log'], $config);
} else {
    // Пытаемся получить эксклюзивную блокировку (неблокирующая)
    $locked = @flock($lockFp, LOCK_EX | LOCK_NB);
    
    // #region agent log
    $debugData = [
        'sessionId' => 'debug-session',
        'runId' => 'run1',
        'hypothesisId' => 'A',
        'location' => 'webhook.php:webhookLock',
        'message' => 'WEBHOOK_LOCK_ATTEMPT',
        'data' => [
            'locked' => $locked,
            'phone' => $phoneValue ?? '',
            'domain' => $domain ?? '',
            'timestamp' => microtime(true)
        ],
        'timestamp' => (int)(microtime(true) * 1000)
    ];
    @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    // #endregion
    
    if (!$locked) {
        fclose($lockFp);
        logMessage("WEBHOOK: Webhook заблокирован, пропускаем обработку", $config['global_log'], $config);
    } else {
        // Записываем время блокировки
        ftruncate($lockFp, 0);
        fwrite($lockFp, date('Y-m-d H:i:s'));
        fflush($lockFp);
        
        // #region agent log
        $debugData = [
            'sessionId' => 'debug-session',
            'runId' => 'run1',
            'hypothesisId' => 'A',
            'location' => 'webhook.php:webhookLock',
            'message' => 'WEBHOOK_LOCK_ACQUIRED',
            'data' => [
                'phone' => $phoneValue ?? '',
                'domain' => $domain ?? '',
                'timestamp' => microtime(true)
            ],
            'timestamp' => (int)(microtime(true) * 1000)
        ];
        @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        // #endregion
        
        try {
            logMessage("WEBHOOK: Запускаем синхронную обработку очередей", $config['global_log'], $config);
            // Дублируем в error_log для гарантии
            error_log("WEBHOOK: Запускаем синхронную обработку очередей");
            
            // Проверяем, что функция существует перед вызовом
            if (!function_exists('processAllQueues')) {
                // queue_manager.php уже подключен в начале файла, но на всякий случай
                require_once __DIR__ . '/universal-system/queue_manager.php';
                
                if (!function_exists('processAllQueues')) {
                    throw new Exception('Функция processAllQueues не найдена после подключения queue_manager.php');
                }
            }
            
            processAllQueues($config);
            
            logMessage("WEBHOOK: Синхронная обработка завершена успешно", $config['global_log'], $config);
        } catch (Throwable $e) {
            // Перехватываем все ошибки, включая фатальные (через Error Handler)
            $errorMsg = "WEBHOOK: Ошибка в синхронной обработке: " . $e->getMessage();
            $errorMsg .= " | File: " . $e->getFile() . " | Line: " . $e->getLine();
            $errorMsg .= " | Trace: " . $e->getTraceAsString();
            logMessage($errorMsg, $config['global_log'], $config);
            
            // Логируем в отдельный файл для критических ошибок
            $criticalLog = $config['logs_dir'] . '/webhook_critical_errors.log';
            @file_put_contents($criticalLog, date('[Y-m-d H:i:s] ') . $errorMsg . "\n", FILE_APPEND);
        } finally {
            if (isset($lockFp) && $lockFp !== false) {
                @flock($lockFp, LOCK_UN);
                fclose($lockFp);
            }
            if (file_exists($lockFile)) {
                @unlink($lockFile);
            }
            
            // #region agent log
            $debugData = [
                'sessionId' => 'debug-session',
                'runId' => 'run1',
                'hypothesisId' => 'A',
                'location' => 'webhook.php:webhookLock',
                'message' => 'WEBHOOK_LOCK_RELEASED',
                'data' => [
                    'phone' => $phoneValue ?? '',
                    'domain' => $domain ?? '',
                    'timestamp' => microtime(true)
                ],
                'timestamp' => (int)(microtime(true) * 1000)
            ];
            @file_put_contents($debugLogPath, json_encode($debugData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
            // #endregion
        }
    }
}

// --- Ответ ---
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status'=>'ok']);
exit();

// Webhook теперь только получает данные и сохраняет в очередь
// Обработка очередей происходит отдельно через:
// - CLI: php queue_manager.php run
// - Cron: */2 * * * * php queue_manager.php run
// - Веб-интерфейс: retry-raw-errors.php
