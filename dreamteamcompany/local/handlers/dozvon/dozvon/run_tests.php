<?php
/**
 * Тестовый скрипт: проверка наличия файлов, загрузки классов и методов.
 * Размещение: local/handlers/dozvon/run_tests.php.
 * Запуск: в браузере под админом или из CLI: php run_tests.php [DOCUMENT_ROOT]
 * Без Bitrix проверяются только файлы и синтаксис PHP; с Bitrix (DOCUMENT_ROOT задан) — классы и методы.
 */

$isCli = (php_sapi_name() === 'cli');
if ($isCli && (empty($_SERVER['DOCUMENT_ROOT']) || !is_dir($_SERVER['DOCUMENT_ROOT']))) {
    $docRoot = $argv[1] ?? __DIR__ . '/../../../..';
    if (!is_dir($docRoot)) {
        echo "Usage: php run_tests.php [DOCUMENT_ROOT]\n";
        echo "Example: php run_tests.php /var/www/site\n";
        exit(1);
    }
    $_SERVER['DOCUMENT_ROOT'] = realpath($docRoot);
}

$results = ['ok' => true, 'checks' => [], 'errors' => []];

function addCheck(array &$results, string $name, bool $passed, string $detail = ''): void
{
    $results['checks'][$name] = $passed ? 'ok' : 'fail';
    if (!$passed && $detail !== '') {
        $results['errors'][$name] = $detail;
    }
    if (!$passed) {
        $results['ok'] = false;
    }
}

$baseDir = __DIR__;
$rootDir = dirname(__DIR__);

// --- 1. Наличие обязательных файлов ---
$rootFiles = [
    'bootstrap.php',
    'config.php',
    'dozvon_trigger.php',
];
$requiredFiles = [
    'DozvonListHelper.php',
    'DozvonCrmHelper.php',
    'DozvonLogic.php',
    'DozvonScheduleStorage.php',
    'DozvonModule2Schema.php',
    'DozvonRestWebhookClient.php',
    'DozvonModule2ListProvisioner.php',
    'DozvonUniversalListHelper.php',
    'DozvonModule2Schedule.php',
    'DozvonModule2Helper.php',
    'DozvonModule2BpHelper.php',
    'process_triggers.php',
    'process_queue.php',
    'complete_cycle.php',
    'messenger_handler.php',
    'inspect_list.php',
    'create_module2_lists.php',
    'bp_module2_generate_queue.txt',
];

foreach ($rootFiles as $file) {
    $path = $rootDir . '/' . $file;
    addCheck($results, "file:{$file}", is_file($path), is_file($path) ? '' : "File not found: {$path}");
}
foreach ($requiredFiles as $file) {
    $path = $baseDir . '/' . $file;
    addCheck($results, "file:{$file}", is_file($path), is_file($path) ? '' : "File not found: {$path}");
}

// --- 2. Синтаксис PHP ---
$phpFiles = array_merge($rootFiles, $requiredFiles);
foreach ($phpFiles as $file) {
    $path = in_array($file, $rootFiles, true) ? $rootDir . '/' . $file : $baseDir . '/' . $file;
    if (!is_file($path)) {
        continue;
    }
    $syntaxOk = false;
    $syntaxError = '';
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $out = [];
        $ret = -1;
        @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $ret);
        $syntaxOk = ($ret === 0);
        $syntaxError = implode(' ', $out);
    } else {
        $code = @file_get_contents($path);
        if ($code !== false) {
            @token_get_all($code);
            $syntaxOk = true;
        }
    }
    addCheck($results, "syntax:{$file}", $syntaxOk, $syntaxOk ? '' : $syntaxError);
}

