#!/usr/bin/env php
<?php
/**
 * Scan lead and deal fields from source portal via REST.
 * Output: data/source_fields.json
 */
$projectRoot = __DIR__;
$configPath = $projectRoot . '/config/webhook.php';
$libPath = $projectRoot . '/lib/BitrixRestClient.php';

if (!file_exists($configPath) || !file_exists($libPath)) {
    fwrite(STDERR, "Error: config/webhook.php or lib/BitrixRestClient.php not found\n");
    exit(1);
}

$config = require $configPath;
require_once $libPath;

$client = new BitrixRestClient($config['url']);
$result = [
    'source_url' => 'https://milleniummed.bitrix24.ru',
    'scanned_at' => date('c'),
    'lead_fields' => [],
    'deal_fields' => [],
];

$leadResp = $client->call('crm.lead.fields', []);
if (isset($leadResp['error'])) {
    fwrite(STDERR, "Lead fields error: " . ($leadResp['error_description'] ?? $leadResp['error']) . "\n");
} elseif (isset($leadResp['result'])) {
    foreach ($leadResp['result'] as $code => $field) {
        $result['lead_fields'][$code] = [
            'type' => $field['type'] ?? '',
            'title' => $field['title'] ?? $field['listLabel'] ?? $code,
            'isRequired' => ($field['isRequired'] ?? false) ? 'Y' : 'N',
            'isReadOnly' => ($field['isReadOnly'] ?? false) ? 'Y' : 'N',
            'isMultiple' => ($field['isMultiple'] ?? false) ? 'Y' : 'N',
        ];
        if (!empty($field['items'])) {
            $result['lead_fields'][$code]['items'] = $field['items'];
        }
    }
}

$dealResp = $client->call('crm.deal.fields', []);
if (isset($dealResp['error'])) {
    fwrite(STDERR, "Deal fields error: " . ($dealResp['error_description'] ?? $dealResp['error']) . "\n");
} elseif (isset($dealResp['result'])) {
    foreach ($dealResp['result'] as $code => $field) {
        $result['deal_fields'][$code] = [
            'type' => $field['type'] ?? '',
            'title' => $field['title'] ?? $field['listLabel'] ?? $code,
            'isRequired' => ($field['isRequired'] ?? false) ? 'Y' : 'N',
            'isReadOnly' => ($field['isReadOnly'] ?? false) ? 'Y' : 'N',
            'isMultiple' => ($field['isMultiple'] ?? false) ? 'Y' : 'N',
        ];
        if (!empty($field['items'])) {
            $result['deal_fields'][$code]['items'] = $field['items'];
        }
    }
}

$dataDir = $projectRoot . '/data';
if (!is_dir($dataDir)) mkdir($dataDir, 0755, true);
$path = $dataDir . '/source_fields.json';
file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved to $path\n";
echo "Lead fields: " . count($result['lead_fields']) . ", Deal fields: " . count($result['deal_fields']) . "\n";
