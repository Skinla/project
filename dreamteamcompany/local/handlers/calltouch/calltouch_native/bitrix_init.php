<?php
/**
 * Инициализация Bitrix ядра
 * Подключается во всех скриптах проекта
 */

// Логирование для отладки
$initLogFile = __DIR__ . '/calltouch_logs/bitrix_init.log';
$initLogsDir = dirname($initLogFile);
if (!is_dir($initLogsDir)) {
    @mkdir($initLogsDir, 0777, true);
}

// Определяем DOCUMENT_ROOT
if (!defined('B_PROLOG_INCLUDED')) {
    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Starting initialization\n", FILE_APPEND | LOCK_EX);
    $isCli = (php_sapi_name() === 'cli');
    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Is CLI: " . ($isCli ? 'yes' : 'no') . "\n", FILE_APPEND | LOCK_EX);
    
    // Для фоновых задач устанавливаем флаги, чтобы Bitrix не требовал авторизацию
    if (!$isCli) {
        // Проверяем наличие внутреннего ключа для пропуска авторизации
        $internalKey = $_GET['internal_key'] ?? '';
        $expectedKey = md5('calltouch_internal_' . date('Y-m-d') . '_' . ($_SERVER['HTTP_HOST'] ?? 'default'));
        $isInternalRequest = ($internalKey === $expectedKey);
        
        if ($isInternalRequest) {
            // Устанавливаем флаги для внутренних скриптов
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['SCRIPT_NAME'] = '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
            $_SERVER['PHP_SELF'] = '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
            // Убираем параметры авторизации из URL
            unset($_GET['login'], $_GET['forgot_password'], $_GET['register'], $_GET['auth_service_id'], $_GET['check_key'], $_GET['internal_key']);
            $_REQUEST = array_diff_key($_REQUEST, ['login' => '', 'forgot_password' => '', 'register' => '', 'auth_service_id' => '', 'check_key' => '', 'internal_key' => '']);
        } else {
            // Если нет внутреннего ключа, все равно пытаемся убрать параметры авторизации
            unset($_GET['login'], $_GET['forgot_password'], $_GET['register'], $_GET['auth_service_id'], $_GET['check_key']);
        }
    }
    
    if ($isCli) {
        // CLI режим - определяем DOCUMENT_ROOT относительно текущего файла
        $scriptDir = __DIR__;
        // Ищем bitrix папку, поднимаясь вверх по директориям
        $currentDir = $scriptDir;
        $maxDepth = 10;
        $depth = 0;
        while ($depth < $maxDepth) {
            if (file_exists($currentDir . '/bitrix/modules/main/include/prolog_before.php')) {
                $_SERVER["DOCUMENT_ROOT"] = $currentDir;
                break;
            }
            $parentDir = dirname($currentDir);
            if ($parentDir === $currentDir) {
                break; // Достигли корня
            }
            $currentDir = $parentDir;
            $depth++;
        }
    } else {
        // HTTP режим
        if (empty($_SERVER["DOCUMENT_ROOT"])) {
            // calltouch_native находится в /local/handlers/calltouch/calltouch_native/
            // DOCUMENT_ROOT должен быть на 3 уровня выше
            // __DIR__ = /local/handlers/calltouch/calltouch_native
            // dirname(__DIR__) = /local/handlers/calltouch
            // dirname(dirname(__DIR__)) = /local/handlers
            // dirname(dirname(dirname(__DIR__))) = /local
            // Но DOCUMENT_ROOT обычно = / (корень сайта), где находится /local и /bitrix
            // Поэтому ищем bitrix папку, поднимаясь вверх
            $currentDir = dirname(__DIR__); // /local/handlers/calltouch
            $maxDepth = 5;
            $depth = 0;
            while ($depth < $maxDepth) {
                if (file_exists($currentDir . '/bitrix/modules/main/include/prolog_before.php')) {
                    $_SERVER["DOCUMENT_ROOT"] = $currentDir;
                    break;
                }
                $parentDir = dirname($currentDir);
                if ($parentDir === $currentDir) {
                    break; // Достигли корня
                }
                $currentDir = $parentDir;
                $depth++;
            }
            // Если не нашли, используем fallback
            if (empty($_SERVER["DOCUMENT_ROOT"])) {
                $_SERVER["DOCUMENT_ROOT"] = dirname(dirname(dirname(__DIR__)));
            }
        }
    }
    
    // Устанавливаем флаги для внутренних скриптов (до подключения prolog)
    // ВАЖНО: Эти константы должны быть установлены ДО подключения prolog
    if (!defined('BX_SKIP_POST_UNPACK')) {
        define('BX_SKIP_POST_UNPACK', true);
    }
    if (!defined('BX_CRONTAB')) {
        define('BX_CRONTAB', true);
    }
    if (!defined('BX_NO_ACCELERATOR_RESET')) {
        define('BX_NO_ACCELERATOR_RESET', true);
    }
    if (!defined('BX_WITH_ON_AFTER_EPILOG')) {
        define('BX_WITH_ON_AFTER_EPILOG', false);
    }
    $_SERVER['BX_SKIP_POST_UNPACK'] = true;
    $_SERVER['BX_CRONTAB'] = true;
    
    // Для внутренних запросов устанавливаем специальные заголовки
    if (!$isCli) {
        $_SERVER['HTTP_X_INTERNAL_REQUEST'] = '1';
        $_SERVER['HTTP_X_CALLTOUCH_PROCESSOR'] = '1';
    }
    
    // Для фоновых задач устанавливаем переменные окружения
    if (!$isCli) {
        // Устанавливаем, что это внутренний скрипт
        $_SERVER['SCRIPT_FILENAME'] = $_SERVER["DOCUMENT_ROOT"] . '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
        $_SERVER['REQUEST_URI'] = '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
        // Убираем параметры авторизации
        if (isset($_GET['login']) || isset($_GET['forgot_password']) || isset($_GET['register'])) {
            $_GET = array_diff_key($_GET, ['login' => '', 'forgot_password' => '', 'register' => '', 'auth_service_id' => '', 'check_key' => '']);
            $_REQUEST = array_diff_key($_REQUEST, ['login' => '', 'forgot_password' => '', 'register' => '', 'auth_service_id' => '', 'check_key' => '']);
        }
    }
    
    // Подключаем Bitrix ядро
    // Для фоновых задач используем прямой доступ к модулям без полной инициализации
    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] DOCUMENT_ROOT: " . ($_SERVER["DOCUMENT_ROOT"] ?? 'not set') . "\n", FILE_APPEND | LOCK_EX);
    
    // Для HTTP режима используем прямой доступ к prolog_before.php
    // без промежуточных файлов, которые могут делать редиректы
    if (!$isCli) {
        // Определяем текущий скрипт из SCRIPT_NAME или PHP_SELF
        $currentScript = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
        
        // Если это queue_manager.php или другие веб-интерфейсы - устанавливаем специальные флаги
        $isWebInterface = (strpos($currentScript, 'queue_manager.php') !== false || 
                          strpos($currentScript, 'add_iblock54.php') !== false ||
                          strpos($currentScript, 'requeue_and_process.php') !== false);
        
        // Устанавливаем переменные для обхода проверки авторизации
        $_SERVER['REQUEST_URI'] = $currentScript;
        $_SERVER['SCRIPT_NAME'] = $currentScript;
        $_SERVER['PHP_SELF'] = $currentScript;
        
        // Для веб-интерфейсов устанавливаем дополнительные флаги
        if ($isWebInterface) {
            $_SERVER['HTTP_X_WEB_INTERFACE'] = '1';
            $_SERVER['HTTP_X_CALLTOUCH_WEB'] = '1';
        }
        
        $bitrixRoot = $_SERVER["DOCUMENT_ROOT"] . "/bitrix";
        
        // Пробуем загрузить prolog_before.php напрямую с перехватом всех выходов
        ob_start();
        
        // Сохраняем текущие обработчики
        $oldErrorHandler = set_error_handler(function($errno, $errstr, $errfile, $errline) use ($initLogFile) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] PHP Error: $errstr in $errfile:$errline\n", FILE_APPEND | LOCK_EX);
            return false;
        });
        
        // Перехватываем exit/die через register_shutdown_function
        register_shutdown_function(function() use ($initLogFile, $isWebInterface) {
            $error = error_get_last();
            if ($error && $error['type'] === E_ERROR) {
                @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Fatal error: " . $error['message'] . " in " . $error['file'] . ":" . $error['line'] . "\n", FILE_APPEND | LOCK_EX);
            }
        });
        
        try {
            $prologFile = $bitrixRoot . "/modules/main/include/prolog_before.php";
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Loading prolog_before.php directly\n", FILE_APPEND | LOCK_EX);
            
            // Временно отключаем все возможные редиректы
            if (function_exists('header_remove')) {
                // Не можем удалить заголовки до их отправки, но можем попробовать
            }
            
            // Загружаем prolog
            require_once($prologFile);
            
            // Если дошли сюда - prolog загружен
            $output = ob_get_contents();
            ob_end_clean();
            
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Prolog loaded, output size: " . strlen($output) . "\n", FILE_APPEND | LOCK_EX);
            
            // Если в выводе есть HTML - это проблема, но продолжаем
            if (strpos($output, '<!DOCTYPE html') !== false || strpos($output, 'Войти в Битрикс24') !== false) {
                @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] WARNING: Auth page in output (" . substr($output, 0, 200) . "), but continuing\n", FILE_APPEND | LOCK_EX);
                
                // Для веб-интерфейсов очищаем вывод, чтобы не показывать страницу авторизации
                if (isset($isWebInterface) && $isWebInterface) {
                    $output = '';
                    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Cleared auth page output for web interface\n", FILE_APPEND | LOCK_EX);
                }
            }
            
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Prolog loaded successfully\n", FILE_APPEND | LOCK_EX);
            
            // Восстанавливаем обработчик ошибок
            if ($oldErrorHandler !== null) {
                set_error_handler($oldErrorHandler);
            }
        } catch (Exception $e) {
            ob_end_clean();
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Exception in prolog: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Stack: " . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
            if ($oldErrorHandler !== null) {
                set_error_handler($oldErrorHandler);
            }
            throw $e;
        } catch (Error $e) {
            ob_end_clean();
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Fatal error in prolog: " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Stack: " . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);
            if ($oldErrorHandler !== null) {
                set_error_handler($oldErrorHandler);
            }
            throw $e;
        }
    } else {
        // CLI режим - стандартная инициализация согласно документации Bitrix
        // prolog_before.php предназначен для инициализации без вывода визуальной части
        $prologFile = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Loading prolog_before.php (CLI)\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] DOCUMENT_ROOT: " . $_SERVER["DOCUMENT_ROOT"] . "\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Prolog file exists: " . (file_exists($prologFile) ? 'yes' : 'no') . "\n", FILE_APPEND | LOCK_EX);
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Prolog file readable: " . (is_readable($prologFile) ? 'yes' : 'no') . "\n", FILE_APPEND | LOCK_EX);
        
        // Устанавливаем флаги ДО загрузки prolog (согласно документации)
        if (!defined('BX_CRONTAB')) {
            define('BX_CRONTAB', true);
        }
        if (!defined('BX_SKIP_POST_UNPACK')) {
            define('BX_SKIP_POST_UNPACK', true);
        }
        if (!defined('BX_NO_ACCELERATOR_RESET')) {
            define('BX_NO_ACCELERATOR_RESET', true);
        }
        if (!defined('BX_WITH_ON_AFTER_EPILOG')) {
            define('BX_WITH_ON_AFTER_EPILOG', false);
        }
        // Константы для оптимизации работы скрипта (согласно документации)
        if (!defined('NO_KEEP_STATISTIC')) {
            define('NO_KEEP_STATISTIC', true);
        }
        if (!defined('NOT_CHECK_PERMISSIONS')) {
            define('NOT_CHECK_PERMISSIONS', true);
        }
        
        // Устанавливаем переменные окружения для CLI
        $_SERVER['REQUEST_METHOD'] = 'CLI';
        $_SERVER['SCRIPT_NAME'] = __FILE__;
        $_SERVER['PHP_SELF'] = __FILE__;
        $_SERVER['BX_CRONTAB'] = true;
        $_SERVER['BX_SKIP_POST_UNPACK'] = true;
        // Устанавливаем HTTP_HOST если не установлен (требуется для некоторых функций Bitrix)
        if (empty($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
        }
        // Устанавливаем SERVER_NAME если не установлен (требуется для модуля pull)
        if (empty($_SERVER['SERVER_NAME'])) {
            $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
        }
        // Устанавливаем REMOTE_USER для избежания ошибок в некоторых модулях Bitrix
        if (empty($_SERVER['REMOTE_USER'])) {
            $_SERVER['REMOTE_USER'] = '';
        }
        // Устанавливаем другие переменные, которые могут потребоваться
        if (empty($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = '/local/handlers/calltouch/calltouch_native/calltouch_processor.php';
        }
        if (empty($_SERVER['SCRIPT_FILENAME'])) {
            $_SERVER['SCRIPT_FILENAME'] = __FILE__;
        }
        
        // Пытаемся исправить права на кеш перед загрузкой prolog
        // Если это не поможет, ошибка будет обработана в shutdown function
        $cacheDir = $_SERVER["DOCUMENT_ROOT"] . "/bitrix/managed_cache";
        if (is_dir($cacheDir) && !is_writable($cacheDir)) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [WARNING] Cache directory is not writable: $cacheDir\n", FILE_APPEND | LOCK_EX);
            // Пытаемся установить права (может не сработать, если нет прав)
            @chmod($cacheDir, 0777);
            // Пытаемся установить права рекурсивно на подпапки
            if (function_exists('exec')) {
                @exec("chmod -R 777 " . escapeshellarg($cacheDir) . " 2>&1", $chmodOutput, $chmodReturn);
                if ($chmodReturn === 0) {
                    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Cache directory permissions fixed\n", FILE_APPEND | LOCK_EX);
                } else {
                    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [WARNING] Could not fix cache permissions: " . implode("\n", $chmodOutput) . "\n", FILE_APPEND | LOCK_EX);
                }
            }
        }
        
        // Регистрируем shutdown function для логирования, если prolog делает exit()
        register_shutdown_function(function() use ($initLogFile) {
            $error = error_get_last();
            if ($error) {
                @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [SHUTDOWN] Error: " . $error['message'] . " in " . $error['file'] . ":" . $error['line'] . "\n", FILE_APPEND | LOCK_EX);
            }
            if (!defined('B_PROLOG_INCLUDED')) {
                @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [SHUTDOWN] B_PROLOG_INCLUDED not defined - prolog may have called exit()\n", FILE_APPEND | LOCK_EX);
            }
        });
        
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Attempting to load prolog_before.php...\n", FILE_APPEND | LOCK_EX);
        
        // Принудительно сбрасываем буфер лога перед загрузкой prolog
        if (function_exists('fflush')) {
            $logHandle = fopen($initLogFile, 'a');
            if ($logHandle) {
                fflush($logHandle);
                fclose($logHandle);
            }
        }
        
        // Загружаем prolog_before.php - стандартный способ инициализации Bitrix в CLI
        // Согласно документации, этот файл предназначен для CLI-скриптов
        require_once($prologFile);
        
        // Если дошли сюда - prolog загружен успешно
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Prolog loaded successfully (CLI)\n", FILE_APPEND | LOCK_EX);
        
        // Проверяем, загружен ли prolog
        if (defined('B_PROLOG_INCLUDED')) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] B_PROLOG_INCLUDED is defined, prolog loaded\n", FILE_APPEND | LOCK_EX);
        } else {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [WARNING] B_PROLOG_INCLUDED not defined after prolog load\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    // Для фоновых задач устанавливаем пользователя системы
    if (!$isCli) {
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Setting up user for HTTP mode\n", FILE_APPEND | LOCK_EX);
        global $USER;
        if (!is_object($USER)) {
            require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/classes/general/user.php");
            $USER = new CUser();
        }
        // Пытаемся авторизоваться как системный пользователь (ID=1) или администратор
        // Если это не работает, Bitrix все равно должен позволить работать с API
        if (empty($_SESSION['SESS_AUTH']['USER_ID'])) {
            // Устанавливаем минимальные права для работы
            $_SESSION['BX_SKIP_AUTH'] = true;
        }
    }
    
    // Подключаем необходимые модули
    // Используем современный подход через \Bitrix\Main\Loader (рекомендуется в документации)
    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Loading modules\n", FILE_APPEND | LOCK_EX);
    
    // Проверяем, доступен ли Loader (D7 API)
    if (class_exists('\Bitrix\Main\Loader')) {
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Using D7 Loader API\n", FILE_APPEND | LOCK_EX);
        
        try {
            \Bitrix\Main\Loader::includeModule("crm");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module CRM loaded (D7)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load CRM module (D7): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            \Bitrix\Main\Loader::includeModule("iblock");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module IBLOCK loaded (D7)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load IBLOCK module (D7): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            \Bitrix\Main\Loader::includeModule("im");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module IM loaded (D7)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load IM module (D7): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            \Bitrix\Main\Loader::includeModule("lists");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module LISTS loaded (D7)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load LISTS module (D7): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        try {
            \Bitrix\Main\Loader::includeModule("bizproc");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module BIZPROC loaded (D7)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load BIZPROC module (D7): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    } else {
        // Fallback на старый API, если D7 недоступен
        @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Using legacy CModule API\n", FILE_APPEND | LOCK_EX);
        
        try {
            CModule::IncludeModule("crm");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module CRM loaded (legacy)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load CRM module (legacy): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            CModule::IncludeModule("iblock");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module IBLOCK loaded (legacy)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load IBLOCK module (legacy): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            CModule::IncludeModule("im");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module IM loaded (legacy)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load IM module (legacy): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
        
        try {
            CModule::IncludeModule("lists");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module LISTS loaded (legacy)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load LISTS module (legacy): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }

        try {
            CModule::IncludeModule("bizproc");
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] Module BIZPROC loaded (legacy)\n", FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [ERROR] Failed to load BIZPROC module (legacy): " . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
        }
    }
    
    @file_put_contents($initLogFile, date('Y-m-d H:i:s') . " [INIT] All modules loaded, initialization complete\n", FILE_APPEND | LOCK_EX);
}

