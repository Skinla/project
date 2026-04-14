<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/src/Contracts/ScoreParserInterface.php';
require_once __DIR__ . '/src/Contracts/IblockScoreWriterInterface.php';
require_once __DIR__ . '/src/DTO/ScorePayloadDTO.php';
require_once __DIR__ . '/src/Parser/MarkdownScoreParser.php';
require_once __DIR__ . '/src/Bitrix/BitrixBootstrap.php';
require_once __DIR__ . '/src/Bitrix/Iblock122ScoreWriter.php';

use App\Bitrix\BitrixBootstrap;
use App\Bitrix\Iblock122ScoreWriter;
use App\Parser\MarkdownScoreParser;

/**
 * Usage:
 *   /process_all_payloads.php
 *   /process_all_payloads.php?dry_run=1
 *   /process_all_payloads.php?limit=100&offset=0
 */

/**
 * @param mixed $rawPayload
 */
function normalizePayloadToText($rawPayload): string
{
    if (is_string($rawPayload)) {
        return $rawPayload;
    }

    if (is_array($rawPayload)) {
        $allStrings = true;
        foreach ($rawPayload as $row) {
            if (!is_string($row)) {
                $allStrings = false;
                break;
            }
        }

        if ($allStrings) {
            /** @var list<string> $rawPayload */
            return implode("\n", $rawPayload);
        }

        $encoded = json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return is_string($encoded) ? $encoded : '';
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
    $normalizedWanted = [];
    foreach ($keys as $key) {
        $normalizedWanted[] = strtolower(trim($key));
    }

    $stack = [normalizeToArray($source)];
    while ($stack !== []) {
        $current = array_pop($stack);
        if (!is_array($current)) {
            continue;
        }

        foreach ($current as $k => $v) {
            if (is_string($k)) {
                $normalizedKey = strtolower(trim($k));
                if (in_array($normalizedKey, $normalizedWanted, true)) {
                    return $v;
                }
            }

            if (is_array($v)) {
                $stack[] = $v;
            } elseif (is_object($v)) {
                $stack[] = normalizeToArray($v);
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

    if (preg_match('/^-?\d+(?:\.\d+)?$/', $normalized) !== 1) {
        return null;
    }

    return $normalized;
}

/**
 * @param array<string, mixed> $rawPayloadParsed
 * @param array<string, mixed> $storedRecord
 * @return array<string, int|float|string>
 */
function extractSupplementalMetadata(array $rawPayloadParsed, array $storedRecord, string $timezone): array
{
    $source = [
        'raw_payload_parsed' => $rawPayloadParsed,
        'stored_record' => $storedRecord,
        'original_request_body' => isset($storedRecord['original_request_body']) && is_array($storedRecord['original_request_body'])
            ? $storedRecord['original_request_body']
            : [],
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

    $callStartRaw = findValueByKeysRecursive($source, ['CALL_START_DATE', 'CALL_START', 'START_DATE', 'DATE_START']);
    $callStartString = toStringOrNull($callStartRaw);
    if ($callStartString !== null) {
        $converted = toBitrixDateTime($callStartString, $timezone);
        if ($converted !== null) {
            $metadata['CALL_START_DATE'] = $converted;
        }
    }

    return $metadata;
}

function jsonResponse(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function deleteProcessedFile(string $path): array
{
    if (!is_file($path)) {
        return [
            'deleted' => false,
            'delete_error' => 'File does not exist.',
        ];
    }

    if (@unlink($path)) {
        return [
            'deleted' => true,
            'delete_error' => null,
        ];
    }

    return [
        'deleted' => false,
        'delete_error' => 'Unable to delete file.',
    ];
}

function toBitrixDateTime(string $value, string $timezone = 'Europe/Moscow'): ?string
{
    try {
        // Primary path: parse timestamp with offset (or UTC default) and convert.
        $targetTz = new DateTimeZone(trim($timezone) !== '' ? $timezone : 'Europe/Moscow');
        $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        $dt = $dt->setTimezone($targetTz);
        return $dt->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }

    try {
        $targetTz = new DateTimeZone(trim($timezone) !== '' ? $timezone : 'Europe/Moscow');
        $dt = (new DateTimeImmutable('@' . (string) $timestamp))->setTimezone($targetTz);
        return $dt->format('d.m.Y H:i:s');
    } catch (Throwable $e) {
        return null;
    }
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'score_parser.php';
if (!is_file($configPath)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Config file not found.',
        'configPath' => $configPath,
    ]);
}

/** @var array<string, mixed> $config */
$config = require $configPath;

$storageDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'call_payloads';
if (!is_dir($storageDir)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Storage directory not found.',
        'storageDir' => $storageDir,
    ]);
}

$limit = isset($_GET['limit']) ? max(1, (int) $_GET['limit']) : 10000;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] === '1';
$deleteProcessedFiles = (bool) ($config['delete_processed_files'] ?? false);

$files = glob($storageDir . DIRECTORY_SEPARATOR . '*.json');
if (!is_array($files)) {
    jsonResponse([
        'ok' => false,
        'error' => 'Failed to list JSON files.',
        'storageDir' => $storageDir,
    ]);
}

sort($files, SORT_STRING);
$files = array_slice($files, $offset, $limit);

$parser = new MarkdownScoreParser((int) ($config['default_base_score'] ?? 27));
$bootstrap = new BitrixBootstrap(
    isset($config['bitrix_prolog_before_path']) && is_string($config['bitrix_prolog_before_path'])
        ? $config['bitrix_prolog_before_path']
        : null
);
$writer = new Iblock122ScoreWriter($bootstrap, $config);

$processed = 0;
$written = 0;
$skipped = 0;
$errors = 0;

/** @var list<array<string, mixed>> $items */
$items = [];

foreach ($files as $filePath) {
    $processed++;
    $fileName = basename($filePath);

    $raw = file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') {
        $errors++;
        $items[] = [
            'file' => $fileName,
            'status' => 'error',
            'error' => 'Empty file or read error.',
        ];
        continue;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $errors++;
        $items[] = [
            'file' => $fileName,
            'status' => 'error',
            'error' => 'Invalid JSON content.',
        ];
        continue;
    }

    $callId = isset($decoded['call_id']) && is_string($decoded['call_id']) && trim($decoded['call_id']) !== ''
        ? $decoded['call_id']
        : $fileName;

    $payloadSource = $decoded['raw_payload_json'] ?? ($decoded['original_request_body']['RAW_PAYLOAD_JSON'] ?? null);
    $payloadText = normalizePayloadToText($payloadSource);
    if ($payloadText === '') {
        $skipped++;
        $items[] = [
            'file' => $fileName,
            'call_id' => $callId,
            'status' => 'skipped',
            'reason' => 'RAW_PAYLOAD_JSON is missing or unsupported format.',
        ];
        continue;
    }

    $dto = $parser->parse($payloadText);
    $preferReceivedAt = (bool) ($config['call_datetime_prefer_received_at'] ?? true);
    $timezone = (string) ($config['call_datetime_timezone'] ?? 'Europe/Moscow');
    if (
        ((bool) ($config['fill_call_datetime_from_received_at'] ?? true))
        && isset($decoded['received_at'])
        && is_string($decoded['received_at'])
    ) {
        $hasCallDateTime = $dto->callDateTime() !== null && $dto->callDateTime() !== '';
        if ($preferReceivedAt || !$hasCallDateTime) {
            $fallbackCallDateTime = toBitrixDateTime($decoded['received_at'], $timezone);
        } else {
            $fallbackCallDateTime = null;
        }
        if ($fallbackCallDateTime !== null) {
            $dto = $dto->withCallDateTime($fallbackCallDateTime);
        }
    }
    $metadata = extractSupplementalMetadata(
        isset($decoded['raw_payload_parsed']) && is_array($decoded['raw_payload_parsed']) ? $decoded['raw_payload_parsed'] : [],
        $decoded,
        $timezone
    );
    if ($metadata !== []) {
        $dto = $dto->withMetadata($metadata);
    }

    if ($dto->finalScore() === null) {
        $skipped++;
        $items[] = [
            'file' => $fileName,
            'call_id' => $callId,
            'status' => 'skipped',
            'reason' => 'FINAL_SCORE is null.',
            'warnings' => $dto->warnings(),
        ];
        continue;
    }

    if ($dryRun) {
        $written++;
        $items[] = [
            'file' => $fileName,
            'call_id' => $callId,
            'status' => 'dry_run_ready',
            'scores' => [
                'criteria_count' => count($dto->criteriaScores()),
                'base_score' => $dto->baseScore(),
                'final_score' => $dto->finalScore(),
                'deduction_total' => $dto->deductionTotal(),
                'criteria_total' => $dto->criteriaTotal(),
                'final_score_percent' => $dto->finalScorePercent(),
            ],
        ];
        continue;
    }

    try {
        $result = $writer->write($callId, $dto);
        $written++;

        $deleteResult = [
            'deleted' => false,
            'delete_error' => null,
        ];
        if (!$dryRun && $deleteProcessedFiles) {
            $deleteResult = deleteProcessedFile($filePath);
        }

        $items[] = [
            'file' => $fileName,
            'call_id' => $callId,
            'status' => 'written',
            'iblock_element_id' => $result['element_id'],
            'verified' => $result['verified'],
            'scores' => [
                'criteria_count' => count($dto->criteriaScores()),
                'criteria_total' => $dto->criteriaTotal(),
                'final_score' => $dto->finalScore(),
            ],
            'deleted' => $deleteResult['deleted'],
            'delete_error' => $deleteResult['delete_error'],
        ];
    } catch (Throwable $e) {
        $errors++;
        $items[] = [
            'file' => $fileName,
            'call_id' => $callId,
            'status' => 'error',
            'error' => $e->getMessage(),
        ];
    }
}

jsonResponse([
    'ok' => $errors === 0,
    'mode' => $dryRun ? 'dry_run' : 'write',
    'summary' => [
        'processed' => $processed,
        'written' => $written,
        'skipped' => $skipped,
        'errors' => $errors,
    ],
    'batch' => [
        'offset' => $offset,
        'limit' => $limit,
        'files_in_batch' => count($files),
        'delete_processed_files' => $deleteProcessedFiles,
    ],
    'items' => $items,
]);
