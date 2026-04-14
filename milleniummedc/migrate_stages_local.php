#!/usr/bin/env php
<?php
/**
 * Migrate lead and deal stages on Bitrix BOX using internal API.
 * Run this script ON the box server (via SSH) - no webhook needed.
 *
 * Usage: php migrate_stages_local.php [path/to/scan_result.json] [--dry-run] [--leads-only] [--deals-only]
 *
 * Example via SSH:
 *   scp migrate_stages_local.php data/scan_result.json root@box:/tmp/
 *   ssh root@box "cd /tmp && php migrate_stages_local.php scan_result.json"
 */

set_error_handler(function ($n, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $n, $file, $line);
});

$options = getopt('', ['dry-run', 'leads-only', 'deals-only']);
$dryRun = isset($options['dry-run']);
$leadsOnly = isset($options['leads-only']);
$dealsOnly = isset($options['deals-only']);

$scanPath = $argv[1] ?? __DIR__ . '/scan_result.json';
if (!file_exists($scanPath)) {
    $scanPath = __DIR__ . '/data/scan_result.json';
}
if (!file_exists($scanPath)) {
    fwrite(STDERR, "Error: scan_result.json not found. Pass path as first argument.\n");
    exit(1);
}

$scan = json_decode(file_get_contents($scanPath), true);
if (!$scan) {
    fwrite(STDERR, "Error: invalid JSON in scan_result.json\n");
    exit(1);
}

