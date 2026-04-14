#!/usr/bin/env php
<?php
/**
 * Bitrix24 Cloud Scanner
 * Scans the source Bitrix24 portal via REST and saves result to data/scan_result.json
 */

$projectRoot = __DIR__;

$configPath = $projectRoot . '/config/webhook.php';
$libPath = $projectRoot . '/lib/BitrixRestClient.php';
$dataDir = $projectRoot . '/data';

if (!file_exists($configPath)) {
    fwrite(STDERR, "Error: config/webhook.php not found\n");
    exit(1);
}

if (!file_exists($libPath)) {
    fwrite(STDERR, "Error: lib/BitrixRestClient.php not found\n");
    exit(1);
}

$config = require $configPath;
require_once $libPath;

$client = new BitrixRestClient($config['url']);
$result = [
    'scanned_at' => date('c'),
    'source_url' => 'https://milleniummed.bitrix24.ru',
    'crm_mode' => null,
    'used_entities' => [],
    'deal_categories' => [],
    'lead_stages' => [],
    'smart_process_types' => [],
    'bizproc_templates_count' => 0,
    'projects_count' => 0,
    'disk_storages_count' => 0,
    'lists_count' => 0,
    'calendar_sections_count' => 0,
    'errors' => [],
];

function safeCall(BitrixRestClient $client, string $method, array $params, array &$result): mixed
{
    $response = $client->call($method, $params);
    if (isset($response['error'])) {
        $result['errors'][] = [
            'method' => $method,
            'error' => $response['error'],
            'description' => $response['error_description'] ?? '',
        ];
        return null;
    }
    return $response;
}

// CRM mode
$modeResp = safeCall($client, 'crm.settings.mode.get', [], $result);
if ($modeResp !== null && isset($modeResp['result'])) {
    $result['crm_mode'] = $modeResp['result'] ?? 'classic';
}

// Deals
$dealResp = safeCall($client, 'crm.deal.list', ['limit' => 1], $result);
if ($dealResp !== null && isset($dealResp['result']) && !empty($dealResp['result'])) {
    $result['used_entities'][] = 'deals';
}

// Deal categories (funnels)
$catResp = safeCall($client, 'crm.category.list', ['entityTypeId' => 2], $result);
if ($catResp !== null && isset($catResp['result']['categories'])) {
    $categories = $catResp['result']['categories'];
    foreach ($categories as $cat) {
        $categoryId = $cat['id'] ?? null;
        $categoryName = $cat['name'] ?? 'Unknown';
        // Default funnel (id=0) uses ENTITY_ID='DEAL_STAGE'; others use 'DEAL_STAGE_N'
        $entityId = ($categoryId === 0 || $categoryId === '0') ? 'DEAL_STAGE' : ('DEAL_STAGE_' . $categoryId);

        $stagesResp = safeCall($client, 'crm.status.list', [
            'filter' => ['ENTITY_ID' => $entityId],
            'order' => ['SORT' => 'ASC'],
        ], $result);

        $stages = [];
        if ($stagesResp !== null && isset($stagesResp['result'])) {
            foreach ($stagesResp['result'] as $s) {
                $stages[] = [
                    'status_id' => $s['STATUS_ID'] ?? '',
                    'name' => $s['NAME'] ?? '',
                ];
            }
        }

        $result['deal_categories'][] = [
            'id' => $categoryId,
            'name' => $categoryName,
            'stages' => $stages,
        ];
    }
}

// Leads
$leadResp = safeCall($client, 'crm.lead.list', ['limit' => 1], $result);
if ($leadResp !== null && isset($leadResp['result']) && !empty($leadResp['result'])) {
    $result['used_entities'][] = 'leads';
}

$leadStatusResp = safeCall($client, 'crm.status.list', [
    'filter' => ['ENTITY_ID' => 'STATUS'],
    'order' => ['SORT' => 'ASC'],
], $result);
if ($leadStatusResp !== null && isset($leadStatusResp['result'])) {
    foreach ($leadStatusResp['result'] as $s) {
        $result['lead_stages'][] = [
            'status_id' => $s['STATUS_ID'] ?? '',
            'name' => $s['NAME'] ?? '',
        ];
    }
}

// Lead sources (SOURCE_ID)
$sourceResp = safeCall($client, 'crm.status.list', [
    'filter' => ['ENTITY_ID' => 'SOURCE'],
    'order' => ['SORT' => 'ASC'],
], $result);
$result['lead_sources'] = [];
if ($sourceResp !== null && isset($sourceResp['result'])) {
    foreach ($sourceResp['result'] as $s) {
        $result['lead_sources'][] = [
            'status_id' => $s['STATUS_ID'] ?? '',
            'name' => $s['NAME'] ?? '',
        ];
    }
}

