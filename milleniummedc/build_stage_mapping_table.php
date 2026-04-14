#!/usr/bin/env php
<?php
/**
 * Собирает data/stage_mapping_table.md из scan_result.json, target_stages.json, stage_mapping.json.
 * Usage: php build_stage_mapping_table.php
 */
$root = __DIR__;
$scanPath = $root . '/data/scan_result.json';
$targetPath = $root . '/data/target_stages.json';
$mapPath = $root . '/data/stage_mapping.json';
$outPath = $root . '/data/stage_mapping_table.md';

foreach ([$scanPath, $targetPath, $mapPath] as $p) {
    if (!is_file($p)) {
        fwrite(STDERR, "Missing: $p\n");
        exit(1);
    }
}

$scan = json_decode(file_get_contents($scanPath), true);
$target = json_decode(file_get_contents($targetPath), true);
$map = json_decode(file_get_contents($mapPath), true);

function findSourceDealStageName(array $scan, string $key): string {
    if (preg_match('/^C(\d+):/', $key)) {
        foreach ($scan['deal_categories'] ?? [] as $c) {
            foreach ($c['stages'] ?? [] as $s) {
                if (($s['status_id'] ?? '') === $key) {
                    return (string)($s['name'] ?? '');
                }
            }
        }
        return '';
    }
    foreach ($scan['deal_categories'] ?? [] as $c) {
        if ((int)($c['id'] ?? -1) !== 0) {
            continue;
        }
        foreach ($c['stages'] ?? [] as $s) {
            if (($s['status_id'] ?? '') === $key) {
                return (string)($s['name'] ?? '');
            }
        }
    }
    return '';
}

function findTargetDealStageName(array $target, ?string $key): string {
    if ($key === null || $key === '') {
        return '';
    }
    if (preg_match('/^C(\d+):/', $key)) {
        foreach ($target['deal_categories'] ?? [] as $c) {
            foreach ($c['stages'] ?? [] as $s) {
                if (($s['status_id'] ?? '') === $key) {
                    return (string)($s['name'] ?? '');
                }
            }
        }
        return '';
    }
    foreach ($target['deal_categories'] ?? [] as $c) {
        if ((int)($c['id'] ?? -1) !== 0) {
            continue;
        }
        foreach ($c['stages'] ?? [] as $s) {
            if (($s['status_id'] ?? '') === $key) {
                return (string)($s['name'] ?? '');
            }
        }
    }
    return '';
}

function sourceCategoryName(array $scan, int $id): string {
    foreach ($scan['deal_categories'] ?? [] as $c) {
        if ((int)($c['id'] ?? -1) === $id) {
            return (string)($c['name'] ?? '');
        }
    }
    return '';
}

function targetCategoryName(array $target, $id): string {
    foreach ($target['deal_categories'] ?? [] as $c) {
        if ((int)($c['id'] ?? -1) === (int)$id) {
            return (string)($c['name'] ?? '');
        }
    }
    return '';
}

function parseDealKey(string $key): ?int {
    if (preg_match('/^C(\d+):/', $key, $m)) {
        return (int)$m[1];
    }
    return 0;
}

$targetLeadIds = [];
foreach ($target['lead_stages'] ?? [] as $ts) {
    $targetLeadIds[(string)($ts['status_id'] ?? '')] = true;
}

$sourceLeadById = [];
foreach ($scan['lead_stages'] ?? [] as $sl) {
    $sourceLeadById[(string)($sl['status_id'] ?? '')] = (string)($sl['name'] ?? '');
}

$targetLeadById = [];
foreach ($target['lead_stages'] ?? [] as $ts) {
    $targetLeadById[(string)($ts['status_id'] ?? '')] = (string)($ts['name'] ?? '');
}

$lines = [];
$lines[] = '# Таблица сопоставления стадий (облако → коробка)';
$lines[] = '';
$lines[] = 'Автогенерация: `php build_stage_mapping_table.php`. Источники: `data/scan_result.json`, `data/target_stages.json`, фактический маппинг — `data/stage_mapping.json`.';
$lines[] = '';
$lines[] = '| Портал | URL |';
$lines[] = '|--------|-----|';
$lines[] = '| Облако (источник) | ' . ($map['source_url'] ?? $scan['source_url'] ?? '—') . ' |';
$lines[] = '| Коробка (цель) | ' . ($map['target_url'] ?? $target['target_url'] ?? '—') . ' |';
$lines[] = '';

// --- Categories ---
$lines[] = '## 1. Воронки сделок (category_mapping)';
$lines[] = '';
$lines[] = '| ID облака | Воронка (облако) | ID коробки | Воронка (коробка) |';
$lines[] = '|----------:|------------------|----------:|-------------------|';
$cm = $map['category_mapping'] ?? [];
ksort($cm, SORT_NUMERIC);
foreach ($cm as $srcId => $tgtId) {
    $sn = sourceCategoryName($scan, (int)$srcId);
    if ($tgtId === null) {
        $lines[] = sprintf('| %s | %s | *null* | *не сопоставлена* |', $srcId, $sn);
    } else {
        $tn = targetCategoryName($target, (int)$tgtId);
        $lines[] = sprintf('| %s | %s | %s | %s |', $srcId, $sn, $tgtId, $tn);
    }
}
$lines[] = '';

