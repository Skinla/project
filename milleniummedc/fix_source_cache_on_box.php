#!/usr/bin/env php
<?php
/**
 * After SQL inserts into b_crm_status (SOURCE), Bitrix ORM cache still serves old list → UI shows 14 items.
 * Run: php fix_source_cache_on_box.php
 */
$sshConfig = file_exists(__DIR__ . '/config/ssh.php') ? require __DIR__ . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$sql = "UPDATE b_crm_status SET CATEGORY_ID = 0 WHERE ENTITY_ID = 'SOURCE' AND CATEGORY_ID IS NULL";
$cmd1 = "mysql sitemanager -e " . escapeshellarg($sql);
$cmd2 = "rm -rf /home/bitrix/www/bitrix/cache/* /home/bitrix/www/bitrix/managed_cache/*";

$ssh = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s', escapeshellarg($pass), $port, $user, $host)
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s', $port, $user, $host);

passthru("$ssh " . escapeshellarg($cmd1));
passthru("$ssh " . escapeshellarg($cmd2));
echo "Done: CATEGORY_ID fixed + Bitrix cache cleared.\n";
