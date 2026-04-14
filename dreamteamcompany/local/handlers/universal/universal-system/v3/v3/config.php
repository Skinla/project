<?php
// v3/config.php

return [
    'webhook_url' => 'https://bitrix.dreamteamcompany.ru/rest/1/wwse63cz9w29662p/',
    'error_chat_id' => 'chat69697',

    'log_file' => __DIR__ . '/logs/webhook.log',
    'max_log_size' => 5 * 1024 * 1024,

    'retry_dir' => __DIR__ . '/queue/retry',
    'errors_dir' => __DIR__ . '/queue/errors',
    'processed_dir' => __DIR__ . '/queue/processed',
    'max_processed_files' => 200,

    'assigned_by_id_default' => 1,
    'max_retries' => 3,
    'retry_delay' => 1000000,
    'curl_timeout' => 30,
];
