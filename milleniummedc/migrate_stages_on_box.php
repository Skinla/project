#!/usr/bin/env php
<?php
/**
 * Копирует на коробку scan_result.json + migrate_stages_local.php и создаёт недостающие стадии лидов/сделок (как в облаке).
 *
 * После успешного прогона:
 *   1) Обновите data/target_stages.json с коробки: php get_target_stages.php (на сервере) → сохранить в проект
 *   2) php build_stage_mapping.php
 *   3) скопировать data/stage_mapping.json в local/handlers/leadsync/data/ и на сервер при необходимости
 *
 * Usage:
 *   php migrate_stages_on_box.php [--dry-run] [--leads-only] [--deals-only]
 */
$projectRoot = __DIR__;
$extra = [];
foreach ($argv ?? [] as $a) {
    if (preg_match('/^--(dry-run|leads-only|deals-only)$/', $a)) {
        $extra[] = $a;
    }
}

$sshConfig = file_exists($projectRoot . '/config/ssh.php') ? require $projectRoot . '/config/ssh.php' : [];
$host = $sshConfig['host'] ?? '185.51.60.122';
$port = $sshConfig['port'] ?? 2226;
$user = $sshConfig['user'] ?? 'root';
$pass = $sshConfig['password'] ?? '';

$scan = $projectRoot . '/data/scan_result.json';
$localScript = $projectRoot . '/migrate_stages_local.php';
if (!is_file($scan) || !is_file($localScript)) {
    fwrite(STDERR, "Need data/scan_result.json and migrate_stages_local.php\n");
    exit(1);
}

$remoteDir = '/home/bitrix/www';
$remoteScan = $remoteDir . '/scan_result_stages.json';
$remoteScript = $remoteDir . '/migrate_stages_local.php';

$scp = function (string $local, string $remote) use ($pass, $port, $user, $host) {
    $cmd = $pass
        ? sprintf('sshpass -p %s scp -o StrictHostKeyChecking=no -P %d %s %s@%s:%s', escapeshellarg($pass), $port, escapeshellarg($local), $user, $host, $remote)
        : sprintf('scp -o StrictHostKeyChecking=no -P %d %s %s@%s:%s', $port, escapeshellarg($local), $user, $host, $remote);
    passthru($cmd . ' 2>&1', $code);
    return $code === 0;
};

echo "Uploading scan_result.json and migrate_stages_local.php...\n";
if (!$scp($scan, $remoteScan) || !$scp($localScript, $remoteScript)) {
    fwrite(STDERR, "SCP failed\n");
    exit(1);
}

$args = array_map('escapeshellarg', $extra);
$inner = 'cd ' . escapeshellarg($remoteDir) . ' && php migrate_stages_local.php scan_result_stages.json ' . implode(' ', $args);
$sshCmd = $pass
    ? sprintf('sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', escapeshellarg($pass), $port, $user, $host, escapeshellarg($inner))
    : sprintf('ssh -o StrictHostKeyChecking=no -p %d %s@%s %s', $port, $user, $host, escapeshellarg($inner));

echo "Running on box: $inner\n";
passthru($sshCmd, $code);
exit($code);
