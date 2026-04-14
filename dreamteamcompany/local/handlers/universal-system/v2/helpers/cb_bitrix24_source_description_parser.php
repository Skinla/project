<?php
/**
 * Узкий обработчик для Bitrix24 payload, где ключ маршрутизации — fields[SOURCE_DESCRIPTION].
 */

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function cb_bitrix24_sd_blank_vars(): array
{
    $keys = [
        'CB_UTM_SOURCE', 'CB_UTM_MEDIUM', 'CB_UTM_CAMPAIGN', 'CB_UTM_CONTENT', 'CB_UTM_TERM',
        'CB_TITLE', 'CB_LEAD_TITLE', 'CB_PHONE', 'CB_NAME', 'CB_LAST_NAME', 'CB_SECOND_NAME',
        'CB_DOMAIN', 'CB_COMMENT', 'CB_SOURCE_DESCRIPTION', 'CB_OPENED', 'CB_STATUS_ID',
        'CB_RESULT', 'CB_ERROR_MSG',
        'CB_ASSIGNED_BY_ID', 'CB_ASSIGNED_BY_EMAIL', 'CB_ASSIGNED_BY_PERSONAL_MOBILE', 'CB_ASSIGNED_BY_WORK_PHONE',
        'CB_SOURCE_ID', 'CB_TRACKING_SOURCE_ID', 'CB_OBSERVER_IDS', 'CB_CITY_ID', 'CB_ISPOLNITEL', 'CB_INFOPOVOD',
    ];

    return array_fill_keys($keys, '');
}

/**
 * @return array<string, string>
 */
function cb_bitrix24_sd_parse_fields(string $queryString): array
{
    $fields = [];
    if ($queryString === '') {
        return $fields;
    }

    $decoded = urldecode($queryString);

    if (preg_match_all('/fields\[([^=]+)\]=([^&]*)/', $decoded, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $fieldName = $match[1];
            $fieldValue = trim(urldecode($match[2]));

            if ($fieldName === 'PHONE[0][VALUE]' || $fieldName === 'PHONE][0][VALUE') {
                $fields['PHONE'] = $fieldValue;
            } elseif ($fieldName === 'PHONE[0][VALUE_TYPE]' || $fieldName === 'PHONE][0][VALUE_TYPE') {
                $fields['PHONE_TYPE'] = $fieldValue;
            } else {
                $fields[$fieldName] = $fieldValue;
            }
        }
    }

    return $fields;
}

function cb_bitrix24_sd_normalize_phone(string $phone): string
{
    $cleanPhone = preg_replace('/\D+/', '', $phone);
    if ($cleanPhone === '') {
        return '';
    }
    if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
        $cleanPhone = '7' . substr($cleanPhone, 1);
    }
    if (strlen($cleanPhone) === 10) {
        $cleanPhone = '7' . $cleanPhone;
    }
    if (strlen($cleanPhone) >= 11 && $cleanPhone[0] === '7') {
        return '+' . $cleanPhone;
    }
    return '+' . $cleanPhone;
}

/**
 * @return array{
 *   result: 'parsed'|'not_parsed'|'error',
 *   error_msg: string,
 *   vars: array<string, string>,
 *   log_line?: string
 * }
 */
function cb_parse_bitrix24_source_description_raw_json(string $rawJson): array
{
    $blank = cb_bitrix24_sd_blank_vars();

    try {
        $rawJson = trim((string)preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
        if ($rawJson === '') {
            $blank['CB_RESULT'] = 'not parsed';
            $blank['CB_ERROR_MSG'] = 'RAW_DATA пуст';
            return ['result' => 'error', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
        }

        $jsonFlags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
        if (defined('JSON_BIGINT_AS_STRING')) {
            $jsonFlags |= JSON_BIGINT_AS_STRING;
        }

        $data = json_decode($rawJson, true, 512, $jsonFlags);
        if (!is_array($data)) {
            $blank['CB_RESULT'] = 'not parsed';
            $blank['CB_ERROR_MSG'] = 'Невалидный JSON в RAW_DATA: ' . json_last_error_msg();
            return ['result' => 'error', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
        }

        $rawHeaders = isset($data['raw_headers']) && is_array($data['raw_headers']) ? $data['raw_headers'] : [];
        $queryString = (string)($rawHeaders['QUERY_STRING'] ?? '');
        $fields = cb_bitrix24_sd_parse_fields($queryString);

        if (empty($fields['SOURCE_DESCRIPTION'])) {
            $blank['CB_RESULT'] = 'not parsed';
            return ['result' => 'not_parsed', 'error_msg' => '', 'vars' => $blank];
        }

        $domain = trim((string)$fields['SOURCE_DESCRIPTION']);
        $name = trim((string)($fields['NAME'] ?? ''));
        $phone = cb_bitrix24_sd_normalize_phone((string)($fields['PHONE'] ?? ''));
        $comment = trim((string)($fields['COMMENTS'] ?? ''));
        $leadTitle = trim((string)($fields['TITLE'] ?? ''));
        if ($leadTitle === '') {
            $leadTitle = 'Лид с сайта [' . $domain . ']';
        }

        $vars = $blank;
        $vars['CB_TITLE'] = 'web';
        $vars['CB_LEAD_TITLE'] = $leadTitle;
        $vars['CB_PHONE'] = $phone;
        $vars['CB_NAME'] = $name;
        $vars['CB_LAST_NAME'] = '';
        $vars['CB_SECOND_NAME'] = '';
        $vars['CB_DOMAIN'] = $domain;
        $vars['CB_COMMENT'] = $comment;
        $vars['CB_SOURCE_DESCRIPTION'] = 'Сайт: ' . $domain;
        $vars['CB_OPENED'] = 'Y';
        $vars['CB_STATUS_ID'] = 'NEW';
        $vars['CB_RESULT'] = 'parsed';
        $vars['CB_ERROR_MSG'] = '';

        return [
            'result' => 'parsed',
            'error_msg' => '',
            'vars' => $vars,
            'log_line' => '[bitrix24_sd] parsed | source=' . $domain . ' | phone=' . $phone . ' | name=' . $name,
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
    fwrite(STDOUT, json_encode(cb_parse_bitrix24_source_description_raw_json($raw), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
