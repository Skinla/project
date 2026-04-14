#!/usr/bin/env php
<?php
/**
 * Без Bitrix: читает HTML-выгрузку и deal_sync_log.json, пишет в stdout JSON
 * для migrate_deals_from_json.php (обновление UF сделки).
 *
 *   php emit_deal_uf_migrate_json.php --file=DEAL_....xls --limit=2 | php migrate_deals_from_json.php
 *   php emit_deal_uf_migrate_json.php --file=DEAL_....xls --all | php migrate_deals_from_json.php
 *
 * Запуск из /home/bitrix/www (stdin migrate_deals_from_json.php).
 *
 * --all или --limit=0 — все строки выгрузки с маппингом и непустой датой (без лимита).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$longopts = ['file:', 'limit:', 'uf:', 'column:', 'all'];
$opts = getopt('', $longopts);
$fileName = $opts['file'] ?? 'DEAL_20260324_f6b1cb87_69c2c578b49c6.xls';
$all = isset($opts['all']);
$limitOpt = isset($opts['limit']) ? (int)$opts['limit'] : 2;
if ($all || $limitOpt === 0) {
    $maxItems = PHP_INT_MAX;
} else {
    $maxItems = max(1, $limitOpt);
}
$ufCode = $opts['uf'] ?? 'UF_CRM_1744280242440';
$columnLabel = $opts['column'] ?? 'Дата/Время записи клиента';

$dealsyncDir = __DIR__;
$exportPath = $dealsyncDir . '/' . $fileName;
$logPath = $dealsyncDir . '/data/deal_sync_log.json';

if (!is_readable($exportPath) || !is_readable($logPath)) {
    fwrite(STDERR, "Error: unreadable export or deal_sync_log.json\n");
    exit(1);
}

$map = json_decode((string)file_get_contents($logPath), true);
if (!is_array($map)) {
    fwrite(STDERR, "Error: invalid deal_sync_log.json\n");
    exit(1);
}

$html = file_get_contents($exportPath);
if ($html === false) {
    fwrite(STDERR, "Error: could not read export\n");
    exit(1);
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
$xpath = new DOMXPath($dom);

$heads = $xpath->query('//table//thead//tr//th');
$colIndex = -1;
for ($i = 0; $i < $heads->length; $i++) {
    $t = trim(preg_replace('~\s+~u', ' ', $heads->item($i)->textContent));
    if ($t === $columnLabel) {
        $colIndex = $i;
        break;
    }
}
if ($colIndex < 0) {
    fwrite(STDERR, "Error: column not found: {$columnLabel}\n");
    exit(1);
}

$rows = $xpath->query('//table//tbody//tr');
$items = [];
for ($r = 0; $r < $rows->length; $r++) {
    $tr = $rows->item($r);
    $tds = $tr->getElementsByTagName('td');
    if ($tds->length <= max(0, $colIndex)) {
        continue;
    }
    $cloudId = trim($tds->item(0)->textContent);
    if ($cloudId === '' || !ctype_digit($cloudId)) {
        continue;
    }
    $rawDate = trim($tds->item($colIndex)->textContent);
    if ($rawDate === '') {
        continue;
    }
    if (!isset($map[$cloudId])) {
        continue;
    }
    $items[] = [
        'cloud_deal_id' => (int)$cloudId,
        'deal' => [
            $ufCode => $rawDate,
        ],
    ];
    if (count($items) >= $maxItems) {
        break;
    }
}

if ($items === []) {
    fwrite(STDERR, "Error: no rows with mapping + non-empty date\n");
    exit(1);
}

fwrite(STDERR, 'emit_deal_uf_migrate_json: items=' . count($items) . "\n");

$payload = [
    'check_date_modify' => false,
    'items' => $items,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