// --- 3. Конфиг (без Bitrix) ---
$configPath = $rootDir . '/config.php';
if (is_file($configPath)) {
    $config = @include $configPath;
    $configOk = is_array($config);
    addCheck($results, 'config:load', $configOk, $configOk ? '' : 'config.php must return array');
    if ($configOk) {
        $requiredKeys = [
            'DOZVON_LIST_ID', 'DOZVON_REL_PATH', 'DOZVON_ROOT',
            'CALL_WEBHOOK_URL', 'CALL_POOL_LEADS', 'CALL_POOL_CAROUSEL',
            'LEAD_STATUS_NEDOZVON', 'LEAD_STATUS_POOR', 'LEAD_STATUS_NOT_RECORDED',
            'CYCLE_LAST_DAY_DEFAULT', 'CALL_QUEUE_BATCH_SIZE', 'RETRY_DELAY_MINUTES',
            'PROCESS_TRIGGERS_CRON_INTERVAL_SECONDS', 'PROCESS_QUEUE_CRON_INTERVAL_SECONDS', 'COMPLETE_CYCLE_CRON_INTERVAL_SECONDS',
            'DOZVON_SCHEDULES_PATH',
            'MODULE2_LISTS_IBLOCK_TYPE_ID', 'MODULE2_MASTER_LIST_CODE', 'MODULE2_ATTEMPTS_LIST_CODE',
            'MODULE2_MASTER_LIST_NAME', 'MODULE2_ATTEMPTS_LIST_NAME', 'MODULE2_LISTS_WEBHOOK_BASE_URL',
            'MODULE2_LISTS_RIGHTS', 'MODULE2_WORKING_HOURS',
        ];
        foreach ($requiredKeys as $key) {
            $has = array_key_exists($key, $config);
            addCheck($results, "config:{$key}", $has, $has ? '' : "Missing key: {$key}");
        }
    }
}

// --- 4. Bitrix: загрузка классов и проверка методов ---
$bitrixLoaded = false;
if (!empty($_SERVER['DOCUMENT_ROOT']) && is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
    try {
        require_once $rootDir . '/bootstrap.php';
        $bitrixLoaded = defined('B_PROLOG_INCLUDED') && B_PROLOG_INCLUDED === true;
    } catch (Throwable $e) {
        addCheck($results, 'bootstrap', false, $e->getMessage());
    }
}

