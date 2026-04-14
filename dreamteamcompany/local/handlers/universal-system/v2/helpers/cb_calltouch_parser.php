<?php
/**
 * CallTouch parser for BP/helper usage.
 */

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function cb_calltouch_blank_vars(): array
{
    $keys = [
        'CB_UTM_SOURCE', 'CB_UTM_MEDIUM', 'CB_UTM_CAMPAIGN', 'CB_UTM_CONTENT', 'CB_UTM_TERM',
        'CB_TITLE', 'CB_LEAD_TITLE', 'CB_PHONE', 'CB_NAME', 'CB_LAST_NAME', 'CB_SECOND_NAME',
        'CB_DOMAIN', 'CB_COMMENT', 'CB_SOURCE_DESCRIPTION', 'CB_OPENED', 'CB_STATUS_ID',
        'CB_RESULT', 'CB_ERROR_MSG',
        'CB_ASSIGNED_BY_ID', 'CB_ASSIGNED_BY_EMAIL', 'CB_ASSIGNED_BY_PERSONAL_MOBILE', 'CB_ASSIGNED_BY_WORK_PHONE',
        'CB_SOURCE_ID', 'CB_TRACKING_SOURCE_ID', 'CB_OBSERVER_IDS', 'CB_CITY_ID', 'CB_ISPOLNITEL', 'CB_INFOPOVOD',
        'CB_SITE_ID', 'CB_SUB_POOL_NAME',
    ];

    return array_fill_keys($keys, '');
}

function cb_calltouch_clean_string($value): string
{
    $value = trim((string)$value);
    return strcasecmp($value, 'null') === 0 ? '' : $value;
}

function cb_calltouch_get_domain_url(string $fullUrl): string
{
    if ($fullUrl === '') {
        return '';
    }
    $parts = parse_url($fullUrl);
    if (!empty($parts['host'])) {
        return (string)$parts['host'];
    }
    return $fullUrl;
}

function cb_calltouch_fix_call_url_and_url(array &$data): void
{
    $subPoolName = cb_calltouch_clean_string($data['subPoolName'] ?? '');
    if (($data['callUrl'] ?? '') === '' && $subPoolName !== '') {
        $data['callUrl'] = $subPoolName;
    }
    if (($data['url'] ?? '') === '' && $subPoolName !== '') {
        $data['url'] = $subPoolName;
    }
}

function cb_calltouch_normalize_phone(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw);
    $cleaned = preg_replace('/[^\d+]/', '', $raw);
    if (preg_match('/^\+7\d{10}$/', $cleaned)) {
        return $cleaned;
    }
    if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
        return '+7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        return '+7' . $digits;
    }
    return '';
}

function cb_calltouch_name_key(array $data): string
{
    $nameKey = cb_calltouch_clean_string($data['hostname'] ?? '');
    if ($nameKey !== '') {
        return $nameKey;
    }

    $url = cb_calltouch_clean_string($data['url'] ?? '');
    if ($url !== '') {
        return cb_calltouch_get_domain_url($url);
    }

    $callUrl = cb_calltouch_clean_string($data['callUrl'] ?? '');
    if ($callUrl !== '') {
        return cb_calltouch_get_domain_url($callUrl);
    }

    $siteName = cb_calltouch_clean_string($data['siteName'] ?? '');
    if ($siteName !== '') {
        return $siteName;
    }

    return cb_calltouch_clean_string($data['subPoolName'] ?? '');
}

