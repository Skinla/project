#!/usr/bin/env php
<?php
/**
 * Fetch contacts from cloud for contact mapping.
 * Output: JSON array to data/cloud_contacts.json
 */
$projectRoot = __DIR__;
$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$contacts = [];
$start = 0;
do {
    $resp = $client->call('crm.contact.list', [
        'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE', 'EMAIL'],
        'order' => ['ID' => 'ASC'],
        'start' => $start,
    ]);
    $batch = $resp['result'] ?? [];
    foreach ($batch as $c) {
        $contacts[] = $c;
    }
    $start += count($batch);
} while (count($batch) >= 50);

file_put_contents($projectRoot . '/data/cloud_contacts.json', json_encode($contacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Fetched " . count($contacts) . " contacts from cloud.\n";
