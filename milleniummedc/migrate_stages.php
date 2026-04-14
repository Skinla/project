#!/usr/bin/env php
<?php
/**
 * Migrate lead and deal stages from scan_result.json to target Bitrix24 portal.
 *
 * Usage: php migrate_stages.php [--dry-run] [--leads-only] [--deals-only] [--target=source]
 *
 * Requires: config/webhook_target.php with target portal REST webhook URL
 */

$projectRoot = __DIR__;
$configPath = $projectRoot . '/config/webhook_target.php';
$dataPath = $projectRoot . '/data/scan_result.json';
$libPath = $projectRoot . '/lib/BitrixRestClient.php';

$options = getopt('', ['dry-run', 'leads-only', 'deals-only', 'target:']);
$dryRun = isset($options['dry-run']);
$leadsOnly = isset($options['leads-only']);
$dealsOnly = isset($options['deals-only']);
$useSourceAsTarget = ($options['target'] ?? '') === 'source';

if ($leadsOnly && $dealsOnly) {
    fwrite(STDERR, "Error: --leads-only and --deals-only are mutually exclusive\n");
    exit(1);
}

if ($useSourceAsTarget) {
    $configPath = $projectRoot . '/config/webhook.php';
}

if (!file_exists($configPath)) {
    fwrite(STDERR, "Error: config file not found. Use config/webhook_target.php or --target=source\n");
    exit(1);
}

if (!file_exists($dataPath)) {
    fwrite(STDERR, "Error: data/scan_result.json not found. Run scan_source.php first.\n");
    exit(1);
}

if (!file_exists($libPath)) {
    fwrite(STDERR, "Error: lib/BitrixRestClient.php not found\n");
    exit(1);
}

$config = require $configPath;
$targetUrl = $config['url'] ?? null;

if (empty($targetUrl)) {
    fwrite(STDERR, "Error: webhook URL is empty in config\n");
    exit(1);
}
if (!$useSourceAsTarget && strpos($targetUrl, 'XXXXXXXX') !== false) {
    fwrite(STDERR, "Error: config/webhook_target.php has invalid URL. Replace XXXXXXXX with real webhook code.\n");
    exit(1);
}

require_once $libPath;

$scan = json_decode(file_get_contents($dataPath), true);
if (!$scan) {
    fwrite(STDERR, "Error: invalid JSON in scan_result.json\n");
    exit(1);
}

$client = new BitrixRestClient($targetUrl);
$stats = ['leads_added' => 0, 'leads_skipped' => 0, 'deals_added' => 0, 'deals_skipped' => 0, 'errors' => []];

function semanticsForStatus(string $statusId): string
{
    if (in_array($statusId, ['WON'], true)) {
        return 'S';
    }
    if (in_array($statusId, ['LOSE', 'APOLOGY'], true)) {
        return 'F';
    }
    return '';
}

function callApi(BitrixRestClient $client, string $method, array $params, array &$errors): ?array
{
    $response = $client->call($method, $params);
    if (isset($response['error'])) {
        $errors[] = sprintf('%s: %s - %s', $method, $response['error'], $response['error_description'] ?? '');
        return null;
    }
    return $response;
}

// --- Lead stages ---
if (!$dealsOnly && !empty($scan['lead_stages'])) {
    echo "=== Lead stages ===\n";

    $existingResp = callApi($client, 'crm.status.list', [
        'filter' => ['ENTITY_ID' => 'STATUS'],
        'select' => ['STATUS_ID'],
    ], $stats['errors']);

    $existingIds = [];
    if ($existingResp && isset($existingResp['result'])) {
        foreach ($existingResp['result'] as $s) {
            $existingIds[(string)($s['STATUS_ID'] ?? '')] = true;
        }
    }

    foreach ($scan['lead_stages'] as $i => $stage) {
        $statusId = (string)($stage['status_id'] ?? '');
        $name = (string)($stage['name'] ?? '');

        if ($statusId === '' || $name === '') {
            continue;
        }

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

        $resp = callApi($client, 'crm.status.add', [
            'fields' => [
                'ENTITY_ID' => 'STATUS',
                'STATUS_ID' => $statusId,
                'NAME' => $name,
                'SORT' => ($i + 1) * 10,
            ],
        ], $stats['errors']);

        if ($resp !== null) {
            echo "  Added: $statusId - $name\n";
            $stats['leads_added']++;
            $existingIds[$statusId] = true;
        } else {
            $stats['leads_skipped']++;
        }

        usleep(100000); // 100ms throttle
    }
}

// --- Deal categories and stages ---
if (!$leadsOnly && !empty($scan['deal_categories'])) {
    echo "\n=== Deal categories & stages ===\n";

    $catResp = callApi($client, 'crm.category.list', ['entityTypeId' => 2], $stats['errors']);
    $targetCategories = [];
    if ($catResp && isset($catResp['result']['categories'])) {
        foreach ($catResp['result']['categories'] as $c) {
            $targetCategories[(string)($c['name'] ?? '')] = (int)($c['id'] ?? 0);
        }
    }

    foreach ($scan['deal_categories'] as $cat) {
        $sourceId = (int)($cat['id'] ?? 0);
        $catName = (string)($cat['name'] ?? '');
        $stages = $cat['stages'] ?? [];

        if ($catName === '') {
            continue;
        }

        $targetCatId = $targetCategories[$catName] ?? null;

        if ($targetCatId === null && $sourceId !== 0) {
            if ($dryRun) {
                echo "  [DRY-RUN] Would create category: $catName\n";
                $targetCatId = $sourceId; // assume same for dry-run
            } else {
                $addResp = callApi($client, 'crm.category.add', [
                    'entityTypeId' => 2,
                    'fields' => ['name' => $catName, 'sort' => 500],
                ], $stats['errors']);

                if ($addResp && isset($addResp['result']['category']['id'])) {
                    $targetCatId = (int)$addResp['result']['category']['id'];
                    $targetCategories[$catName] = $targetCatId;
                    echo "  Created category: $catName (id=$targetCatId)\n";
                } else {
                    echo "  Failed to create category: $catName, skipping stages\n";
                    continue;
                }
            }
        } elseif ($targetCatId === null && $sourceId === 0) {
            $targetCatId = 0; // default funnel
        }

        $entityId = ($targetCatId === 0) ? 'DEAL_STAGE' : ('DEAL_STAGE_' . $targetCatId);

        $existingResp = callApi($client, 'crm.status.list', [
            'filter' => ['ENTITY_ID' => $entityId],
            'select' => ['STATUS_ID'],
        ], $stats['errors']);

        $existingStageIds = [];
        if ($existingResp && isset($existingResp['result'])) {
            foreach ($existingResp['result'] as $s) {
                $sid = $s['STATUS_ID'] ?? '';
                $existingStageIds[$sid] = true;
                if (preg_match('/^C\d+:(.+)$/', $sid, $m)) {
                    $existingStageIds[$m[1]] = true;
                }
            }
        }

        foreach ($stages as $i => $stage) {
            $fullStatusId = (string)($stage['status_id'] ?? '');
            $name = (string)($stage['name'] ?? '');

            if ($fullStatusId === '' || $name === '') {
                continue;
            }

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

            $resp = callApi($client, 'crm.status.add', ['fields' => $fields], $stats['errors']);

            if ($resp !== null) {
                echo "    Added: $statusId - $name\n";
                $stats['deals_added']++;
                $existingStageIds[$statusId] = true;
                $existingStageIds[$fullStatusId] = true;
            } else {
                $stats['deals_skipped']++;
            }

            usleep(100000);
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
