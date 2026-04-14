<?php
/**
 * Project integrity check for lead_callback_v2.
 */

if (!defined('NOT_CHECK_PERMISSIONS')) {
    define('NOT_CHECK_PERMISSIONS', true);
}
if (!defined('NO_KEEP_STATISTIC')) {
    define('NO_KEEP_STATISTIC', true);
}
if (!defined('NO_AGENT_CHECK')) {
    define('NO_AGENT_CHECK', true);
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/LeadCallbackQueueStore.php';
require_once __DIR__ . '/LeadCallbackService.php';
require_once __DIR__ . '/LeadCallbackProcessRunner.php';

header('Content-Type: application/json; charset=utf-8');

$config = isset($GLOBALS['LEAD_CALLBACK_V2_CONFIG']) && is_array($GLOBALS['LEAD_CALLBACK_V2_CONFIG'])
    ? $GLOBALS['LEAD_CALLBACK_V2_CONFIG']
    : [];

$report = [
    'ok' => true,
    'project' => 'lead_callback_v2',
    'checked_at' => date('c'),
    'php_sapi' => PHP_SAPI,
    'document_root' => (string)($_SERVER['DOCUMENT_ROOT'] ?? ''),
    'errors' => [],
    'warnings' => [],
    'checks' => [],
];

lead_callback_v2_integrity_check_required_files($report, __DIR__);
lead_callback_v2_integrity_check_classes($report);
lead_callback_v2_integrity_check_config($report, $config);
lead_callback_v2_integrity_check_filesystem($report, $config);
lead_callback_v2_integrity_check_bitrix($report);

$report['ok'] = empty($report['errors']);

if (!$report['ok']) {
    http_response_code(500);
}

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

function lead_callback_v2_integrity_check_required_files(array &$report, $baseDir)
{
    $requiredFiles = [
        'start.php',
        'process.php',
        'bootstrap.php',
        'config.php',
        'LeadCallbackService.php',
        'LeadCallbackQueueStore.php',
        'LeadCallbackProcessRunner.php',
        'vox_callback_operator_client.js',
        'README.md',
    ];

    foreach ($requiredFiles as $relativePath) {
        $path = $baseDir . '/' . $relativePath;
        lead_callback_v2_integrity_add_check(
            $report,
            'required_file:' . $relativePath,
            is_file($path),
            ['path' => $path]
        );
    }
}

function lead_callback_v2_integrity_check_classes(array &$report)
{
    $classes = [
        'LeadCallbackQueueStore',
        'LeadCallbackService',
        'LeadCallbackProcessRunner',
    ];

    foreach ($classes as $className) {
        lead_callback_v2_integrity_add_check(
            $report,
            'class_loaded:' . $className,
            class_exists($className, false),
            ['class' => $className]
        );
    }
}

function lead_callback_v2_integrity_check_config(array &$report, array $config)
{
    $requiredKeys = [
        'LEAD_CALLBACK_V2_LOG_FILE',
        'LEAD_CALLBACK_V2_JOBS_PATH',
        'LEAD_CALLBACK_V2_CITY_LIST_ID',
        'LEAD_CALLBACK_V2_OPERATORS_LIST_ID',
        'LEAD_CALLBACK_V2_SYSTEM_USER_ID',
        'LEAD_CALLBACK_V2_DEFAULT_PORTAL_HOST',
    ];

    foreach ($requiredKeys as $key) {
        $exists = array_key_exists($key, $config);
        $value = $exists ? $config[$key] : null;
        $isFilled = $exists && !($value === '' || $value === null || $value === []);

        lead_callback_v2_integrity_add_check(
            $report,
            'config_key:' . $key,
            $isFilled,
            ['key' => $key, 'value' => $value]
        );
    }

    $testMode = $config['LEAD_CALLBACK_V2_TEST_MODE'] ?? false;
    lead_callback_v2_integrity_add_check(
        $report,
        'config:test_mode',
        true,
        ['enabled' => (bool)$testMode],
        true
    );

    $routeMode = (string)($config['LEAD_CALLBACK_V2_OPERATOR_ROUTE_MODE'] ?? 'user');
    lead_callback_v2_integrity_add_check(
        $report,
        'config:operator_route_mode',
        in_array($routeMode, ['user', 'sip'], true),
        ['value' => $routeMode]
    );

    $voxAccountId = trim((string)($config['LEAD_CALLBACK_V2_VOX_ACCOUNT_ID'] ?? ''));
    $voxApiKey = trim((string)($config['LEAD_CALLBACK_V2_VOX_API_KEY'] ?? ''));
    $voxConfigured = $voxAccountId !== '' && $voxApiKey !== '';
    lead_callback_v2_integrity_add_check(
        $report,
        'config:vox_credentials',
        $voxConfigured || (bool)$testMode,
        [
            'configured' => $voxConfigured,
            'account_id_present' => $voxAccountId !== '',
            'api_key_present' => $voxApiKey !== '',
            'test_mode' => (bool)$testMode,
        ],
        !$voxConfigured && (bool)$testMode
    );
}

function lead_callback_v2_integrity_check_filesystem(array &$report, array $config)
{
    $logPath = !empty($config['LEAD_CALLBACK_V2_LOG_FILE'])
        ? (string)$config['LEAD_CALLBACK_V2_LOG_FILE']
        : (__DIR__ . '/lead_callback_v2.log');
    $jobsPath = !empty($config['LEAD_CALLBACK_V2_JOBS_PATH'])
        ? (string)$config['LEAD_CALLBACK_V2_JOBS_PATH']
        : (__DIR__ . '/jobs');

    $logDir = dirname($logPath);
    lead_callback_v2_integrity_add_check(
        $report,
        'filesystem:log_dir_exists',
        is_dir($logDir),
        ['path' => $logDir]
    );
    lead_callback_v2_integrity_add_check(
        $report,
        'filesystem:log_dir_writable',
        is_dir($logDir) && is_writable($logDir),
        ['path' => $logDir]
    );

    $jobsDirExists = is_dir($jobsPath);
    lead_callback_v2_integrity_add_check(
        $report,
        'filesystem:jobs_dir_exists',
        $jobsDirExists,
        ['path' => $jobsPath],
        !$jobsDirExists
    );

    if ($jobsDirExists) {
        lead_callback_v2_integrity_add_check(
            $report,
            'filesystem:jobs_dir_writable',
            is_writable($jobsPath),
            ['path' => $jobsPath]
        );
    }
}

function lead_callback_v2_integrity_check_bitrix(array &$report)
{
    $modules = ['crm', 'iblock', 'timeman', 'voximplant'];
    foreach ($modules as $moduleName) {
        $loaded = class_exists('\Bitrix\Main\Loader') && \Bitrix\Main\Loader::includeModule($moduleName);
        lead_callback_v2_integrity_add_check(
            $report,
            'bitrix_module:' . $moduleName,
            $loaded,
            ['module' => $moduleName]
        );
    }

    $currentUserId = 0;
    if (isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser) {
        $currentUserId = (int)$GLOBALS['USER']->GetID();
    }

    lead_callback_v2_integrity_add_check(
        $report,
        'bitrix:authorized_user',
        $currentUserId > 0,
        ['current_user_id' => $currentUserId]
    );
}

function lead_callback_v2_integrity_add_check(array &$report, $name, $passed, array $details = [], $warningOnly = false)
{
    $check = [
        'name' => (string)$name,
        'ok' => (bool)$passed,
        'details' => $details,
    ];

    $report['checks'][] = $check;

    if ($passed) {
        return;
    }

    if ($warningOnly) {
        $report['warnings'][] = $check;
        return;
    }

    $report['errors'][] = $check;
}
