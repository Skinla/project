<?php

declare(strict_types=1);

$baseDir = __DIR__;
$dataDir = $baseDir . '/data';

return [
    'base_dir' => $baseDir,
    'data_dir' => $dataDir,
    'logs_dir' => $baseDir . '/logs',
    'log_file' => 'v4.log',
    'enable_debug_logging' => false,
    'queue_dirs' => [
        'incoming' => $dataDir . '/incoming',
        'processing' => $dataDir . '/processing',
        'done' => $dataDir . '/done',
        'error' => $dataDir . '/error',
    ],
    'portal_webhooks' => [
        'dreamteamcompany' => 'https://bitrix.dreamteamcompany.ru/rest/1/wwse63cz9w29662p/',
    ],
    'default_portal' => 'dreamteamcompany',
    'default_assigned_by_id' => 1,
    'curl_timeout' => 30,
    'max_api_retries' => 3,
    'api_retry_delay_seconds' => 1,
    'max_attempts_per_request' => 3,
    'max_items_per_run' => 20,
    'processing_stale_after_seconds' => 300,
    'iblock' => [
        'source_config_id' => 54,
        'source_list_id' => 19,
        'city_list_id' => 22,
    ],
];
