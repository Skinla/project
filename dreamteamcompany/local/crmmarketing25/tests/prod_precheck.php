<?php
declare(strict_types=1);

/**
 * Production precheck for crmmarketing25 integration.
 *
 * Usage:
 *   php tests/prod_precheck.php
 *   php tests/prod_precheck.php --format=json
 */

$checks = [];
$failed = 0;

function resolveBaseDir(): string
{
    $candidates = [dirname(__DIR__), __DIR__];
    foreach ($candidates as $start) {
        $dir = $start;
        for ($i = 0; $i < 6; $i++) {
            if (is_file($dir . '/deal_webhook_handler.php') && is_file($dir . '/deal_webhook_config.php')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
    }
    return dirname(__DIR__);
}

$baseDir = resolveBaseDir();

function addCheck(array &$checks, string $name, bool $ok, string $details): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'details' => $details,
    ];
}

function boolStr(bool $value): string
{
    return $value ? 'yes' : 'no';
}

// 1) Required files
$requiredFiles = [
    $baseDir . '/bitrix_init.php',
    $baseDir . '/deal_webhook_config.php',
    $baseDir . '/deal_webhook_handler.php',
];

foreach ($requiredFiles as $file) {
    $exists = is_file($file);
    addCheck($checks, 'file_exists:' . basename($file), $exists, $exists ? 'ok' : 'missing');
    if (!$exists) {
        $failed++;
    }
}

// 2) Config load and shape
$config = null;
try {
    $config = require $baseDir . '/deal_webhook_config.php';
    $ok = is_array($config)
        && isset($config['cloud']['webhook_base'])
        && isset($config['storage']['deal_lead_map_file'])
        && isset($config['log']['file'])
        && isset($config['notifications']['error_chat_id']);
    addCheck($checks, 'config_load', $ok, $ok ? 'config keys present' : 'invalid config structure');
    if (!$ok) {
        $failed++;
    }
} catch (Throwable $e) {
    addCheck($checks, 'config_load', false, $e->getMessage());
    $failed++;
}

// 3) Basic PHP/runtime checks
$curlLoaded = extension_loaded('curl');
addCheck($checks, 'php_extension:curl', $curlLoaded, 'loaded=' . boolStr($curlLoaded));
if (!$curlLoaded) {
    $failed++;
}

$jsonLoaded = extension_loaded('json');
addCheck($checks, 'php_extension:json', $jsonLoaded, 'loaded=' . boolStr($jsonLoaded));
if (!$jsonLoaded) {
    $failed++;
}

// 4) Filesystem writable checks
if (is_array($config)) {
    $mapFile = (string)$config['storage']['deal_lead_map_file'];
    $mapDir = dirname($mapFile);
    $logFile = (string)$config['log']['file'];
    $logDir = dirname($logFile);

    $mapDirWritable = is_dir($mapDir) ? is_writable($mapDir) : is_writable(dirname($mapDir));
    addCheck($checks, 'fs_writable:map_dir', $mapDirWritable, $mapDir);
    if (!$mapDirWritable) {
        $failed++;
    }

    $logDirWritable = is_dir($logDir) ? is_writable($logDir) : is_writable(dirname($logDir));
    addCheck($checks, 'fs_writable:log_dir', $logDirWritable, $logDir);
    if (!$logDirWritable) {
        $failed++;
    }

    $lockFile = $mapFile . '.lock';
    $lockHandle = @fopen($lockFile, 'c+');
    if ($lockHandle === false) {
        addCheck($checks, 'lock_file_open', false, $lockFile);
        $failed++;
    } else {
        $lockOk = @flock($lockHandle, LOCK_EX | LOCK_NB);
        addCheck($checks, 'lock_file_flock', $lockOk, $lockFile);
        if (!$lockOk) {
            $failed++;
        }
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
}

// 5) Bitrix bootstrap and modules
try {
    require_once $baseDir . '/bitrix_init.php';
    addCheck($checks, 'bitrix_init', true, 'loaded');
} catch (Throwable $e) {
    addCheck($checks, 'bitrix_init', false, $e->getMessage());
    $failed++;
}

$crmModule = class_exists('CModule') ? (bool)CModule::IncludeModule('crm') : false;
addCheck($checks, 'bitrix_module:crm', $crmModule, 'loaded=' . boolStr($crmModule));
if (!$crmModule) {
    $failed++;
}

$iblockModule = class_exists('CModule') ? (bool)CModule::IncludeModule('iblock') : false;
addCheck($checks, 'bitrix_module:iblock', $iblockModule, 'loaded=' . boolStr($iblockModule));
if (!$iblockModule) {
    $failed++;
}

$imModule = class_exists('CModule') ? (bool)CModule::IncludeModule('im') : false;
addCheck($checks, 'bitrix_module:im', $imModule, 'loaded=' . boolStr($imModule));

// 6) Cloud webhook connectivity (read-only method)
if (is_array($config) && !empty($config['cloud']['webhook_base']) && $curlLoaded) {
    $url = rtrim((string)$config['cloud']['webhook_base'], '/') . '/crm.deal.fields';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        addCheck($checks, 'cloud_webhook_reachable', false, curl_error($ch));
        $failed++;
    } else {
        $decoded = json_decode($response, true);
        $ok = is_array($decoded) && empty($decoded['error']);
        $details = $ok ? 'crm.deal.fields ok' : ('error=' . (string)($decoded['error_description'] ?? $decoded['error'] ?? 'unknown'));
        addCheck($checks, 'cloud_webhook_reachable', $ok, $details);
        if (!$ok) {
            $failed++;
        }
    }
    curl_close($ch);
}

$argvList = [];
if (isset($argv) && is_array($argv)) {
    $argvList = $argv;
} elseif (isset($_SERVER['argv']) && is_array($_SERVER['argv'])) {
    $argvList = $_SERVER['argv'];
}

$isJsonOutput = in_array('--format=json', $argvList, true);
if ($isJsonOutput) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => $failed === 0,
        'failed' => $failed,
        'checks' => $checks,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit($failed === 0 ? 0 : 1);
}

echo "=== crmmarketing25 Production Precheck ===\n";
foreach ($checks as $check) {
    $mark = $check['ok'] ? '[OK]' : '[FAIL]';
    echo sprintf("%-8s %-32s %s\n", $mark, $check['name'], $check['details']);
}
echo "\nResult: " . ($failed === 0 ? 'READY FOR PRODUCTION' : ('FAILED CHECKS: ' . $failed)) . "\n";
exit($failed === 0 ? 0 : 1);
