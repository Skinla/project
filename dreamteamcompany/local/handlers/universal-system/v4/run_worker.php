<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use UniversalSystem\V4\Bitrix\LeadCreator;
use UniversalSystem\V4\Bitrix\SourceResolver;
use UniversalSystem\V4\Queue\FileQueue;
use UniversalSystem\V4\Routing\HandlerSelector;
use UniversalSystem\V4\Support\Logger;
use UniversalSystem\V4\Worker;

$config = v4_config();
$logger = new Logger($config);
$worker = new Worker(
    $config,
    $logger,
    new FileQueue($config, $logger),
    new HandlerSelector(),
    new SourceResolver($config, $logger),
    new LeadCreator($config, $logger)
);

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : (isset($_SERVER['argv'][1]) ? (int)$_SERVER['argv'][1] : (int)($config['max_items_per_run'] ?? 20));
$limit = $limit > 0 ? $limit : (int)($config['max_items_per_run'] ?? 20);
$result = $worker->processBatch($limit);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
