<?php
// retry-raw-errors.php
// Веб-интерфейс для повторной обработки файлов из raw_errors
// Этот файл находится в universal-system, использует относительные пути

// Включаем отображение всех ошибок для диагностики
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Определяем режим запуска
$isWeb = isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST']);

if ($isWeb) {
    header('Content-Type: text/html; charset=utf-8');
}

// Загружаем файлы с обработкой ошибок
try {
    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
        die("Ошибка: файл config.php не найден по пути: $configPath");
    }
    require_once $configPath;
    
    $queueManagerPath = __DIR__ . '/queue_manager.php';
    if (!file_exists($queueManagerPath)) {
        die("Ошибка: файл queue_manager.php не найден по пути: $queueManagerPath");
    }
    require_once $queueManagerPath;
    
    $errorHandlerPath = __DIR__ . '/error_handler.php';
    if (!file_exists($errorHandlerPath)) {
        die("Ошибка: файл error_handler.php не найден по пути: $errorHandlerPath");
    }
    require_once $errorHandlerPath;
    
} catch (Throwable $e) {
    die("Ошибка при загрузке файлов: " . $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine());
}

// Загружаем конфиг
try {
    $config = require $configPath;
} catch (Throwable $e) {
    die("Ошибка при загрузке конфига: " . $e->getMessage());
}

// Проверяем, что конфиг загружен
if (empty($config) || !is_array($config)) {
    die("Ошибка: не удалось загрузить конфигурацию. Конфиг пуст или не является массивом.");
}

// Проверяем обязательные поля конфига
if (empty($config['queue_dir'])) {
    die("Ошибка: в конфиге не указан queue_dir");
}

try {
    $errorHandler = new ErrorHandler($config);
} catch (Throwable $e) {
    die("Ошибка при создании ErrorHandler: " . $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine());
}

// Получаем активную вкладку
$activeTab = $_GET['tab'] ?? 'errors';

// Параметры пагинации
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 100;
$showAll = isset($_GET['show_all']) && $_GET['show_all'] == '1';
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Функция для пагинации файлов
function getPaginatedFiles($files, $page = 1, $perPage = 100, $showAll = false) {
    if ($showAll) {
        return ['files' => $files, 'total' => count($files), 'page' => 1, 'perPage' => count($files), 'totalPages' => 1];
    }
    
    $total = count($files);
    $totalPages = ceil($total / $perPage);
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    $paginatedFiles = array_slice($files, $offset, $perPage);
    
    return [
        'files' => $paginatedFiles,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'totalPages' => $totalPages
    ];
}

// Функция для получения списка файлов в очереди (локальная функция для этого файла)
// Использует ту же логику, что и getQueueStats - ищет все .json файлы
if (!function_exists('getQueueFilesList')) {
    function getQueueFilesList($queueDir, $prefix = '') {
        if (!is_dir($queueDir)) {
            return [];
        }
        // Ищем все .json файлы (как в getQueueStats), чтобы было соответствие со статистикой
        $files = glob($queueDir . '/*.json');
        if (empty($files)) {
            return [];
        }
        // Если указан префикс, фильтруем по нему
        if ($prefix) {
            $filtered = [];
            foreach ($files as $file) {
                $basename = basename($file);
                if (strpos($basename, $prefix) === 0) {
                    $filtered[] = $basename;
                }
            }
            return $filtered;
        }
        return array_map('basename', $files);
    }
}

// Получаем статистику по очередям
if (!function_exists('getQueueStats')) {
    die('Ошибка: функция getQueueStats не найдена. Проверьте подключение queue_manager.php. Загруженные функции: ' . implode(', ', get_defined_functions()['user'] ?? []));
}

try {
    // Проверяем, что queue_dir существует и доступен
    if (!is_dir($config['queue_dir'])) {
        // Пытаемся создать директорию
        if (!@mkdir($config['queue_dir'], 0777, true)) {
            die("Ошибка: не удалось создать директорию queue_dir: " . $config['queue_dir']);
        }
    }
    
    $queueStats = getQueueStats($config);
    if (!is_array($queueStats)) {
        die('Ошибка: getQueueStats вернул не массив. Результат: ' . var_export($queueStats, true));
    }
} catch (Throwable $e) {
    die("Ошибка при получении статистики очередей: " . $e->getMessage() . " в файле " . $e->getFile() . " на строке " . $e->getLine() . ". Trace: " . $e->getTraceAsString());
}

// Получаем файлы для каждой очереди
// Используем ту же логику, что и getQueueStats - показываем все .json файлы
$queueFiles = [
    'raw' => getQueueFilesList($config['queue_dir'] . '/raw', ''), // Все файлы
    'detected' => getQueueFilesList($config['queue_dir'] . '/detected', ''), // Все файлы
    'normalized' => getQueueFilesList($config['queue_dir'] . '/normalized', ''), // Все файлы
    'processed' => getQueueFilesList($config['queue_dir'] . '/processed', ''), // Все файлы
    'duplicates' => getQueueFilesList($config['queue_dir'] . '/duplicates', ''), // Все файлы
    'failed' => getQueueFilesList($config['queue_dir'] . '/failed', '') // Все файлы
];

