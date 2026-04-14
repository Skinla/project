<?php
/**
 * Process due lead callback v2 jobs.
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

$config = isset($GLOBALS['LEAD_CALLBACK_V2_CONFIG']) && is_array($GLOBALS['LEAD_CALLBACK_V2_CONFIG']) ? $GLOBALS['LEAD_CALLBACK_V2_CONFIG'] : [];
$request = lead_callback_v2_process_request();
$leadId = lead_callback_v2_process_extract_int($request['lead_id'] ?? 0);
$limit = max(1, lead_callback_v2_process_extract_int($request['limit'] ?? 10));
$force = isset($request['force']) && in_array((string)$request['force'], ['1', 'Y', 'y', 'true', 'TRUE'], true);

$store = new LeadCallbackQueueStore(lead_callback_v2_process_jobs_path($config));
$logger = function ($message, array $context = []) use ($config) {
    lead_callback_v2_process_log($config, $message, $context);
};
$service = new LeadCallbackService(lead_callback_v2_process_service_options($config), $logger);
$runner = new LeadCallbackProcessRunner($store, $service, $logger);
$result = $runner->run($leadId, $limit, $force);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

function lead_callback_v2_process_request()
{
    $request = array_merge($_GET, $_POST);

    if (PHP_SAPI === 'cli' && !empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
        foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
            if (strpos($arg, '=') === false) {
                continue;
            }

            list($key, $value) = explode('=', $arg, 2);
            if ($key !== '') {
                $request[$key] = $value;
            }
        }
    }

    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $request = array_merge($request, $decoded);
        }
    }

    return $request;
}

function lead_callback_v2_process_extract_int($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }

    $clean = preg_replace('/\D+/', '', (string)$value);
    if (!is_string($clean) || $clean === '') {
        return 0;
    }

    return (int)$clean;
}

function lead_callback_v2_process_jobs_path(array $config)
{
    if (!empty($config['LEAD_CALLBACK_V2_JOBS_PATH'])) {
        return (string)$config['LEAD_CALLBACK_V2_JOBS_PATH'];
    }

    return __DIR__ . '/jobs';
}

function lead_callback_v2_process_log_path(array $config)
{
    if (!empty($config['LEAD_CALLBACK_V2_LOG_FILE'])) {
        return (string)$config['LEAD_CALLBACK_V2_LOG_FILE'];
    }

    return __DIR__ . '/lead_callback_v2.log';
}

function lead_callback_v2_process_log(array $config, $message, array $context = [])
{
    $path = lead_callback_v2_process_log_path($config);
    $line = date('Y-m-d H:i:s') . ' ' . (string)$message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function lead_callback_v2_process_service_options(array $config)
{
    return [
        'city_list_id' => isset($config['LEAD_CALLBACK_V2_CITY_LIST_ID']) ? (int)$config['LEAD_CALLBACK_V2_CITY_LIST_ID'] : 22,
        'operators_list_id' => isset($config['LEAD_CALLBACK_V2_OPERATORS_LIST_ID']) ? (int)$config['LEAD_CALLBACK_V2_OPERATORS_LIST_ID'] : 128,
        'jobs_path' => lead_callback_v2_process_jobs_path($config),
        'vox_account_id' => (string)($config['LEAD_CALLBACK_V2_VOX_ACCOUNT_ID'] ?? ''),
        'vox_api_key' => (string)($config['LEAD_CALLBACK_V2_VOX_API_KEY'] ?? ''),
        'vox_test_mode' => $config['LEAD_CALLBACK_V2_TEST_MODE'] ?? false,
        'vox_test_result' => (string)($config['LEAD_CALLBACK_V2_TEST_RESULT'] ?? 'connected'),
        'operator_route_mode' => (string)($config['LEAD_CALLBACK_V2_OPERATOR_ROUTE_MODE'] ?? 'user'),
        'operator_sip_destination_template' => (string)($config['LEAD_CALLBACK_V2_OPERATOR_SIP_DESTINATION_TEMPLATE'] ?? ''),
        'default_portal_host' => (string)($config['LEAD_CALLBACK_V2_DEFAULT_PORTAL_HOST'] ?? 'bitrix.dreamteamcompany.ru'),
        'required_lead_status' => (string)($config['LEAD_CALLBACK_V2_REQUIRED_STATUS'] ?? 'NEW'),
        'lead_final_failed_status' => (string)($config['LEAD_CALLBACK_V2_FINAL_FAILED_STATUS'] ?? 'PROCESSED'),
    ];
}
