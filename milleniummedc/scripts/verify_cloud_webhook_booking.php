#!/usr/bin/env php
<?php
/**
 * Проверка, что входящий webhook облака может вызывать booking.v1.* (нужно для CRM_BOOKING).
 *
 *   php scripts/verify_cloud_webhook_booking.php
 *
 * Требует config/webhook.php с полем url (полный URL вебхука).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root . '/config/webhook.php';
$libPath = $root . '/lib/BitrixRestClient.php';

if (!is_readable($configPath) || !is_readable($libPath)) {
    fwrite(STDERR, "Missing config/webhook.php or lib/BitrixRestClient.php\n");
    exit(1);
}

$config = require $configPath;
$url = $config['url'] ?? '';
if ($url === '') {
    fwrite(STDERR, "Empty url in config\n");
    exit(1);
}

require_once $libPath;
$client = new BitrixRestClient($url);

$ok = true;
$r = $client->call('booking.v1.resource.list', []);
if (!empty($r['error'])) {
    echo 'FAIL booking.v1.resource.list: ' . ($r['error'] ?? '') . ' — ' . ($r['error_description'] ?? '') . "\n";
    $ok = false;
} else {
    echo "OK booking.v1.resource.list\n";
    $resources = $r['result']['resources'] ?? $r['result'] ?? [];
    $firstId = null;
    if (is_array($resources)) {
        foreach ($resources as $row) {
            if (is_array($row) && !empty($row['id'])) {
                $firstId = (int)$row['id'];
                break;
            }
        }
    }
    if ($firstId !== null && $firstId > 0) {
        $g = $client->call('booking.v1.resource.get', ['id' => $firstId]);
        if (!empty($g['error'])) {
            echo 'FAIL booking.v1.resource.get: ' . ($g['error'] ?? '') . ' — ' . ($g['error_description'] ?? '') . "\n";
            $ok = false;
        } else {
            echo "OK booking.v1.resource.get (id={$firstId})\n";
        }
    } else {
        echo "SKIP booking.v1.resource.get (no resources in portal to probe)\n";
    }
}

exit($ok ? 0 : 2);
