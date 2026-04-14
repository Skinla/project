#!/usr/bin/env php
<?php
/**
 * Check cloud portal via REST API: leads, contacts, activities.
 * Usage: php check_cloud.php
 */
$projectRoot = __DIR__;
$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);

$log = file_exists($projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json')
    ? json_decode(file_get_contents($projectRoot . '/local/handlers/leadsync/data/lead_sync_log.json'), true)
    : [];

$cloudLeadIds = array_keys($log ?: []);
if (empty($cloudLeadIds)) {
    echo "No cloud leads in lead_sync_log.\n";
    exit(0);
}

$result = [
    'cloud_url' => $config['url'],
    'lead_sync_log_pairs' => count($log),
    'cloud_lead_ids' => $cloudLeadIds,
    'leads' => [],
    'contacts' => [],
    'activities' => [],
    'errors' => [],
];

foreach ($cloudLeadIds as $cloudLeadId) {
    $leadResp = $client->call('crm.lead.get', ['id' => $cloudLeadId]);
    if (isset($leadResp['error'])) {
        $result['errors'][] = "Lead $cloudLeadId: " . ($leadResp['error_description'] ?? $leadResp['error']);
        continue;
    }
    $lead = $leadResp['result'] ?? null;
    if (!$lead) {
        $result['errors'][] = "Lead $cloudLeadId: not found";
        continue;
    }

    $result['leads'][] = [
        'cloud_id' => (int)$lead['ID'],
        'title' => $lead['TITLE'] ?? '',
        'contact_id' => (int)($lead['CONTACT_ID'] ?? 0),
        'source_id' => $lead['SOURCE_ID'] ?? null,
        'date_create' => $lead['DATE_CREATE'] ?? null,
        'date_modify' => $lead['DATE_MODIFY'] ?? null,
    ];

    $contactId = (int)($lead['CONTACT_ID'] ?? 0);
    if ($contactId > 0 && !isset($result['contacts'][$contactId])) {
        $contactResp = $client->call('crm.contact.get', ['id' => $contactId]);
        if (isset($contactResp['error'])) {
            $result['errors'][] = "Contact $contactId: " . ($contactResp['error_description'] ?? $contactResp['error']);
        } else {
            $c = $contactResp['result'] ?? null;
            $result['contacts'][$contactId] = $c ? [
                'cloud_id' => (int)$c['ID'],
                'name' => trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? '')),
                'date_create' => $c['DATE_CREATE'] ?? null,
                'date_modify' => $c['DATE_MODIFY'] ?? null,
            ] : null;
        }
    }

    $actResp = $client->call('crm.activity.list', [
        'filter' => ['OWNER_TYPE_ID' => 1, 'OWNER_ID' => $cloudLeadId],
        'select' => ['ID', 'TYPE_ID', 'SUBJECT', 'CREATED', 'LAST_UPDATED'],
    ]);
    $acts = $actResp['result'] ?? [];
    if (isset($actResp['error'])) {
        $result['errors'][] = "Activities lead $cloudLeadId: " . ($actResp['error_description'] ?? $actResp['error']);
    } else {
        $result['activities'][$cloudLeadId] = array_map(function ($a) {
            return [
                'id' => $a['ID'] ?? null,
                'type_id' => $a['TYPE_ID'] ?? null,
                'subject' => mb_substr($a['SUBJECT'] ?? '', 0, 40),
                'created' => $a['CREATED'] ?? null,
                'last_updated' => $a['LAST_UPDATED'] ?? null,
            ];
        }, $acts);
    }
}

$result['contacts'] = array_values($result['contacts']);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