// Honorific (HONORIFIC)
$honorificResp = safeCall($client, 'crm.status.list', [
    'filter' => ['ENTITY_ID' => 'HONORIFIC'],
    'order' => ['SORT' => 'ASC'],
], $result);
$result['lead_honorific'] = [];
if ($honorificResp !== null && isset($honorificResp['result'])) {
    foreach ($honorificResp['result'] as $s) {
        $result['lead_honorific'][] = [
            'status_id' => $s['STATUS_ID'] ?? '',
            'name' => $s['NAME'] ?? '',
        ];
    }
}

// Contacts, Companies
$contactResp = safeCall($client, 'crm.contact.list', ['limit' => 1], $result);
if ($contactResp !== null && isset($contactResp['result']) && !empty($contactResp['result'])) {
    $result['used_entities'][] = 'contacts';
}

$companyResp = safeCall($client, 'crm.company.list', ['limit' => 1], $result);
if ($companyResp !== null && isset($companyResp['result']) && !empty($companyResp['result'])) {
    $result['used_entities'][] = 'companies';
}

// Smart processes
$typeResp = safeCall($client, 'crm.type.list', [], $result);
if ($typeResp !== null && isset($typeResp['result']['types'])) {
    $types = $typeResp['result']['types'];
    $result['used_entities'][] = 'smart_processes';

    foreach ($types as $type) {
        $entityTypeId = $type['entityTypeId'] ?? $type['id'] ?? null;
        $title = $type['title'] ?? $type['name'] ?? 'Unknown';

        $spCategories = [];
        $catSpResp = safeCall($client, 'crm.category.list', ['entityTypeId' => $entityTypeId], $result);
        if ($catSpResp !== null && isset($catSpResp['result']['categories'])) {
            foreach ($catSpResp['result']['categories'] as $spCat) {
                $spCatId = $spCat['id'] ?? null;
                $entityId = 'DT' . $entityTypeId . '_STAGE';
                $stagesResp = safeCall($client, 'crm.status.list', [
                    'filter' => ['ENTITY_ID' => $entityId, 'CATEGORY_ID' => $spCatId],
                    'order' => ['SORT' => 'ASC'],
                ], $result);

                $spStages = [];
                if ($stagesResp !== null && isset($stagesResp['result'])) {
                    foreach ($stagesResp['result'] as $s) {
                        $spStages[] = ['status_id' => $s['STATUS_ID'] ?? '', 'name' => $s['NAME'] ?? ''];
                    }
                }

                $spCategories[] = [
                    'id' => $spCatId,
                    'name' => $spCat['name'] ?? 'Unknown',
                    'stages' => $spStages,
                ];
            }
        }

        $result['smart_process_types'][] = [
            'entityTypeId' => $entityTypeId,
            'title' => $title,
            'categories' => $spCategories,
        ];
    }
}

// BizProc templates
$bizResp = safeCall($client, 'bizproc.workflow.template.list', [
    'select' => ['ID', 'NAME'],
    'start' => 0,
], $result);
if ($bizResp !== null && isset($bizResp['result'])) {
    $result['bizproc_templates_count'] = is_array($bizResp['result']) ? count($bizResp['result']) : (int)($bizResp['total'] ?? 0);
}

// Projects (sonet groups)
$sonetResp = safeCall($client, 'sonet_group.get', [], $result);
if ($sonetResp !== null && isset($sonetResp['result'])) {
    $result['projects_count'] = is_array($sonetResp['result']) ? count($sonetResp['result']) : (int)($sonetResp['total'] ?? 0);
}

// Disk
$diskResp = safeCall($client, 'disk.storage.get', [], $result);
if ($diskResp !== null && isset($diskResp['result'])) {
    $result['disk_storages_count'] = 1;
} elseif ($diskResp === null) {
    $diskListResp = safeCall($client, 'disk.storage.getchildren', ['id' => '0'], $result);
    if ($diskListResp !== null) {
        $result['disk_storages_count'] = 1;
    }
}

// Lists
$listsResp = safeCall($client, 'lists.get', [], $result);
if ($listsResp !== null && isset($listsResp['result'])) {
    $result['lists_count'] = is_array($listsResp['result']) ? count($listsResp['result']) : 0;
}

// Calendar
$calResp = safeCall($client, 'calendar.section.get', [], $result);
if ($calResp !== null && isset($calResp['result'])) {
    $result['calendar_sections_count'] = is_array($calResp['result']) ? count($calResp['result']) : 0;
}

// Deduplicate used_entities
$result['used_entities'] = array_values(array_unique($result['used_entities']));

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$outputPath = $dataDir . '/scan_result.json';
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if (file_put_contents($outputPath, $json) === false) {
    fwrite(STDERR, "Error: failed to write $outputPath\n");
    exit(1);
}

echo "Scan complete. Result saved to data/scan_result.json\n";
echo "Used entities: " . implode(', ', $result['used_entities']) . "\n";
echo "Deal categories: " . count($result['deal_categories']) . "\n";
echo "Smart process types: " . count($result['smart_process_types']) . "\n";
if (!empty($result['errors'])) {
    echo "Errors: " . count($result['errors']) . "\n";
}
