#!/usr/bin/env php
<?php
/**
 * Вывод JSON логов онлайн-записи (без Bitrix). Запуск на коробке или локально.
 *
 *   php scripts/cat_booking_sync_data.php [path/to/dealsync/data]
 *
 * По умолчанию: ./local/handlers/dealsync/data относительно корня проекта.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$dir = $argv[1] ?? ($root . '/local/handlers/dealsync/data');
$dir = rtrim($dir, '/');

$files = ['deal_booking_sync_failures.json', 'deal_booking_sync_log.json'];
foreach ($files as $f) {
    $p = $dir . '/' . $f;
    echo "=== {$p} ===\n";
    if (!is_readable($p)) {
        echo "(file missing or not readable)\n\n";
        continue;
    }
    $raw = file_get_contents($p);
    $j = json_decode((string)$raw, true);
    echo $j !== null
        ? json_encode($j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
        : $raw . "\n\n";
}