// Find Bitrix document root
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (empty($docRoot)) {
    $candidates = ['/home/bitrix/www', '/var/www/bitrix', '/var/www/html'];
    foreach ($candidates as $d) {
        if (is_dir($d . '/bitrix/modules/main/include')) {
            $docRoot = $d;
            break;
        }
    }
}
if (empty($docRoot) || !is_dir($docRoot . '/bitrix/modules/main/include')) {
    fwrite(STDERR, "Error: Bitrix document root not found. Set DOCUMENT_ROOT or run from Bitrix www dir.\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('BX_NO_ACCELERATOR_RESET', true);

try {
    require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}

if (!\Bitrix\Main\Loader::includeModule('crm')) {
    fwrite(STDERR, "Error: CRM module not loaded\n");
    exit(1);
}

$stats = ['leads_added' => 0, 'leads_skipped' => 0, 'deals_added' => 0, 'deals_skipped' => 0, 'errors' => []];

function semanticsForStatus(string $statusId): string
{
    if (in_array($statusId, ['WON'], true)) return 'S';
    if (in_array($statusId, ['LOSE', 'APOLOGY'], true)) return 'F';
    return '';
}

function addStatus(string $entityId, array $fields, array &$errors): bool
{
    $obj = new CCrmStatus($entityId);
    $id = $obj->Add($fields);
    if ($id) {
        return true;
    }
    $ex = $GLOBALS['APPLICATION']->GetException();
    $errors[] = ($ex ? $ex->GetString() : 'Unknown error') . ' for ' . ($fields['STATUS_ID'] ?? '');
    return false;
}

function getExistingStatusIds(string $entityId): array
{
    $obj = new CCrmStatus($entityId);
    $ids = [];
    $res = $obj->GetList(['SORT' => 'ASC']);
    while ($row = $res->Fetch()) {
        $sid = $row['STATUS_ID'] ?? '';
        $ids[$sid] = true;
        if (preg_match('/^C\d+:(.+)$/', $sid, $m)) {
            $ids[$m[1]] = true;
        }
    }
    return $ids;
}

// --- Lead stages ---
if (!$dealsOnly && !empty($scan['lead_stages'])) {
    echo "=== Lead stages ===\n";

    $existingIds = getExistingStatusIds('STATUS');

    foreach ($scan['lead_stages'] as $i => $stage) {
        $statusId = (string)($stage['status_id'] ?? '');
        $name = (string)($stage['name'] ?? '');

        if ($statusId === '' || $name === '') continue;

        if (isset($existingIds[$statusId])) {
            echo "  Skip (exists): $statusId - $name\n";
            $stats['leads_skipped']++;
            continue;
        }

        if ($dryRun) {
            echo "  [DRY-RUN] Would add: $statusId - $name\n";
            $stats['leads_added']++;
            continue;
        }

        $fields = [
            'ENTITY_ID' => 'STATUS',
            'STATUS_ID' => $statusId,
            'NAME' => $name,
            'SORT' => ($i + 1) * 10,
        ];

        if (addStatus('STATUS', $fields, $stats['errors'])) {
            echo "  Added: $statusId - $name\n";
            $stats['leads_added']++;
            $existingIds[$statusId] = true;
        } else {
            $stats['leads_skipped']++;
        }
    }
}

// --- Deal categories and stages ---
if (!$leadsOnly && !empty($scan['deal_categories'])) {
    echo "\n=== Deal categories & stages ===\n";

    $targetCategories = [];
    $res = \Bitrix\Crm\Category\DealCategory::getList(['select' => ['ID', 'NAME']]);
    while ($row = $res->fetch()) {
        $targetCategories[(string)($row['NAME'] ?? '')] = (int)($row['ID'] ?? 0);
    }

    foreach ($scan['deal_categories'] as $cat) {
        $sourceId = (int)($cat['id'] ?? 0);
        $catName = (string)($cat['name'] ?? '');
        $stages = $cat['stages'] ?? [];

        if ($catName === '') continue;

        $targetCatId = $targetCategories[$catName] ?? null;

        if ($targetCatId === null && $sourceId !== 0) {
            if ($dryRun) {
                echo "  [DRY-RUN] Would create category: $catName\n";
                $targetCatId = $sourceId;
            } else {
                try {
                    $newId = \Bitrix\Crm\Category\DealCategory::add(['NAME' => $catName, 'SORT' => 500]);
                    if ($newId) {
                        $targetCatId = (int)$newId;
                        $targetCategories[$catName] = $targetCatId;
                        echo "  Created category: $catName (id=$targetCatId)\n";
                    }
                } catch (\Exception $e) {
                    echo "  Failed to create category: $catName - " . $e->getMessage() . "\n";
                    continue;
                }
                if (!$targetCatId) {
                    continue;
                }
            }
        } elseif ($targetCatId === null && $sourceId === 0) {
            $targetCatId = 0;
        }

        $entityId = ($targetCatId === 0) ? 'DEAL_STAGE' : ('DEAL_STAGE_' . $targetCatId);
        $existingStageIds = getExistingStatusIds($entityId);

        foreach ($stages as $i => $stage) {
            $fullStatusId = (string)($stage['status_id'] ?? '');
            $name = (string)($stage['name'] ?? '');

            if ($fullStatusId === '' || $name === '') continue;

            $statusId = $fullStatusId;
            if (preg_match('/^C\d+:(.+)$/', $fullStatusId, $m)) {
                $statusId = $m[1];
            }

            if (isset($existingStageIds[$fullStatusId]) || isset($existingStageIds[$statusId])) {
                echo "    Skip (exists): $fullStatusId - $name\n";
                $stats['deals_skipped']++;
                continue;
            }

            $semantics = semanticsForStatus($statusId);

            if ($dryRun) {
                echo "    [DRY-RUN] Would add: $statusId - $name\n";
                $stats['deals_added']++;
                continue;
            }

            $fields = [
                'ENTITY_ID' => $entityId,
                'STATUS_ID' => $statusId,
                'NAME' => $name,
                'SORT' => ($i + 1) * 10,
            ];
            if ($semantics !== '') {
                $fields['SEMANTICS'] = $semantics;
            }

            if (addStatus($entityId, $fields, $stats['errors'])) {
                echo "    Added: $statusId - $name\n";
                $stats['deals_added']++;
                $existingStageIds[$statusId] = true;
                $existingStageIds[$fullStatusId] = true;
            } else {
                $stats['deals_skipped']++;
            }
        }
    }
}

// --- Summary ---
echo "\n=== Summary ===\n";
echo "Leads: added={$stats['leads_added']}, skipped={$stats['leads_skipped']}\n";
echo "Deals: added={$stats['deals_added']}, skipped={$stats['deals_skipped']}\n";
if (!empty($stats['errors'])) {
    echo "Errors:\n";
    foreach (array_slice($stats['errors'], 0, 10) as $e) {
        echo "  - $e\n";
    }
    if (count($stats['errors']) > 10) {
        echo "  ... and " . (count($stats['errors']) - 10) . " more\n";
    }
}
