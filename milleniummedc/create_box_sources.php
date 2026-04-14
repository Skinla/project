#!/usr/bin/env php
<?php
/**
 * Create missing SOURCE entries on box from cloud.
 * Run: php create_box_sources.php [--dry-run]
 * Or deploy to box and run there.
 */
$projectRoot = __DIR__;
$dryRun = in_array('--dry-run', $argv ?? []);

$config = require $projectRoot . '/config/webhook.php';
require_once $projectRoot . '/lib/BitrixRestClient.php';

$client = new BitrixRestClient($config['url']);
$cloudResp = $client->call('crm.status.list', ['filter' => ['ENTITY_ID' => 'SOURCE']]);

$missingOnBox = [
    'UC_QUY0M1' => 'Арбитражник 2',
    'UC_WLSZQY' => 'Арбитражник 3',
    'UC_WDO908' => 'Арбитражник 4',
    'UC_7NTPSY' => 'НЕРОН',
    'UC_2N3ORA' => 'Стоматология',
    '2|RADIST_ONLINE_WHATSAPP' => 'WhatsApp - Открытая линия',
    '2|NOTIFICATIONS' => 'Битрикс24 СМС и WhatsApp',
];

foreach ($cloudResp['result'] ?? [] as $s) {
    $id = $s['STATUS_ID'];
    $name = $s['NAME'] ?? '';
    if (isset($missingOnBox[$id]) || (strpos($id, 'UC_') === 0 || strpos($id, '2|') === 0)) {
        $missingOnBox[$id] = $name;
    }
}

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

// SORT must stay <= ~80 or new CRM lead card hides the source (see source_mapping_table.md)
$sort = 11;
$sqls = [];
foreach ($missingOnBox as $statusId => $name) {
    $statusIdEsc = str_replace("'", "''", $statusId);
    $nameEsc = str_replace("'", "''", $name);
    $sqls[] = "INSERT INTO b_crm_status (ENTITY_ID, STATUS_ID, NAME, SORT, `SYSTEM`) SELECT 'SOURCE', '$statusIdEsc', '$nameEsc', $sort, 'N' FROM (SELECT 1) x WHERE NOT EXISTS (SELECT 1 FROM b_crm_status WHERE ENTITY_ID='SOURCE' AND STATUS_ID='$statusIdEsc')";
    $sort++;
}

if ($dryRun) {
    echo "Would execute:\n" . implode(";\n", $sqls) . "\n";
    exit(0);
}

$tmpFile = sys_get_temp_dir() . '/box_sources_' . getmypid() . '.sql';
file_put_contents($tmpFile, implode(";\n", $sqls));

$scpCmd = $pass
    ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/tmp/', escapeshellarg($pass), $port, escapeshellarg($tmpFile), $user, $host)
    : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:/tmp/', $port, escapeshellarg($tmpFile), $user, $host);
exec($scpCmd . ' 2>&1');

$remoteFile = '/tmp/' . basename($tmpFile);
$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "mysql sitemanager < %s"', escapeshellarg($pass), $port, $user, $host, escapeshellarg($remoteFile))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s "mysql sitemanager < %s"', $port, $user, $host, escapeshellarg($remoteFile));
exec($sshCmd . ' 2>&1', $out, $code);

@unlink($tmpFile);
exec(sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s "rm -f %s"', escapeshellarg($pass), $port, $user, $host, escapeshellarg($remoteFile)) . ' 2>/dev/null');

echo implode("\n", $out) . "\n";
echo ($code === 0 ? "OK" : "Exit: $code") . "\n";
