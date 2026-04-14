#!/usr/bin/env php
<?php
/**
 * Build contact mapping: cloud contact ID -> box contact ID.
 * Matches by phone (normalized digits) or email.
 */
$projectRoot = __DIR__;
$cloudPath = $projectRoot . '/data/cloud_contacts.json';
$boxPath = $projectRoot . '/data/box_contacts.json';
$outputPath = $projectRoot . '/data/contact_mapping.json';

if (!file_exists($cloudPath) || !file_exists($boxPath)) {
    fwrite(STDERR, "Error: Run get_cloud_contacts.php and get_box_contacts on server first.\n");
    fwrite(STDERR, "  php get_cloud_contacts.php\n");
    fwrite(STDERR, "  ssh ... 'php get_box_contacts.php' > data/box_contacts.json\n");
    exit(1);
}

$cloudContacts = json_decode(file_get_contents($cloudPath), true);
$boxContacts = json_decode(file_get_contents($boxPath), true);

if (!is_array($cloudContacts) || !is_array($boxContacts)) {
    fwrite(STDERR, "Error: invalid JSON\n");
    exit(1);
}

$boxByPhone = [];
$boxByEmail = [];
foreach ($boxContacts as $b) {
    $id = (int)$b['ID'];
    foreach ($b['PHONES'] ?? [] as $p) {
        $digits = preg_replace('/\D/', '', $p);
        if (strlen($digits) >= 10) $boxByPhone[$digits] = $id;
    }
    foreach ($b['EMAILS'] ?? [] as $e) {
        if ($e) $boxByEmail[$e] = $id;
    }
}

function extractPhones($contact) {
    $phones = [];
    foreach ($contact['PHONE'] ?? [] as $p) {
        $v = $p['VALUE'] ?? '';
        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) >= 10) $phones[] = $digits;
    }
    return $phones;
}
function extractEmails($contact) {
    $emails = [];
    foreach ($contact['EMAIL'] ?? [] as $e) {
        $v = strtolower(trim($e['VALUE'] ?? ''));
        if ($v) $emails[] = $v;
    }
    return $emails;
}

$mapping = [
    '_comment' => 'cloud_contact_id -> box_contact_id. Match by phone or email.',
    'source_url' => 'https://milleniummed.bitrix24.ru',
    'target_url' => 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'contacts' => [],
];

foreach ($cloudContacts as $c) {
    $cloudId = (int)$c['ID'];
    $boxId = null;
    foreach (extractPhones($c) as $digits) {
        if (isset($boxByPhone[$digits])) {
            $boxId = $boxByPhone[$digits];
            break;
        }
    }
    if (!$boxId) {
        foreach (extractEmails($c) as $email) {
            if (isset($boxByEmail[$email])) {
                $boxId = $boxByEmail[$email];
                break;
            }
        }
    }
    $mapping['contacts'][(string)$cloudId] = $boxId;
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Built contact mapping: " . count(array_filter($mapping['contacts'])) . " matched of " . count($mapping['contacts']) . "\n";
