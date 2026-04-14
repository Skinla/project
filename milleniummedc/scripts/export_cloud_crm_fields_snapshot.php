#!/usr/bin/env php
<?php
/**
 * Полный снимок метаданных полей CRM из облака (REST) — сохраняется в data/,
 * чтобы не обращаться к облаку повторно (справочник UF и полей сущностей).
 *
 * Сохраняет:
 *   - crm.lead.fields, crm.deal.fields, crm.contact.fields, crm.company.fields
 *   - отдельно только пользовательские поля (код UF_*) по каждой сущности
 *
 * Usage: php scripts/export_cloud_crm_fields_snapshot.php
 * Output: data/cloud_crm_fields_snapshot.json
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/lib/BitrixRestClient.php';

$configPath = $projectRoot . '/config/webhook.php';
if (!is_readable($configPath)) {
    fwrite(STDERR, "Нет config/webhook.php\n");
    exit(1);
}

$config = require $configPath;
$client = new BitrixRestClient($config['url']);

$basePortal = 'https://milleniummed.bitrix24.ru';
if (preg_match('#^(https?://[^/]+)#', (string) ($config['url'] ?? ''), $m)) {
    $basePortal = $m[1];
}

$methods = [
    'CRM_LEAD' => 'crm.lead.fields',
    'CRM_DEAL' => 'crm.deal.fields',
    'CRM_CONTACT' => 'crm.contact.fields',
    'CRM_COMPANY' => 'crm.company.fields',
];

function extractUserFields(array $fieldsResult): array
{
    $uf = [];
    foreach ($fieldsResult as $code => $meta) {
        if (strpos((string) $code, 'UF_') === 0) {
            $uf[$code] = $meta;
        }
    }
    ksort($uf);

    return $uf;
}

$payload = [
    'exported_at' => date('c'),
    'source_url' => $basePortal,
    'webhook_base' => $basePortal,
    'entities' => [],
    'errors' => [],
];

foreach ($methods as $entityKey => $method) {
    $resp = $client->call($method, []);
    if (!empty($resp['error'])) {
        $payload['errors'][] = [
            'method' => $method,
            'entity' => $entityKey,
            'error' => $resp['error'],
            'error_description' => $resp['error_description'] ?? '',
        ];
        $payload['entities'][$entityKey] = [
            'method' => $method,
            'all_fields' => null,
            'user_fields' => [],
        ];
        continue;
    }
    $all = $resp['result'] ?? [];
    if (!is_array($all)) {
        $all = [];
    }
    $payload['entities'][$entityKey] = [
        'method' => $method,
        'all_fields' => $all,
        'user_fields' => extractUserFields($all),
        'counts' => [
            'all' => count($all),
            'user_fields' => count(extractUserFields($all)),
        ],
    ];
}

$dataDir = $projectRoot . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$outFile = $dataDir . '/cloud_crm_fields_snapshot.json';
$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, 'JSON encode failed: ' . json_last_error_msg() . "\n");
    exit(1);
}

file_put_contents($outFile, $json);

fwrite(STDOUT, "Saved: $outFile\n");
foreach ($payload['entities'] as $ek => $block) {
    $c = $block['counts'] ?? null;
    if ($c) {
        fwrite(STDOUT, "  $ek: fields={$c['all']}, UF={$c['user_fields']}\n");
    }
}
if (!empty($payload['errors'])) {
    fwrite(STDERR, 'Errors: ' . count($payload['errors']) . "\n");
    exit(1);
}
