#!/usr/bin/env php
<?php
/**
 * Register ONCRMLEADADD event handler in Bitrix24 cloud.
 * Bitrix24 will POST to handler_url when a new lead is created.
 *
 * Usage: php register_lead_webhook.php [handler_url]
 *   handler_url - optional, overrides config/webhook.php handler_url
 */
$projectRoot = __DIR__;
$handlerUrl = $argv[1] ?? null;

$config = require $projectRoot . '/config/webhook.php';
$handlerUrl = $handlerUrl ?: ($config['handler_url'] ?? '');

if (empty($handlerUrl)) {
    fwrite(STDERR, "Usage: php register_lead_webhook.php <handler_url>\n");
    fwrite(STDERR, "  Or set config/webhook.php handler_url and run without args.\n");
    fwrite(STDERR, "  handler_url must be HTTPS, e.g. https://your-server.com/webhook_lead_handler.php\n");
    exit(1);
}

if (strpos($handlerUrl, 'https://') !== 0) {
    fwrite(STDERR, "Error: handler_url must use HTTPS\n");
    exit(1);
}

require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$resp = $client->call('event.bind', [
    'event' => 'ONCRMLEADADD',
    'handler' => rtrim($handlerUrl, '/'),
]);

if (isset($resp['error'])) {
    fwrite(STDERR, "Error: " . ($resp['error_description'] ?? $resp['error']) . "\n");
    exit(1);
}

echo "OK: ONCRMLEADADD handler registered: $handlerUrl\n";
echo "Bitrix24 will POST to this URL when a new lead is created.\n";
