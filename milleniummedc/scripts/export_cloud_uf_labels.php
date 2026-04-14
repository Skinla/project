#!/usr/bin/env php
<?php
/**
 * Export CRM user field labels (listLabel, formLabel, filterLabel): лиды, сделки, контакты, компании.
 * Вывод JSON для sync_uf_labels_on_box.php на коробке.
 *
 * Usage:
 *   php scripts/export_cloud_uf_labels.php > /tmp/cloud_uf_labels.json
 * Без облака (из снимка data/cloud_crm_fields_snapshot.json):
 *   php scripts/export_cloud_uf_labels.php --from-snapshot > /tmp/cloud_uf_labels.json
 *   php scripts/export_cloud_uf_labels.php --from-snapshot=/path/to/snapshot.json
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/lib/uf_cloud_label_normalize.php';

$snapshotPath = null;
foreach ($argv as $a) {
    if ($a === '--from-snapshot') {
        $snapshotPath = $projectRoot . '/data/cloud_crm_fields_snapshot.json';
    } elseif (str_starts_with($a, '--from-snapshot=')) {
        $snapshotPath = substr($a, strlen('--from-snapshot='));
    }
}

function extractUfLabels(array $crmFieldsResult): array
{
    $out = [];
    foreach ($crmFieldsResult as $code => $meta) {
        if (strpos((string) $code, 'UF_') !== 0) {
            continue;
        }
        $list = trim((string) ($meta['listLabel'] ?? ''));
        $form = trim((string) ($meta['formLabel'] ?? ''));
        $filter = trim((string) ($meta['filterLabel'] ?? ''));
        [$list, $form, $filter] = uf_normalize_cloud_labels((string) $code, $list, $form, $filter, true);
        $out[$code] = [
            'listLabel' => $list,
            'formLabel' => $form,
            'filterLabel' => $filter,
        ];
    }

    return $out;
}

if ($snapshotPath !== null) {
    if (!is_readable($snapshotPath)) {
        fwrite(STDERR, "Snapshot not found: $snapshotPath\n");
        exit(1);
    }
    $snap = json_decode(file_get_contents($snapshotPath), true);
    if (!$snap || empty($snap['entities'])) {
        fwrite(STDERR, "Invalid snapshot JSON\n");
        exit(1);
    }
    $basePortal = (string) ($snap['source_url'] ?? 'https://milleniummed.bitrix24.ru');
    $leadFields = $snap['entities']['CRM_LEAD']['user_fields'] ?? $snap['entities']['CRM_LEAD']['all_fields'] ?? [];
    $dealFields = $snap['entities']['CRM_DEAL']['user_fields'] ?? $snap['entities']['CRM_DEAL']['all_fields'] ?? [];
    $contactFields = $snap['entities']['CRM_CONTACT']['user_fields'] ?? $snap['entities']['CRM_CONTACT']['all_fields'] ?? [];
    $companyFields = $snap['entities']['CRM_COMPANY']['user_fields'] ?? $snap['entities']['CRM_COMPANY']['all_fields'] ?? [];
    $exportedAt = (string) ($snap['exported_at'] ?? date('c'));
} else {
    require_once $projectRoot . '/lib/BitrixRestClient.php';
    $configPath = $projectRoot . '/config/webhook.php';
    if (!is_readable($configPath)) {
        fwrite(STDERR, "Missing config/webhook.php (or use --from-snapshot)\n");
        exit(1);
    }
    $config = require $configPath;
    $client = new BitrixRestClient($config['url']);
    $basePortal = 'https://milleniummed.bitrix24.ru';
    if (preg_match('#^(https?://[^/]+)#', (string) ($config['url'] ?? ''), $m)) {
        $basePortal = $m[1];
    }
    $lead = $client->call('crm.lead.fields', []);
    $deal = $client->call('crm.deal.fields', []);
    $contact = $client->call('crm.contact.fields', []);
    $company = $client->call('crm.company.fields', []);
    foreach (['crm.lead.fields' => $lead, 'crm.deal.fields' => $deal, 'crm.contact.fields' => $contact, 'crm.company.fields' => $company] as $m => $resp) {
        if (!empty($resp['error'])) {
            fwrite(STDERR, "$m: " . ($resp['error'] ?? '') . "\n");
            exit(1);
        }
    }
    $leadFields = $lead['result'] ?? [];
    $dealFields = $deal['result'] ?? [];
    $contactFields = $contact['result'] ?? [];
    $companyFields = $company['result'] ?? [];
    $exportedAt = date('c');
}

$payload = [
    'exported_at' => $exportedAt,
    'source_url' => $basePortal,
    'entities' => [
        'CRM_LEAD' => extractUfLabels($leadFields),
        'CRM_DEAL' => extractUfLabels($dealFields),
        'CRM_CONTACT' => extractUfLabels($contactFields),
        'CRM_COMPANY' => extractUfLabels($companyFields),
    ],
];

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
