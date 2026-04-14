<?php
/**
 * Выгрузка ресурсов «Онлайн-запись» из облака (REST).
 * Запуск из корня проекта: php scripts/pull_cloud_booking_resources.php
 * Результат: data/booking_resources_cloud.json
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib/BitrixRestClient.php';

$cfg = require $root . '/config/webhook.php';
$client = new BitrixRestClient($cfg['url']);

$r = $client->call('booking.v1.resource.list', []);
if (!empty($r['error'])) {
    fwrite(STDERR, $r['error'] . ': ' . ($r['error_description'] ?? '') . "\n");
    exit(1);
}

$list = $r['result']['resource'] ?? null;
if (!is_array($list)) {
    fwrite(STDERR, "Unexpected REST response: no result.resource\n");
    exit(1);
}

$out = [
    'fetchedAt' => gmdate('c'),
    'resources' => $list,
];

$path = $root . '/data/booking_resources_cloud.json';
if (file_put_contents($path, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "Write failed: {$path}\n");
    exit(1);
}

echo count($list) . " resources -> {$path}\n";
