<?php
/**
 * Точка входа для запуска process_queue.php по URL без авторизации.
 * Размещение в продакшене: local/handlers/universal/dozvon_process_queue.php
 * Вызов: .../universal/dozvon_process_queue.php  (опционально: ?cron_key=... если в конфиге задан CRON_SECRET_KEY)
 * Запускает process_queue.php через CLI — Bitrix не редиректит на логин.
 */

header('Content-Type: application/json; charset=utf-8');

$dozvonDir = __DIR__ . '/dozvon';
$script = $dozvonDir . '/process_queue.php';
if (!is_file($script)) {
    echo json_encode(['ok' => false, 'error' => 'process_queue.php not found', 'processed' => 0, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$documentRoot = realpath(__DIR__ . '/../../..');
if ($documentRoot === false) {
    echo json_encode(['ok' => false, 'error' => 'DOCUMENT_ROOT not resolved', 'processed' => 0, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = 'env DOCUMENT_ROOT=' . escapeshellarg($documentRoot) . ' ' . escapeshellcmd($phpBinary) . ' ' . escapeshellarg($script);
$output = @shell_exec($cmd);

if ($output === null || $output === '') {
    echo json_encode(['ok' => false, 'error' => 'Handler did not return (shell_exec disabled?)', 'processed' => 0, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if (stripos($output, '<!DOCTYPE') !== false || stripos($output, '<html') !== false) {
    echo json_encode(['ok' => false, 'error' => 'Handler returned login page (Bitrix auth in CLI)', 'processed' => 0, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $output;
