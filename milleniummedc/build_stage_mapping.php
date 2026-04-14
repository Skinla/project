#!/usr/bin/env php
<?php
/**
 * Build stage mapping: source (cloud) -> target (box).
 * Matches by category name + stage name.
 *
 * Usage: php build_stage_mapping.php [target_stages.json]
 *   If target_stages.json omitted, runs get_target_stages.php via SSH and uses output.
 */

$projectRoot = __DIR__;
$sourcePath = $projectRoot . '/data/scan_result.json';
$targetPath = $argv[1] ?? null;
$outputPath = $projectRoot . '/data/stage_mapping.json';

if (!file_exists($sourcePath)) {
    fwrite(STDERR, "Error: data/scan_result.json not found\n");
    exit(1);
}

$source = json_decode(file_get_contents($sourcePath), true);
if (!$source) {
    fwrite(STDERR, "Error: invalid scan_result.json\n");
    exit(1);
}

if ($targetPath && file_exists($targetPath)) {
    $target = json_decode(file_get_contents($targetPath), true);
} else {
    $targetPath = $projectRoot . '/data/target_stages.json';
    if (!file_exists($targetPath)) {
        fwrite(STDERR, "Error: target stages not found. Run: scp get_target_stages.php root@box:/tmp/ && ssh root@box 'php /tmp/get_target_stages.php' > data/target_stages.json\n");
        exit(1);
    }
    $target = json_decode(file_get_contents($targetPath), true);
}

if (!$target) {
    fwrite(STDERR, "Error: invalid target JSON\n");
    exit(1);
}

/**
 * Перевод STATUS_ID сделки с воронки источника на целевую (C6:NEW + cat 6→1 → C1:NEW).
 */
function translateDealStatusId(string $srcFullId, int $srcCatId, ?int $tgtCatId): ?string {
    if ($tgtCatId === null) {
        return null;
    }
    if (preg_match('/^C(\d+):(.+)$/', $srcFullId, $m)) {
        if ((int)$m[1] !== $srcCatId) {
            return null;
        }
        $suffix = $m[2];
    } else {
        if ($srcCatId !== 0) {
            return null;
        }
        $suffix = $srcFullId;
    }
    if ($suffix === '') {
        return null;
    }
    if ($tgtCatId === 0) {
        return $suffix;
    }

    return 'C' . $tgtCatId . ':' . $suffix;
}

$mapping = [
    'source_url' => $source['source_url'] ?? 'https://milleniummed.bitrix24.ru',
    'target_url' => $target['target_url'] ?? 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'category_mapping' => [],
    'lead_stages' => [],
    'deal_stages' => [],
];

// Category mapping: source_category_id -> target_category_id (by name)
$targetCatsByName = [];
foreach ($target['deal_categories'] ?? [] as $tc) {
    $targetCatsByName[(string)$tc['name']] = (int)$tc['id'];
}

foreach ($source['deal_categories'] ?? [] as $sc) {
    $srcId = (int)$sc['id'];
    $name = (string)($sc['name'] ?? '');
    $tgtId = $targetCatsByName[$name] ?? null;
    $mapping['category_mapping'][$srcId] = $tgtId;
}

// Lead stages: source STATUS_ID -> target STATUS_ID (same if matched by name)
$targetLeadByName = [];
foreach ($target['lead_stages'] ?? [] as $ts) {
    $targetLeadByName[(string)$ts['name']] = (string)$ts['status_id'];
}

foreach ($source['lead_stages'] ?? [] as $sl) {
    $srcId = (string)($sl['status_id'] ?? '');
    $name = (string)($sl['name'] ?? '');
    $tgtId = $targetLeadByName[$name] ?? $srcId;
    $mapping['lead_stages'][$srcId] = $tgtId;
}

// Deal stages: source STATUS_ID (e.g. C6:NEW) -> target STATUS_ID (e.g. C1:NEW)
foreach ($source['deal_categories'] ?? [] as $sc) {
    $srcCatId = (int)$sc['id'];
    $catName = (string)($sc['name'] ?? '');
    $tgtCatId = $mapping['category_mapping'][$srcCatId] ?? null;

    $targetStagesByName = [];
    $targetStagesByStatusId = [];
    foreach ($target['deal_categories'] ?? [] as $tc) {
        if ((int)$tc['id'] === $tgtCatId) {
            foreach ($tc['stages'] ?? [] as $ts) {
                $sid = (string)($ts['status_id'] ?? '');
                $targetStagesByName[(string)$ts['name']] = $sid;
                $targetStagesByStatusId[$sid] = true;
            }
            break;
        }
    }

    foreach ($sc['stages'] ?? [] as $ss) {
        $srcStatusId = (string)($ss['status_id'] ?? '');
        $name = (string)($ss['name'] ?? '');
        $tgtStatusId = $targetStagesByName[$name] ?? null;

        if ($tgtStatusId === null && $tgtCatId !== null) {
            $cand = translateDealStatusId($srcStatusId, $srcCatId, $tgtCatId);
            if ($cand !== null && isset($targetStagesByStatusId[$cand])) {
                $tgtStatusId = $cand;
            }
        }

        if ($tgtStatusId !== null) {
            $mapping['deal_stages'][$srcStatusId] = $tgtStatusId;
        } else {
            $mapping['deal_stages'][$srcStatusId] = null;
        }
    }
}

$manualPath = $projectRoot . '/data/stage_mapping_manual_deals.json';
if (file_exists($manualPath)) {
    $man = json_decode(file_get_contents($manualPath), true);
    if (is_array($man)) {
        foreach ($man['deal_stages'] ?? [] as $k => $v) {
            if ($k === '_comment') {
                continue;
            }
            if ($v !== null && $v !== '') {
                $mapping['deal_stages'][(string)$k] = (string)$v;
            }
        }
    }
}

if (!is_dir($projectRoot . '/data')) {
    mkdir($projectRoot . '/data', 0755, true);
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Stage mapping saved to data/stage_mapping.json\n";
echo "Categories: " . count($mapping['category_mapping']) . ", Lead stages: " . count($mapping['lead_stages']) . ", Deal stages: " . count($mapping['deal_stages']) . "\n";
