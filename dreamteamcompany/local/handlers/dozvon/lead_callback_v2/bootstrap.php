<?php
/**
 * Local bootstrap for lead_callback_v2 only.
 */

if (!isset($_SERVER['DOCUMENT_ROOT']) || $_SERVER['DOCUMENT_ROOT'] === '') {
    $fromEnv = getenv('DOCUMENT_ROOT');
    if (is_string($fromEnv) && $fromEnv !== '') {
        $_SERVER['DOCUMENT_ROOT'] = $fromEnv;
    }
}

if (!isset($_SERVER['DOCUMENT_ROOT']) || $_SERVER['DOCUMENT_ROOT'] === '') {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'DOCUMENT_ROOT not defined'], JSON_UNESCAPED_UNICODE));
}

$leadCallbackV2DocumentRoot = realpath($_SERVER['DOCUMENT_ROOT']);
if ($leadCallbackV2DocumentRoot === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Invalid DOCUMENT_ROOT'], JSON_UNESCAPED_UNICODE));
}

if (PHP_SAPI === 'cli') {
    if (!defined('BX_CRONTAB')) {
        define('BX_CRONTAB', true);
    }
    if (!defined('NOT_CHECK_PERMISSIONS')) {
        define('NOT_CHECK_PERMISSIONS', true);
    }
}

require_once $leadCallbackV2DocumentRoot . '/bitrix/modules/main/include/prolog_before.php';

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Bitrix not initialized'], JSON_UNESCAPED_UNICODE));
}

$leadCallbackV2ConfigPath = __DIR__ . '/config.php';
if (!is_file($leadCallbackV2ConfigPath)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Config not found: lead_callback_v2/config.php'], JSON_UNESCAPED_UNICODE));
}

$LEAD_CALLBACK_V2_CONFIG = require $leadCallbackV2ConfigPath;
$GLOBALS['LEAD_CALLBACK_V2_CONFIG'] = $LEAD_CALLBACK_V2_CONFIG;

$cronKey = isset($_GET['cron_key']) ? (string)$_GET['cron_key'] : (isset($_POST['cron_key']) ? (string)$_POST['cron_key'] : '');
$secret = (string)($LEAD_CALLBACK_V2_CONFIG['CRON_SECRET_KEY'] ?? '');
$allowedIps = (array)($LEAD_CALLBACK_V2_CONFIG['ALLOWED_IPS'] ?? []);

$isWebhook = false;
if ($secret !== '' && $cronKey !== '' && hash_equals($secret, $cronKey)) {
    $isWebhook = true;
} elseif ($secret === '') {
    $isWebhook = true;
}

if ($isWebhook && !empty($allowedIps)) {
    $remoteIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!in_array($remoteIp, $allowedIps, true)) {
        $isWebhook = false;
    }
}

$isAdmin = isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser && $GLOBALS['USER']->IsAdmin();
if (!$isWebhook && !$isAdmin) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    die(json_encode(['error' => 'Access denied: admin or cron_key required'], JSON_UNESCAPED_UNICODE));
}

$systemUserId = (int)($LEAD_CALLBACK_V2_CONFIG['LEAD_CALLBACK_V2_SYSTEM_USER_ID'] ?? 0);
if (!$isAdmin && $systemUserId > 0 && isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser) {
    $currentUserId = (int)$GLOBALS['USER']->GetID();
    if ($currentUserId !== $systemUserId) {
        $authResult = $GLOBALS['USER']->Authorize($systemUserId);
        lead_callback_v2_bootstrap_log($LEAD_CALLBACK_V2_CONFIG, 'bootstrap:system_user_authorize', [
            'system_user_id' => $systemUserId,
            'current_user_id_before' => $currentUserId,
            'auth_result' => $authResult,
            'current_user_id_after' => (int)$GLOBALS['USER']->GetID(),
        ]);
    }
} elseif (!$isAdmin && $systemUserId <= 0) {
    lead_callback_v2_bootstrap_log($LEAD_CALLBACK_V2_CONFIG, 'bootstrap:no_system_user_configured');
}

function lead_callback_v2_bootstrap_log(array $config, $message, array $context = [])
{
    $path = !empty($config['LEAD_CALLBACK_V2_LOG_FILE'])
        ? (string)$config['LEAD_CALLBACK_V2_LOG_FILE']
        : (__DIR__ . '/lead_callback_v2.log');

    $line = date('Y-m-d H:i:s') . ' ' . (string)$message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}
