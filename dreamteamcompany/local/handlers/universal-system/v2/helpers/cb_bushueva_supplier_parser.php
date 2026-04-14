<?php
/**
 * Узкий обработчик для поставщика Bushueva.
 *
 * Технический признак:
 * - в payload есть `ASSIGNED_BY_ID`
 * - значение начинается с `Заявка от Bushueva`
 *
 * Идентификатор сайта берется из ASSIGNED_BY_ID только до первого `_`:
 * `Заявка от Bushueva_Новокузнецк` -> `Заявка от Bushueva`
 */

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function cb_bushueva_blank_vars(): array
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

function cb_extract_bushueva_site_key(string $assignedById): string
{
    $assignedById = trim($assignedById);
    if ($assignedById === '' || mb_strpos($assignedById, 'Заявка от Bushueva') !== 0) {
        return '';
    }

    $parts = explode('_', $assignedById, 2);
    return trim((string) $parts[0]);
}

/**
 * @return array{
 *     parsedData: array<string, mixed>,
 *     rawHeaders: array<string, mixed>,
 *     rawBody: string,
 *     assignedById: string,
 *     siteKey: string
 * }|null
 */
function cb_extract_bushueva_payload(string $rawJson): ?array
{
    $rawJson = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
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

    $rawHeaders = isset($data['raw_headers']) && is_array($data['raw_headers']) ? $data['raw_headers'] : [];
    $rawBody = (string)($data['raw_body'] ?? '');

    if (isset($data['parsed_data']) && is_array($data['parsed_data'])) {
        $parsedData = $data['parsed_data'];
    } else {
        $parsedData = $data;
    }

    $queryString = (string)($rawHeaders['QUERY_STRING'] ?? '');
    if ($queryString !== '') {
        $qs = [];
        parse_str($queryString, $qs);
        if (is_array($qs)) {
            foreach ($qs as $k => $v) {
                if (!isset($parsedData[$k]) || $parsedData[$k] === '') {
                    $parsedData[$k] = $v;
                }
            }
        }
    }

    if ($rawBody !== '' && preg_match('/[^=&]+=/', $rawBody)) {
        $bodyFields = [];
        parse_str($rawBody, $bodyFields);
        if (is_array($bodyFields)) {
            foreach ($bodyFields as $k => $v) {
                if (!isset($parsedData[$k]) || $parsedData[$k] === '') {
                    $parsedData[$k] = $v;
                }
            }
        }
    }

    $assignedById = trim((string)($parsedData['ASSIGNED_BY_ID'] ?? $parsedData['assigned_by_id'] ?? ''));
    $siteKey = cb_extract_bushueva_site_key($assignedById);
    if ($siteKey === '') {
        return null;
    }

    return [
        'parsedData' => $parsedData,
        'rawHeaders' => $rawHeaders,
        'rawBody' => $rawBody,
        'assignedById' => $assignedById,
        'siteKey' => $siteKey,
    ];
}

function cb_bushueva_normalize_phone(string $phone): string
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
 *     result: 'parsed'|'not_parsed'|'error',
 *     error_msg: string,
 *     vars: array<string, string>,
 *     log_line?: string
 * }
 */
function cb_parse_bushueva_supplier_raw_json(string $rawJson): array
{
    $blank = cb_bushueva_blank_vars();

    try {
        $payload = cb_extract_bushueva_payload($rawJson);
        if ($payload === null) {
            $blank['CB_RESULT'] = 'not parsed';
            return [
                'result' => 'not_parsed',
                'error_msg' => '',
                'vars' => $blank,
            ];
        }

        $parsedData = $payload['parsedData'];
        $assignedById = $payload['assignedById'];
        $siteKey = $payload['siteKey'];
        $searchKey = $assignedById;

        $phone = cb_bushueva_normalize_phone((string)($parsedData['PHONE'] ?? $parsedData['Phone'] ?? $parsedData['phone'] ?? ''));
        $name = trim((string)($parsedData['NAME'] ?? $parsedData['Name'] ?? $parsedData['name'] ?? ''));
        $comment = trim((string)($parsedData['comments'] ?? $parsedData['COMMENTS'] ?? ''));

        $vars = $blank;
        $vars['CB_TITLE'] = 'web';
        $vars['CB_LEAD_TITLE'] = 'Лид с сайта [' . $searchKey . ']';
        $vars['CB_PHONE'] = $phone;
        $vars['CB_NAME'] = $name;
        $vars['CB_DOMAIN'] = $searchKey;
        $vars['CB_COMMENT'] = $comment;
        $vars['CB_SOURCE_DESCRIPTION'] = 'Сайт: ' . $searchKey;
        $vars['CB_OPENED'] = 'Y';
        $vars['CB_STATUS_ID'] = 'NEW';
        $vars['CB_RESULT'] = 'parsed';
        $vars['CB_ERROR_MSG'] = '';

        // В этот payload ASSIGNED_BY_ID используется как ключ поиска в ИБ54, а не user ID.
        // Поэтому в CB_DOMAIN кладем полную строку, а в ответственного ничего не пишем.
        $logLine = '[bushueva] parsed | site=' . $siteKey . ' | search=' . $searchKey . ' | phone=' . $phone;

        return [
            'result' => 'parsed',
            'error_msg' => '',
            'vars' => $vars,
            'log_line' => $logLine,
        ];
    } catch (Throwable $e) {
        $blank['CB_RESULT'] = 'not parsed';
        $blank['CB_ERROR_MSG'] = 'Parse error: ' . $e->getMessage();

        return [
            'result' => 'error',
            'error_msg' => $blank['CB_ERROR_MSG'],
            'vars' => $blank,
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && basename((string)$_SERVER['argv'][0]) === basename(__FILE__)) {
    $path = $_SERVER['argv'][1] ?? '';
    $raw = ($path !== '' && is_readable($path)) ? (string) file_get_contents($path) : (string) stream_get_contents(STDIN);
    fwrite(STDOUT, json_encode(cb_parse_bushueva_supplier_raw_json($raw), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
