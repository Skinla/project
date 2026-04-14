#!/usr/bin/env php
<?php
/**
 * Заполняет UF сделки из HTML-выгрузки через уже проверенный migrate_deals_from_json.php.
 * Парсинг большого файла выполняется отдельным процессом без ядра Bitrix, затем JSON идёт в migrate.
 *
 * Запуск из document root коробки:
 *   php local/handlers/dealsync/fill_deal_uf_from_export.php --file=DEAL_....xls --limit=100
 *   php local/handlers/dealsync/fill_deal_uf_from_export.php --file=DEAL_....xls --all
 *
 * См. также emit_deal_uf_migrate_json.php (только генерация JSON в stdout).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$docRoot = dirname(__DIR__, 3);
if (!is_dir($docRoot . '/bitrix') || !is_file($docRoot . '/migrate_deals_from_json.php')) {
    fwrite(STDERR, "Error: run from Bitrix site root (migrate_deals_from_json.php not found under {$docRoot})\n");
    exit(1);
}

$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
$emit = $docRoot . '/local/handlers/dealsync/emit_deal_uf_migrate_json.php';
$migrate = $docRoot . '/migrate_deals_from_json.php';
if (!is_file($emit) || !is_file($migrate)) {
    fwrite(STDERR, "Error: emit or migrate script missing\n");
    exit(1);
}

$args = array_slice($argv, 1);
$argStr = '';
foreach ($args as $a) {
    $argStr .= ' ' . escapeshellarg($a);
}

$cmd = 'cd ' . escapeshellarg($docRoot) . ' && '
    . escapeshellcmd($php) . ' ' . escapeshellarg($emit) . $argStr
    . ' | ' . escapeshellcmd($php) . ' ' . escapeshellarg($migrate);

passthru($cmd, $exit);
exit($exit);
