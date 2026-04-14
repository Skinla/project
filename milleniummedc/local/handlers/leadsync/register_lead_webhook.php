#!/usr/bin/env php
<?php
/**
 * Register ONCRMLEADADD event handler in Bitrix24 cloud.
 * Run from project root or from this directory.
 *
 * Usage: php register_lead_webhook.php
 */
$baseDir = __DIR__;
$config = require $baseDir . '/config/webhook.php';

$handlerUrl = 'https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php';

require_once $baseDir . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$resp = $client->call('event.bind', [
    'event' => 'ONCRMLEADADD',
    'handler' => $handlerUrl,
]);

if (isset($resp['error'])) {
    fwrite(STDERR, "Error: " . ($resp['error_description'] ?? $resp['error']) . "\n");
    exit(1);
}

echo "OK: ONCRMLEADADD handler registered: $handlerUrl\n";
echo "Bitrix24 will POST to this URL when a new lead is created.\n";
