#!/usr/bin/env php
<?php
/**
 * Build field mapping: source (cloud) -> target (box).
 * Standard fields: same code. User fields: matched by code or by title.
 *
 * Usage: php build_field_mapping.php [source_fields.json] [target_fields.json]
 */
$projectRoot = __DIR__;
$sourcePath = $argv[1] ?? $projectRoot . '/data/source_fields.json';
$targetPath = $argv[2] ?? $projectRoot . '/data/target_fields.json';
$outputPath = $projectRoot . '/data/field_mapping.json';

if (!file_exists($sourcePath) || !file_exists($targetPath)) {
    fwrite(STDERR, "Error: source or target fields file not found\n");
    exit(1);
}

$source = json_decode(file_get_contents($sourcePath), true);
$target = json_decode(file_get_contents($targetPath), true);

if (!$source || !$target) {
    fwrite(STDERR, "Error: invalid JSON\n");
    exit(1);
}

$mapping = [
    '_comment' => 'Сопоставление кодов полей: источник (облако) -> целевой портал (box). null = поле не найдено на целевом портале.',
    'source_url' => $source['source_url'] ?? 'https://milleniummed.bitrix24.ru',
    'target_url' => $target['target_url'] ?? 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'lead_fields' => [],
    'deal_fields' => [],
];

foreach (['lead_fields', 'deal_fields'] as $entity) {
    $targetByCode = $target[$entity] ?? [];
    $targetByTitle = [];
    foreach ($targetByCode as $code => $f) {
        $t = (string)($f['title'] ?? $code);
        if ($t !== '') {
            $targetByTitle[$t] = $code;
        }
    }

    foreach ($source[$entity] ?? [] as $srcCode => $srcField) {
        $srcTitle = (string)($srcField['title'] ?? $srcCode);

        if (isset($targetByCode[$srcCode])) {
            $mapping[$entity][$srcCode] = $srcCode;
        } elseif (isset($targetByTitle[$srcTitle])) {
            $mapping[$entity][$srcCode] = $targetByTitle[$srcTitle];
        } else {
            $mapping[$entity][$srcCode] = null;
        }
    }
}

if (!is_dir($projectRoot . '/data')) {
    mkdir($projectRoot . '/data', 0755, true);
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Field mapping saved to data/field_mapping.json\n";
echo "Lead fields: " . count($mapping['lead_fields']) . ", Deal fields: " . count($mapping['deal_fields']) . "\n";

$mapped = array_filter($mapping['lead_fields']) + array_filter($mapping['deal_fields']);
$unmapped = array_filter($mapping['lead_fields'], fn($v) => $v === null) + array_filter($mapping['deal_fields'], fn($v) => $v === null);
echo "Mapped: " . count($mapped) . ", Unmapped: " . count($unmapped) . "\n";
