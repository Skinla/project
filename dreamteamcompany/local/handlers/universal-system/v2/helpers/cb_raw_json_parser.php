<?php
/**
 * Парсер сырого JSON вебхука (raw_body / raw_headers / parsed_data / source_domain).
 *
 * Использование по схеме БП:
 * - `cb_try_parse_universal_raw_webhook()` — первый PHP-блок: «наш конверт / не наш».
 * - если результат `not_parsed`, переходите штатным блоком БП на следующий обработчик.
 * - `cb_parse_raw_webhook_json()` — полный разбор во втором PHP-блоке.
 *
 * CLI: php cb_raw_json_parser.php file.json
 */

declare(strict_types=1);

/**
 * Пустые CB_* (чтобы в БП не оставались старые значения после «not parsed»).
 *
 * @return array<string, string>
 */
function cb_blank_webhook_cb_vars(): array
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
 * Узнаваемый универсальным парсером «конверт» вебхука (не путать с битым JSON).
 */
function cb_universal_raw_webhook_recognized(array $data): bool
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
    foreach (['Phone', 'phone', 'PHONE', 'callerphone', 'utm_source'] as $k) {
        if (!empty($data[$k])) {
            return true;
        }
    }
    if (isset($data['contacts']) && is_array($data['contacts']) && $data['contacts'] !== []) {
        return true;
    }

    return false;
}

/**
 * Шаг 1 (универсальный): не распознал формат — result not_parsed и CB_RESULT `not parsed`.
 *
 * @return array{
 *     result: 'parsed'|'not_parsed'|'error',
 *     error_msg: string,
 *     vars: array<string, string>,
 *     log_line?: string
 * }
 */
function cb_try_parse_universal_raw_webhook(string $rawJson): array
{
    $jsonFlags = JSON_INVALID_UTF8_SUBSTITUTE;
    if (defined('JSON_BIGINT_AS_STRING')) {
        $jsonFlags |= JSON_BIGINT_AS_STRING;
    }

    $rawJson = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
    if ($rawJson === '') {
        $vars = cb_blank_webhook_cb_vars();
        $vars['CB_RESULT'] = 'not parsed';
        $vars['CB_ERROR_MSG'] = 'RAW_DATA пуст';

        return ['result' => 'error', 'error_msg' => 'RAW_DATA пуст', 'vars' => $vars];
    }

    $data = json_decode($rawJson, true, 512, $jsonFlags);
    if (!is_array($data)) {
        $msg = 'Невалидный JSON в RAW_DATA: ' . json_last_error_msg();
        $vars = cb_blank_webhook_cb_vars();
        $vars['CB_RESULT'] = 'not parsed';
        $vars['CB_ERROR_MSG'] = $msg;

        return ['result' => 'error', 'error_msg' => $msg, 'vars' => $vars];
    }

    if (!cb_universal_raw_webhook_recognized($data)) {
        $vars = cb_blank_webhook_cb_vars();
        $vars['CB_RESULT'] = 'not parsed';
        $vars['CB_ERROR_MSG'] = '';

        return ['result' => 'not_parsed', 'error_msg' => '', 'vars' => $vars];
    }

    $out = cb_parse_raw_webhook_from_decoded_array($data, $jsonFlags);
    if (($out['result'] ?? '') === 'parsed') {
        $out['vars']['CB_RESULT'] = 'parsed';
    } elseif (($out['result'] ?? '') === 'error') {
        $out['vars']['CB_RESULT'] = 'not parsed';
    }

    return $out;
}

/**
 * Полный парс без проверки «универсальный конверт».
 *
 * @return array{
 *     result: 'parsed'|'error',
 *     error_msg: string,
 *     vars: array<string, string>,
 *     log_line?: string
 * }
 */
