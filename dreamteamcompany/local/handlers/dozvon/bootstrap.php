<?php
/**
 * Подключение Bitrix и проверка доступа к скриптам недозвона.
 * Размещение: local/handlers/dozvon/bootstrap.php.
 * Допуск: админ (веб) или cron_key (если задан CRON_SECRET_KEY), либо при пустом ключе — любой запрос (БП, интеграции без кодов). Ограничение по IP — ALLOWED_IPS.
 */

if (!isset($_SERVER['DOCUMENT_ROOT']) || empty($_SERVER['DOCUMENT_ROOT'])) {
    $fromEnv = getenv('DOCUMENT_ROOT');
    if ($fromEnv !== false && $fromEnv !== '') {
        $_SERVER['DOCUMENT_ROOT'] = $fromEnv;
    }
}
if (!isset($_SERVER['DOCUMENT_ROOT']) || empty($_SERVER['DOCUMENT_ROOT'])) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'DOCUMENT_ROOT not defined']));
}

$documentRoot = realpath($_SERVER['DOCUMENT_ROOT']);
if ($documentRoot === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Invalid DOCUMENT_ROOT']));
}

// CLI: Bitrix не должен показывать страницу логина (cron/агент)
if (php_sapi_name() === 'cli') {
    if (!defined('BX_CRONTAB')) {
        define('BX_CRONTAB', true);
    }
    if (!defined('NOT_CHECK_PERMISSIONS')) {
        define('NOT_CHECK_PERMISSIONS', true);
    }
}

require_once $documentRoot . '/bitrix/modules/main/include/prolog_before.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Bitrix not initialized']));
}

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Config not found: config.php']));
}

$DOZVON_CONFIG = require $configPath;
$GLOBALS['DOZVON_CONFIG'] = $DOZVON_CONFIG;

$cronKey = isset($_GET['cron_key']) ? (string)$_GET['cron_key'] : (isset($_POST['cron_key']) ? (string)$_POST['cron_key'] : '');
$secret = $DOZVON_CONFIG['CRON_SECRET_KEY'] ?? '';
$allowedIps = $DOZVON_CONFIG['ALLOWED_IPS'] ?? [];

// Доступ: при заданном CRON_SECRET_KEY — только cron_key или админ; при пустом ключе — любой запрос (БП, интеграции без кодов)
$isCron = false;
if ($secret !== '' && $cronKey !== '' && hash_equals($secret, $cronKey)) {
    $isCron = true;
} elseif ($secret === '') {
    $isCron = true;
}
if ($isCron && !empty($allowedIps)) {
    $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remoteIp, $allowedIps, true)) {
        $isCron = false;
    }
}

$isAdmin = isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser && $GLOBALS['USER']->IsAdmin();

if (!$isCron && !$isAdmin) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Access denied: admin or cron_key required']));
}

/**
 * Краткая запись в лог из конфига.
 */
function dozvon_log(string $message, array $context = []): void {
    global $DOZVON_CONFIG;
    $logFile = $DOZVON_CONFIG['LOG_FILE'] ?? '';
    if ($logFile === '') {
        return;
    }
    $line = date('Y-m-d H:i:s') . ' ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
