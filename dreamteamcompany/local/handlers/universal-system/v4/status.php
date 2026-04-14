<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use UniversalSystem\V4\Queue\FileQueue;
use UniversalSystem\V4\Support\Logger;

$config = v4_config();
$logger = new Logger($config);
$queue = new FileQueue($config, $logger);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'counts' => $queue->counts(),
    'stale_processing' => $queue->staleProcessing(),
    'recent_errors' => $queue->recentErrors(20),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
