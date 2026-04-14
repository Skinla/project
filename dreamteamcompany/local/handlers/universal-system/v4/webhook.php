<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use UniversalSystem\V4\Queue\FileQueue;
use UniversalSystem\V4\Support\Logger;
use UniversalSystem\V4\Support\RequestEnvelope;

$config = v4_config();
$logger = new Logger($config);
$queue = new FileQueue($config, $logger);

header('Content-Type: application/json; charset=utf-8');

$rawBody = file_get_contents('php://input');
$envelope = RequestEnvelope::fromGlobals($_SERVER, $_GET, $_POST, $rawBody === false ? '' : $rawBody);

if ($envelope->isTestRequest()) {
    echo json_encode(['status' => 'ok', 'message' => 'webhook_available'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$envelope->hasProcessablePhone()) {
    echo json_encode(['status' => 'ok', 'message' => 'empty_phone'], JSON_UNESCAPED_UNICODE);
    exit;
}

$existing = $queue->findByRequestId($envelope->requestId);
if ($existing !== null) {
    $existingData = $existing['data'];
    $state = $existing['state'];
    $leadId = $existingData['result']['lead_id'] ?? null;
    $payload = [
        'status' => 'ok',
        'message' => $state === 'done' ? 'already_processed' : ('already_' . $state),
        'request_id' => $envelope->requestId,
        'state' => $state,
    ];
    if (is_numeric($leadId)) {
        $payload['lead_id'] = (int)$leadId;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$path = $queue->enqueue($envelope->toArray());
$logger->info('Request enqueued', ['request_id' => $envelope->requestId, 'path' => $path]);

echo json_encode([
    'status' => 'ok',
    'message' => 'queued',
    'request_id' => $envelope->requestId,
], JSON_UNESCAPED_UNICODE);
