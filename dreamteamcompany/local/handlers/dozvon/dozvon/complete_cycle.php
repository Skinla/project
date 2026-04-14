<?php
/**
 * Завершение цикла недозвона.
 * Размещение: local/handlers/dozvon/complete_cycle.php.
 * По расписанию (cron раз в час/день): лиды с завершённым циклом → «Некачественный лид», отмена оставшихся записей очереди.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';
require_once __DIR__ . '/DozvonScheduleStorage.php';

$DOZVON_CONFIG = $GLOBALS['DOZVON_CONFIG'];
$listId = $DOZVON_CONFIG['DOZVON_LIST_ID'];
$statusPoor = $DOZVON_CONFIG['LEAD_STATUS_POOR'];
$schedulesPath = $DOZVON_CONFIG['DOZVON_SCHEDULES_PATH'] ?? __DIR__ . '/schedules';

$helper = new DozvonListHelper($listId);
$scheduleStorage = new DozvonScheduleStorage($schedulesPath);

$cycleRecords = $helper->getElements(['RECORD_TYPE' => 'cycle', 'IN_CYCLE' => 'Y'], 500);

$completed = 0;
$errors = [];
$now = new DateTime();

foreach ($cycleRecords as $rec) {
    $leadId = (int)$rec['LEAD_ID'];
    $lastScheduled = $rec['LAST_SCHEDULED_CALL_DATE'] ?? '';
    $cycleLastDay = (int)($rec['CYCLE_LAST_DAY'] ?? 21);
    $cycleEndDate = $rec['CYCLE_END_DATE'] ?? '';
    $cycleDay = (int)($rec['CYCLE_DAY'] ?? 0);

    $shouldComplete = false;
    if ($lastScheduled !== '') {
        try {
            $lastDt = new DateTime($lastScheduled);
            if ($now > $lastDt) {
                $shouldComplete = true;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!$shouldComplete && $cycleEndDate !== '') {
        try {
            $endDt = new DateTime($cycleEndDate . ' 23:59:59');
            if ($now > $endDt) {
                $shouldComplete = true;
            }
        } catch (Exception $e) {
            // ignore
        }
    }
    if (!$shouldComplete && $cycleLastDay === 10 && $cycleDay >= 10) {
        $shouldComplete = true;
    }

    if (!$shouldComplete) {
        continue;
    }

    if (\Bitrix\Main\Loader::includeModule('crm')) {
        if (class_exists('\Bitrix\Crm\LeadTable')) {
            $r = \Bitrix\Crm\LeadTable::update($leadId, ['STATUS_ID' => $statusPoor]);
            if (!$r->isSuccess()) {
                $errors[] = 'Lead ' . $leadId . ': ' . implode(', ', $r->getErrorMessages());
                continue;
            }
        } elseif (class_exists('\CCrmLead')) {
            $entity = new \CCrmLead(false);
            $ok = $entity->Update($leadId, ['STATUS_ID' => $statusPoor]);
            if (!$ok) {
                $errors[] = 'Lead ' . $leadId . ': update failed';
                continue;
            }
        } else {
            $errors[] = 'Lead ' . $leadId . ': CRM update not available';
            continue;
        }
    } else {
        $errors[] = 'Lead ' . $leadId . ': CRM module not loaded';
        continue;
    }

    $helper->cancelQueueByLeadId($leadId, $scheduleStorage);
    $completed++;
}

if (function_exists('dozvon_log')) {
    dozvon_log('complete_cycle', ['completed' => $completed, 'errors' => $errors]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'completed' => $completed, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
