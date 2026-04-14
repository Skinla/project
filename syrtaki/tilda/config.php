<?php

declare(strict_types=1);

/**
 * Продакшен-конфиг: подключается только из webhook.php / скриптов (см. TILDA_CONFIG_INCLUDE).
 * Прямой заход в браузере на config.php запрещён.
 */
if (!defined('TILDA_CONFIG_INCLUDE')) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Forbidden');
    }
    fwrite(STDERR, "config.php подключается только из webhook.php (define TILDA_CONFIG_INCLUDE).\n");
    exit(1);
}

return [
    'bitrix_webhook_base' => 'https://syrtaki-bielefeld.bitrix24.ru/rest/11/1clcd34v2u2zugrs/',
    'category_id' => 1,
    'assigned_by_id' => 1,
    /** Код источника сделки (SOURCE_ID), напр. WEB */
    'source_id' => 'WEB',
    /** Код стадии (STATUS_ID), напр. C1:NEW */
    'stage_id' => 'C1:NEW',
    'log_dir' => __DIR__ . '/logs',
    'tilda_shared_secret' => null,
    'tilda_secret_post_field' => 'api_key',
];
