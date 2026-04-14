<?php
/**
 * Web debug page for Vox calls by vox_session_id.
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

$config = isset($GLOBALS['LEAD_CALLBACK_V2_CONFIG']) && is_array($GLOBALS['LEAD_CALLBACK_V2_CONFIG'])
    ? $GLOBALS['LEAD_CALLBACK_V2_CONFIG']
    : [];

$input = isset($_REQUEST['vox_session_id']) ? (string)$_REQUEST['vox_session_id'] : '4551054900';
$format = isset($_REQUEST['format']) ? trim((string)$_REQUEST['format']) : '';
$sessionIds = lead_callback_v2_debug_extract_session_ids($input);
$reports = [];

foreach ($sessionIds as $sessionId) {
    $reports[] = lead_callback_v2_debug_build_report($config, $sessionId);
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    if (count($reports) === 1) {
        echo json_encode($reports[0], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return;
    }

    echo json_encode([
        'ok' => !empty($reports),
        'requested_input' => $input,
        'vox_session_ids' => $sessionIds,
        'reports' => $reports,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

header('Content-Type: text/html; charset=utf-8');
echo lead_callback_v2_debug_render_html($input, $sessionIds, $reports);

function lead_callback_v2_debug_extract_digits($value)
{
    $digits = preg_replace('/\D+/', '', (string)$value);
    return is_string($digits) ? $digits : '';
}

function lead_callback_v2_debug_extract_session_ids($value)
{
    $matches = [];
    preg_match_all('/\d{6,}/', (string)$value, $matches);
    $ids = [];
    foreach (($matches[0] ?? []) as $match) {
        $digits = lead_callback_v2_debug_extract_digits($match);
        if ($digits !== '' && !in_array($digits, $ids, true)) {
            $ids[] = $digits;
        }
    }

    if (empty($ids)) {
        $fallback = lead_callback_v2_debug_extract_digits($value);
        if ($fallback !== '') {
            $ids[] = $fallback;
        }
    }

    return $ids;
}

function lead_callback_v2_debug_build_report(array $config, $sessionId)
{
    $accountId = lead_callback_v2_debug_resolve_config_value(
        $config,
        'LEAD_CALLBACK_V2_VOX_ACCOUNT_ID',
        ['LEAD_CALLBACK_V2_VOX_ACCOUNT_ID', 'LEAD_CALLBACK_VOX_ACCOUNT_ID']
    );
    $apiKey = lead_callback_v2_debug_resolve_config_value(
        $config,
        'LEAD_CALLBACK_V2_VOX_API_KEY',
        ['LEAD_CALLBACK_V2_VOX_API_KEY', 'LEAD_CALLBACK_VOX_API_KEY']
    );
    $callId = 'vox_' . $sessionId;

    $report = [
        'ok' => true,
        'vox_session_id' => $sessionId,
        'call_id' => $callId,
        'checked_at' => date('c'),
        'bitrix_user_id' => (isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser) ? (int)$GLOBALS['USER']->GetID() : 0,
        'vox_credentials' => [
            'account_id' => $accountId,
            'api_key_present' => $apiKey !== '',
        ],
        'vox_history' => null,
        'vox_history_summary' => null,
        'custom_data_decoded' => null,
        'scenario_log' => null,
        'call_leg_analysis' => null,
        'statistic_table' => null,
        'errors' => [],
    ];

    if ($accountId === '' || $apiKey === '') {
        $report['ok'] = false;
        $report['errors'][] = 'Vox credentials are not configured';
        return $report;
    }

    $historyResult = lead_callback_v2_debug_get_vox_history($accountId, $apiKey, $sessionId);
    $report['vox_history'] = $historyResult;
    if (!empty($historyResult['matched_result']) && is_array($historyResult['matched_result'])) {
        $matchedHistory = $historyResult['matched_result'];
        $report['vox_history_summary'] = lead_callback_v2_debug_summarize_vox_history($matchedHistory);
        $report['custom_data_decoded'] = lead_callback_v2_debug_decode_custom_data($matchedHistory);
        $report['scenario_log'] = lead_callback_v2_debug_fetch_scenario_log($matchedHistory);
        $report['call_leg_analysis'] = lead_callback_v2_debug_analyze_scenario_log(
            $report['scenario_log'],
            $report['vox_history_summary'],
            $report['custom_data_decoded']
        );
    }
    if (!empty($historyResult['error'])) {
        $report['errors'][] = 'vox_history: ' . $historyResult['error'];
    }

    $statisticRow = lead_callback_v2_debug_get_statistic_row($callId);
    $report['statistic_table'] = $statisticRow;
    if (isset($statisticRow['error'])) {
        $report['errors'][] = 'statistic_table: ' . $statisticRow['error'];
    }

    $report['ok'] = empty($report['errors']);
    return $report;
}

function lead_callback_v2_debug_render_html($input, array $sessionIds, array $reports)
{
    $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
    $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">';
    $html .= '<title>Lead Callback V2 Vox Debug</title>';
    $html .= '<style>';
    $html .= 'body{font-family:Arial,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;padding:24px;}';
    $html .= '.wrap{max-width:1200px;margin:0 auto;}';
    $html .= '.card{background:#fff;border:1px solid #dbe3f0;border-radius:10px;padding:16px 18px;margin-bottom:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);}';
    $html .= 'h1,h2,h3{margin:0 0 12px;}';
    $html .= 'textarea{width:100%;min-height:96px;font:14px/1.4 monospace;padding:10px;border:1px solid #c7d2e3;border-radius:8px;box-sizing:border-box;}';
    $html .= 'button{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 16px;font-weight:600;cursor:pointer;}';
    $html .= '.muted{color:#6b7280;font-size:14px;}';
    $html .= '.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700;margin-right:8px;}';
    $html .= '.ok{background:#dcfce7;color:#166534;}';
    $html .= '.bad{background:#fee2e2;color:#991b1b;}';
    $html .= '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;}';
    $html .= '.kv{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:10px;}';
    $html .= '.kv b{display:block;font-size:12px;color:#6b7280;margin-bottom:6px;}';
    $html .= 'pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e5e7eb;padding:14px;border-radius:8px;overflow:auto;font-size:12px;}';
    $html .= 'ul{margin:8px 0 0 18px;padding:0;}';
    $html .= '</style></head><body><div class="wrap">';

    $html .= '<div class="card">';
    $html .= '<h1>Lead Callback V2: Vox Debug</h1>';
    $html .= '<p class="muted">Вставь один или несколько <code>vox_session_id</code>. Можно по одному в строке, через пробел или запятую.</p>';
    $html .= '<form method="get">';
    $html .= '<textarea name="vox_session_id">' . htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</textarea>';
    $html .= '<div style="margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
    $html .= '<button type="submit">Проверить</button>';
    $html .= '<a href="?format=json&amp;vox_session_id=' . rawurlencode($input) . '">JSON</a>';
    $html .= '</div></form></div>';

    if (empty($sessionIds)) {
        $html .= '<div class="card"><span class="badge bad">Нет ID</span>Не удалось распознать ни одного <code>vox_session_id</code>.</div>';
        $html .= '</div></body></html>';
        return $html;
    }

    foreach ($reports as $report) {
        $analysis = is_array($report['call_leg_analysis'] ?? null) ? $report['call_leg_analysis'] : [];
        $summary = is_array($report['vox_history_summary'] ?? null) ? $report['vox_history_summary'] : [];
        $statusLabel = !empty($analysis['scenario_final_status']) ? $analysis['scenario_final_status'] : ((string)($summary['resolved_status'] ?? 'unknown'));
        $isOk = (bool)($report['ok'] ?? false);

        $html .= '<div class="card">';
        $html .= '<h2>Сессия ' . htmlspecialchars((string)$report['vox_session_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
        $html .= '<div style="margin-bottom:12px;">';
        $html .= '<span class="badge ' . ($isOk ? 'ok' : 'bad') . '">' . ($isOk ? 'OK' : 'Есть ошибки') . '</span>';
        $html .= '<span class="badge ' . ($statusLabel === 'connected' ? 'ok' : 'bad') . '">' . htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        $html .= '</div>';

        $html .= '<div class="grid">';
        $html .= lead_callback_v2_debug_render_kv('Выбранный оператор', (string)($analysis['selected_operator_id_from_payload'] ?? ''));
        $html .= lead_callback_v2_debug_render_kv('Тип маршрута', (string)($analysis['operator_destination_type'] ?? ''));
        $html .= lead_callback_v2_debug_render_kv('Оператор ответил', lead_callback_v2_debug_bool_text($analysis['operator_answered'] ?? null));
        $html .= lead_callback_v2_debug_render_kv('Клиент ответил', lead_callback_v2_debug_bool_text($analysis['client_answered'] ?? null));
        $html .= lead_callback_v2_debug_render_kv('Автоответчик', lead_callback_v2_debug_bool_text($analysis['client_voicemail'] ?? null));
        $html .= lead_callback_v2_debug_render_kv('Занято', lead_callback_v2_debug_bool_text($analysis['client_busy'] ?? null));
        $html .= lead_callback_v2_debug_render_kv('Длительность', (string)($summary['duration'] ?? ''));
        $html .= lead_callback_v2_debug_render_kv('Finish reason', (string)($summary['finish_reason'] ?? ''));
        $html .= '</div>';

        if (!empty($report['errors'])) {
            $html .= '<h3 style="margin-top:16px;">Ошибки</h3><ul>';
            foreach ($report['errors'] as $error) {
                $html .= '<li>' . htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
        }

        $scenarioLog = is_array($report['scenario_log'] ?? null) ? $report['scenario_log'] : [];
        $relevantLines = is_array($scenarioLog['relevant_lines'] ?? null) ? $scenarioLog['relevant_lines'] : [];
        if (!empty($relevantLines)) {
            $html .= '<h3 style="margin-top:16px;">Ключевые строки лога сценария</h3>';
            $html .= '<pre>' . htmlspecialchars(implode("\n", $relevantLines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        }

        $html .= '<h3 style="margin-top:16px;">Полный JSON</h3>';
        $html .= '<pre>' . htmlspecialchars(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
        $html .= '</div>';
    }

    $html .= '</div></body></html>';
    return $html;
}

function lead_callback_v2_debug_render_kv($label, $value)
{
    return '<div class="kv"><b>' . htmlspecialchars((string)$label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</b>'
        . htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
}

function lead_callback_v2_debug_bool_text($value)
{
    if ($value === null || $value === '') {
        return 'unknown';
    }
    return $value ? 'yes' : 'no';
}

function lead_callback_v2_debug_resolve_config_value(array $config, $configKey, array $envKeys)
{
    $value = trim((string)($config[$configKey] ?? ''));
    if ($value !== '') {
        return $value;
    }

    foreach ($envKeys as $envKey) {
        $envValue = getenv($envKey);
        if (is_string($envValue) && trim($envValue) !== '') {
            return trim($envValue);
        }
    }

    return '';
}

function lead_callback_v2_debug_get_vox_history($accountId, $apiKey, $sessionId)
{
    $payload = http_build_query([
        'account_id' => $accountId,
        'api_key' => $apiKey,
        'call_session_history_id' => $sessionId,
    ]);

    $result = [
        'endpoint' => 'https://api.voximplant.com/platform_api/GetCallHistory/',
        'request' => [
            'account_id' => $accountId,
            'call_session_history_id' => $sessionId,
        ],
        'http_code' => 0,
        'curl_error' => '',
        'decoded' => null,
        'matched_result' => null,
        'error' => '',
    ];

    if (!function_exists('curl_init')) {
        $result['error'] = 'curl_init is not available';
        return $result;
    }

    $ch = curl_init($result['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['curl_error'] = curl_error($ch);
    curl_close($ch);

    if (!is_string($response) || $response === '') {
        $result['error'] = $result['curl_error'] !== '' ? $result['curl_error'] : 'Empty response';
        return $result;
    }

    $decoded = json_decode($response, true);
    $result['decoded'] = $decoded;
    if (!is_array($decoded)) {
        $result['error'] = 'Invalid JSON from Vox';
        return $result;
    }

    if (!empty($decoded['error'])) {
        $result['error'] = is_array($decoded['error'])
            ? (string)($decoded['error']['msg'] ?? 'unknown')
            : (string)$decoded['error'];
        return $result;
    }

    if (!empty($decoded['result'][0]) && is_array($decoded['result'][0])) {
        $result['matched_result'] = $decoded['result'][0];
    } elseif (!empty($decoded['result']) && is_array($decoded['result'])) {
        foreach ($decoded['result'] as $row) {
            if (is_array($row) && (string)($row['history_id'] ?? '') === (string)$sessionId) {
                $result['matched_result'] = $row;
                break;
            }
        }
    }

    if ($result['matched_result'] === null) {
        $result['error'] = 'No matching result found in Vox response';
    }

    return $result;
}

function lead_callback_v2_debug_summarize_vox_history(array $history)
{
    $duration = (int)($history['duration'] ?? 0);
    $finishReason = trim((string)($history['finish_reason'] ?? ''));
    $resourceUsage = is_array($history['other_resource_usage'] ?? null) ? $history['other_resource_usage'] : [];
    $voicemailDetected = false;

    foreach ($resourceUsage as $usage) {
        if (!is_array($usage)) {
            continue;
        }
        $type = strtoupper(trim((string)($usage['resource_type'] ?? '')));
        $desc = strtoupper(trim((string)($usage['description'] ?? '')));
        if ($type === 'VOICEMAILDETECTION' || $desc === 'VOICEMAIL') {
            $voicemailDetected = true;
            break;
        }
    }

    $finishReasonLc = function_exists('mb_strtolower') ? mb_strtolower($finishReason) : strtolower($finishReason);
    $resolvedStatus = 'operator_no_answer';
    $resolvedCode = '603';

    if ($voicemailDetected) {
        $resolvedStatus = 'client_no_answer';
        $resolvedCode = '304';
    } elseif ($duration > 0) {
        $resolvedStatus = 'connected';
        $resolvedCode = '200';
    } elseif (strpos($finishReasonLc, 'busy') !== false || strpos($finishReasonLc, '486') !== false) {
        $resolvedStatus = 'client_busy';
        $resolvedCode = '486';
    } elseif (
        strpos($finishReasonLc, 'operator') !== false
        && (strpos($finishReasonLc, 'no answer') !== false || strpos($finishReasonLc, 'unanswered') !== false)
    ) {
        $resolvedStatus = 'operator_no_answer';
        $resolvedCode = '603';
    } elseif (
        strpos($finishReasonLc, 'no answer') !== false
        || strpos($finishReasonLc, 'no_answer') !== false
        || strpos($finishReasonLc, 'not answered') !== false
        || strpos($finishReasonLc, 'unanswered') !== false
        || strpos($finishReasonLc, 'timeout') !== false
    ) {
        $resolvedStatus = 'client_no_answer';
        $resolvedCode = '304';
    } elseif (
        strpos($finishReasonLc, 'cancel') !== false
        || strpos($finishReasonLc, 'fail') !== false
        || strpos($finishReasonLc, 'reject') !== false
    ) {
        $resolvedStatus = 'cancelled';
        $resolvedCode = '402';
    }

    return [
        'history_id' => (string)($history['history_id'] ?? ''),
        'duration' => $duration,
        'finish_reason' => $finishReason,
        'rule_name' => (string)($history['rule_name'] ?? ''),
        'application_name' => (string)($history['application_name'] ?? ''),
        'record_url' => (string)($history['record_url'] ?? ($history['recording_url'] ?? '')),
        'operator_id_from_vox' => (int)preg_replace('/\D+/', '', (string)($history['user_id'] ?? ($history['portal_user_id'] ?? '0'))),
        'voicemail_detected' => $voicemailDetected,
        'resolved_status' => $resolvedStatus,
        'resolved_code' => $resolvedCode,
    ];
}

function lead_callback_v2_debug_get_statistic_row($callId)
{
    if (!class_exists('\Bitrix\Main\Loader') || !\Bitrix\Main\Loader::includeModule('voximplant')) {
        return ['error' => 'voximplant module not loaded'];
    }

    $tableClass = null;
    if (class_exists('\Bitrix\Voximplant\StatisticTable')) {
        $tableClass = '\Bitrix\Voximplant\StatisticTable';
    } elseif (class_exists('\Bitrix\Voximplant\Model\StatisticTable')) {
        $tableClass = '\Bitrix\Voximplant\Model\StatisticTable';
    }

    if ($tableClass === null) {
        return ['error' => 'StatisticTable class not found'];
    }

    try {
        $res = $tableClass::getList([
            'filter' => ['=CALL_ID' => $callId],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ]);
        $row = $res->fetch();
        if (!is_array($row)) {
            return ['error' => 'No StatisticTable row found for CALL_ID=' . $callId];
        }

        return $row;
    } catch (\Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function lead_callback_v2_debug_decode_custom_data(array $history)
{
    $raw = (string)($history['custom_data'] ?? '');
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['raw' => $raw];
}

function lead_callback_v2_debug_fetch_scenario_log(array $history)
{
    $url = trim((string)($history['log_file_url'] ?? ''));
    $result = [
        'url' => $url,
        'http_code' => 0,
        'curl_error' => '',
        'text' => '',
        'relevant_lines' => [],
        'error' => '',
    ];

    if ($url === '') {
        $result['error'] = 'log_file_url is empty';
        return $result;
    }

    if (!function_exists('curl_init')) {
        $result['error'] = 'curl_init is not available';
        return $result;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $result['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['curl_error'] = curl_error($ch);
    curl_close($ch);

    if (!is_string($response) || $response === '') {
        $result['error'] = $result['curl_error'] !== '' ? $result['curl_error'] : 'Empty log response';
        return $result;
    }

    $result['text'] = $response;
    $lines = preg_split('/\r\n|\r|\n/', $response) ?: [];
    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }
        if (
            strpos($trimmed, 'callback ') !== false
            || strpos($trimmed, 'result=') !== false
            || strpos($trimmed, 'operator answered') !== false
            || strpos($trimmed, 'client answered') !== false
        ) {
            $result['relevant_lines'][] = $trimmed;
        }
    }

    return $result;
}

function lead_callback_v2_debug_analyze_scenario_log($scenarioLog, $historySummary, $customData)
{
    $relevantLines = [];
    if (is_array($scenarioLog)) {
        if (isset($scenarioLog['relevant_lines']) && is_array($scenarioLog['relevant_lines'])) {
            $relevantLines = $scenarioLog['relevant_lines'];
        }
        if (empty($relevantLines) && !empty($scenarioLog['text']) && is_string($scenarioLog['text'])) {
            $relevantLines = preg_split('/\r\n|\r|\n/', $scenarioLog['text']) ?: [];
        }
    }
    $joined = implode("\n", $relevantLines);

    $finalStatus = '';
    $finalReason = '';
    if (preg_match_all('/callback result=([a-z_]+) reason=([^\r\n]+)/u', $joined, $matches, PREG_SET_ORDER)) {
        $last = end($matches);
        if (is_array($last)) {
            $finalStatus = (string)($last[1] ?? '');
            $finalReason = trim((string)($last[2] ?? ''));
        }
    }

    $operatorAnswered = strpos($joined, 'callback operator answered') !== false;
    $clientAnswered = strpos($joined, 'callback client answered, bridge start') !== false;
    $voicemailDetected = is_array($historySummary) ? !empty($historySummary['voicemail_detected']) : false;
    $resolvedStatus = is_array($historySummary) ? (string)($historySummary['resolved_status'] ?? '') : '';
    $selectedOperatorId = is_array($customData) ? (int)preg_replace('/\D+/', '', (string)($customData['operator_user_id'] ?? '0')) : 0;
    $missingRequiredFields = strpos($joined, 'Missing required fields for StartScenarios') !== false;
    $scenarioStarted = strpos($joined, 'customData received, starting scenario') !== false;

    return [
        'selected_operator_id_from_payload' => $selectedOperatorId,
        'operator_destination_type' => is_array($customData) ? (string)($customData['operator_destination_type'] ?? '') : '',
        'operator_destination' => is_array($customData) ? ($customData['operator_destination'] ?? '') : '',
        'scenario_started' => $scenarioStarted,
        'missing_required_fields' => $missingRequiredFields,
        'operator_answered' => $operatorAnswered,
        'client_answered' => $clientAnswered,
        'client_voicemail' => $voicemailDetected,
        'client_busy' => $finalStatus === 'client_busy' || $resolvedStatus === 'client_busy',
        'client_no_answer' => $finalStatus === 'client_no_answer' || $resolvedStatus === 'client_no_answer',
        'operator_no_answer' => $finalStatus === 'operator_no_answer' || $resolvedStatus === 'operator_no_answer',
        'cancelled' => $finalStatus === 'cancelled' || $resolvedStatus === 'cancelled',
        'connected' => !$missingRequiredFields && ($finalStatus === 'connected' || ($resolvedStatus === 'connected' && $operatorAnswered && $clientAnswered)),
        'scenario_final_status' => $finalStatus,
        'scenario_final_reason' => $finalReason,
        'relevant_lines_count' => count($relevantLines),
    ];
}