function cb_parse_raw_webhook_json(string $rawJson): array
{
    try {
        $rawJson = trim(preg_replace('/^\xEF\xBB\xBF/', '', $rawJson));
        if ($rawJson === '') {
            $vars = cb_blank_webhook_cb_vars();
            $vars['CB_RESULT'] = 'not parsed';
            $vars['CB_ERROR_MSG'] = 'RAW_DATA пуст';

            return ['result' => 'error', 'error_msg' => 'RAW_DATA пуст', 'vars' => $vars];
        }

        $jsonFlags = JSON_INVALID_UTF8_SUBSTITUTE;
        if (defined('JSON_BIGINT_AS_STRING')) {
            $jsonFlags |= JSON_BIGINT_AS_STRING;
        }

        $data = json_decode($rawJson, true, 512, $jsonFlags);
        if (!is_array($data)) {
            $msg = 'Невалидный JSON в RAW_DATA: ' . json_last_error_msg();
            $vars = cb_blank_webhook_cb_vars();
            $vars['CB_RESULT'] = 'not parsed';
            $vars['CB_ERROR_MSG'] = $msg;

            return ['result' => 'error', 'error_msg' => $msg, 'vars' => $vars];
        }

        return cb_parse_raw_webhook_from_decoded_array($data, $jsonFlags);
    } catch (Throwable $e) {
        $vars = cb_blank_webhook_cb_vars();
        $vars['CB_RESULT'] = 'not parsed';
        $vars['CB_ERROR_MSG'] = 'Parse error: ' . $e->getMessage();

        return [
            'result' => 'error',
            'error_msg' => $vars['CB_ERROR_MSG'],
            'vars' => $vars,
        ];
    }
}

/**
 * @return array{
 *     result: 'parsed'|'error',
 *     error_msg: string,
 *     vars: array<string, string>,
 *     log_line?: string
 * }
 */