// --- Lead stages ---
$lines[] = '## 2. Стадии лидов (lead_stages)';
$lines[] = '';
$lines[] = 'Статус в `LeadSync`: если для `STATUS_ID` с облака нет ключа в маппинге или значение отсутствует на коробке — стадия может не примениться или остаться как в облаке.';
$lines[] = '';
$lines[] = '| STATUS_ID (облако) | Название (облако) | → STATUS_ID (коробка) | Название (коробка) | Проверка |';
$lines[] = '|--------------------|------------------|------------------------|-------------------|----------|';
$ls = $map['lead_stages'] ?? [];
ksort($ls);
$leadOk = 0;
$leadBad = 0;
foreach ($ls as $srcId => $tgtId) {
    $sName = $sourceLeadById[$srcId] ?? '';
    if ($tgtId === null) {
        $lines[] = sprintf('| `%s` | %s | *null* | — | **нет маппинга** |', $srcId, $sName);
        $leadBad++;
        continue;
    }
    $tName = $targetLeadById[$tgtId] ?? '';
    $ok = isset($targetLeadIds[$tgtId]);
    if ($ok) {
        $leadOk++;
    } else {
        $leadBad++;
    }
    $flag = $ok ? 'OK' : '**на коробке нет такого STATUS_ID**';
    $lines[] = sprintf('| `%s` | %s | `%s` | %s | %s |', $srcId, $sName, $tgtId, $tName ?: '—', $flag);
}
$lines[] = '';
$lines[] = '**Сводка (лиды):** OK — **' . $leadOk . '**, проблемы (null или нет стадии на коробке) — **' . $leadBad . '**.';
$lines[] = '';

// --- Deal stages ---
$lines[] = '## 3. Стадии сделок (deal_stages)';
$lines[] = '';
$lines[] = 'Ключ: как в Bitrix (`NEW` для воронки 0, `C6:NEW` для воронки с ID 6 на источнике). **null** — на коробке не найдена стадия с тем же *названием* в парной воронке (см. `build_stage_mapping.php`) или маппинг не задан.';
$lines[] = '';
$lines[] = '| Ключ (облако) | Воронка облака | Стадия (облако) | → Коробка (STATUS_ID) | Стадия (коробка) | Статус |';
$lines[] = '|----------------|----------------|-----------------|----------------------|------------------|--------|';
$ds = $map['deal_stages'] ?? [];
$keys = array_keys($ds);
usort($keys, function ($a, $b) {
    $ca = parseDealKey($a);
    $cb = parseDealKey($b);
    if ($ca !== $cb) {
        return $ca <=> $cb;
    }
    return strcmp($a, $b);
});
$nullCount = 0;
$okCount = 0;
foreach ($keys as $key) {
    $val = $ds[$key];
    $srcCat = parseDealKey($key);
    $srcCatName = sourceCategoryName($scan, $srcCat);
    $srcStageName = findSourceDealStageName($scan, $key);
    if ($val === null) {
        $lines[] = sprintf(
            '| `%s` | %s (%s) | %s | *null* | — | **не сопоставлено** |',
            $key,
            $srcCatName,
            $srcCat,
            $srcStageName ?: '—'
        );
        $nullCount++;
    } else {
        $tName = findTargetDealStageName($target, $val);
        $lines[] = sprintf(
            '| `%s` | %s (%s) | %s | `%s` | %s | OK |',
            $key,
            $srcCatName,
            $srcCat,
            $srcStageName ?: '—',
            $val,
            $tName ?: '—'
        );
        $okCount++;
    }
}
$lines[] = '';
$lines[] = '### Сводка по сделкам';
$lines[] = '';
$lines[] = '- Сопоставлено (не null): **' . $okCount . '**';
$lines[] = '- Без сопоставления (null): **' . $nullCount . '**';
$lines[] = '';

// --- Recommendations ---
$lines[] = '## 4. Что сделать, если стадии «не бьются»';
$lines[] = '';
$lines[] = '1. **Лиды:** на коробке добавить недостающие стадии (как на облаке) или вручную поправить `lead_stages` в `data/stage_mapping.json` (например «Дубль» / «Неправильный номер» → `JUNK` или `IN_PROCESS`).';
$lines[] = '2. **Сделки:** переименовать стадии на коробке под облако и заново выполнить `php build_stage_mapping.php` — либо вручную заполнить `deal_stages` в JSON.';
$lines[] = '3. После правок скопировать `stage_mapping.json` в `local/handlers/leadsync/data/` и на сервер, при необходимости пересинхронизировать сущности.';
$lines[] = '';

file_put_contents($outPath, implode("\n", $lines));
echo "Wrote $outPath\n";
echo "Deal stages: $okCount mapped, $nullCount null\n";
