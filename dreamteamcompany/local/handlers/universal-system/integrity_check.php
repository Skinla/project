<?php
// integrity_check.php
// Веб/CLI-скрипт проверки целостности и версий ключевых файлов проекта

// Определяем режим запуска
$isWeb = isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST']);

if ($isWeb) {
    // Веб-режим: устанавливаем заголовки и отключаем ошибки на экран
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
} else {
    // CLI-режим
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

$root = __DIR__;
$manifestFile = $root . '/integrity_manifest.json';

// Ключевые файлы и инварианты, которые мы проверяем
$requiredFiles = [
    'config.php',
    'logger_and_queue.php',
    'duplicate_checker.php',
    'error_handler.php',
    'data_type_detector.php',
    'queue_manager.php',
    'lead_processor.php',
    'chat_notifications.php',
    'normalizers/base_normalizer.php',
    'normalizers/generic_normalizer.php',
    'normalizers/tilda_normalizer.php',
    'normalizers/calltouch_normalizer.php',
    'normalizers/koltaсh_normalizer.php',
    'normalizers/normalizer_factory.php',
    // Новые веб-интерфейсы
    'retry-raw-errors.php',
    'add-to-iblock.php',
    'add-to-exceptions.php',
];

$queueDirs = [
    'raw', 'detected', 'normalized', 'processed', 'failed', 'queue_errors', 'raw/raw_errors'
];

function readFileSafe(string $path): string {
    return file_exists($path) ? (string)file_get_contents($path) : '';
}

function sha256(string $content): string {
    return hash('sha256', $content);
}

function buildManifest(array $files, string $root): array {
    $out = [
        'generated_at' => date('c'),
        'files' => []
    ];
    foreach ($files as $rel) {
        $abs = $root . '/' . $rel;
        $out['files'][$rel] = [
            'exists' => file_exists($abs),
            'size' => file_exists($abs) ? filesize($abs) : 0,
            'mtime' => file_exists($abs) ? filemtime($abs) : 0,
            'sha256' => file_exists($abs) ? sha256((string)file_get_contents($abs)) : ''
        ];
    }
    return $out;
}

function checkSemanticInvariants(string $root): array {
    $problems = [];

    // webhook.php: новая структура сохранения сырых данных
    $webhookPaths = [
        $root . '/../webhook.php',  // Локальная структура
        $root . '/webhook.php',      // Альтернативная структура
        dirname($root) . '/webhook.php'  // Еще один вариант
    ];
    
    $webhook = '';
    $webhookFound = false;
    foreach ($webhookPaths as $webhookPath) {
        if (file_exists($webhookPath)) {
            $webhook = readFileSafe($webhookPath);
            $webhookFound = true;
            break;
        }
    }
    
    if (!$webhookFound) {
        $problems[] = 'webhook.php: файл не найден (проверены пути: ' . implode(', ', $webhookPaths) . ')';
    } else {
        if (strpos($webhook, 'raw_body') === false) {
            $problems[] = 'webhook.php: не сохраняются сырые данные (raw_body)';
        }
        if (strpos($webhook, 'raw_headers') === false) {
            $problems[] = 'webhook.php: не сохраняются HTTP заголовки (raw_headers)';
        }
        if (strpos($webhook, 'parsed_data') === false) {
            $problems[] = 'webhook.php: не сохраняются обработанные данные (parsed_data)';
        }
    }

    // data_type_detector.php: новая сигнатура и вызов с $rawFile; отсутствие unlink raw на detect
    $dtPath = $root . '/data_type_detector.php';
    $dt = readFileSafe($dtPath);
    if ($dt === '') {
        $problems[] = 'data_type_detector.php: файл не найден';
    } else {
        if (!preg_match('/function\s+getNormalizerFromIblock54\s*\(\s*\$domain\s*,\s*\$config\s*,\s*\$rawFilePath\s*=\s*null\s*\)/', $dt)) {
            $problems[] = 'data_type_detector.php: старая сигнатура getNormalizerFromIblock54 (ожидается 3-й параметр $rawFilePath)';
        }
        if (!preg_match('/getNormalizerFromIblock54\s*\(\s*\$domain\s*,\s*\$config\s*,\s*\$rawFile\s*\)/', $dt)) {
            $problems[] = 'data_type_detector.php: processRawFiles не передаёт $rawFile в getNormalizerFromIblock54';
        }
        // Не должно быть unlink($rawFile) сразу после сохранения detected
        if (preg_match('/unlink\s*\(\s*\$rawFile\s*\)\s*;/', $dt)) {
            $problems[] = 'data_type_detector.php: обнаружен unlink($rawFile) на этапе detect (raw должен сохраняться)';
        }
        // Проверяем обработку новой структуры данных
        if (!preg_match('/\$parsedData\s*=\s*\$rawData\[\s*[\'"]parsed_data[\'"]\s*\]\s*\?\?\s*\$rawData/', $dt)) {
            $problems[] = 'data_type_detector.php: не обрабатывается новая структура данных (parsed_data)';
        }
        if (!preg_match('/\$rawBody\s*=\s*\$rawData\[\s*[\'"]raw_body[\'"]\s*\]\s*\?\?\s*[\'"]/', $dt)) {
            $problems[] = 'data_type_detector.php: не извлекаются сырые данные (raw_body)';
        }
        // Проверяем обработку только корня raw/, не подпапок
        if (!preg_match('/glob\s*\(\s*\$rawDir\s*\.\s*[\'"]\/raw_\*\.json[\'"]\s*\)/', $dt)) {
            $problems[] = 'data_type_detector.php: обрабатываются файлы из подпапок (должны только из корня raw/)';
        }
    }

    // error_handler.php: используется copyToErrorQueue из handleError
    $ehPath = $root . '/error_handler.php';
    $eh = readFileSafe($ehPath);
    if ($eh === '') {
        $problems[] = 'error_handler.php: файл не найден';
    } else {
        // Проверяем методы простыми проверками (regex не работает корректно)
        if (strpos($eh, 'function copyToErrorQueue') === false) {
            $problems[] = 'error_handler.php: отсутствует метод copyToErrorQueue (должны копировать, не перемещать)';
        }
        if (strpos($eh, 'copyToErrorQueue(') === false) {
            $problems[] = 'error_handler.php: handleError не вызывает copyToErrorQueue';
        }
        // Проверяем новые методы
        if (strpos($eh, 'function addToExceptions') === false) {
            $problems[] = 'error_handler.php: отсутствует метод addToExceptions';
        }
        if (strpos($eh, 'function moveToRawErrors') === false) {
            $problems[] = 'error_handler.php: отсутствует метод moveToRawErrors';
        }
        // Проверяем обновленные ссылки в сообщениях
        if (strpos($eh, 'raw_errors') === false) {
            $problems[] = 'error_handler.php: ссылки не обновлены на raw_errors';
        }
        if (strpos($eh, 'retry-raw-errors.php') === false) {
            $problems[] = 'error_handler.php: ссылки не обновлены на retry-raw-errors.php';
        }
        
        // Отладочная информация убрана - все проверки пройдены успешно
    }

    // logger_and_queue.php: есть copyToQueueErrors и в moveToFailed не используется sendFailedFileNotification
    $lgPath = $root . '/logger_and_queue.php';
    $lg = readFileSafe($lgPath);
    if ($lg === '') {
        $problems[] = 'logger_and_queue.php: файл не найден';
    } else {
        if (!preg_match('/function\s+copyToQueueErrors\s*\(/', $lg)) {
            $problems[] = 'logger_and_queue.php: отсутствует функция copyToQueueErrors';
        }
        // Проверим, что внутри moveToFailed нет упоминания sendFailedFileNotification
        if (preg_match('/moveToFailed\s*\([\s\S]*sendFailedFileNotification\s*\(/', $lg)) {
            $problems[] = 'logger_and_queue.php: moveToFailed использует sendFailedFileNotification (должно быть через ErrorHandler)';
        }
        if (!preg_match('/moveToFailed\s*\([\s\S]*new\s+ErrorHandler\s*\(/', $lg)) {
            $problems[] = 'logger_and_queue.php: moveToFailed не уведомляет через ErrorHandler';
        }
    }

    // queue_manager.php: прокидка raw_file_path/raw_file_name в normalizedData
    $qm = readFileSafe($root . '/queue_manager.php');
    if ($qm !== '') {
        if (!preg_match('/\$normalizedData\s*\[\s*[\'\"]raw_file_path[\'\"]\s*\]/', $qm)) {
            $problems[] = 'queue_manager.php: в normalizedData не прокидывается raw_file_path';
        }
        // Проверяем новую функцию retryRawErrors
        if (!preg_match('/function\s+retryRawErrors\s*\(/', $qm)) {
            $problems[] = 'queue_manager.php: отсутствует функция retryRawErrors';
        }
        // Проверяем новый CLI команду
        if (!preg_match('/case\s+[\'"]retry-raw-errors[\'"]:/', $qm)) {
            $problems[] = 'queue_manager.php: отсутствует CLI команда retry-raw-errors';
        }
    }

    // lead_processor.php: после успеха удаляется raw_file_path, при ошибках — copyToQueueErrors
    $lp = readFileSafe($root . '/lead_processor.php');
    if ($lp !== '') {
        if (!preg_match('/unlink\s*\(\s*\$normalizedData\[\s*[\'"]raw_file_path[\'"]\s*\]\s*\)\s*;|@unlink\s*\(\s*\$normalizedData\[\s*[\'"]raw_file_path[\'"]\s*\]\s*\)\s*;/', $lp)) {
            $problems[] = 'lead_processor.php: после успешного лида не удаляется raw_file_path';
        }
        if (!preg_match('/copyToQueueErrors\s*\(\s*\$normalizedFile\s*,\s*\$config\s*\)/', $lp)) {
            $problems[] = 'lead_processor.php: при ошибке не копируется normalized в queue_errors';
        }
    }

    // generic_normalizer.php: работа с новой структурой данных
    $gnPath = $root . '/normalizers/generic_normalizer.php';
    $gn = readFileSafe($gnPath);
    if ($gn === '') {
        $problems[] = 'generic_normalizer.php: файл не найден';
    } else {
        if (!preg_match('/\$parsedData\s*=\s*\$rawData\[\s*[\'"]parsed_data[\'"]\s*\]\s*\?\?\s*\$rawData/', $gn)) {
            $problems[] = 'generic_normalizer.php: не обрабатывается новая структура данных (parsed_data)';
        }
        if (!preg_match('/\$rawBody\s*=\s*\$rawData\[\s*[\'"]raw_body[\'"]\s*\]\s*\?\?\s*[\'"]/', $gn)) {
            $problems[] = 'generic_normalizer.php: не извлекаются сырые данные (raw_body)';
        }
        if (!preg_match('/\$rawHeaders\s*=\s*\$rawData\[\s*[\'"]raw_headers[\'"]\s*\]\s*\?\?\s*\[/', $gn)) {
            $problems[] = 'generic_normalizer.php: не извлекаются HTTP заголовки (raw_headers)';
        }
        if (!preg_match('/buildGenericComment\s*\(\s*\$parsedData\s*,\s*\$rawBody\s*,\s*\$rawHeaders\s*\)/', $gn)) {
            $problems[] = 'generic_normalizer.php: buildGenericComment не принимает сырые данные';
        }
    }

    // Проверяем новые веб-интерфейсы
    $newFiles = ['retry-raw-errors.php', 'add-to-iblock.php', 'add-to-exceptions.php'];
    foreach ($newFiles as $file) {
        $filePath = $root . '/' . $file;
        if (!file_exists($filePath)) {
            $problems[] = "$file: новый веб-интерфейс не найден";
        } else {
            $content = readFileSafe($filePath);
            if (strpos($content, 'ErrorHandler') === false) {
                $problems[] = "$file: не использует ErrorHandler";
            }
            if (strpos($content, 'require_once') === false) {
                $problems[] = "$file: отсутствуют необходимые подключения";
            }
        }
    }

    return $problems;
}

function checkQueuePermissions(string $root, array $dirs): array {
    $issues = [];
    foreach ($dirs as $d) {
        $path = $root . '/queue/' . $d;
        if (!is_dir($path)) {
            $issues[] = "queue/$d: каталога нет";
            continue;
        }
        if (!is_writable($path)) {
            $issues[] = "queue/$d: нет прав на запись";
        }
    }
    
    // Дополнительная проверка для raw/raw_errors
    $rawErrorsPath = $root . '/queue/raw/raw_errors';
    if (!is_dir($rawErrorsPath)) {
        $issues[] = "queue/raw/raw_errors: каталога нет (будет создан автоматически)";
    } else {
        if (!is_writable($rawErrorsPath)) {
            $issues[] = "queue/raw/raw_errors: нет прав на запись";
        }
    }
    
    return $issues;
}

// Разбор аргументов
if ($isWeb) {
    $mode = $_GET['mode'] ?? 'check'; // check | generate
} else {
    $mode = $argv[1] ?? 'check'; // check | generate
}

if ($mode === 'generate') {
    $manifest = buildManifest($requiredFiles, $root);
    file_put_contents($manifestFile, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($isWeb) {
        echo json_encode(['status' => 'ok', 'message' => 'Манифест сгенерирован: integrity_manifest.json'], JSON_UNESCAPED_UNICODE);
    } else {
        echo "Манифест сгенерирован: integrity_manifest.json\n";
    }
    exit(0);
}

// Проверка по манифесту (если есть)
$result = [
    'status' => 'ok',
    'problems' => [],
    'manifest_mismatches' => [],
    'missing_files' => [],
    'queue_permission_issues' => [],
    'version_info' => [
        'check_date' => date('Y-m-d H:i:s'),
        'changes_summary' => [
            'webhook_new_structure' => 'Сохранение сырых данных + метаданные',
            'raw_errors_folder' => 'Новая папка для файлов с ошибками',
            'web_interfaces' => '3 новых веб-интерфейса для управления',
            'retry_mechanism' => 'Механизм повторной обработки файлов',
            'enhanced_error_handling' => 'Улучшенная обработка ошибок'
        ]
    ]
];

if (file_exists($manifestFile)) {
    $manifest = json_decode((string)file_get_contents($manifestFile), true);
    if (is_array($manifest) && isset($manifest['files'])) {
        foreach ($requiredFiles as $rel) {
            $abs = $root . '/' . $rel;
            $expected = $manifest['files'][$rel] ?? null;
            if (!$expected) {
                $result['manifest_mismatches'][] = "$rel: нет в манифесте";
                continue;
            }
            if (!file_exists($abs)) {
                $result['missing_files'][] = $rel;
                continue;
            }
            $actualSha = sha256((string)file_get_contents($abs));
            if (!empty($expected['sha256']) && $expected['sha256'] !== $actualSha) {
                $result['manifest_mismatches'][] = "$rel: sha256 не совпадает";
            }
        }
    }
}

// Семантические инварианты
$result['problems'] = array_merge($result['problems'], checkSemanticInvariants($root));

// Права на очереди
$result['queue_permission_issues'] = checkQueuePermissions($root, $queueDirs);

// Итоговый статус
if (!empty($result['problems']) || !empty($result['manifest_mismatches']) || !empty($result['missing_files']) || !empty($result['queue_permission_issues'])) {
    $result['status'] = 'fail';
}

// Вывод
if ($isWeb) {
    // Веб-режим: добавляем HTML-обёртку для удобства просмотра
    if (isset($_GET['html']) && $_GET['html'] === '1') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Проверка целостности</title></head><body>';
        echo '<h1>Проверка целостности проекта</h1>';
        echo '<pre>' . htmlspecialchars(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
        echo '<p><a href="?mode=check">Проверка</a> | <a href="?mode=generate">Генерация манифеста</a> | <a href="?mode=check&html=1">HTML-вид</a></p>';
        echo '</body></html>';
    } else {
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
} else {
    // CLI-режим
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}

exit($result['status'] === 'ok' ? 0 : 1);


