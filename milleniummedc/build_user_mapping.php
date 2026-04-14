#!/usr/bin/env php
<?php
/**
 * Build user mapping: cloud user ID -> box user ID.
 * Matches by LOGIN (primary) or EMAIL (fallback).
 */
$projectRoot = __DIR__;
$boxPath = $projectRoot . '/data/box_users.json';
$cloudPath = $projectRoot . '/data/cloud_users.json';
$outputPath = $projectRoot . '/data/user_mapping.json';

if (!file_exists($boxPath) || !file_exists($cloudPath)) {
    fwrite(STDERR, "Error: Run get_box_users on server and user.get from cloud first.\n");
    fwrite(STDERR, "  ssh root@box 'php get_box_users.php' > data/box_users.json\n");
    fwrite(STDERR, "  php -r \"require 'lib/BitrixRestClient.php'; ... user.get ...\" > data/cloud_users.json\n");
    exit(1);
}

$boxUsers = json_decode(file_get_contents($boxPath), true);
$cloudUsers = json_decode(file_get_contents($cloudPath), true);

if (!is_array($boxUsers) || !is_array($cloudUsers)) {
    fwrite(STDERR, "Error: invalid JSON\n");
    exit(1);
}

$boxByLogin = [];
$boxByEmail = [];
foreach ($boxUsers as $u) {
    $login = strtolower(trim($u['LOGIN'] ?? ''));
    $email = strtolower(trim($u['EMAIL'] ?? ''));
    if ($login) $boxByLogin[$login] = (int)$u['ID'];
    if ($email) $boxByEmail[$email] = (int)$u['ID'];
}

$mapping = [
    '_comment' => 'Сопоставление пользователей: cloud_user_id -> box_user_id. null = пользователь не найден на коробке.',
    'source_url' => 'https://milleniummed.bitrix24.ru',
    'target_url' => 'https://bitrix.milleniummedc.ru',
    'created_at' => date('c'),
    'users' => [],
];

foreach ($cloudUsers as $c) {
    $cloudId = (int)$c['ID'];
    $login = strtolower(trim($c['LOGIN'] ?? ''));
    $email = strtolower(trim($c['EMAIL'] ?? ''));
    $name = trim(($c['NAME'] ?? '') . ' ' . ($c['LAST_NAME'] ?? ''));

    $boxId = null;
    if ($login && isset($boxByLogin[$login])) {
        $boxId = $boxByLogin[$login];
    } elseif ($email && isset($boxByEmail[$email])) {
        $boxId = $boxByEmail[$email];
    }

    $mapping['users'][(string)$cloudId] = $boxId;
}

file_put_contents($outputPath, json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "User mapping saved to data/user_mapping.json\n";

$mapped = count(array_filter($mapping['users']));
$total = count($mapping['users']);
echo "Mapped: $mapped / $total users\n";