if ($bitrixLoaded) {
    addCheck($results, 'bootstrap', true);

    require_once $baseDir . '/DozvonListHelper.php';
    require_once $baseDir . '/DozvonCrmHelper.php';
    require_once $baseDir . '/DozvonLogic.php';
    require_once $baseDir . '/DozvonScheduleStorage.php';
    require_once $baseDir . '/DozvonModule2Schema.php';
    require_once $baseDir . '/DozvonRestWebhookClient.php';
    require_once $baseDir . '/DozvonModule2ListProvisioner.php';
    require_once $baseDir . '/DozvonUniversalListHelper.php';
    require_once $baseDir . '/DozvonModule2Schedule.php';
    require_once $baseDir . '/DozvonModule2Helper.php';
    require_once $baseDir . '/DozvonModule2BpHelper.php';

    // DozvonListHelper
    if (class_exists('DozvonListHelper')) {
        addCheck($results, 'class:DozvonListHelper', true);
        $listMethods = ['getElements', 'getElementById', 'addElement', 'updateElement', 'cancelQueueByLeadId'];
        foreach ($listMethods as $m) {
            addCheck($results, "DozvonListHelper::{$m}", method_exists('DozvonListHelper', $m));
        }
    } else {
        addCheck($results, 'class:DozvonListHelper', false, 'Class not loaded');
    }

    // DozvonLogic
    if (class_exists('DozvonLogic')) {
        addCheck($results, 'class:DozvonLogic', true);
        $logicMethods = [
            'getCycleDayFromDateCreate', 'getLeadTypeByUndialTime', 'getPoolNameByCycleDay',
            'getSlotsForDayAndType', 'buildAllSlots', 'getCycleEndDate', 'createQueueForLead',
        ];
        foreach ($logicMethods as $m) {
            addCheck($results, "DozvonLogic::{$m}", method_exists('DozvonLogic', $m));
        }
    } else {
        addCheck($results, 'class:DozvonLogic', false, 'Class not loaded');
    }

    // DozvonScheduleStorage
    if (class_exists('DozvonScheduleStorage')) {
        addCheck($results, 'class:DozvonScheduleStorage', true);
        $storageMethods = ['filenameForLead', 'pathForFilename', 'read', 'write', 'delete', 'ensureDirectory'];
        foreach ($storageMethods as $m) {
            addCheck($results, "DozvonScheduleStorage::{$m}", method_exists('DozvonScheduleStorage', $m));
        }
        $storageStaticMethods = ['findNextDueSlot', 'computeNextSlotAt'];
        foreach ($storageStaticMethods as $m) {
            addCheck($results, "DozvonScheduleStorage::{$m}", method_exists('DozvonScheduleStorage', $m));
        }
    } else {
        addCheck($results, 'class:DozvonScheduleStorage', false, 'Class not loaded');
    }

    // dozvon_get_lead_phone
    addCheck($results, 'function:dozvon_get_lead_phone', function_exists('dozvon_get_lead_phone'));

    addCheck($results, 'class:DozvonModule2Schema', class_exists('DozvonModule2Schema'));
    addCheck($results, 'class:DozvonRestWebhookClient', class_exists('DozvonRestWebhookClient'));
    addCheck($results, 'class:DozvonModule2ListProvisioner', class_exists('DozvonModule2ListProvisioner'));
    addCheck($results, 'class:DozvonUniversalListHelper', class_exists('DozvonUniversalListHelper'));
    addCheck($results, 'class:DozvonModule2Schedule', class_exists('DozvonModule2Schedule'));
    addCheck($results, 'class:DozvonModule2Helper', class_exists('DozvonModule2Helper'));
    addCheck($results, 'class:DozvonModule2BpHelper', class_exists('DozvonModule2BpHelper'));

    // dozvon_log (из bootstrap)
    addCheck($results, 'function:dozvon_log', function_exists('dozvon_log'));

    // --- Список: доступ и enum RECORD_TYPE ---
    $config = is_file($rootDir . '/config.php') ? (array)include $rootDir . '/config.php' : [];
    $listId = (int)($config['DOZVON_LIST_ID'] ?? 0);
    if ($listId > 0) {
        try {
            $helper = new DozvonListHelper($listId);
            $items = $helper->getElements(['RECORD_TYPE' => 'cycle'], 1);
            addCheck($results, 'list:getElements:cycle', is_array($items), is_array($items) ? '' : 'getElements must return array');
            if (!empty($items)) {
                $first = reset($items);
                $recType = $first['RECORD_TYPE'] ?? '';
                addCheck($results, 'list:RECORD_TYPE:cycle:value', $recType === 'cycle', $recType === 'cycle' ? '' : "RECORD_TYPE value is '{$recType}', expected 'cycle'");
            }
            $ref = new ReflectionClass($helper);
            $method = $ref->getMethod('getListPropertyEnumId');
            $method->setAccessible(true);
            $enumId = $method->invoke($helper, 'RECORD_TYPE', 'cycle');
            addCheck($results, 'list:enum:RECORD_TYPE:cycle', is_int($enumId) && $enumId > 0, is_int($enumId) && $enumId > 0 ? '' : 'getListPropertyEnumId(RECORD_TYPE, cycle) must return positive int');
            $enumTrigger = $method->invoke($helper, 'RECORD_TYPE', 'trigger');
            addCheck($results, 'list:enum:RECORD_TYPE:trigger', is_int($enumTrigger) && $enumTrigger > 0, is_int($enumTrigger) && $enumTrigger > 0 ? '' : 'getListPropertyEnumId(RECORD_TYPE, trigger) must return positive int');
        } catch (Throwable $e) {
            addCheck($results, 'list:integration', false, $e->getMessage());
        }
    } else {
        addCheck($results, 'list:getElements:cycle', false, 'DOZVON_LIST_ID not set');
    }
} else {
    addCheck($results, 'bootstrap', false, 'DOCUMENT_ROOT not set or Bitrix not available — skip class/method checks');
}