// Обработка AJAX запроса для просмотра файла
if (isset($_GET['ajax']) && $_GET['ajax'] == '1' && isset($_GET['view']) && isset($_GET['queue'])) {
    header('Content-Type: application/json');
    $fileName = $_GET['view'];
    $queueName = $_GET['queue'];
    
    // Обработка специального случая для raw_errors
    if ($queueName === 'raw/raw_errors') {
        $filePath = $config['queue_dir'] . '/raw/raw_errors/' . $fileName;
} else {
        $filePath = $config['queue_dir'] . '/' . $queueName . '/' . $fileName;
    }
    
    if (file_exists($filePath) && is_readable($filePath)) {
        $viewContent = file_get_contents($filePath);
        $viewData = json_decode($viewContent, true);
        $formattedContent = htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(['success' => true, 'content' => $formattedContent]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Файл не найден']);
}
    exit;
}

// Обработка просмотра файла (для обратной совместимости)
$viewFile = null;
$viewContent = null;
$viewData = null;
$viewQueue = null;
$viewTab = null;
if (isset($_GET['view']) && isset($_GET['queue']) && isset($_GET['tab'])) {
    $fileName = $_GET['view'];
    $queueName = $_GET['queue'];
    $viewTab = $_GET['tab'];
    
    // Показываем файл только если он открыт из текущей вкладки
    if ($viewTab === $activeTab) {
        // Обработка специального случая для raw_errors
        if ($queueName === 'raw/raw_errors') {
            $filePath = $config['queue_dir'] . '/raw/raw_errors/' . $fileName;
        } else {
            $filePath = $config['queue_dir'] . '/' . $queueName . '/' . $fileName;
        }
        
        if (file_exists($filePath) && is_readable($filePath)) {
            $viewFile = $fileName;
            $viewQueue = $queueName;
            $viewContent = file_get_contents($filePath);
            $viewData = json_decode($viewContent, true);
        }
    }
}

// Обработка GET запроса для прямых ссылок
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['file'])) {
    $fileName = $_GET['file'];
    $rawDir = $config['queue_dir'] . '/raw';
    $rawErrorsDir = $rawDir . '/raw_errors';
    $errorFile = $rawErrorsDir . '/' . $fileName;
    $newPath = $rawDir . '/' . $fileName;
    
    if (file_exists($errorFile) && rename($errorFile, $newPath)) {
        // Запускаем обработку очередей
        if (function_exists('processAllQueues')) {
        processAllQueues($config);
        }
        $message = "Файл $fileName отправлен на повторную обработку";
    } else {
        $message = "Ошибка: не удалось обработать файл $fileName";
    }
}

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $queueName = $_POST['queue'] ?? '';
    $fileName = $_POST['file'] ?? '';
    $redirectTab = $_POST['tab'] ?? 'errors';
    
    if ($action === 'retry_all') {
        if (!function_exists('retryRawErrors')) {
            $message = "Ошибка: функция retryRawErrors не найдена";
        } else {
        $retried = retryRawErrors($config);
        if ($retried > 0) {
            // Запускаем обработку очередей для перемещенных файлов
                if (function_exists('processAllQueues')) {
            processAllQueues($config);
                }
            }
            $message = "Отправлено на повторную обработку $retried файлов из raw_errors";
        }
    } elseif ($action === 'process_queues') {
        // Ручной запуск обработки очередей
        if (function_exists('processAllQueues')) {
        processAllQueues($config);
        $message = "Обработка очередей запущена";
        } else {
            $message = "Ошибка: функция processAllQueues не найдена";
        }
    } elseif ($action === 'retry_file' && !empty($fileName)) {
        // Обработка файла из ошибок
        $rawDir = $config['queue_dir'] . '/raw';
        $rawErrorsDir = $rawDir . '/raw_errors';
        $errorFile = $rawErrorsDir . '/' . $fileName;
        $newPath = $rawDir . '/' . $fileName;
        
        if (file_exists($errorFile) && rename($errorFile, $newPath)) {
            // Запускаем обработку очередей
            if (function_exists('processAllQueues')) {
            processAllQueues($config);
            }
            $message = "Файл $fileName отправлен на повторную обработку";
        } else {
            $message = "Ошибка: не удалось обработать файл $fileName";
        }
    } elseif ($action === 'process_file' && !empty($fileName) && !empty($queueName)) {
        // Обработка файла из конкретной очереди
        $filePath = $config['queue_dir'] . '/' . $queueName . '/' . $fileName;
        
        if ($queueName === 'raw') {
            // Для raw переименовываем файл в формат raw_*.json если нужно
            if (strpos($fileName, 'raw_') !== 0) {
                $rawDir = $config['queue_dir'] . '/raw';
                $newFileName = 'raw_' . time() . '_' . uniqid() . '.json';
                $newPath = $rawDir . '/' . $newFileName;
                
                if (file_exists($filePath) && rename($filePath, $newPath)) {
                    $message = "Файл $fileName переименован в $newFileName и будет обработан";
                    $fileName = $newFileName; // Обновляем имя для логирования
                } else {
                    $message = "Ошибка: не удалось переименовать файл $fileName";
                }
            }
            
            // Запускаем обработку
            if (function_exists('processAllQueues')) {
                processAllQueues($config);
                if (!isset($message)) {
                    $message = "Обработка очередей запущена для файла $fileName";
                }
            } else {
                $message = "Ошибка: функция processAllQueues не найдена";
            }
        } elseif ($queueName === 'detected' || $queueName === 'normalized') {
            // Для detected и normalized просто запускаем обработку
            if (function_exists('processAllQueues')) {
                processAllQueues($config);
                $message = "Обработка очередей запущена для файла $fileName";
            } else {
                $message = "Ошибка: функция processAllQueues не найдена";
            }
        } elseif ($queueName === 'failed') {
            // Для failed перемещаем в raw для повторной обработки
            $rawDir = $config['queue_dir'] . '/raw';
            $newPath = $rawDir . '/' . $fileName;
            
            if (file_exists($filePath) && rename($filePath, $newPath)) {
                if (function_exists('processAllQueues')) {
                    processAllQueues($config);
                }
                $message = "Файл $fileName перемещен в raw для повторной обработки";
            } else {
                $message = "Ошибка: не удалось переместить файл $fileName";
            }
        } else {
            $message = "Обработка для очереди $queueName не поддерживается";
        }
    } elseif ($action === 'delete_file' && !empty($fileName) && !empty($queueName)) {
        // Удаление файла
        if ($queueName === 'raw/raw_errors') {
            $filePath = $config['queue_dir'] . '/raw/raw_errors/' . $fileName;
        } else {
            $filePath = $config['queue_dir'] . '/' . $queueName . '/' . $fileName;
        }
        
        // Также удаляем .lock файл, если есть
        $lockFile = $filePath . '.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
        
        if (file_exists($filePath) && unlink($filePath)) {
            $message = "Файл $fileName успешно удален";
        } else {
            $message = "Ошибка: не удалось удалить файл $fileName";
        }
    } elseif ($action === 'process_all' && !empty($queueName)) {
        // Обработка всех файлов в очереди
        $queueDir = $config['queue_dir'] . '/' . $queueName;
        if ($queueName === 'raw/raw_errors') {
            $queueDir = $config['queue_dir'] . '/raw/raw_errors';
        }
        
        if (!is_dir($queueDir)) {
            $message = "Ошибка: директория $queueName не существует";
        } else {
            $files = glob($queueDir . '/*.json');
            $processedCount = 0;
            
            if ($queueName === 'raw' || $queueName === 'raw/raw_errors') {
                // Для raw перемещаем все в raw и запускаем обработку
                $rawDir = $config['queue_dir'] . '/raw';
                foreach ($files as $file) {
                    $fileName = basename($file);
                    // Переименовываем в raw_*.json если нужно
                    if (strpos($fileName, 'raw_') !== 0) {
                        $newFileName = 'raw_' . time() . '_' . uniqid() . '.json';
                        $newPath = $rawDir . '/' . $newFileName;
                        if (rename($file, $newPath)) {
                            $processedCount++;
                        }
                    } else {
                        $newPath = $rawDir . '/' . $fileName;
                        if (rename($file, $newPath)) {
                            $processedCount++;
                        }
                    }
                }
                if ($processedCount > 0 && function_exists('processAllQueues')) {
                    processAllQueues($config);
                }
                $message = "Перемещено $processedCount файлов в raw и запущена обработка";
            } elseif ($queueName === 'failed') {
                // Для failed перемещаем все в raw
                $rawDir = $config['queue_dir'] . '/raw';
                foreach ($files as $file) {
                    $fileName = basename($file);
                    $newPath = $rawDir . '/' . $fileName;
                    if (rename($file, $newPath)) {
                        $processedCount++;
                    }
                }
                if ($processedCount > 0 && function_exists('processAllQueues')) {
                    processAllQueues($config);
                }
                $message = "Перемещено $processedCount файлов из failed в raw и запущена обработка";
            } else {
                // Для других очередей просто запускаем обработку
                if (function_exists('processAllQueues')) {
                    processAllQueues($config);
                    $message = "Обработка очередей запущена для всех файлов в $queueName";
                } else {
                    $message = "Ошибка: функция processAllQueues не найдена";
                }
            }
        }
    } elseif ($action === 'delete_all' && !empty($queueName)) {
        // Удаление всех файлов в очереди
        $queueDir = $config['queue_dir'] . '/' . $queueName;
        if ($queueName === 'raw/raw_errors') {
            $queueDir = $config['queue_dir'] . '/raw/raw_errors';
        }
        
        if (!is_dir($queueDir)) {
            $message = "Ошибка: директория $queueName не существует";
        } else {
            $files = glob($queueDir . '/*.json');
            $deletedCount = 0;
            
            foreach ($files as $file) {
                // Удаляем .lock файл, если есть
                $lockFile = $file . '.lock';
                if (file_exists($lockFile)) {
                    @unlink($lockFile);
                }
                
                if (unlink($file)) {
                    $deletedCount++;
                }
            }
            
            $message = "Удалено $deletedCount файлов из $queueName";
        }
    }
    
    // Редирект после POST для избежания повторной отправки формы
    if ($isWeb) {
        $redirectUrl = '?tab=' . urlencode($redirectTab);
        if (isset($message)) {
            $redirectUrl .= '&message=' . urlencode($message);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Получаем список файлов в raw_errors
$rawErrorsDir = $config['queue_dir'] . '/raw/raw_errors';
$errorFiles = [];
if (is_dir($rawErrorsDir)) {
    $files = glob($rawErrorsDir . '/raw_*.json');
    $errorFiles = $files ? array_map('basename', $files) : [];
}

if ($isWeb) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Повторная обработка raw_errors</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
            .container { max-width: 1200px; margin: 0 auto; background-color: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .message { padding: 10px; margin: 10px 0; border-radius: 5px; }
            .success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
            .tabs { display: flex; border-bottom: 2px solid #dee2e6; margin-bottom: 20px; }
            .tab { padding: 12px 24px; cursor: pointer; background-color: #f8f9fa; border: none; border-top-left-radius: 5px; border-top-right-radius: 5px; margin-right: 5px; text-decoration: none; color: #495057; }
            .tab:hover { background-color: #e9ecef; }
            .tab.active { background-color: #007bff; color: white; }
            .tab-content { display: none; }
            .tab-content.active { display: block; }
            .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
            .stat-card { padding: 15px; background-color: #f8f9fa; border-radius: 5px; border-left: 4px solid #007bff; }
            .stat-label { font-size: 12px; color: #6c757d; text-transform: uppercase; }
            .stat-value { font-size: 24px; font-weight: bold; color: #212529; margin-top: 5px; }
            .file-list { margin: 20px 0; }
            .file-item { padding: 10px; margin: 5px 0; background-color: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; }
            .file-item-header { display: flex; justify-content: space-between; align-items: center; }
            .file-name { font-weight: bold; flex: 1; }
            .file-actions { margin-left: 10px; }
            .file-view-content { display: none; margin-top: 15px; padding: 15px; background-color: #ffffff; border: 1px solid #dee2e6; border-radius: 5px; }
            .file-view-content.active { display: block; }
            .file-view-content pre { background-color: #f8f9fa; padding: 15px; border-radius: 5px; overflow-x: auto; margin: 0; }
            .btn { padding: 8px 16px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
            .btn-primary { background-color: #007bff; color: white; }
            .btn-success { background-color: #28a745; color: white; }
            .btn-danger { background-color: #dc3545; color: white; }
            .btn-warning { background-color: #ffc107; color: #212529; }
            .btn:hover { opacity: 0.8; }
            .view-content { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 20px; }
            .view-content pre { background-color: #ffffff; padding: 15px; border-radius: 5px; overflow-x: auto; }
            .queue-actions { margin-bottom: 20px; }
        </style>
        <script>
            function showTab(tabName, element) {
                // Скрываем все вкладки
                document.querySelectorAll('.tab-content').forEach(tab => {
                    tab.classList.remove('active');
                });
                document.querySelectorAll('.tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                
                // Показываем выбранную вкладку
                var tabElement = document.getElementById('tab-' + tabName);
                if (tabElement) {
                    tabElement.classList.add('active');
                }
                if (element) {
                    element.classList.add('active');
                }
            }
            
            function toggleFileView(fileId, queueName, fileName) {
                var viewContent = document.getElementById('view-' + fileId);
                if (!viewContent) {
                    // Загружаем содержимое файла
                    var activeTab = '<?= $activeTab ?>';
                    var urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.get('tab')) {
                        activeTab = urlParams.get('tab');
                    }
                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '?tab=' + encodeURIComponent(activeTab) + '&view=' + encodeURIComponent(fileName) + '&queue=' + encodeURIComponent(queueName) + '&ajax=1', true);
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    var fileItem = document.getElementById('file-' + fileId);
                                    var viewDiv = document.createElement('div');
                                    viewDiv.id = 'view-' + fileId;
                                    viewDiv.className = 'file-view-content active';
                                    viewDiv.innerHTML = '<pre>' + response.content + '</pre>';
                                    fileItem.appendChild(viewDiv);
                                } else {
                                    alert('Ошибка загрузки файла: ' + (response.error || 'Неизвестная ошибка'));
                                }
                            } catch (e) {
                                alert('Ошибка парсинга ответа: ' + e.message);
                            }
                        }
                    };
                    xhr.send();
                } else {
                    viewContent.classList.toggle('active');
                }
            }
            
            // Инициализация при загрузке страницы
            document.addEventListener('DOMContentLoaded', function() {
                var activeTab = '<?= $activeTab ?>';
                if (activeTab) {
                    var tabLink = document.querySelector('.tab[href*="tab=' + activeTab + '"]');
                    if (tabLink) {
                        showTab(activeTab, tabLink);
                    }
                }
                
                // Показываем просмотр файла если он был открыт
                <?php if ($viewFile && $viewContent && $viewTab === $activeTab): ?>
                var viewFile = '<?= htmlspecialchars($viewFile, ENT_QUOTES) ?>';
                var viewQueue = '<?= htmlspecialchars($viewQueue, ENT_QUOTES) ?>';
                var fileId = viewFile.replace(/[^a-zA-Z0-9]/g, '_');
                setTimeout(function() {
                    toggleFileView(fileId, viewQueue, viewFile);
                }, 100);
                <?php endif; ?>
            });
        </script>
    </head>
    <body>
        <div class="container">
            <h1>Управление очередями обработки 
                <a href="QUEUE_MANAGEMENT.html" target="_blank" style="font-size: 14px; margin-left: 15px; color: #007bff; text-decoration: none; font-weight: normal;">
                    📖 Инструкция
                </a>
            </h1>
            
            <?php 
            // Обработка сообщения из GET параметра (после редиректа)
            if (isset($_GET['message'])) {
                $message = $_GET['message'];
            }
            if (isset($message)): ?>
                <div class="message success"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            
            <!-- Статистика -->
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-label">Ошибки</div>
                    <div class="stat-value"><?= count($errorFiles) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Raw</div>
                    <div class="stat-value"><?= $queueStats['raw'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Detected</div>
                    <div class="stat-value"><?= $queueStats['detected'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Normalized</div>
                    <div class="stat-value"><?= $queueStats['normalized'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Processed</div>
                    <div class="stat-value"><?= $queueStats['processed'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Duplicates</div>
                    <div class="stat-value"><?= $queueStats['duplicates'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Failed</div>
                    <div class="stat-value"><?= $queueStats['failed'] ?></div>
                </div>
            </div>
            
            <!-- Вкладки -->
            <div class="tabs">
                <a href="?tab=errors" class="tab <?= $activeTab === 'errors' ? 'active' : '' ?>" onclick="showTab('errors', this); return false;">Ошибки (<?= count($errorFiles) ?>)</a>
                <a href="?tab=raw" class="tab <?= $activeTab === 'raw' ? 'active' : '' ?>" onclick="showTab('raw', this); return false;">Raw (<?= $queueStats['raw'] ?>)</a>
                <a href="?tab=detected" class="tab <?= $activeTab === 'detected' ? 'active' : '' ?>" onclick="showTab('detected', this); return false;">Detected (<?= $queueStats['detected'] ?>)</a>
                <a href="?tab=normalized" class="tab <?= $activeTab === 'normalized' ? 'active' : '' ?>" onclick="showTab('normalized', this); return false;">Normalized (<?= $queueStats['normalized'] ?>)</a>
                <a href="?tab=processed" class="tab <?= $activeTab === 'processed' ? 'active' : '' ?>" onclick="showTab('processed', this); return false;">Processed (<?= $queueStats['processed'] ?>)</a>
                <a href="?tab=duplicates" class="tab <?= $activeTab === 'duplicates' ? 'active' : '' ?>" onclick="showTab('duplicates', this); return false;">Duplicates (<?= $queueStats['duplicates'] ?>)</a>
                <a href="?tab=failed" class="tab <?= $activeTab === 'failed' ? 'active' : '' ?>" onclick="showTab('failed', this); return false;">Failed (<?= $queueStats['failed'] ?>)</a>
            </div>
            
            <!-- Вкладка: Ошибки -->
            <div id="tab-errors" class="tab-content <?= $activeTab === 'errors' ? 'active' : '' ?>">
            <div class="file-list">
                <?php 
                $errorsPagination = getPaginatedFiles($errorFiles, $currentPage, $perPage, $showAll && $activeTab === 'errors');
                ?>
                <h2>Файлы с ошибками (<?= $errorsPagination['total'] ?>)</h2>
                
                <?php if (empty($errorsPagination['files'])): ?>
                    <p>Нет файлов с ошибками</p>
                <?php else: ?>
                    <form method="post" style="margin-bottom: 20px; display: inline-block;">
                        <input type="hidden" name="action" value="retry_all">
                            <input type="hidden" name="tab" value="errors">
                        <button type="submit" class="btn btn-success">Обработать все файлы</button>
                    </form>
                    
                    <form method="post" style="margin-bottom: 20px; display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="raw/raw_errors">
                        <input type="hidden" name="tab" value="errors">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из ошибок? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                    
                    <form method="post" style="margin-bottom: 20px; display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="process_queues">
                            <input type="hidden" name="tab" value="errors">
                        <button type="submit" class="btn btn-warning">Запустить обработку очередей</button>
                    </form>
                    
                    <?php if ($errorsPagination['total'] > $errorsPagination['perPage']): ?>
                        <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                            <span>Страница <?= $errorsPagination['page'] ?> из <?= $errorsPagination['totalPages'] ?> (показано <?= count($errorsPagination['files']) ?> из <?= $errorsPagination['total'] ?>)</span>
                            <?php if ($errorsPagination['page'] > 1): ?>
                                <a href="?tab=errors&page=<?= $errorsPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                            <?php endif; ?>
                            <?php if ($errorsPagination['page'] < $errorsPagination['totalPages']): ?>
                                <a href="?tab=errors&page=<?= $errorsPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                            <?php endif; ?>
                            <?php if (!$showAll || $activeTab !== 'errors'): ?>
                                <a href="?tab=errors&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                            <?php else: ?>
                                <a href="?tab=errors" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                        <?php foreach ($errorsPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'errors');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                            <div class="file-name"><?= htmlspecialchars($file) ?></div>
                            <div class="file-actions">
                                <form method="post" style="display: inline;">
                                    <input type="hidden" name="action" value="retry_file">
                                    <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="tab" value="errors">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Обработать файл <?= htmlspecialchars($file) ?>?')">Обработать</button>
                                        </form>
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'raw/raw_errors', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="raw/raw_errors">
                                            <input type="hidden" name="tab" value="errors">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Raw -->
            <div id="tab-raw" class="tab-content <?= $activeTab === 'raw' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="process_all">
                        <input type="hidden" name="queue" value="raw">
                        <input type="hidden" name="tab" value="raw">
                        <button type="submit" class="btn btn-success">Обработать все файлы</button>
                    </form>
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="raw">
                        <input type="hidden" name="tab" value="raw">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Raw? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="process_queues">
                        <input type="hidden" name="tab" value="raw">
                        <button type="submit" class="btn btn-warning">Запустить обработку очередей</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $rawPagination = getPaginatedFiles($queueFiles['raw'], $currentPage, $perPage, $showAll && $activeTab === 'raw');
                    ?>
                    <h2>Raw очередь (<?= $rawPagination['total'] ?> файлов)</h2>
                    <?php if (empty($rawPagination['files'])): ?>
                        <p>Нет файлов в очереди Raw</p>
                    <?php else: ?>
                        <?php if ($rawPagination['total'] > $rawPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $rawPagination['page'] ?> из <?= $rawPagination['totalPages'] ?> (показано <?= count($rawPagination['files']) ?> из <?= $rawPagination['total'] ?>)</span>
                                <?php if ($rawPagination['page'] > 1): ?>
                                    <a href="?tab=raw&page=<?= $rawPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($rawPagination['page'] < $rawPagination['totalPages']): ?>
                                    <a href="?tab=raw&page=<?= $rawPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'raw'): ?>
                                    <a href="?tab=raw&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=raw" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($rawPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'raw');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="process_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="raw">
                                            <input type="hidden" name="tab" value="raw">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Обработать файл <?= htmlspecialchars($file) ?>?')">Обработать</button>
                                        </form>
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'raw', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="raw">
                                            <input type="hidden" name="tab" value="raw">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Detected -->
            <div id="tab-detected" class="tab-content <?= $activeTab === 'detected' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="process_all">
                        <input type="hidden" name="queue" value="detected">
                        <input type="hidden" name="tab" value="detected">
                        <button type="submit" class="btn btn-success">Обработать все файлы</button>
                    </form>
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="detected">
                        <input type="hidden" name="tab" value="detected">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Detected? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $detectedPagination = getPaginatedFiles($queueFiles['detected'], $currentPage, $perPage, $showAll && $activeTab === 'detected');
                    ?>
                    <h2>Detected очередь (<?= $detectedPagination['total'] ?> файлов)</h2>
                    <?php if (empty($detectedPagination['files'])): ?>
                        <p>Нет файлов в очереди Detected</p>
                    <?php else: ?>
                        <?php if ($detectedPagination['total'] > $detectedPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $detectedPagination['page'] ?> из <?= $detectedPagination['totalPages'] ?> (показано <?= count($detectedPagination['files']) ?> из <?= $detectedPagination['total'] ?>)</span>
                                <?php if ($detectedPagination['page'] > 1): ?>
                                    <a href="?tab=detected&page=<?= $detectedPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($detectedPagination['page'] < $detectedPagination['totalPages']): ?>
                                    <a href="?tab=detected&page=<?= $detectedPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'detected'): ?>
                                    <a href="?tab=detected&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=detected" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($detectedPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'detected');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="process_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="detected">
                                            <input type="hidden" name="tab" value="detected">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Обработать файл <?= htmlspecialchars($file) ?>?')">Обработать</button>
                                        </form>
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'detected', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="detected">
                                            <input type="hidden" name="tab" value="detected">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Normalized -->
            <div id="tab-normalized" class="tab-content <?= $activeTab === 'normalized' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="process_all">
                        <input type="hidden" name="queue" value="normalized">
                        <input type="hidden" name="tab" value="normalized">
                        <button type="submit" class="btn btn-success">Обработать все файлы</button>
                    </form>
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="normalized">
                        <input type="hidden" name="tab" value="normalized">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Normalized? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $normalizedPagination = getPaginatedFiles($queueFiles['normalized'], $currentPage, $perPage, $showAll && $activeTab === 'normalized');
                    ?>
                    <h2>Normalized очередь (<?= $normalizedPagination['total'] ?> файлов)</h2>
                    <?php if (empty($normalizedPagination['files'])): ?>
                        <p>Нет файлов в очереди Normalized</p>
                    <?php else: ?>
                        <?php if ($normalizedPagination['total'] > $normalizedPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $normalizedPagination['page'] ?> из <?= $normalizedPagination['totalPages'] ?> (показано <?= count($normalizedPagination['files']) ?> из <?= $normalizedPagination['total'] ?>)</span>
                                <?php if ($normalizedPagination['page'] > 1): ?>
                                    <a href="?tab=normalized&page=<?= $normalizedPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($normalizedPagination['page'] < $normalizedPagination['totalPages']): ?>
                                    <a href="?tab=normalized&page=<?= $normalizedPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'normalized'): ?>
                                    <a href="?tab=normalized&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=normalized" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($normalizedPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'normalized');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="process_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="normalized">
                                            <input type="hidden" name="tab" value="normalized">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Обработать файл <?= htmlspecialchars($file) ?>?')">Обработать</button>
                                        </form>
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'normalized', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="normalized">
                                            <input type="hidden" name="tab" value="normalized">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Processed -->
            <div id="tab-processed" class="tab-content <?= $activeTab === 'processed' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="processed">
                        <input type="hidden" name="tab" value="processed">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Processed? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $processedPagination = getPaginatedFiles($queueFiles['processed'], $currentPage, $perPage, $showAll && $activeTab === 'processed');
                    ?>
                    <h2>Processed очередь (<?= $processedPagination['total'] ?> файлов)</h2>
                    <?php if (empty($processedPagination['files'])): ?>
                        <p>Нет файлов в очереди Processed</p>
                    <?php else: ?>
                        <?php if ($processedPagination['total'] > $processedPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $processedPagination['page'] ?> из <?= $processedPagination['totalPages'] ?> (показано <?= count($processedPagination['files']) ?> из <?= $processedPagination['total'] ?>)</span>
                                <?php if ($processedPagination['page'] > 1): ?>
                                    <a href="?tab=processed&page=<?= $processedPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($processedPagination['page'] < $processedPagination['totalPages']): ?>
                                    <a href="?tab=processed&page=<?= $processedPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'processed'): ?>
                                    <a href="?tab=processed&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=processed" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($processedPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'processed');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'processed', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="processed">
                                            <input type="hidden" name="tab" value="processed">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Duplicates -->
            <div id="tab-duplicates" class="tab-content <?= $activeTab === 'duplicates' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="duplicates">
                        <input type="hidden" name="tab" value="duplicates">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Duplicates? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $duplicatesPagination = getPaginatedFiles($queueFiles['duplicates'], $currentPage, $perPage, $showAll && $activeTab === 'duplicates');
                    ?>
                    <h2>Duplicates очередь (<?= $duplicatesPagination['total'] ?> файлов)</h2>
                    <?php if (empty($duplicatesPagination['files'])): ?>
                        <p>Нет файлов в очереди Duplicates</p>
                    <?php else: ?>
                        <?php if ($duplicatesPagination['total'] > $duplicatesPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $duplicatesPagination['page'] ?> из <?= $duplicatesPagination['totalPages'] ?> (показано <?= count($duplicatesPagination['files']) ?> из <?= $duplicatesPagination['total'] ?>)</span>
                                <?php if ($duplicatesPagination['page'] > 1): ?>
                                    <a href="?tab=duplicates&page=<?= $duplicatesPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($duplicatesPagination['page'] < $duplicatesPagination['totalPages']): ?>
                                    <a href="?tab=duplicates&page=<?= $duplicatesPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'duplicates'): ?>
                                    <a href="?tab=duplicates&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=duplicates" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($duplicatesPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'duplicates');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'duplicates', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="duplicates">
                                            <input type="hidden" name="tab" value="duplicates">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                </div>
            </div>
            
            <!-- Вкладка: Failed -->
            <div id="tab-failed" class="tab-content <?= $activeTab === 'failed' ? 'active' : '' ?>">
                <div class="queue-actions">
                    <form method="post" style="display: inline-block;">
                        <input type="hidden" name="action" value="process_all">
                        <input type="hidden" name="queue" value="failed">
                        <input type="hidden" name="tab" value="failed">
                        <button type="submit" class="btn btn-success">Обработать все файлы</button>
                    </form>
                    <form method="post" style="display: inline-block; margin-left: 10px;">
                        <input type="hidden" name="action" value="delete_all">
                        <input type="hidden" name="queue" value="failed">
                        <input type="hidden" name="tab" value="failed">
                        <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить ВСЕ файлы из Failed? Это действие нельзя отменить!')">Удалить все файлы</button>
                    </form>
                </div>
                <div class="file-list">
                    <?php 
                    $failedPagination = getPaginatedFiles($queueFiles['failed'], $currentPage, $perPage, $showAll && $activeTab === 'failed');
                    ?>
                    <h2>Failed очередь (<?= $failedPagination['total'] ?> файлов)</h2>
                    <?php if (empty($failedPagination['files'])): ?>
                        <p>Нет файлов в очереди Failed</p>
                    <?php else: ?>
                        <?php if ($failedPagination['total'] > $failedPagination['perPage']): ?>
                            <div class="pagination-controls" style="margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 4px;">
                                <span>Страница <?= $failedPagination['page'] ?> из <?= $failedPagination['totalPages'] ?> (показано <?= count($failedPagination['files']) ?> из <?= $failedPagination['total'] ?>)</span>
                                <?php if ($failedPagination['page'] > 1): ?>
                                    <a href="?tab=failed&page=<?= $failedPagination['page'] - 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">← Назад</a>
                                <?php endif; ?>
                                <?php if ($failedPagination['page'] < $failedPagination['totalPages']): ?>
                                    <a href="?tab=failed&page=<?= $failedPagination['page'] + 1 ?>&per_page=<?= $perPage ?>" class="btn btn-sm" style="margin-left: 10px;">Вперед →</a>
                                <?php endif; ?>
                                <?php if (!$showAll || $activeTab !== 'failed'): ?>
                                    <a href="?tab=failed&show_all=1" class="btn btn-sm btn-primary" style="margin-left: 10px;">Показать все</a>
                                <?php else: ?>
                                    <a href="?tab=failed" class="btn btn-sm btn-secondary" style="margin-left: 10px;">Показать по 100</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($failedPagination['files'] as $file): 
                            $fileId = preg_replace('/[^a-zA-Z0-9]/', '_', $file);
                            $isViewing = ($viewFile === $file && $viewTab === 'failed');
                        ?>
                            <div class="file-item" id="file-<?= $fileId ?>">
                                <div class="file-item-header">
                                    <div class="file-name"><?= htmlspecialchars($file) ?></div>
                                    <div class="file-actions">
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="process_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="failed">
                                            <input type="hidden" name="tab" value="failed">
                                            <button type="submit" class="btn btn-success" onclick="return confirm('Обработать файл <?= htmlspecialchars($file) ?>?')">Обработать</button>
                                        </form>
                                        <button type="button" class="btn btn-primary" onclick="toggleFileView('<?= $fileId ?>', 'failed', '<?= htmlspecialchars($file, ENT_QUOTES) ?>')">Просмотр</button>
                                        <form method="post" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_file">
                                            <input type="hidden" name="file" value="<?= htmlspecialchars($file) ?>">
                                            <input type="hidden" name="queue" value="failed">
                                            <input type="hidden" name="tab" value="failed">
                                            <button type="submit" class="btn btn-danger" onclick="return confirm('Удалить файл <?= htmlspecialchars($file) ?>?')">Удалить</button>
                                        </form>
                                    </div>
                                </div>
                                <?php if ($isViewing && $viewData): ?>
                                <div class="file-view-content active" id="view-<?= $fileId ?>">
                                    <pre><?= htmlspecialchars(json_encode($viewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
} else {
    // CLI режим
    if (!function_exists('retryRawErrors')) {
        die("Ошибка: функция retryRawErrors не найдена. Проверьте подключение queue_manager.php\n");
    }
    $retried = retryRawErrors($config);
    echo "Отправлено на повторную обработку $retried файлов из raw_errors\n";
}
?>

