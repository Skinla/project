<?php
/**
 * Start lead callback v2 job from BizProc webhook.
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
$store = new LeadCallbackQueueStore(lead_callback_v2_jobs_path($config));
$logger = function ($message, array $context = []) use ($config) {
    lead_callback_v2_log($config, $message, $context);
};
$service = new LeadCallbackService(lead_callback_v2_service_options($config), $logger);
$runner = new LeadCallbackProcessRunner($store, $service, $logger);
$request = lead_callback_v2_request();
$leadId = lead_callback_v2_extract_int($request['lead_id'] ?? 0);

if ($leadId <= 0) {
    lead_callback_v2_log($config, 'start:invalid_request', ['request' => $request]);
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'lead_id required'], JSON_UNESCAPED_UNICODE);
    return;
}

$lock = $store->acquireLeadLock($leadId);
if ($lock === false) {
    lead_callback_v2_log($config, 'start:lock_failed', ['lead_id' => $leadId]);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Failed to acquire lead lock'], JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $existingJob = $store->readJob($leadId);
    $created = false;

    if (is_array($existingJob) && $service->isActiveState($existingJob['state'] ?? '')) {
        $job = $service->mergeRequestIntoJob($existingJob, $request);
        $job['result_message'] = 'Активная задача уже существует';
    } else {
        $job = $service->createJob($request);
        $created = true;
    }

    if (!$store->writeJob($job)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Failed to persist job'], JSON_UNESCAPED_UNICODE);
        return;
    }
} finally {
    $store->releaseLeadLock($lock);
}

$processResult = $runner->run($leadId, 1, true);
$processedNow = (int)($processResult['processed'] ?? 0) > 0;
$dispatchTriggered = false;
if (!empty($processResult['items'][0]['job']) && is_array($processResult['items'][0]['job'])) {
    $job = array_merge($job, $processResult['items'][0]['job']);
}
lead_callback_v2_log($config, 'start:accepted', [
    'lead_id' => $leadId,
    'created' => $created,
    'processed_now' => $processedNow,
    'state' => $job['state'] ?? '',
    'job_id' => $job['job_id'] ?? '',
]);

echo json_encode([
    'ok' => true,
    'created' => $created,
    'processed_now' => $processedNow,
    'dispatched' => $dispatchTriggered,
    'process_result' => $processResult,
    'job' => $service->formatPublicJob($job),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

function lead_callback_v2_request()
{
    $request = array_merge($_GET, $_POST);
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $request = array_merge($request, $decoded);
        }
    }

    return $request;
}

function lead_callback_v2_extract_int($value)
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

function lead_callback_v2_jobs_path(array $config)
{
    if (!empty($config['LEAD_CALLBACK_V2_JOBS_PATH'])) {
        return (string)$config['LEAD_CALLBACK_V2_JOBS_PATH'];
    }

    return __DIR__ . '/jobs';
}

function lead_callback_v2_log_path(array $config)
{
    if (!empty($config['LEAD_CALLBACK_V2_LOG_FILE'])) {
        return (string)$config['LEAD_CALLBACK_V2_LOG_FILE'];
    }

    return __DIR__ . '/lead_callback_v2.log';
}

function lead_callback_v2_log(array $config, $message, array $context = [])
{
    $path = lead_callback_v2_log_path($config);
    $line = date('Y-m-d H:i:s') . ' ' . (string)$message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
    }
    $line .= "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function lead_callback_v2_service_options(array $config)
{
    return [
        'city_list_id' => isset($config['LEAD_CALLBACK_V2_CITY_LIST_ID']) ? (int)$config['LEAD_CALLBACK_V2_CITY_LIST_ID'] : 22,
        'operators_list_id' => isset($config['LEAD_CALLBACK_V2_OPERATORS_LIST_ID']) ? (int)$config['LEAD_CALLBACK_V2_OPERATORS_LIST_ID'] : 128,
        'jobs_path' => lead_callback_v2_jobs_path($config),
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