// --- 5. DozvonLogic (unit, без Bitrix) ---
if (!class_exists('DozvonLogic')) {
    require_once $baseDir . '/DozvonLogic.php';
}
if (class_exists('DozvonLogic')) {
    $logic = new DozvonLogic(21);
    $today = new DateTime('today');
    $day1 = $logic->getCycleDayFromDateCreate($today);
    addCheck($results, 'logic:getCycleDayFromDateCreate:today', $day1 === 1, $day1 === 1 ? '' : "expected 1, got {$day1}");
    $yesterday = (clone $today)->modify('-1 day');
    $day2 = $logic->getCycleDayFromDateCreate($yesterday, $today);
    addCheck($results, 'logic:getCycleDayFromDateCreate:yesterday', $day2 === 2, $day2 === 2 ? '' : "expected 2, got {$day2}");
    $morning = new DateTime('today 09:30');
    addCheck($results, 'logic:getLeadTypeByUndialTime:morning', $logic->getLeadTypeByUndialTime($morning) === 'morning', '09:30 must be morning');
    $dayTime = new DateTime('today 12:00');
    addCheck($results, 'logic:getLeadTypeByUndialTime:day', $logic->getLeadTypeByUndialTime($dayTime) === 'day', '12:00 must be day');
    addCheck($results, 'logic:getPoolNameByCycleDay:1', $logic->getPoolNameByCycleDay(1) === 'Лиды', 'day 1 = Лиды');
    addCheck($results, 'logic:getPoolNameByCycleDay:4', $logic->getPoolNameByCycleDay(4) === 'Карусель Лиды', 'day 4 = Карусель Лиды');
    $leadCreate = new DateTime('2025-01-01 10:00:00');
    $slots1 = $logic->getSlotsForDayAndType(1, 'morning', $leadCreate, 21);
    addCheck($results, 'logic:getSlotsForDayAndType:day1:morning:count', count($slots1) === 4, 'day 1 morning = 4 slots');
    $slots10 = $logic->getSlotsForDayAndType(10, 'day', $leadCreate, 21);
    addCheck($results, 'logic:getSlotsForDayAndType:day10', count($slots10) === 1 && ($slots10[0]['time'] ?? '') === '15:15', 'day 10 = one slot 15:15');
    $allSlots = $logic->buildAllSlots($leadCreate, 'day', 21);
    addCheck($results, 'logic:buildAllSlots:count', count($allSlots) > 0, 'buildAllSlots must return slots');
    $firstSlot = $allSlots[0] ?? [];
    addCheck($results, 'logic:buildAllSlots:first:scheduledAt', !empty($firstSlot['scheduledAt']), 'first slot must have scheduledAt');
    $cycleEnd = $logic->getCycleEndDate($leadCreate, 21);
    addCheck($results, 'logic:getCycleEndDate', $cycleEnd === '2025-01-21', "expected 2025-01-21, got {$cycleEnd}");
}

