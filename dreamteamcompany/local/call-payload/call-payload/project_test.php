<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

/**
 * URL runner for project self-check.
 *
 * Usage:
 *   /project_test.php
 *   /project_test.php?verbose=1
 *   /project_test.php?strict_samples=1
 */

$baseDir = __DIR__;
$verbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';
$strictSamples = isset($_GET['strict_samples']) && $_GET['strict_samples'] === '1';
$strictCleanup = isset($_GET['strict_cleanup']) && $_GET['strict_cleanup'] === '1';
$samplesLimitRaw = isset($_GET['samples_limit']) ? (int) $_GET['samples_limit'] : 20;
$samplesLimit = $samplesLimitRaw > 0 ? $samplesLimitRaw : 20;

/** @var array<int, array<string, mixed>> $checks */
$checks = [];

/**
 * @param array<int, array<string, mixed>> $checks
 */
function addCheck(array &$checks, string $name, bool $ok, string $message = '', array $meta = []): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'message' => $message,
        'meta' => $meta,
    ];
}

function toAbsolute(string $baseDir, string $relative): string
{
    return $baseDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
}

/**
 * Safe include to avoid fatal on partial deploy.
 */
function tryIncludeFile(string $absolutePath, array &$checks, string $relativePath): bool
{
    if (!is_file($absolutePath)) {
        addCheck($checks, 'include_file:' . $relativePath, false, 'file not found for include');
        return false;
    }

    try {
        include_once $absolutePath;
        addCheck($checks, 'include_file:' . $relativePath, true, 'ok');
        return true;
    } catch (Throwable $e) {
        addCheck(
            $checks,
            'include_file:' . $relativePath,
            false,
            'include failed: ' . $e->getMessage()
        );
        return false;
    }
}

/**
 * Read declared global function names from file without executing it.
 *
 * @return list<string>
 */
function extractFunctionNamesFromFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $code = file_get_contents($path);
    if (!is_string($code) || $code === '') {
        return [];
    }

    $tokens = token_get_all($code);
    $functions = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }

        if ($tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        for ($j = $i + 1; $j < $count; $j++) {
            if (!is_array($tokens[$j])) {
                continue;
            }

            if ($tokens[$j][0] === T_STRING) {
                $functions[] = $tokens[$j][1];
                break;
            }
        }
    }

    return array_values(array_unique($functions));
}

$requiredFiles = [
    'call_payload_handler.php',
    'config/score_parser.php',
    'src/CappedFileLogger.php',
    'src/Contracts/ScoreParserInterface.php',
    'src/Contracts/IblockScoreWriterInterface.php',
    'src/DTO/ScorePayloadDTO.php',
    'src/Parser/MarkdownScoreParser.php',
    'src/Bitrix/BitrixBootstrap.php',
    'src/Bitrix/Iblock122ScoreWriter.php',
];

foreach ($requiredFiles as $relative) {
    $absolute = toAbsolute($baseDir, $relative);
    addCheck(
        $checks,
        'file_exists:' . $relative,
        is_file($absolute),
        is_file($absolute) ? 'ok' : 'file not found',
        ['path' => $absolute]
    );
}

$autoloadFiles = [
    'src/CappedFileLogger.php',
    'src/Contracts/ScoreParserInterface.php',
    'src/Contracts/IblockScoreWriterInterface.php',
    'src/DTO/ScorePayloadDTO.php',
    'src/Parser/MarkdownScoreParser.php',
    'src/Bitrix/BitrixBootstrap.php',
    'src/Bitrix/Iblock122ScoreWriter.php',
];

foreach ($autoloadFiles as $relative) {
    $absolute = toAbsolute($baseDir, $relative);
    tryIncludeFile($absolute, $checks, $relative);
}

$classMethodMap = [
    'App\\CappedFileLogger' => ['__construct', 'log'],
    'App\\Parser\\MarkdownScoreParser' => ['__construct', 'parse'],
    'App\\Bitrix\\BitrixBootstrap' => ['__construct', 'boot'],
    'App\\Bitrix\\Iblock122ScoreWriter' => ['__construct', 'write'],
    'App\\DTO\\ScorePayloadDTO' => [
        '__construct',
        'criteriaScores',
        'baseScore',
        'finalScore',
        'deductionTotal',
        'criteriaTotal',
        'finalScorePercent',
        'warnings',
        'metadata',
        'withMetadata',
    ],
];

foreach ($classMethodMap as $class => $methods) {
    $classExists = class_exists($class);
    addCheck(
        $checks,
        'class_exists:' . $class,
        $classExists,
        $classExists ? 'ok' : 'class not found'
    );

    if (!$classExists) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    foreach ($methods as $method) {
        $hasMethod = $reflection->hasMethod($method);
        addCheck(
            $checks,
            'method_exists:' . $class . '::' . $method,
            $hasMethod,
            $hasMethod ? 'ok' : 'method not found'
        );
    }
}

