#!/usr/bin/env php
<?php
/**
 * Build honorific mapping: cloud HONORIFIC -> box HONORIFIC.
 * Matches by name.
 */
$projectRoot = __DIR__;
$sourcePath = $projectRoot . '/data/scan_result.json';
$targetPath = $projectRoot . '/data/target_stages.json';
$outputPath = $projectRoot . '/data/honorific_mapping.json';

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
$targetHonorific = $target['lead_honorific'] ?? [];

$targetByName = [];
foreach ($targetHonorific as $t) {
    $targetByName[(string)$t['name']] = (string)$t['status_id'];
}

$mapping = [
    '_comment' => 'cloud HONORIFIC -> box HONORIFIC. Match by name.',
    'source_url' => $source['source_url'] ?? 'https://milleniummed.bitrix24.ru',
    'target_url' => $target['target_url'] ?? 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'honorific' => [],
];

foreach ($source['lead_honorific'] ?? [] as $s) {
    $srcId = (string)($s['status_id'] ?? '');
    $name = (string)($s['name'] ?? '');
    $tgtId = $targetByName[$name] ?? $srcId;
    $mapping['honorific'][$srcId] = $tgtId;
}

if (!is_dir($projectRoot . '/data')) {
    mkdir($projectRoot . '/data', 0755, true);
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Honorific mapping saved to data/honorific_mapping.json: " . count($mapping['honorific']) . " values\n";
