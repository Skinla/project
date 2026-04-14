<?php

/**
 * Шаблон на случай форка: в продакшене используется готовый config.php в корне.
 * В Tilda URL приёмника: https://ваш-домен/webhook.php
 */
return [
    'bitrix_webhook_base' => 'https://YOUR_PORTAL.bitrix24.ru/rest/USER_ID/SECRET/',
    'category_id' => 1,
    'assigned_by_id' => 1,
    /** Код источника (SOURCE_ID), напр. WEB */
    'source_id' => 'WEB',
    /** Код стадии (STATUS_ID), напр. C1:NEW */
    'stage_id' => 'C1:NEW',
    'log_dir' => __DIR__ . '/logs',
    /** Опционально: то же значение, что в настройках Webhook Tilda (API-ключ) */
    'tilda_shared_secret' => null,
    'tilda_secret_post_field' => 'api_key',
];