$handlerPath = toAbsolute($baseDir, 'call_payload_handler.php');
$handlerFunctions = extractFunctionNamesFromFile($handlerPath);
$requiredHandlerFunctions = [
    'respond',
    'loadConfig',
    'buildLogger',
    'resolveParserInput',
    'normalizeToArray',
    'findValueByKeysRecursive',
    'toStringOrNull',
    'toNumericStringOrNull',
    'toBitrixDateTime',
    'extractSupplementalMetadata',
];

foreach ($requiredHandlerFunctions as $fn) {
    $ok = in_array($fn, $handlerFunctions, true);
    addCheck(
        $checks,
        'handler_function:' . $fn,
        $ok,
        $ok ? 'ok' : 'function not found in handler'
    );
}

$configPath = toAbsolute($baseDir, 'config/score_parser.php');
if (is_file($configPath)) {
    /** @var array<string, mixed> $config */
    $config = require $configPath;

    $requiredConfigKeys = [
        'iblock_id',
        'call_id_property_code',
        'default_base_score',
        'write_logs',
        'log_path',
        'max_log_bytes',
        'fail_fast_on_bitrix_error',
        'criteria_property_map',
        'aggregate_property_map',
        'extra_property_map',
    ];

    foreach ($requiredConfigKeys as $key) {
        $ok = array_key_exists($key, $config);
        addCheck(
            $checks,
            'config_key:' . $key,
            $ok,
            $ok ? 'ok' : 'missing key'
        );
    }

    $criteriaMap = $config['criteria_property_map'] ?? null;
    $criteriaOk = is_array($criteriaMap) && count($criteriaMap) === 17;
    addCheck(
        $checks,
        'config_criteria_count',
        $criteriaOk,
        $criteriaOk ? 'ok' : 'criteria_property_map must have 17 items',
        ['count' => is_array($criteriaMap) ? count($criteriaMap) : null]
    );

    $aggregateMap = $config['aggregate_property_map'] ?? null;
    $requiredAgg = ['BASE_SCORE', 'FINAL_SCORE', 'DEDUCTION_TOTAL', 'CRITERIA_TOTAL', 'FINAL_SCORE_PERCENT'];
    $aggOk = is_array($aggregateMap);
    if ($aggOk) {
        foreach ($requiredAgg as $aggKey) {
            if (!array_key_exists($aggKey, $aggregateMap)) {
                $aggOk = false;
                break;
            }
        }
    }
    addCheck(
        $checks,
        'config_aggregate_map',
        $aggOk,
        $aggOk ? 'ok' : 'aggregate_property_map missing one or more required keys'
    );

    $extraMap = $config['extra_property_map'] ?? null;
    $requiredExtra = [
        'PHONE_NUMBER',
        'PORTAL_NUMBER',
        'CRM_ENTITY_TYPE',
        'CRM_ENTITY_ID',
        'CRM_ACTIVITY_ID',
        'CALL_START_DATE',
        'PORTAL_USER_ID',
        'CALL_DURATION',
        'CALL_TYPE',
    ];
    $extraOk = is_array($extraMap);
    if ($extraOk) {
        foreach ($requiredExtra as $extraKey) {
            if (!array_key_exists($extraKey, $extraMap)) {
                $extraOk = false;
                break;
            }
        }
    }
    addCheck(
        $checks,
        'config_extra_map',
        $extraOk,
        $extraOk ? 'ok' : 'extra_property_map missing one or more required keys'
    );
}

