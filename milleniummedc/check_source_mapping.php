#!/usr/bin/env php
<?php
/**
 * Output cloud vs box SOURCE mapping table (code + name).
 * Usage: php check_source_mapping.php
 */
$projectRoot = __DIR__;
$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$cloudResp = $client->call('crm.status.list', ['filter' => ['ENTITY_ID' => 'SOURCE']]);
$cloudSources = [];
foreach ($cloudResp['result'] ?? [] as $s) {
    $cloudSources[$s['STATUS_ID']] = $s['NAME'] ?? '';
}

$sourceMapping = file_exists($projectRoot . '/data/source_mapping.json')
    ? json_decode(file_get_contents($projectRoot . '/data/source_mapping.json'), true) ?: []
    : [];
$mapping = $sourceMapping['sources'] ?? [];

// Get box sources via SSH
$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$cmd = "mysql sitemanager -e \"SELECT STATUS_ID, NAME FROM b_crm_status WHERE ENTITY_ID='SOURCE'\" 2>/dev/null";
$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($cmd))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', $port, $user, $host, escapeshellarg($cmd));

$output = shell_exec($sshCmd);
$boxSources = [];
foreach (explode("\n", trim($output ?? '')) as $line) {
    if (strpos($line, "\t") !== false) {
        list($id, $name) = explode("\t", $line, 2);
        if ($id !== 'STATUS_ID') {
            $boxSources[trim($id)] = trim($name);
        }
    }
}

echo "=== ИСТОЧНИК: облако vs коробка ===\n\n";
echo "| Код (облако) | Название (облако) | → Код (коробка) | Название (коробка) | Есть на коробке? |\n";
echo "|--------------|-------------------|-----------------|--------------------|------------------|\n";

$allCodes = array_unique(array_merge(array_keys($cloudSources), array_keys($mapping)));
sort($allCodes);

foreach ($allCodes as $cloudCode) {
    $cloudName = $cloudSources[$cloudCode] ?? '(нет в облаке)';
    $boxCode = $mapping[$cloudCode] ?? $cloudCode;
    $boxName = $boxSources[$boxCode] ?? '(нет на коробке)';
    $exists = isset($boxSources[$boxCode]) ? 'да' : '**НЕТ**';
    echo sprintf("| %-14s | %-17s | %-15s | %-18s | %-16s |\n",
        $cloudCode,
        mb_substr($cloudName, 0, 17),
        $boxCode,
        mb_substr($boxName, 0, 18),
        $exists
    );
}

echo "\n=== Источники только на коробке ===\n";
foreach ($boxSources as $code => $name) {
    if (!in_array($code, array_values($mapping)) && !isset($mapping[$code])) {
        echo "  $code => $name\n";
    }
}
