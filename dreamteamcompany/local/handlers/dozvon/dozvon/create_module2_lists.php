<?php
declare(strict_types=1);

/**
 * Создаёт два списка модуля 2 и их поля через настроенный Bitrix REST webhook.
 * Запуск: браузер/CLI под админом или с cron_key через bootstrap.
 */
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonModule2Schema.php';
require_once __DIR__ . '/DozvonRestWebhookClient.php';
require_once __DIR__ . '/DozvonModule2ListProvisioner.php';

$config = $GLOBALS['DOZVON_CONFIG'];

header('Content-Type: application/json; charset=utf-8');

try {
    $provisioner = new DozvonModule2ListProvisioner($config);
    $result = $provisioner->ensureLists();
    if (function_exists('dozvon_log')) {
        dozvon_log('create_module2_lists', $result);
    }
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (function_exists('dozvon_log')) {
        dozvon_log('create_module2_lists_error', ['error' => $e->getMessage()]);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
