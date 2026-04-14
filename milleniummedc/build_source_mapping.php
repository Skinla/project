#!/usr/bin/env php
<?php
/**
 * Build source mapping: cloud SOURCE_ID -> box SOURCE_ID.
 * Matches by name. Uses target_stages.json (lead_sources) or scan_result.
 */
$projectRoot = __DIR__;
$sourcePath = $projectRoot . '/data/scan_result.json';
$targetPath = $projectRoot . '/data/target_stages.json';
$outputPath = $projectRoot . '/data/source_mapping.json';

if (!file_exists($sourcePath)) {
    fwrite(STDERR, "Error: data/scan_result.json not found. Run scan_source.php first.\n");
    exit(1);
}

$source = json_decode(file_get_contents($sourcePath), true);
if (!$source) {
    fwrite(STDERR, "Error: invalid scan_result.json\n");
    exit(1);
}

$target = file_exists($targetPath) ? json_decode(file_get_contents($targetPath), true) : null;
$targetSources = $target['lead_sources'] ?? [];

$targetByName = [];
foreach ($targetSources as $t) {
    $targetByName[(string)$t['name']] = (string)$t['status_id'];
}

$mapping = [
    '_comment' => 'cloud SOURCE_ID -> box SOURCE_ID. Match by name.',
    'source_url' => $source['source_url'] ?? 'https://milleniummed.bitrix24.ru',
    'target_url' => $target['target_url'] ?? 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'sources' => [],
];

foreach ($source['lead_sources'] ?? [] as $s) {
    $srcId = (string)($s['status_id'] ?? '');
    $name = (string)($s['name'] ?? '');
    $tgtId = $targetByName[$name] ?? $srcId;
    $mapping['sources'][$srcId] = $tgtId;
}

if (!is_dir($projectRoot . '/data')) {
    mkdir($projectRoot . '/data', 0755, true);
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Source mapping saved to data/source_mapping.json: " . count($mapping['sources']) . " sources\n";
