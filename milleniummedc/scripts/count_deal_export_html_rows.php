#!/usr/bin/env php
<?php
/**
 * Считает число сделок в HTML-выгрузке CRM (файл .xls от Битрикс24 — это таблица в разметке HTML).
 *
 *   php scripts/count_deal_export_html_rows.php [path/to/export.xls]
 *
 * По умолчанию ищет в корне проекта файл DEAL_*.xls (если один — берёт его).
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$path = $argv[1] ?? null;
if ($path === null || $path === '') {
    $glob = glob($root . '/DEAL_*.xls') ?: [];
    if (count($glob) === 1) {
        $path = $glob[0];
    } else {
        fwrite(STDERR, "Usage: php scripts/count_deal_export_html_rows.php <path/to/export.xls>\n");
        fwrite(STDERR, "Or place a single DEAL_*.xls in project root.\n");
        exit(1);
    }
}

if (!is_readable($path)) {
    fwrite(STDERR, "Error: file not readable: {$path}\n");
    exit(1);
}

$html = file_get_contents($path);
if ($html === false) {
    fwrite(STDERR, "Error: could not read file: {$path}\n");
    exit(1);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
libxml_clear_errors();

$dealRows = 0;
if ($loaded) {
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('//table//tbody//tr');
    if ($nodes !== false) {
        $dealRows = $nodes->length;
    }
}

// Fallback: типичная разметка <tr><td>ID</td>...
if ($dealRows === 0) {
    if (preg_match_all('~<tr[^>]*>\s*<td>\s*\d+\s*</td>~', $html, $m)) {
        $dealRows = count($m[0]);
    }
}

echo $dealRows . "\n";