function cb_parse_raw_webhook_from_decoded_array(array $data, int $jsonFlags): array
{
    $vars = [];
    $set = static function (string $name, string $value) use (&$vars): void {
        $vars[$name] = $value;
    };

    try {
        $rawHeaders = $data['raw_headers'] ?? [];
        if (!is_array($rawHeaders)) {
            $rawHeaders = [];
        }
        $rawBody = (string)($data['raw_body'] ?? '');
        $contentType = (string)($rawHeaders['CONTENT_TYPE'] ?? '');

        if (array_key_exists('parsed_data', $data)) {
            $pd = $data['parsed_data'];
            if (is_array($pd)) {
                $parsedData = $pd;
            } elseif (is_string($pd) && $pd !== '') {
                $parsedData = json_decode($pd, true, 512, $jsonFlags);
                $parsedData = is_array($parsedData) ? $parsedData : [];
            } else {
                $parsedData = [];
            }
        } else {
            $parsedData = $data;
        }

        $isFormUrlencoded = stripos($contentType, 'application/x-www-form-urlencoded') !== false;
        $looksLikeQueryBody = $rawBody !== '' && preg_match('/[^=&]+=/', $rawBody);
        if ($rawBody !== '' && ($isFormUrlencoded || ($contentType === '' && $looksLikeQueryBody))) {
            $reparsed = [];
            parse_str($rawBody, $reparsed);
            if (is_array($reparsed)) {
                foreach ($reparsed as $k => $v) {
                    $parsedData[$k] = $v;
                }
            }
        }

        if (is_array($parsedData)) {
            array_walk_recursive($parsedData, static function (&$value): void {
                if (is_string($value)) {
                    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            });
        } else {
            $parsedData = [];
        }

        $domain = (string)($data['source_domain'] ?? ($parsedData['source_domain'] ?? 'unknown'));

        $phone = '';
        foreach (['Phone', 'phone', 'PHONE'] as $f) {
            if (!empty($parsedData[$f])) {
                $phone = (string)$parsedData[$f];
                break;
            }
        }
        if ($phone === '' && !empty($parsedData['contacts']['phone'])) {
            $phone = (string)$parsedData['contacts']['phone'];
        }
        if ($phone === '' && !empty($parsedData['callerphone'])) {
            $phone = (string)$parsedData['callerphone'];
        }
        if ($phone === '' && !empty($rawHeaders['QUERY_STRING'])) {
            $qs = urldecode((string)$rawHeaders['QUERY_STRING']);
            if (preg_match('/fields\[PHONE\]\[0\]\[VALUE\]=([^&]+)/', $qs, $m)) {
                $phone = urldecode($m[1]);
            }
        }
        if ($phone === '') {
            foreach ($parsedData as $k => $v) {
                if (is_string($v) && $v !== '' && preg_match('/^phone/i', (string)$k)) {
                    $phone = $v;
                    break;
                }
            }
        }

        $cleanPhone = preg_replace('/\D+/', '', $phone);
        if (strlen($cleanPhone) === 11 && $cleanPhone[0] === '8') {
            $cleanPhone = '7' . substr($cleanPhone, 1);
        }
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '7' . $cleanPhone;
        }
        if (strlen($cleanPhone) >= 11 && $cleanPhone[0] === '7') {
            $phone = '+' . $cleanPhone;
        } elseif ($cleanPhone !== '') {
            $phone = '+' . $cleanPhone;
        }

        $name = '';
        foreach (['name', 'Name', 'NAME', 'fio', 'FIO', 'fullname'] as $f) {
            if (!empty($parsedData[$f]) && $parsedData[$f] !== 'Неизвестно') {
                $val = trim((string)$parsedData[$f]);
                if (strlen($val) < 100 && !preg_match('/^\d+$/', $val)) {
                    $name = $val;
                    break;
                }
            }
        }
        if ($name === '' && !empty($parsedData['contacts']['name'])) {
            $name = trim((string)$parsedData['contacts']['name']);
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $uf) {
            $set('CB_' . strtoupper($uf), (string)($parsedData[$uf] ?? ''));
        }

        $excludeKeys = [
            'phone', 'Phone', 'PHONE', 'name', 'Name', 'NAME', 'source_domain',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'rawBody', 'raw_body', 'raw_headers', 'parsed_data',
            'contacts', 'extra', '__submission', 'ASSIGNED_BY_ID', 'callerphone',
        ];
        $commentLines = [];

        if (!empty($parsedData['answers']) && is_array($parsedData['answers'])) {
            foreach ($parsedData['answers'] as $item) {
                $q = $item['q'] ?? '';
                $a = $item['a'] ?? '';
                if (is_array($a)) {
                    $a = implode(', ', $a);
                }
                $q = strip_tags(trim((string)$q));
                $a = strip_tags(trim((string)$a));
                if ($q !== '' && $a !== '') {
                    $commentLines[] = $q . ': ' . $a;
                }
            }
        } else {
            foreach ($parsedData as $k => $v) {
                if (in_array($k, $excludeKeys, true)) {
                    continue;
                }
                if (is_string($v) && $v !== '' && strlen($v) < 500) {
                    $commentLines[] = str_replace('_', ' ', (string)$k) . ': ' . strip_tags(trim($v));
                }
            }
        }
        $comment = implode("\n", $commentLines);

        $lastName = '';
        foreach (['last_name', 'surname', 'lastName', 'LAST_NAME'] as $f) {
            if (!empty($parsedData[$f])) {
                $lastName = trim((string)$parsedData[$f]);
                break;
            }
        }
        $secondName = '';
        foreach (['second_name', 'middle_name', 'patronymic', 'otchestvo', 'SECOND_NAME'] as $f) {
            if (!empty($parsedData[$f])) {
                $secondName = trim((string)$parsedData[$f]);
                break;
            }
        }

        $isCallTouch = isset($parsedData['callerphone']) || isset($parsedData['subPoolName']) || isset($parsedData['siteId']);
        $sourceType = $isCallTouch ? 'calltouch' : 'web';
        $leadTitle = (($sourceType === 'calltouch') ? 'Звонок' : 'Лид') . " с сайта [$domain]";
        $sourceDescription = ($sourceType === 'calltouch') ? 'CallTouch' : ('Сайт: ' . $domain);

        $set('CB_TITLE', $sourceType);
        $set('CB_LEAD_TITLE', $leadTitle);
        $set('CB_PHONE', $phone);
        $set('CB_NAME', $name);
        $set('CB_LAST_NAME', $lastName);
        $set('CB_SECOND_NAME', $secondName);
        $set('CB_DOMAIN', $domain);
        $set('CB_COMMENT', $comment);
        $set('CB_SOURCE_DESCRIPTION', $sourceDescription);
        $set('CB_OPENED', 'Y');
        $set('CB_STATUS_ID', 'NEW');
        $set('CB_RESULT', 'parsed');
        $set('CB_ERROR_MSG', '');

        $set('CB_ASSIGNED_BY_ID', (string)($parsedData['ASSIGNED_BY_ID'] ?? $parsedData['assigned_by_id'] ?? ''));
        $set('CB_ASSIGNED_BY_EMAIL', (string)($parsedData['ASSIGNED_BY_EMAIL'] ?? $parsedData['assigned_by_email'] ?? ''));
        $set('CB_ASSIGNED_BY_PERSONAL_MOBILE', (string)($parsedData['ASSIGNED_BY_PERSONAL_MOBILE'] ?? $parsedData['assigned_by_personal_mobile'] ?? ''));
        $set('CB_ASSIGNED_BY_WORK_PHONE', (string)($parsedData['ASSIGNED_BY_WORK_PHONE'] ?? $parsedData['assigned_by_work_phone'] ?? ''));
        $set('CB_SOURCE_ID', (string)($parsedData['SOURCE_ID'] ?? $parsedData['source_id'] ?? ''));
        $set('CB_TRACKING_SOURCE_ID', (string)($parsedData['TRACKING_SOURCE_ID'] ?? $parsedData['tracking_source_id'] ?? ''));
        $set('CB_OBSERVER_IDS', (string)($parsedData['OBSERVER_IDS'] ?? $parsedData['observer_ids'] ?? ''));
        $set('CB_CITY_ID', (string)($parsedData['UF_CRM_1773161068'] ?? $parsedData['UF_CRM_1744362815'] ?? $parsedData['city'] ?? ''));
        $set('CB_ISPOLNITEL', (string)($parsedData['UF_CRM_LEAD_1761829560855'] ?? $parsedData['UF_CRM_1745957138'] ?? $parsedData['ispolnitel'] ?? ''));
        $set('CB_INFOPOVOD', (string)($parsedData['UF_CRM_1754927102'] ?? $parsedData['infopovod'] ?? ''));

        $logLine = "[1/3] Парсинг OK | Phone: $phone | Name: $name | Domain: $domain | Title: $leadTitle";

        return [
            'result' => 'parsed',
            'error_msg' => '',
            'vars' => $vars,
            'log_line' => $logLine,
        ];
    } catch (Throwable $e) {
        $vars = array_merge(cb_blank_webhook_cb_vars(), $vars);
        $vars['CB_RESULT'] = 'not parsed';
        $vars['CB_ERROR_MSG'] = 'Parse error: ' . $e->getMessage();

        return [
            'result' => 'error',
            'error_msg' => $vars['CB_ERROR_MSG'],
            'vars' => $vars,
        ];
    }
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && basename((string)$_SERVER['argv'][0]) === basename(__FILE__)) {
    $path = $_SERVER['argv'][1] ?? '';
    $raw = ($path !== '' && is_readable($path)) ? (string)file_get_contents($path) : stream_get_contents(STDIN);
    fwrite(STDOUT, json_encode(cb_parse_raw_webhook_json($raw), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
}
