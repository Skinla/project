#!/usr/bin/env php
<?php
/**
 * One-off: compare cloud lead activities vs box (SSH + local logs/DB).
 * Usage: php scripts/check_lead_activities_box_ssh.php CLOUD_LEAD_ID
 */
$projectRoot = dirname(__DIR__);
$cloudLeadId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($cloudLeadId <= 0) {
    fwrite(STDERR, "Usage: php scripts/check_lead_activities_box_ssh.php CLOUD_LEAD_ID\n");
    exit(1);
}

$sshPath = $projectRoot . '/config/ssh.php';
if (!file_exists($sshPath)) {
    fwrite(STDERR, "Missing config/ssh.php\n");
    exit(1);
}
$ssh = require $sshPath;
$host = $ssh['host'] ?? '185.51.60.122';
$port = (int)($ssh['port'] ?? 2226);
$user = $ssh['user'] ?? 'root';
$pass = $ssh['password'] ?? '';

$remotePhp = <<<'PHP'
<?php
$cloud = (int)getenv('CLOUD_LEAD');
$doc = '/home/bitrix/www';
chdir($doc);
$leadLog = json_decode(@file_get_contents('local/handlers/leadsync/data/lead_sync_log.json'), true) ?: [];
$actLog = json_decode(@file_get_contents('local/handlers/leadsync/data/activity_sync_log.json'), true) ?: [];
$boxLead = isset($leadLog[(string)$cloud]) ? (int)$leadLog[(string)$cloud] : null;
$actKeys = [];
foreach (array_keys($actLog) as $k) {
    if (strpos((string)$k, (string)$cloud . ':') === 0) {
        $actKeys[$k] = $actLog[$k];
    }
}
$out = ['box_lead_id' => $boxLead, 'activity_sync_entries' => $actKeys];
if ($boxLead) {
    $_SERVER['DOCUMENT_ROOT'] = $doc;
    global $DB;
    define('NO_KEEP_STATISTIC', true);
    define('NOT_CHECK_PERMISSIONS', true);
    require_once $doc . '/bitrix/modules/main/include/prolog_before.php';
    $r = $DB->Query('SELECT ID, TYPE_ID, PROVIDER_ID, SUBJECT, ORIGINATOR_ID, ORIGIN_ID FROM b_crm_act WHERE OWNER_TYPE_ID = 1 AND OWNER_ID = ' . (int)$boxLead . ' ORDER BY ID');
    $rows = [];
    while ($row = $r->Fetch()) {
        $rows[] = $row;
    }
    $out['b_crm_act_rows'] = $rows;
    $r2 = $DB->Query('SELECT COUNT(*) AS C FROM b_crm_timeline WHERE ASSOCIATED_ENTITY_TYPE_ID = 1 AND ASSOCIATED_ENTITY_ID = ' . (int)$boxLead);
    $out['timeline_count'] = ($r2 && ($x = $r2->Fetch())) ? (int)$x['C'] : null;
    $r3 = $DB->Query(
        'SELECT ID, TYPE_ID, CREATED, AUTHOR_ID, SOURCE_ID, LEFT(CAST(SETTINGS AS CHAR), 200) AS SETTINGS_SNIP '
        . 'FROM b_crm_timeline WHERE ASSOCIATED_ENTITY_TYPE_ID = 1 AND ASSOCIATED_ENTITY_ID = ' . (int)$boxLead . ' ORDER BY ID'
    );
    $trows = [];
    while ($row = $r3->Fetch()) {
        $trows[] = $row;
    }
    $out['b_crm_timeline_rows'] = $trows;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
PHP;

$b64 = base64_encode($remotePhp);
$inner = 'cd /home/bitrix/www && export CLOUD_LEAD=' . (int)$cloudLeadId . ' && echo ' . escapeshellarg($b64) . ' | base64 -d | php';

if ($pass !== '') {
    $cmd = sprintf(
        'sshpass -p %s ssh -o StrictHostKeyChecking=no -p %d %s@%s %s',
        escapeshellarg($pass),
        $port,
        $user,
        $host,
        escapeshellarg($inner)
    );
} else {
    $cmd = sprintf(
        'ssh -o StrictHostKeyChecking=no -p %d %s@%s %s',
        $port,
        $user,
        $host,
        escapeshellarg($inner)
    );
}

passthru($cmd, $code);
exit($code ?: 0);
