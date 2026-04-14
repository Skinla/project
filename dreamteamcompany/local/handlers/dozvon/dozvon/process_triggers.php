<?php
/**
 * Обработка триггеров недозвона (вариант B).
 * Размещение: local/handlers/dozvon/process_triggers.php.
 * Cron по интервалу из config (PROCESS_TRIGGERS_CRON_INTERVAL_SECONDS): выборка элементов списка с RECORD_TYPE=trigger и пустым PROCESSED_AT,
 * для каждого — создание очереди и цикла через DozvonLogic::createQueueForLead, пометка триггера PROCESSED_AT.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';
require_once __DIR__ . '/DozvonLogic.php';
require_once __DIR__ . '/DozvonScheduleStorage.php';

$DOZVON_CONFIG = $GLOBALS['DOZVON_CONFIG'];
$listId = $DOZVON_CONFIG['DOZVON_LIST_ID'];
$cycleLastDay = $DOZVON_CONFIG['CYCLE_LAST_DAY_DEFAULT'];
$schedulesPath = $DOZVON_CONFIG['DOZVON_SCHEDULES_PATH'] ?? __DIR__ . '/schedules';

$helper = new DozvonListHelper($listId);
$logic = new DozvonLogic($cycleLastDay);
$scheduleStorage = new DozvonScheduleStorage($schedulesPath);

function dozvon_has_cycle(DozvonListHelper $helper, int $leadId): bool
{
    $items = $helper->getElements(['RECORD_TYPE' => 'cycle', 'LEAD_ID' => $leadId], 1);
    return count($items) > 0;
}

$triggerItems = $helper->getElements(['RECORD_TYPE' => 'trigger'], 50);
$toProcess = [];
foreach ($triggerItems as $item) {
    $processedAt = $item['PROCESSED_AT'] ?? '';
    if ($processedAt === '' || $processedAt === null) {
        $toProcess[] = $item;
    }
}

$processed = 0;
$errors = [];
$now = (new DateTime())->format('Y-m-d\TH:i:s');

foreach ($toProcess as $item) {
    $elementId = (int)$item['ID'];
    $leadId = (int)$item['LEAD_ID'];
    $dateCreateStr = trim((string)($item['LEAD_DATE_CREATE'] ?? ''));
    $phone = trim((string)($item['PHONE'] ?? ''));

    if ($dateCreateStr === '') {
        $errors[] = "Trigger {$elementId}: LEAD_DATE_CREATE empty";
        continue;
    }
    if ($phone === '') {
        $errors[] = "Trigger {$elementId}: PHONE empty";
        continue;
    }

    if (dozvon_has_cycle($helper, $leadId)) {
        $helper->updateElement($elementId, ['PROCESSED_AT' => $now]);
        continue;
    }

    try {
        $dateCreate = new DateTime($dateCreateStr);
    } catch (Exception $e) {
        $errors[] = "Trigger {$elementId}: invalid LEAD_DATE_CREATE";
        continue;
    }

    $undialAt = new DateTime();
    $result = $logic->createQueueForLead($leadId, $dateCreate, $undialAt, $helper, null, $phone, $scheduleStorage, $elementId);

    if (!empty($result['error'])) {
        $errors[] = "Trigger {$elementId}: " . $result['error'];
        continue;
    }

    $processed++;
}

if (function_exists('dozvon_log')) {
    dozvon_log('process_triggers', ['processed' => $processed, 'errors_count' => count($errors), 'errors' => $errors]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'processed' => $processed, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
