<?php
/**
 * Симуляция cron: обработка одного элемента-триггера по ID (та же логика, что в process_triggers.php).
 * Размещение: local/handlers/dozvon/run_process_trigger_once.php.
 * Запуск: в браузере под админом или из CLI.
 * Параметры: element_id (GET/POST или argv[1]) — ID элемента списка «Недозвон» с RECORD_TYPE=trigger.
 * Пример: run_process_trigger_once.php?element_id=2383266  или  php run_process_trigger_once.php 2383266
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';
require_once __DIR__ . '/DozvonLogic.php';
require_once __DIR__ . '/DozvonScheduleStorage.php';

$DOZVON_CONFIG = $GLOBALS['DOZVON_CONFIG'];
$listId = $DOZVON_CONFIG['DOZVON_LIST_ID'];
$cycleLastDay = $DOZVON_CONFIG['CYCLE_LAST_DAY_DEFAULT'];
$schedulesPath = $DOZVON_CONFIG['DOZVON_SCHEDULES_PATH'] ?? __DIR__ . '/schedules';

$elementIdParam = isset($_GET['element_id']) ? (int)$_GET['element_id'] : (isset($_POST['element_id']) ? (int)$_POST['element_id'] : 0);
if ($elementIdParam <= 0 && php_sapi_name() === 'cli' && !empty($argv[1])) {
    $elementIdParam = (int)$argv[1];
}
if ($elementIdParam <= 0) {
    $elementIdParam = 2383266;
}

$helper = new DozvonListHelper($listId);
$logic = new DozvonLogic($cycleLastDay);
$scheduleStorage = new DozvonScheduleStorage($schedulesPath);

function dozvon_has_cycle(DozvonListHelper $helper, int $leadId): bool
{
    $items = $helper->getElements(['RECORD_TYPE' => 'cycle', 'LEAD_ID' => $leadId], 1);
    return count($items) > 0;
}

$item = $helper->getElementById($elementIdParam);

$result = [
    'ok' => false,
    'element_id' => $elementIdParam,
    'element_found' => false,
    'record_type' => null,
    'processed_at_before' => null,
    'skipped' => null,
    'processed' => 0,
    'cycle_record_id' => null,
    'schedule_filename' => null,
    'schedule_path' => null,
    'schedule_file_exists' => null,
    'slots_count' => 0,
    'last_scheduled_call_date' => null,
    'errors' => [],
];

if ($item === null) {
    $result['errors'][] = "Element {$elementIdParam} not found or not in this list";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$result['element_found'] = true;
$result['record_type'] = $item['RECORD_TYPE'] ?? null;
$result['processed_at_before'] = $item['PROCESSED_AT'] ?? null;

$recordType = trim((string)($item['RECORD_TYPE'] ?? ''));
if ($recordType !== 'trigger') {
    $result['errors'][] = "Element is not a trigger (RECORD_TYPE={$recordType})";
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$elementId = (int)$item['ID'];
$leadId = (int)$item['LEAD_ID'];
$dateCreateStr = trim((string)($item['LEAD_DATE_CREATE'] ?? ''));
$phone = trim((string)($item['PHONE'] ?? ''));

if ($dateCreateStr === '') {
    $result['errors'][] = 'LEAD_DATE_CREATE empty';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}
if ($phone === '') {
    $result['errors'][] = 'PHONE empty';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

if (dozvon_has_cycle($helper, $leadId)) {
    $result['skipped'] = 'Lead already has cycle';
    $now = (new DateTime())->format('Y-m-d\TH:i:s');
    $helper->updateElement($elementId, ['PROCESSED_AT' => $now]);
    $result['ok'] = true;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

try {
    $dateCreate = new DateTime($dateCreateStr);
} catch (Exception $e) {
    $result['errors'][] = 'Invalid LEAD_DATE_CREATE: ' . $dateCreateStr;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

if (function_exists('dozvon_log')) {
    dozvon_log('run_process_trigger_once:start', [
        'element_id' => $elementIdParam,
        'lead_id' => $leadId,
        'schedules_path' => $schedulesPath,
        'schedules_path_real' => realpath($schedulesPath) ?: '(dir not exists or not resolved)',
    ]);
}

$undialAt = new DateTime();
$createResult = $logic->createQueueForLead($leadId, $dateCreate, $undialAt, $helper, null, $phone, $scheduleStorage, $elementId);

if (!empty($createResult['error'])) {
    $result['errors'][] = $createResult['error'];
    if (function_exists('dozvon_log')) {
        dozvon_log('run_process_trigger_once:error', ['element_id' => $elementIdParam, 'lead_id' => $leadId, 'error' => $createResult['error']]);
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return;
}

$schedulePath = $createResult['schedulePath'] ?? $scheduleStorage->pathForFilename($createResult['scheduleFilename'] ?? '');
$result['ok'] = true;
$result['processed'] = 1;
$result['cycle_record_id'] = $createResult['cycleRecordId'] ?? null;
$result['schedule_filename'] = $createResult['scheduleFilename'] ?? null;
$result['schedule_path'] = $schedulePath;
$result['schedule_file_exists'] = $schedulePath !== '' && is_file($schedulePath);
$result['slots_count'] = $createResult['slotsCount'] ?? 0;
$result['last_scheduled_call_date'] = $createResult['lastScheduledCallDate'] ?? null;

if (function_exists('dozvon_log')) {
    dozvon_log('run_process_trigger_once:ok', [
        'element_id' => $elementIdParam,
        'lead_id' => $leadId,
        'slots_count' => $result['slots_count'],
        'schedule_path' => $schedulePath,
        'file_exists' => $result['schedule_file_exists'],
    ]);
}

if ($result['schedule_file_exists'] === false) {
    $result['warning'] = 'Schedule file not found at path. Add list properties SCHEDULE_FILENAME (S), NEXT_SLOT_AT (DateTime). Check DOZVON_SCHEDULES_PATH and dozvon.log.';
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
