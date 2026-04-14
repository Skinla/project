<?php
// v3/status.php
// Простой статус очередей и последних записей лога.
// Доступ: https://bitrix.dreamteamcompany.ru/local/handlers/universal-system/v3/status.php

$config = require __DIR__ . '/config.php';

$retryCount = 0;
$errorsCount = 0;
$processedCount = 0;

if (is_dir($config['retry_dir'])) {
    $retryCount = count(glob($config['retry_dir'] . '/*.json'));
}
if (is_dir($config['errors_dir'])) {
    $errorsCount = count(glob($config['errors_dir'] . '/*.json'));
}
if (is_dir($config['processed_dir'])) {
    $processedCount = count(glob($config['processed_dir'] . '/*.json'));
}

$lastLogs = [];
if (file_exists($config['log_file'])) {
    $lines = file($config['log_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lastLogs = array_slice($lines, -30);
    $lastLogs = array_reverse($lastLogs);
}

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'retry' => $retryCount,
        'errors' => $errorsCount,
        'processed' => $processedCount,
        'last_logs' => $lastLogs,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
?><!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>v3 Status</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; color: #333; }
  h1 { font-size: 1.4em; border-bottom: 2px solid #2196F3; padding-bottom: 8px; }
  .cards { display: flex; gap: 16px; margin: 20px 0; }
  .card { flex: 1; background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center; }
  .card .num { font-size: 2em; font-weight: 700; }
  .card .label { font-size: 0.85em; color: #888; margin-top: 4px; }
  .retry .num { color: #FF9800; }
  .errors .num { color: #f44336; }
  .processed .num { color: #4CAF50; }
  h2 { font-size: 1.1em; margin-top: 30px; }
  .log { background: #1e1e1e; color: #d4d4d4; border-radius: 8px; padding: 16px; font-family: 'Fira Code', 'Consolas', monospace; font-size: 0.8em; max-height: 500px; overflow-y: auto; line-height: 1.6; }
  .log .ok { color: #4EC9B0; }
  .log .err { color: #F44747; }
  .log .retry { color: #DCDCAA; }
</style>
</head>
<body>
<h1>Universal System v3 -- Status</h1>

<div class="cards">
  <div class="card retry">
    <div class="num"><?= $retryCount ?></div>
    <div class="label">Retry</div>
  </div>
  <div class="card errors">
    <div class="num"><?= $errorsCount ?></div>
    <div class="label">Errors</div>
  </div>
  <div class="card processed">
    <div class="num"><?= $processedCount ?></div>
    <div class="label">Processed</div>
  </div>
</div>

<h2>Последние записи лога</h2>
<div class="log">
<?php if (empty($lastLogs)): ?>
  <em>Лог пуст</em>
<?php else: ?>
  <?php foreach ($lastLogs as $line):
    $cls = '';
    if (strpos($line, '] OK ') !== false) $cls = 'ok';
    elseif (strpos($line, '] ERR ') !== false) $cls = 'err';
    elseif (strpos($line, '] RETRY ') !== false) $cls = 'retry';
  ?>
    <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
  <?php endforeach; ?>
<?php endif; ?>
</div>

<p style="font-size:0.8em;color:#aaa;margin-top:20px">
  JSON: <a href="?format=json">status.php?format=json</a>
</p>
</body>
</html>