if (class_exists('App\\Parser\\MarkdownScoreParser')) {
    $defaultBase = 27;
    if (isset($config) && is_array($config) && isset($config['default_base_score'])) {
        $defaultBase = (int) $config['default_base_score'];
    }

    $parser = new App\Parser\MarkdownScoreParser($defaultBase);

    $sampleFiles = [];
    $sampleDirs = [
        $baseDir,
        $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'call_payloads',
    ];

    foreach ($sampleDirs as $sampleDir) {
        if (!is_dir($sampleDir)) {
            continue;
        }

        $found = glob($sampleDir . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($found)) {
            continue;
        }

        foreach ($found as $filePath) {
            $sampleFiles[] = $filePath;
        }
    }

    $sampleFiles = array_values(array_unique($sampleFiles));
    sort($sampleFiles);
    if (count($sampleFiles) > $samplesLimit) {
        $sampleFiles = array_slice($sampleFiles, -$samplesLimit);
    }
    addCheck(
        $checks,
        'samples_found_count',
        true,
        'ok',
        ['count' => count($sampleFiles), 'samples_limit' => $samplesLimit]
    );

    if ($sampleFiles !== []) {
        foreach ($sampleFiles as $samplePath) {
            $sampleName = basename($samplePath);
            $raw = file_get_contents($samplePath);
            if (!is_string($raw) || trim($raw) === '') {
                addCheck($checks, 'sample_read:' . $sampleName, false, 'empty sample file');
                continue;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                addCheck($checks, 'sample_json:' . $sampleName, false, 'invalid sample json');
                continue;
            }

            $callId = isset($decoded['call_id']) && is_string($decoded['call_id']) ? $decoded['call_id'] : $sampleName;
            $payloadText = '';
            if (isset($decoded['raw_payload_json']) && is_string($decoded['raw_payload_json'])) {
                $payloadText = $decoded['raw_payload_json'];
            } elseif (isset($decoded['original_request_body']['RAW_PAYLOAD_JSON']) && is_string($decoded['original_request_body']['RAW_PAYLOAD_JSON'])) {
                $payloadText = $decoded['original_request_body']['RAW_PAYLOAD_JSON'];
            }

            if ($payloadText === '') {
                addCheck(
                    $checks,
                    'sample_payload:' . $callId,
                    !$strictSamples,
                    $strictSamples ? 'RAW_PAYLOAD_JSON missing in sample' : 'skip: RAW_PAYLOAD_JSON missing in sample'
                );
                continue;
            }

            $dto = $parser->parse($payloadText);

            addCheck(
                $checks,
                'sample_parse:' . $callId,
                true,
                'ok',
                [
                    'criteria_count' => count($dto->criteriaScores()),
                    'base_score' => $dto->baseScore(),
                    'final_score' => $dto->finalScore(),
                    'deduction_total' => $dto->deductionTotal(),
                    'criteria_total' => $dto->criteriaTotal(),
                    'final_score_percent' => $dto->finalScorePercent(),
                    'warnings' => $dto->warnings(),
                ]
            );

            addCheck(
                $checks,
                'sample_final_score_present:' . $callId,
                $dto->finalScore() !== null || !$strictSamples,
                $dto->finalScore() !== null
                    ? 'ok'
                    : ($strictSamples ? 'FINAL_SCORE is null' : 'warning: FINAL_SCORE is null')
            );
        }
    } else {
        addCheck(
            $checks,
            'samples_available',
            !$strictSamples,
            $strictSamples
                ? 'no sample json files found in supported directories'
                : 'skip: no sample json files found in supported directories'
        );
    }
}

$total = count($checks);
$failed = count(array_filter($checks, static function (array $row): bool {
    return $row['ok'] !== true;
}));

$productionCleanupRecommendations = [
    'safe_to_delete' => [
        'WORK_DESCRIPTION.md',
        'dump.php',
        'process_all_payloads.php',
        'project_test.php',
        'ping_ok.php',
        'backups/',
        '.cursor/',
        'storage/call_payloads/',
    ],
    'keep_required' => [
        'call_payload_handler.php',
        'config/score_parser.php',
        'src/CappedFileLogger.php',
        'src/Contracts/ScoreParserInterface.php',
        'src/Contracts/IblockScoreWriterInterface.php',
        'src/DTO/ScorePayloadDTO.php',
        'src/Parser/MarkdownScoreParser.php',
        'src/Bitrix/BitrixBootstrap.php',
        'src/Bitrix/Iblock122ScoreWriter.php',
        'storage/',
    ],
    'keep_optional_bitrix_service_files' => [
        '.section.php',
        'config/.section.php',
        'src/.section.php',
        'src/Bitrix/.section.php',
        'src/Contracts/.section.php',
        'src/DTO/.section.php',
        'src/Parser/.section.php',
    ],
];

$presentExtraFiles = [];
$missingExtraFiles = [];
foreach ($productionCleanupRecommendations['safe_to_delete'] as $relativePath) {
    $normalizedRelativePath = rtrim($relativePath, '/');
    $absolutePath = toAbsolute($baseDir, $normalizedRelativePath);
    $exists = is_file($absolutePath) || is_dir($absolutePath);

    addCheck(
        $checks,
        'cleanup_extra:' . $normalizedRelativePath,
        $strictCleanup ? !$exists : true,
        $exists
            ? ($strictCleanup ? 'extra file/dir exists and should be removed in production' : 'extra file/dir exists')
            : 'not present',
        [
            'path' => $absolutePath,
            'exists' => $exists,
            'strict_cleanup' => $strictCleanup,
        ]
    );

    if ($exists) {
        $presentExtraFiles[] = $relativePath;
    } else {
        $missingExtraFiles[] = $relativePath;
    }
}

$total = count($checks);
$failed = count(array_filter($checks, static function (array $row): bool {
    return $row['ok'] !== true;
}));

$response = [
    'ok' => $failed === 0,
    'summary' => [
        'total' => $total,
        'passed' => $total - $failed,
        'failed' => $failed,
    ],
    'production_cleanup_recommendations' => $productionCleanupRecommendations,
    'production_cleanup_audit' => [
        'strict_cleanup' => $strictCleanup,
        'present_extra_files' => $presentExtraFiles,
        'missing_extra_files' => $missingExtraFiles,
    ],
    'checks' => $verbose ? $checks : array_values(
        array_filter(
            $checks,
            static function (array $row): bool {
                return $row['ok'] !== true;
            }
        )
    ),
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
