#!/usr/bin/env php
<?php
/**
 * Validate list mappings: no null values in stage, source, honorific mappings.
 * Exit 1 if any null found.
 * Usage: php validate_list_mappings.php [--leads-only]  # skip deal_stages check
 */
$projectRoot = __DIR__;
$leadsOnly = in_array('--leads-only', $argv ?? []);
$errors = [];

$stagePath = $projectRoot . '/data/stage_mapping.json';
if (file_exists($stagePath)) {
    $stage = json_decode(file_get_contents($stagePath), true);
    if ($stage) {
        foreach ($stage['lead_stages'] ?? [] as $k => $v) {
            if ($v === null) $errors[] = "stage_mapping.lead_stages[$k] = null";
        }
        if (!$leadsOnly) {
            foreach ($stage['deal_stages'] ?? [] as $k => $v) {
                if ($v === null) $errors[] = "stage_mapping.deal_stages[$k] = null";
            }
        }
    }
}

$sourcePath = $projectRoot . '/data/source_mapping.json';
if (file_exists($sourcePath)) {
    $src = json_decode(file_get_contents($sourcePath), true);
    if ($src) {
        foreach ($src['sources'] ?? [] as $k => $v) {
            if ($v === null) $errors[] = "source_mapping.sources[$k] = null";
        }
    }
}

$honorPath = $projectRoot . '/data/honorific_mapping.json';
if (file_exists($honorPath)) {
    $hon = json_decode(file_get_contents($honorPath), true);
    if ($hon) {
        foreach ($hon['honorific'] ?? [] as $k => $v) {
            if ($v === null) $errors[] = "honorific_mapping.honorific[$k] = null";
        }
    }
}

if (!empty($errors)) {
    fwrite(STDERR, "Validation failed:\n");
    foreach ($errors as $e) fwrite(STDERR, "  - $e\n");
    exit(1);
}

echo "All list mappings valid (no nulls).\n";
exit(0);
