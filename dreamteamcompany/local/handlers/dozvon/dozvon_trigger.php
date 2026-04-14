<?php
/**
 * Точка входа без авторизации (как universal/webhook.php).
 * Размещение в продакшене: local/handlers/universal/dozvon_trigger.php
 * Вызов: .../universal/dozvon_trigger.php?element_id=123
 * Запускает обработчик через CLI — Bitrix не редиректит на логин (нет HTTP-сессии).
 */

header('Content-Type: application/json; charset=utf-8');

$elementId = (int)($_GET['element_id'] ?? $_POST['element_id'] ?? 0);
if ($elementId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'element_id required', 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$dozvonDir = __DIR__ . '/dozvon';
$script = $dozvonDir . '/run_process_trigger_once.php';
if (!is_file($script)) {
    echo json_encode(['ok' => false, 'error' => 'Handler not found', 'element_id' => $elementId, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$documentRoot = realpath(__DIR__ . '/../../..');
if ($documentRoot === false) {
    echo json_encode(['ok' => false, 'error' => 'DOCUMENT_ROOT not resolved', 'element_id' => $elementId, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$phpBinary = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
$cmd = 'env DOCUMENT_ROOT=' . escapeshellarg($documentRoot) . ' ' . escapeshellcmd($phpBinary) . ' ' . escapeshellarg($script) . ' ' . (int)$elementId;
$output = @shell_exec($cmd);

if ($output === null || $output === '') {
    echo json_encode(['ok' => false, 'error' => 'Handler did not return (shell_exec disabled?)', 'element_id' => $elementId, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// CLI вернул HTML (страница логина) — константы BX_CRONTAB/NOT_CHECK_PERMISSIONS не сработали
if (stripos($output, '<!DOCTYPE') !== false || stripos($output, '<html') !== false) {
    echo json_encode(['ok' => false, 'error' => 'Handler returned login page (Bitrix auth in CLI)', 'element_id' => $elementId, 'errors' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $output;