// --- 6. DozvonScheduleStorage (unit, без Bitrix) ---
if (!class_exists('DozvonScheduleStorage')) {
    require_once $baseDir . '/DozvonScheduleStorage.php';
}
if (class_exists('DozvonScheduleStorage')) {
    $tmpDir = sys_get_temp_dir() . '/dozvon_test_' . getmypid();
    $storage = new DozvonScheduleStorage($tmpDir);
    addCheck($results, 'storage:filenameForLead', $storage->filenameForLead(123) === 'lead_123.json', 'filenameForLead(123) = lead_123.json');
    addCheck($results, 'storage:pathForFilename', strpos($storage->pathForFilename('lead_1.json'), 'lead_1.json') !== false, 'pathForFilename contains filename');
    addCheck($results, 'storage:ensureDirectory', $storage->ensureDirectory() && is_dir($tmpDir), 'ensureDirectory creates dir');
    $testData = ['lead_id' => 999, 'created_at' => '2025-01-01T00:00:00', 'slots' => [
        ['scheduled_at' => '2025-01-01T10:00:00', 'cycle_day' => 1, 'status' => 'pending', 'attempted_at' => null, 'retry_at' => null],
        ['scheduled_at' => '2025-01-01T12:00:00', 'cycle_day' => 1, 'status' => 'processed', 'attempted_at' => '2025-01-01T10:05:00', 'retry_at' => null],
    ]];
    $written = $storage->write('lead_999.json', $testData);
    addCheck($results, 'storage:write', $written, 'write must succeed');
    $read = $storage->read('lead_999.json');
    addCheck($results, 'storage:read', $read !== null && isset($read['slots']) && count($read['slots']) === 2, 'read returns same structure');
    $next = DozvonScheduleStorage::findNextDueSlot($read['slots'], '2025-01-01T11:00:00');
    addCheck($results, 'storage:findNextDueSlot', $next !== null && ($next['scheduled_at'] ?? '') === '2025-01-01T10:00:00', 'findNextDueSlot finds first pending due (scheduled_at <= now)');
    $nextAt = DozvonScheduleStorage::computeNextSlotAt($read['slots'], '2025-01-01T09:00:00');
    addCheck($results, 'storage:computeNextSlotAt', $nextAt === '2025-01-01T10:00:00', 'computeNextSlotAt returns first pending time');
    $deleted = $storage->delete('lead_999.json');
    addCheck($results, 'storage:delete', $deleted && !is_file($storage->pathForFilename('lead_999.json')), 'delete removes file');
    @rmdir($tmpDir);
}

// --- 7. Module2 schema/schedule (unit, без Bitrix) ---
if (!class_exists('DozvonModule2Schema')) {
    require_once $baseDir . '/DozvonModule2Schema.php';
}
if (!class_exists('DozvonModule2Schedule')) {
    require_once $baseDir . '/DozvonModule2Schedule.php';
}
if (class_exists('DozvonModule2Schema')) {
    addCheck($results, 'module2:schema:master:fields', count(DozvonModule2Schema::masterListFields()) > 10, 'master list fields must be defined');
    addCheck($results, 'module2:schema:attempt:fields', count(DozvonModule2Schema::attemptListFields(1)) > 10, 'attempt list fields must be defined');
    addCheck($results, 'module2:schema:attempt:operator', in_array('OPERATOR_ID', DozvonModule2Schema::attemptPropertyCodes(), true), 'OPERATOR_ID must exist');
    addCheck($results, 'module2:schema:attempt:call_id', in_array('CALL_ID', DozvonModule2Schema::attemptPropertyCodes(), true), 'CALL_ID must exist');
}
if (class_exists('DozvonModule2Schedule')) {
    $module2Schedule = new DozvonModule2Schedule(['default' => ['start' => '09:00', 'end' => '18:00']]);
    $startDate = new DateTimeImmutable('2025-01-01 12:00:00');
    $plan = $module2Schedule->buildPlan($startDate, 'default');
    addCheck($results, 'module2:schedule:count', count($plan) === 25, 'module2 must create 25 attempts on 10-day cycle');
    addCheck($results, 'module2:schedule:first', ($plan[0]['scheduled_at'] ?? '') === '2025-01-01T11:00:00', 'first attempt must be 11:00 on day 1');
    $day10 = array_values(array_filter($plan, static fn(array $row): bool => (int)$row['cycle_day'] === 10));
    addCheck($results, 'module2:schedule:day10', count($day10) === 1 && ($day10[0]['scheduled_at'] ?? '') === '2025-01-10T16:00:00', 'day 10 must contain one attempt at 16:00');
}

// --- Вывод ---
if ($isCli) {
    echo ($results['ok'] ? "OK\n" : "FAIL\n");
    foreach ($results['checks'] as $name => $status) {
        echo "  [{$status}] {$name}\n";
    }
    if (!empty($results['errors'])) {
        echo "\nErrors:\n";
        foreach ($results['errors'] as $name => $msg) {
            echo "  {$name}: {$msg}\n";
        }
    }
    exit($results['ok'] ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
