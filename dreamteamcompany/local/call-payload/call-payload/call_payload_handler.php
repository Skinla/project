<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/CappedFileLogger.php';
require_once __DIR__ . '/src/Contracts/ScoreParserInterface.php';
require_once __DIR__ . '/src/Contracts/IblockScoreWriterInterface.php';
require_once __DIR__ . '/src/DTO/ScorePayloadDTO.php';
require_once __DIR__ . '/src/Parser/MarkdownScoreParser.php';
require_once __DIR__ . '/src/Bitrix/BitrixBootstrap.php';
require_once __DIR__ . '/src/Bitrix/Iblock122ScoreWriter.php';

use App\Bitrix\BitrixBootstrap;
use App\Bitrix\Iblock122ScoreWriter;
use App\CappedFileLogger;
use App\Parser\MarkdownScoreParser;

function ensureDir(string $path): void
{
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

/**
 * @return array<string, mixed>
 */
function loadConfig(): array
{
    $configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'score_parser.php';
    if (!is_file($configPath)) {
        respond(500, [
            'ok' => false,
            'error' => 'Score parser config is missing.',
            'configPath' => $configPath,
        ]);
    }

    $config = require $configPath;
    if (!is_array($config)) {
        respond(500, [
            'ok' => false,
            'error' => 'Score parser config must return an array.',
        ]);
    }

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function buildLogger(array $config): CappedFileLogger
{
    $storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    ensureDir($storageDir);

    $logPath = $config['log_path'] ?? ($storageDir . DIRECTORY_SEPARATOR . 'integration.log');
    if (!is_string($logPath) || trim($logPath) === '') {
        $logPath = $storageDir . DIRECTORY_SEPARATOR . 'integration.log';
    } elseif ($logPath[0] !== DIRECTORY_SEPARATOR) {
        $logPath = __DIR__ . DIRECTORY_SEPARATOR . ltrim($logPath, '/\\');
    }

    return new CappedFileLogger(
        $logPath,
        (int) ($config['max_log_bytes'] ?? 2097152),
        (string) ($config['service_timezone'] ?? $config['call_datetime_timezone'] ?? 'Europe/Moscow'),
        (bool) ($config['write_logs'] ?? true)
    );
}

/**
 * @param mixed $rawPayloadJson
 * @param mixed $rawPayloadParsed
 */
function resolveParserInput($rawPayloadJson, $rawPayloadParsed): string
{
    if (is_string($rawPayloadJson) && trim($rawPayloadJson) !== '') {
        return $rawPayloadJson;
    }

    if (is_array($rawPayloadParsed)) {
        $encoded = json_encode($rawPayloadParsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }

    if (is_array($rawPayloadJson)) {
        $encoded = json_encode($rawPayloadJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($encoded) && $encoded !== '') {
            return $encoded;
        }
    }

    return '';
}

/**
 * @param mixed $source
 * @return array<string, mixed>
 */
function normalizeToArray($source): array
{
    if (is_array($source)) {
        return $source;
    }

    if (is_object($source)) {
        try {
            $encoded = json_encode($source, JSON_THROW_ON_ERROR);
            $decoded = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    return [];
}

/**
 * @param mixed $source
 * @param list<string> $keys
 * @return mixed|null
 */
function findValueByKeysRecursive($source, array $keys)
{
    $wanted = [];
    foreach ($keys as $key) {
        $wanted[] = strtolower(trim($key));
    }

    $stack = [normalizeToArray($source)];
    while ($stack !== []) {
        $current = array_pop($stack);
        if (!is_array($current)) {
            continue;
        }

        foreach ($current as $key => $value) {
            if (is_string($key) && in_array(strtolower(trim($key)), $wanted, true)) {
                return $value;
            }

            if (is_array($value)) {
                $stack[] = $value;
            } elseif (is_object($value)) {
                $stack[] = normalizeToArray($value);
            }
        }
    }

    return null;
}

/**
 * @param mixed $value
 */
function toStringOrNull($value): ?string
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return null;
}

/**
 * @param mixed $value
 */
function toNumericStringOrNull($value): ?string
{
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '') {
        return null;
    }

    return preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) === 1 ? $normalized : null;
}

function toBitrixDateTime(string $value, string $timezone = 'Europe/Moscow'): ?string
{
    try {
        $targetTz = new DateTimeZone(trim($timezone) !== '' ? $timezone : 'Europe/Moscow');
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        return $dt->setTimezone($targetTz)->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    try {
        $targetTz = new DateTimeZone(trim($timezone) !== '' ? $timezone : 'Europe/Moscow');
        return (new DateTimeImmutable('@' . (string) $timestamp))->setTimezone($targetTz)->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @param array<string, mixed> $rawPayloadParsed
 * @param array<string, mixed> $requestPayload
 * @return array<string, int|float|string>
 */
function extractSupplementalMetadata(array $rawPayloadParsed, array $requestPayload, string $timezone): array
{
    $source = [
        'raw_payload_parsed' => $rawPayloadParsed,
        'request_payload' => $requestPayload,
    ];

    $metadata = [];

    $stringFieldMap = [
        'NAME' => ['NAME', 'TITLE'],
        'PORTAL_NUMBER' => ['PORTAL_NUMBER', 'PORTAL_NUMBER_ID', 'PORTAL'],
        'CRM_ENTITY_TYPE' => ['CRM_ENTITY_TYPE', 'ENTITY_TYPE', 'CRM_TYPE'],
    ];
    foreach ($stringFieldMap as $targetField => $keys) {
        $value = toStringOrNull(findValueByKeysRecursive($source, $keys));
        if ($value !== null) {
            $metadata[$targetField] = $value;
        }
    }

    $numericFieldMap = [
        'PHONE_NUMBER' => ['PHONE_NUMBER', 'PHONE', 'CLIENT_PHONE', 'PHONE_DIGITS'],
        'CRM_ENTITY_ID' => ['CRM_ENTITY_ID', 'ENTITY_ID'],
        'CRM_ACTIVITY_ID' => ['CRM_ACTIVITY_ID', 'ACTIVITY_ID'],
        'PORTAL_USER_ID' => ['PORTAL_USER_ID', 'USER_ID', 'RESPONSIBLE_ID'],
        'CALL_DURATION' => ['CALL_DURATION', 'DURATION', 'DURATION_SEC'],
        'CALL_TYPE' => ['CALL_TYPE', 'TYPE', 'CALL_DIRECTION'],
    ];
    foreach ($numericFieldMap as $targetField => $keys) {
        $rawValue = findValueByKeysRecursive($source, $keys);
        if ($targetField === 'PHONE_NUMBER' && is_string($rawValue)) {
            $digitsOnly = preg_replace('/\D+/', '', $rawValue);
            if (is_string($digitsOnly) && $digitsOnly !== '') {
                $metadata[$targetField] = $digitsOnly;
                continue;
            }
        }

        $value = toNumericStringOrNull($rawValue);
        if ($value !== null) {
            $metadata[$targetField] = $value;
        }
    }

    $callStart = toStringOrNull(findValueByKeysRecursive($source, ['CALL_START_DATE', 'CALL_START', 'START_DATE', 'DATE_START']));
    if ($callStart !== null) {
        $converted = toBitrixDateTime($callStart, $timezone);
        if ($converted !== null) {
            $metadata['CALL_START_DATE'] = $converted;
        }
    }

    return $metadata;
}

$config = loadConfig();
$logger = buildLogger($config);

register_shutdown_function(static function () use ($logger): void {
    $lastError = error_get_last();
    if (!is_array($lastError)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
        return;
    }

    $logger->log(sprintf(
        'FATAL type=%s file=%s line=%s message=%s',
        (string) ($lastError['type'] ?? ''),
        (string) ($lastError['file'] ?? ''),
        (string) ($lastError['line'] ?? ''),
        (string) ($lastError['message'] ?? '')
    ));
});

$requestMethod = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$requestContentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
$logger->log(sprintf(
    'INCOMING method=%s content_type=%s remote_ip=%s ua=%s',
    $requestMethod,
    $requestContentType,
    (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
));

if ($requestMethod !== 'POST') {
    $logger->log('REJECT reason=method_not_allowed');
    respond(405, [
        'ok' => false,
        'error' => 'Method Not Allowed. Use POST.',
    ]);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    $logger->log('REJECT reason=empty_request_body');
    respond(400, [
        'ok' => false,
        'error' => 'Empty request body.',
    ]);
}

try {
    /** @var array<string, mixed> $payload */
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    $logger->log('REJECT reason=invalid_json_body details=' . $e->getMessage());
    respond(400, [
        'ok' => false,
        'error' => 'Invalid JSON body.',
        'details' => $e->getMessage(),
    ]);
}

$callId = $payload['CALL_ID'] ?? null;
if (!is_string($callId) || trim($callId) === '') {
    $logger->log('REJECT reason=missing_call_id');
    respond(422, [
        'ok' => false,
        'error' => 'CALL_ID is required and must be a non-empty string.',
    ]);
}
$callId = trim($callId);

$rawPayloadJson = $payload['RAW_PAYLOAD_JSON'] ?? null;
if ($rawPayloadJson === null) {
    $logger->log('REJECT reason=missing_raw_payload_json call_id=' . $callId);
    respond(422, [
        'ok' => false,
        'error' => 'RAW_PAYLOAD_JSON is required.',
    ]);
}

$rawPayloadNormalized = $rawPayloadJson;
$rawPayloadParsed = null;

if (is_string($rawPayloadJson)) {
    $trimmed = trim($rawPayloadJson);
    if ($trimmed !== '') {
        try {
            $rawPayloadParsed = json_decode($rawPayloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $rawPayloadParsed = null;
        }
    }
} elseif (is_array($rawPayloadJson)) {
    $rawPayloadParsed = $rawPayloadJson;
} elseif (is_object($rawPayloadJson)) {
    try {
        $encoded = json_encode($rawPayloadJson, JSON_THROW_ON_ERROR);
        $decoded = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);
        $rawPayloadParsed = is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        $rawPayloadParsed = null;
    }
} else {
    $logger->log('REJECT reason=raw_payload_wrong_type call_id=' . $callId . ' got=' . gettype($rawPayloadJson));
    respond(422, [
        'ok' => false,
        'error' => 'RAW_PAYLOAD_JSON must be a string or JSON object/array.',
        'got_type' => gettype($rawPayloadJson),
    ]);
}

$parserInput = resolveParserInput($rawPayloadNormalized, $rawPayloadParsed);
$requestReceivedAt = gmdate('c');
$timezone = (string) ($config['call_datetime_timezone'] ?? 'Europe/Moscow');
$failFast = (bool) ($config['fail_fast_on_bitrix_error'] ?? false);
$verboseResponse = isset($_GET['verbose']) && $_GET['verbose'] === '1';

$logger->log(sprintf('CALL_ID=%s PROCESS_START', $callId));

$scorePayload = null;
$bitrixResult = null;
$bitrixError = null;

try {
    $parser = new MarkdownScoreParser((int) ($config['default_base_score'] ?? 27));
    $scorePayload = $parser->parse($parserInput);

    if ((bool) ($config['fill_call_datetime_from_received_at'] ?? true)) {
        $preferReceivedAt = (bool) ($config['call_datetime_prefer_received_at'] ?? true);
        $hasCallDateTime = $scorePayload->callDateTime() !== null && $scorePayload->callDateTime() !== '';
        if ($preferReceivedAt || !$hasCallDateTime) {
            $fallbackCallDateTime = toBitrixDateTime($requestReceivedAt, $timezone);
            if ($fallbackCallDateTime !== null) {
                $scorePayload = $scorePayload->withCallDateTime($fallbackCallDateTime);
            }
        }
    }

    $metadata = extractSupplementalMetadata(
        is_array($rawPayloadParsed) ? $rawPayloadParsed : [],
        $payload,
        $timezone
    );
    if ($metadata !== []) {
        $scorePayload = $scorePayload->withMetadata($metadata);
    }

    $logger->log(sprintf(
        'CALL_ID=%s PARSE_OK final_score=%s warnings=%d',
        $callId,
        (string) ($scorePayload->finalScore() ?? 'NULL'),
        count($scorePayload->warnings())
    ));
} catch (Throwable $e) {
    $bitrixError = 'Parser failed: ' . $e->getMessage();
    $logger->log(sprintf('CALL_ID=%s PARSER_ERROR=%s', $callId, $bitrixError));
}

if ($scorePayload !== null) {
    try {
        $bootstrap = new BitrixBootstrap(
            isset($config['bitrix_prolog_before_path']) && is_string($config['bitrix_prolog_before_path'])
                ? $config['bitrix_prolog_before_path']
                : null
        );
        $writer = new Iblock122ScoreWriter($bootstrap, $config);
        $bitrixResult = $writer->write($callId, $scorePayload);
        $logger->log(sprintf(
            'CALL_ID=%s BITRIX_WRITE_OK element_id=%s verified=%s',
            $callId,
            (string) ($bitrixResult['element_id'] ?? ''),
            isset($bitrixResult['verified']) && $bitrixResult['verified'] ? 'Y' : 'N'
        ));
    } catch (Throwable $e) {
        $bitrixError = $e->getMessage();
        $logger->log(sprintf('CALL_ID=%s BITRIX_WRITE_ERROR=%s', $callId, $bitrixError));

        if ($failFast) {
            respond(500, [
                'ok' => false,
                'error' => 'Bitrix write failed.',
                'details' => $bitrixError,
                'call_id' => $callId,
            ]);
        }
    }
}

$responseBody = [
    'ok' => true,
    'call_id' => $callId,
    'processing' => [
        'deps_loaded' => true,
        'parsed' => $scorePayload !== null,
        'bitrix_written' => $bitrixResult !== null,
        'bitrix_error' => $bitrixError,
    ],
];

if ($verboseResponse) {
    $responseBody['scores'] = [
        'criteria_scores' => $scorePayload !== null ? $scorePayload->criteriaScores() : [],
        'base_score' => $scorePayload !== null ? $scorePayload->baseScore() : null,
        'final_score' => $scorePayload !== null ? $scorePayload->finalScore() : null,
        'deduction_total' => $scorePayload !== null ? $scorePayload->deductionTotal() : null,
        'criteria_total' => $scorePayload !== null ? $scorePayload->criteriaTotal() : 0,
        'final_score_percent' => $scorePayload !== null ? $scorePayload->finalScorePercent() : null,
        'is_booked_value' => $scorePayload !== null ? $scorePayload->isBookedValue() : null,
        'warnings' => $scorePayload !== null ? $scorePayload->warnings() : [],
        'metadata' => $scorePayload !== null ? $scorePayload->metadata() : [],
    ];
    $responseBody['bitrix'] = [
        'written' => $bitrixResult !== null,
        'element_id' => $bitrixResult['element_id'] ?? null,
        'verified' => $bitrixResult['verified'] ?? null,
        'error' => $bitrixError,
        'fail_fast' => $failFast,
        'write_logs' => (bool) ($config['write_logs'] ?? true),
    ];
}

respond(200, $responseBody);
