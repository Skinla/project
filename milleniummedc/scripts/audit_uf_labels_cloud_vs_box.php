#!/usr/bin/env php
<?php
/**
 * Compare user field list labels: cloud (REST listLabel) vs box (get_target_fields title).
 * Cloud: config/webhook.php + crm.lead.fields / crm.deal.fields
 * Box: JSON from SSH: php get_target_fields.php (saved path via --box-json=FILE)
 *
 * Usage:
 *   ssh ... "cd /home/bitrix/www && php get_target_fields.php" > /tmp/box_fields.json
 *   php scripts/audit_uf_labels_cloud_vs_box.php --box-json=/tmp/box_fields.json
 */

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/lib/BitrixRestClient.php';

$boxPath = $projectRoot . '/data/target_fields.json';
foreach ($argv as $a) {
    if (str_starts_with($a, '--box-json=')) {
        $boxPath = substr($a, strlen('--box-json='));
    }
}

$mappingPath = $projectRoot . '/data/field_mapping.json';
if (!is_readable($mappingPath)) {
    fwrite(STDERR, "Missing $mappingPath\n");
    exit(1);
}
if (!is_readable($boxPath)) {
    fwrite(STDERR, "Missing box JSON: $boxPath\n");
    exit(1);
}

$mapping = json_decode(file_get_contents($mappingPath), true);
$box = json_decode(file_get_contents($boxPath), true);
if (!$mapping || !$box || empty($box['lead_fields']) || empty($box['deal_fields'])) {
    fwrite(STDERR, "Invalid mapping or box JSON\n");
    exit(1);
}

$hook = $projectRoot . '/config/webhook.php';
if (!is_readable($hook)) {
    fwrite(STDERR, "Missing config/webhook.php\n");
    exit(1);
}
$config = require $hook;
$client = new BitrixRestClient($config['url']);

$cloudLead = ($client->call('crm.lead.fields', [])['result'] ?? []);
$cloudDeal = ($client->call('crm.deal.fields', [])['result'] ?? []);
if ($cloudLead === [] || $cloudDeal === []) {
    fwrite(STDERR, "Cloud REST returned empty fields\n");
    exit(1);
}

function cloudListLabel(array $fields, string $code): string
{
    $m = $fields[$code] ?? [];

    return trim((string) ($m['listLabel'] ?? $m['formLabel'] ?? ''));
}

function boxTitle(array $boxEntity, string $code): string
{
    $m = $boxEntity[$code] ?? [];

    return trim((string) ($m['title'] ?? ''));
}

$missing = ['lead' => [], 'deal' => []];

$entities = [
    'lead_fields' => ['cloud' => $cloudLead, 'box' => $box['lead_fields'], 'bucket' => 'lead'],
    'deal_fields' => ['cloud' => $cloudDeal, 'box' => $box['deal_fields'], 'bucket' => 'deal'],
];

foreach ($entities as $entityKey => $ctx) {
    foreach ($mapping[$entityKey] ?? [] as $src => $tgt) {
        if ($tgt === null || strpos((string) $src, 'UF_') !== 0) {
            continue;
        }
        $code = (string) $tgt;
        $cl = cloudListLabel($ctx['cloud'], $code);
        $bt = boxTitle($ctx['box'], $code);
        $cloudHasHuman = ($cl !== '' && $cl !== $code);
        $boxHasHuman = ($bt !== '' && $bt !== $code);
        if ($cloudHasHuman && !$boxHasHuman) {
            $missing[$ctx['bucket']][] = [
                'field' => $code,
                'cloud_listLabel' => $cl,
                'box_title' => $bt === '' ? '(empty)' : $bt,
            ];
        }
    }
}

$report = [
    '_comment' => 'Поля из data/field_mapping.json (UF_* с не-null целью): в облаке задан человекочитаемый listLabel/formLabel, в коробке title (LIST_COLUMN_LABEL) пустой или совпадает с кодом поля.',
    'box_json' => $boxPath,
    'lead_missing_count' => count($missing['lead']),
    'deal_missing_count' => count($missing['deal']),
    'lead_missing' => $missing['lead'],
    'deal_missing' => $missing['deal'],
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