function cb_calltouch_is_payload(array $data): bool
{
    if (cb_calltouch_clean_string($data['siteId'] ?? '') === '') {
        return false;
    }

    foreach (['callerphone', 'subPoolName', 'ctCallerId', 'callphase', 'leadtype', 'hostname', 'siteName', 'url', 'callUrl'] as $key) {
        if (cb_calltouch_clean_string($data[$key] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function cb_calltouch_extract_payload(string $rawJson): ?array
{
    $rawJson = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
    if ($rawJson === '') {
        return null;
    }

    $jsonFlags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    if (defined('JSON_BIGINT_AS_STRING')) {
        $jsonFlags |= JSON_BIGINT_AS_STRING;
    }

    $data = json_decode($rawJson, true, 512, $jsonFlags);
    if (!is_array($data)) {
        return null;
    }

    $payload = isset($data['parsed_data']) && is_array($data['parsed_data']) ? $data['parsed_data'] : $data;
    $rawHeaders = isset($data['raw_headers']) && is_array($data['raw_headers']) ? $data['raw_headers'] : [];
    $rawBody = (string)($data['raw_body'] ?? '');
    $contentType = (string)($rawHeaders['CONTENT_TYPE'] ?? '');

    if ($rawBody !== '') {
        $looksLikeQueryBody = preg_match('/[^=&]+=/', $rawBody) === 1;
        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false || $looksLikeQueryBody) {
            $bodyFields = [];
            parse_str($rawBody, $bodyFields);
            foreach ($bodyFields as $k => $v) {
                if (!isset($payload[$k]) || $payload[$k] === '') {
                    $payload[$k] = $v;
                }
            }
        } elseif (($rawBody[0] ?? '') === '{') {
            $bodyJson = json_decode($rawBody, true, 512, $jsonFlags);
            if (is_array($bodyJson)) {
                foreach ($bodyJson as $k => $v) {
                    if (!isset($payload[$k]) || $payload[$k] === '') {
                        $payload[$k] = $v;
                    }
                }
            }
        }
    }

    cb_calltouch_fix_call_url_and_url($payload);
    return cb_calltouch_is_payload($payload) ? $payload : null;
}

/**
 * @return array{
 *   result: 'parsed'|'not_parsed'|'error',
 *   error_msg: string,
 *   vars: array<string, string>,
 *   log_line?: string
 * }
 */
function cb_parse_calltouch_raw_json(string $rawJson): array
{
    $blank = cb_calltouch_blank_vars();

    try {
        $payload = cb_calltouch_extract_payload($rawJson);
        if ($payload === null) {
            $blank['CB_RESULT'] = 'not parsed';
            return ['result' => 'not_parsed', 'error_msg' => '', 'vars' => $blank];
        }

        $nameKey = cb_calltouch_name_key($payload);
        $siteId = cb_calltouch_clean_string($payload['siteId'] ?? '');
        $phone = cb_calltouch_normalize_phone((string)($payload['callerphone'] ?? $payload['phone'] ?? ''));
        $name = trim((string)($payload['name'] ?? $payload['Name'] ?? ''));
        $comment = trim((string)($payload['comment'] ?? $payload['COMMENTS'] ?? ''));

        $vars = $blank;
        $vars['CB_TITLE'] = 'calltouch';
        $vars['CB_LEAD_TITLE'] = '';
        $vars['CB_PHONE'] = $phone;
        $vars['CB_NAME'] = $name;
        $vars['CB_LAST_NAME'] = '';
        $vars['CB_SECOND_NAME'] = '';
        $vars['CB_DOMAIN'] = $nameKey;
        $vars['CB_COMMENT'] = $comment;
        $vars['CB_SOURCE_DESCRIPTION'] = 'CallTouch (siteId=' . $siteId . ')';
        $vars['CB_OPENED'] = 'Y';
        $vars['CB_STATUS_ID'] = 'NEW';
        $vars['CB_RESULT'] = 'parsed';
        $vars['CB_ERROR_MSG'] = '';
        $vars['CB_SITE_ID'] = $siteId;
        $vars['CB_SUB_POOL_NAME'] = cb_calltouch_clean_string($payload['subPoolName'] ?? '');

        foreach ($payload as $k => $v) {
            if (is_string($v) && stripos($k, 'utm_') === 0 && $v !== '') {
                $vars['CB_' . strtoupper($k)] = $v;
            }
        }

        return [
            'result' => 'parsed',
            'error_msg' => '',
            'vars' => $vars,
            'log_line' => '[calltouch] parsed | nameKey=' . $nameKey . ' | siteId=' . $siteId . ' | phone=' . $phone,
        ];
    } catch (Throwable $e) {
        $blank['CB_RESULT'] = 'not parsed';
        $blank['CB_ERROR_MSG'] = 'Parse error: ' . $e->getMessage();
        return ['result' => 'error', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && basename((string)$_SERVER['argv'][0]) === basename(__FILE__)) {
    $path = $_SERVER['argv'][1] ?? '';
    $raw = ($path !== '' && is_readable($path)) ? (string)file_get_contents($path) : (string)stream_get_contents(STDIN);
    fwrite(STDOUT, json_encode(cb_parse_calltouch_raw_json($raw), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
