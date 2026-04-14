<?php
header('Content-Type: application/json; charset=utf-8');

$baseDir = __DIR__;
$verbose = isset($_GET['verbose']) && $_GET['verbose'] === '1';

function absolutePath($baseDir, $relativePath)
{
    return $baseDir . DIRECTORY_SEPARATOR . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $relativePath);
}

function readFileSafe($path)
{
    if (!is_file($path)) {
        return '';
    }

    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
}

function fileContainsFunction($filePath, $functionName)
{
    $content = readFileSafe($filePath);
    if ($content === '') {
        return false;
    }

    $pattern = '/function\s+' . preg_quote($functionName, '/') . '\s*\(/i';
    return (bool) preg_match($pattern, $content);
}

function fileContainsClass($filePath, $classShortName)
{
    $content = readFileSafe($filePath);
    if ($content === '') {
        return false;
    }

    if (function_exists('token_get_all')) {
        $tokens = token_get_all($content);
        $count = is_array($tokens) ? count($tokens) : 0;

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i])) {
                continue;
            }

            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if (!is_array($tokens[$j])) {
                    continue;
                }

                if ($tokens[$j][0] === T_STRING) {
                    return strcasecmp($tokens[$j][1], $classShortName) === 0;
                }
            }
        }
    }

    $pattern = '/(?:final\s+|abstract\s+|readonly\s+)*class\s+' . preg_quote($classShortName, '/') . '\b/i';
    return preg_match($pattern, $content) === 1;
}

$requiredFiles = array(
    'call_payload_handler.php',
    'project_test.php',
    'process_all_payloads.php',
    'config/score_parser.php',
    'src/Contracts/ScoreParserInterface.php',
    'src/Contracts/IblockScoreWriterInterface.php',
    'src/DTO/ScorePayloadDTO.php',
    'src/Parser/MarkdownScoreParser.php',
    'src/Bitrix/BitrixBootstrap.php',
    'src/Bitrix/Iblock122ScoreWriter.php',
);

$optionalFiles = array(
    'CHANGES.md',
    'WORK_DESCRIPTION.md',
);

$requiredDirs = array(
    'config',
    'src',
    'src/Contracts',
    'src/DTO',
    'src/Parser',
    'src/Bitrix',
    'storage',
    'storage/call_payloads',
);

$classSpecs = array(
    array(
        'fqcn' => 'App\\Parser\\MarkdownScoreParser',
        'short' => 'MarkdownScoreParser',
        'file' => 'src/Parser/MarkdownScoreParser.php',
        'methods' => array('__construct', 'parse'),
    ),
    array(
        'fqcn' => 'App\\Bitrix\\BitrixBootstrap',
        'short' => 'BitrixBootstrap',
        'file' => 'src/Bitrix/BitrixBootstrap.php',
        'methods' => array('__construct', 'boot'),
    ),
    array(
        'fqcn' => 'App\\Bitrix\\Iblock122ScoreWriter',
        'short' => 'Iblock122ScoreWriter',
        'file' => 'src/Bitrix/Iblock122ScoreWriter.php',
        'methods' => array('__construct', 'write'),
    ),
    array(
        'fqcn' => 'App\\DTO\\ScorePayloadDTO',
        'short' => 'ScorePayloadDTO',
        'file' => 'src/DTO/ScorePayloadDTO.php',
        'methods' => array(
            '__construct',
            'criteriaScores',
            'baseScore',
            'finalScore',
            'deductionTotal',
            'criteriaTotal',
            'finalScorePercent',
            'familyOfferStatusCode',
            'callDateTime',
            'isBookedValue',
            'warnings',
            'withCallDateTime',
            'metadata',
            'withMetadata',
        ),
    ),
);

$handlerFunctionsExpected = array(
    'respond',
    'resolveParserInput',
    'appendIntegrationLog',
    'toBitrixDateTime',
);

$missingFiles = array();
$missingOptionalFiles = array();
$missingDirs = array();
$existingFiles = array();
$existingOptionalFiles = array();
$existingDirs = array();

foreach ($requiredFiles as $relativePath) {
    if (is_file(absolutePath($baseDir, $relativePath))) {
        $existingFiles[] = $relativePath;
    } else {
        $missingFiles[] = $relativePath;
    }
}

foreach ($optionalFiles as $relativePath) {
    if (is_file(absolutePath($baseDir, $relativePath))) {
        $existingOptionalFiles[] = $relativePath;
    } else {
        $missingOptionalFiles[] = $relativePath;
    }
}

foreach ($requiredDirs as $relativePath) {
    if (is_dir(absolutePath($baseDir, $relativePath))) {
        $existingDirs[] = $relativePath;
    } else {
        $missingDirs[] = $relativePath;
    }
}

$classChecks = array();
$missingClasses = array();
$missingMethods = array();

foreach ($classSpecs as $spec) {
    $filePath = absolutePath($baseDir, $spec['file']);
    $classExistsInFile = fileContainsClass($filePath, $spec['short']);

    $classChecks[$spec['fqcn']] = array(
        'file' => $spec['file'],
        'class_found_in_file' => $classExistsInFile,
        'missing_methods' => array(),
    );

    if (!$classExistsInFile) {
        $missingClasses[] = $spec['fqcn'];
        continue;
    }

    foreach ($spec['methods'] as $methodName) {
        if (fileContainsFunction($filePath, $methodName)) {
            continue;
        }

        $classChecks[$spec['fqcn']]['missing_methods'][] = $methodName;
        $missingMethods[] = $spec['fqcn'] . '::' . $methodName;
    }
}

$handlerPath = absolutePath($baseDir, 'call_payload_handler.php');
$handlerMissingFunctions = array();
$handlerFunctionsFound = array();

foreach ($handlerFunctionsExpected as $functionName) {
    if (fileContainsFunction($handlerPath, $functionName)) {
        $handlerFunctionsFound[] = $functionName;
    } else {
        $handlerMissingFunctions[] = $functionName;
    }
}

$ok = empty($missingFiles)
    && empty($missingDirs)
    && empty($missingClasses)
    && empty($missingMethods)
    && empty($handlerMissingFunctions);

$response = array(
    'ok' => $ok,
    'message' => $ok ? 'ok!' : 'missing required files/directories or methods',
    'summary' => array(
        'checked_files' => count($requiredFiles),
        'checked_optional_files' => count($optionalFiles),
        'checked_dirs' => count($requiredDirs),
        'missing_files' => count($missingFiles),
        'missing_optional_files' => count($missingOptionalFiles),
        'missing_dirs' => count($missingDirs),
        'checked_classes' => count($classSpecs),
        'missing_classes' => count($missingClasses),
        'missing_methods' => count($missingMethods),
        'handler_missing_functions' => count($handlerMissingFunctions),
    ),
    'missing' => array(
        'files' => $missingFiles,
        'optional_files' => $missingOptionalFiles,
        'dirs' => $missingDirs,
        'classes' => $missingClasses,
        'methods' => $missingMethods,
        'handler_functions' => $handlerMissingFunctions,
    ),
);

if ($verbose) {
    $response['existing'] = array(
        'files' => $existingFiles,
        'optional_files' => $existingOptionalFiles,
        'dirs' => $existingDirs,
    );
    $response['methods'] = array(
        'class_checks' => $classChecks,
        'handler_functions_found' => $handlerFunctionsFound,
        'handler_functions_expected' => $handlerFunctionsExpected,
    );
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
