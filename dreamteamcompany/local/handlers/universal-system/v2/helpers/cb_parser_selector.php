<?php
/**
 * Выбор парсера для БП по техническим признакам payload.
 *
 * Возвращает:
 * - `CB_PARSER_NAME`: `universal` или `narrow`
 * - `CB_PARSER_REASON`: краткая техническая причина выбора
 *
 * Пример PHP-блока БП:
 *
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/local/handlers/universal-system/v2/helpers/cb_parser_selector.php';
 *   $out = cb_detect_parser_for_bp((string)$this->GetVariable('CB_RAW_JSON'));
 *   $root = $this->GetRootActivity();
 *   if (method_exists($root, 'SetVariable')) {
 *       $root->SetVariable('CB_PARSER_NAME', $out['vars']['CB_PARSER_NAME']);
 *       $root->SetVariable('CB_PARSER_REASON', $out['vars']['CB_PARSER_REASON']);
 *   }
 *   $this->WriteToTrackingService('[select] parser=' . $out['parser'] . ' reason=' . $out['reason']);
 */

declare(strict_types=1);

/**
 * @return array{
 *     parser: 'universal'|'narrow',
 *     reason: string,
 *     vars: array<string, string>
 * }
 */
function cb_detect_parser_for_bp(string $rawJson): array
{
    $rawJson = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));

    if ($rawJson === '') {
        return cb_parser_selector_result('narrow', 'empty_raw_json');
    }

    $jsonFlags = defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
    if (defined('JSON_BIGINT_AS_STRING')) {
        $jsonFlags |= JSON_BIGINT_AS_STRING;
    }

    $data = json_decode($rawJson, true, 512, $jsonFlags);
    if (!is_array($data)) {
        return cb_parser_selector_result('narrow', 'invalid_json');
    }

    $bitrix24SourceDescription = cb_parser_selector_extract_bitrix24_source_description($data);
    if ($bitrix24SourceDescription !== '') {
        return cb_parser_selector_result('bitrix24_source_description', 'query_source_description');
    }

    $bushuevaSiteKey = cb_parser_selector_extract_bushueva_site_key($data);
    if ($bushuevaSiteKey !== '') {
        return cb_parser_selector_result('bushueva_supplier', 'assigned_by_prefix_bushueva');
    }

    if (cb_parser_selector_is_calltouch($data)) {
        return cb_parser_selector_result('calltouch', 'calltouch_signature');
    }

    if (array_key_exists('parsed_data', $data)) {
        return cb_parser_selector_result('universal', 'has_parsed_data');
    }

    if (array_key_exists('raw_headers', $data) && is_array($data['raw_headers'])) {
        return cb_parser_selector_result('universal', 'has_raw_headers');
    }

    if (array_key_exists('raw_body', $data) && is_string($data['raw_body'])) {
        return cb_parser_selector_result('universal', 'has_raw_body');
    }

    if (array_key_exists('source_domain', $data) && is_string($data['source_domain']) && $data['source_domain'] !== '') {
        return cb_parser_selector_result('universal', 'has_source_domain');
    }

    if (cb_parser_selector_recognized_envelope($data)) {
        return cb_parser_selector_result('universal', 'recognized_webhook_shape');
    }

    return cb_parser_selector_result('narrow', 'no_standard_envelope');
}

/**
 * @return array{
 *     parser: 'universal'|'narrow',
 *     reason: string,
 *     vars: array<string, string>
 * }
 */
function cb_parser_selector_result(string $parser, string $reason): array
{
    return [
        'parser' => $parser,
        'reason' => $reason,
        'vars' => [
            'CB_PARSER_NAME' => $parser,
            'CB_PARSER_REASON' => $reason,
        ],
    ];
}

function cb_parser_selector_recognized_envelope(array $data): bool
{
    if (array_key_exists('parsed_data', $data)) {
        return true;
    }

    if (!empty($data['raw_body']) && is_string($data['raw_body'])) {
        return true;
    }

    if (!empty($data['raw_headers']) && is_array($data['raw_headers'])) {
        return true;
    }

    if (!empty($data['source_domain']) && is_string($data['source_domain'])) {
        return true;
    }

    foreach (['Phone', 'phone', 'PHONE', 'callerphone', 'utm_source'] as $key) {
        if (!empty($data[$key])) {
            return true;
        }
    }

    if (isset($data['contacts']) && is_array($data['contacts']) && $data['contacts'] !== []) {
        return true;
    }

    return false;
}

function cb_parser_selector_extract_bushueva_site_key(array $data): string
{
    $parsedData = isset($data['parsed_data']) && is_array($data['parsed_data']) ? $data['parsed_data'] : $data;
    $rawHeaders = isset($data['raw_headers']) && is_array($data['raw_headers']) ? $data['raw_headers'] : [];
    $rawBody = (string)($data['raw_body'] ?? '');

    $assignedById = trim((string)($parsedData['ASSIGNED_BY_ID'] ?? $parsedData['assigned_by_id'] ?? ''));

    if ($assignedById === '' && !empty($rawHeaders['QUERY_STRING'])) {
        $qs = [];
        parse_str((string)$rawHeaders['QUERY_STRING'], $qs);
        $assignedById = trim((string)($qs['ASSIGNED_BY_ID'] ?? ''));
    }

    if ($assignedById === '' && $rawBody !== '' && preg_match('/[^=&]+=/', $rawBody)) {
        $bodyFields = [];
        parse_str($rawBody, $bodyFields);
        $assignedById = trim((string)($bodyFields['ASSIGNED_BY_ID'] ?? ''));
    }

    if ($assignedById === '' || mb_strpos($assignedById, 'Заявка от Bushueva') !== 0) {
        return '';
    }

    $parts = explode('_', $assignedById, 2);
    return trim((string)$parts[0]);
}

function cb_parser_selector_extract_bitrix24_source_description(array $data): string
{
    $rawHeaders = isset($data['raw_headers']) && is_array($data['raw_headers']) ? $data['raw_headers'] : [];
    $queryString = (string)($rawHeaders['QUERY_STRING'] ?? '');
    if ($queryString === '') {
        return '';
    }

    $decoded = urldecode($queryString);
    if (preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', $decoded, $m)) {
        return trim((string)urldecode($m[1]));
    }

    return '';
}

function cb_parser_selector_is_calltouch(array $data): bool
{
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
        }
    }

    $siteId = trim((string)($payload['siteId'] ?? ''));
    if ($siteId === '' || strcasecmp($siteId, 'null') === 0) {
        return false;
    }

    foreach (['callerphone', 'subPoolName', 'ctCallerId', 'callphase', 'leadtype', 'hostname', 'siteName', 'url', 'callUrl'] as $key) {
        $value = trim((string)($payload[$key] ?? ''));
        if ($value !== '' && strcasecmp($value, 'null') !== 0) {
            return true;
        }
    }

    return false;
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && basename((string) $_SERVER['argv'][0]) === basename(__FILE__)) {
    $path = $_SERVER['argv'][1] ?? '';
    $raw = ($path !== '' && is_readable($path)) ? (string) file_get_contents($path) : (string) stream_get_contents(STDIN);
    fwrite(STDOUT, json_encode(cb_detect_parser_for_bp($raw), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
