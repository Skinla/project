<?php
/**
 * Обработка очереди недозвона (расписание в JSON-файлах).
 * Выборка cycle по NEXT_SLOT_AT <= now, для каждого — чтение JSON, звонок по текущему слоту, обновление файла и NEXT_SLOT_AT.
 * Размещение: local/handlers/dozvon/process_queue.php.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';
require_once __DIR__ . '/DozvonLogic.php';
require_once __DIR__ . '/DozvonScheduleStorage.php';

$DOZVON_CONFIG = $GLOBALS['DOZVON_CONFIG'];
$listId = $DOZVON_CONFIG['DOZVON_LIST_ID'];
$batchSize = $DOZVON_CONFIG['CALL_QUEUE_BATCH_SIZE'];
$retryDelayMinutes = $DOZVON_CONFIG['RETRY_DELAY_MINUTES'];
$webhookUrl = $DOZVON_CONFIG['CALL_WEBHOOK_URL'];
$poolLeads = $DOZVON_CONFIG['CALL_POOL_LEADS'];
$poolCarousel = $DOZVON_CONFIG['CALL_POOL_CAROUSEL'];
$schedulesPath = $DOZVON_CONFIG['DOZVON_SCHEDULES_PATH'] ?? __DIR__ . '/schedules';

$helper = new DozvonListHelper($listId);
$logic = new DozvonLogic($DOZVON_CONFIG['CYCLE_LAST_DAY_DEFAULT']);
$scheduleStorage = new DozvonScheduleStorage($schedulesPath);

$callWebhookPath = realpath(__DIR__ . '/../../lib/Api/CallWebhookClient.php') ?: realpath(__DIR__ . '/CallWebhookClient.php');
if ($callWebhookPath && is_file($callWebhookPath)) {
    require_once $callWebhookPath;
}
$hasWebhookClient = class_exists('\Dozvon\Automation\Api\CallWebhookClient');

/**
 * Запись попытки автоматического звонка в карточку лида (ТЗ п. 5).
 */
function dozvon_log_attempt_to_lead_card(int $leadId, string $attemptedAt, string $fromNumber): void
{
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        return;
    }
    $responsibleId = 1;
    if (class_exists('\Bitrix\Crm\LeadTable')) {
        $row = \Bitrix\Crm\LeadTable::getById($leadId)->fetch();
        if ($row && !empty($row['ASSIGNED_BY_ID'])) {
            $responsibleId = (int)$row['ASSIGNED_BY_ID'];
        }
    }
    $ownerTypeId = defined('\CCrmOwnerType::Lead') ? (int)\CCrmOwnerType::Lead : 1;
    $typeId = defined('\CCrmActivityType::Call') ? (int)\CCrmActivityType::Call : 2;
    $dateStr = date('d.m.Y H:i', strtotime($attemptedAt));
    $subject = 'Попытка автоматического звонка';
    $description = sprintf(
        "Дата: %s\nНомер с которого звонили: %s\nСтатус: Не отвечен",
        $dateStr,
        $fromNumber
    );
    $fields = [
        'OWNER_TYPE_ID' => $ownerTypeId,
        'OWNER_ID' => $leadId,
        'TYPE_ID' => $typeId,
        'SUBJECT' => $subject,
        'DESCRIPTION' => $description,
        'START_TIME' => $attemptedAt,
        'COMPLETED' => 'N',
        'RESPONSIBLE_ID' => $responsibleId,
    ];
    if (class_exists('\CCrmActivity')) {
        \CCrmActivity::Add($fields);
    } elseif (class_exists('\Bitrix\Crm\ActivityTable')) {
        try {
            \Bitrix\Crm\ActivityTable::add($fields);
        } catch (\Exception $e) {
            // ignore
        }
    }
}

$now = (new DateTime())->format('Y-m-d\TH:i:s');

$cycleRecords = $helper->getElements([
    'RECORD_TYPE' => 'cycle',
    'IN_CYCLE' => 'Y',
], $batchSize);

$processed = 0;
$errors = [];

foreach ($cycleRecords as $rec) {
    $nextSlotAt = trim((string)($rec['NEXT_SLOT_AT'] ?? ''));
    if ($nextSlotAt === '' || $nextSlotAt > $now) {
        continue;
    }

    $cycleElementId = (int)$rec['ID'];
    $leadId = (int)$rec['LEAD_ID'];
    $filename = trim((string)($rec['SCHEDULE_FILENAME'] ?? ''));
    $phone = trim((string)($rec['PHONE'] ?? ''));

    if ($filename === '') {
        $helper->updateElement($cycleElementId, ['IN_CYCLE' => 'N']);
        continue;
    }
    if ($phone === '') {
        $errors[] = "Lead {$leadId}: phone empty";
        continue;
    }

    $data = $scheduleStorage->read($filename);
    if ($data === null || empty($data['slots'])) {
        $scheduleStorage->delete($filename);
        $helper->updateElement($cycleElementId, ['IN_CYCLE' => 'N', 'NEXT_SLOT_AT' => '']);
        continue;
    }

    $due = DozvonScheduleStorage::findNextDueSlot($data['slots'], $now);
    if ($due === null) {
        $nextAt = DozvonScheduleStorage::computeNextSlotAt($data['slots'], $now);
        $helper->updateElement($cycleElementId, ['NEXT_SLOT_AT' => $nextAt]);
        if ($nextAt === '') {
            $scheduleStorage->delete($filename);
            $helper->updateElement($cycleElementId, ['IN_CYCLE' => 'N']);
        }
        continue;
    }

    $cycleDay = $due['cycle_day'];
    $poolName = $logic->getPoolNameByCycleDay($cycleDay);
    $fromNumber = $poolName === 'Лиды' ? $poolLeads : $poolCarousel;

    $callSuccess = false;
    if ($hasWebhookClient) {
        $client = new \Dozvon\Automation\Api\CallWebhookClient($webhookUrl, ['Лиды' => $poolLeads, 'Карусель Лиды' => $poolCarousel]);
        $result = $client->makeCall($phone, $poolName, 'Звоним новому лиду', null, null);
        $callSuccess = !empty($result['success']);
    }

    dozvon_log_attempt_to_lead_card($leadId, $now, $fromNumber);

    $idx = $due['index'];
    if ($callSuccess) {
        $data['slots'][$idx]['status'] = 'processed';
        $data['slots'][$idx]['attempted_at'] = $now;
        $processed++;
    } else {
        $retryAt = (new DateTime('+' . $retryDelayMinutes . ' minutes'))->format('Y-m-d\TH:i:s');
        $data['slots'][$idx]['status'] = 'retry';
        $data['slots'][$idx]['attempted_at'] = $now;
        $data['slots'][$idx]['retry_at'] = $retryAt;
        $errors[] = $hasWebhookClient ? "Lead {$leadId}: call failed" : "Lead {$leadId}: webhook client not loaded";
    }

    $nextAt = DozvonScheduleStorage::computeNextSlotAt($data['slots'], $now);
    if (!$scheduleStorage->write($filename, $data)) {
        $errors[] = "Lead {$leadId}: failed to save schedule";
        continue;
    }
    $helper->updateElement($cycleElementId, ['NEXT_SLOT_AT' => $nextAt]);
    if ($nextAt === '') {
        $scheduleStorage->delete($filename);
        $helper->updateElement($cycleElementId, ['IN_CYCLE' => 'N']);
    }
}

if (function_exists('dozvon_log')) {
    dozvon_log('process_queue', ['processed' => $processed, 'errors_count' => count($errors), 'errors' => $errors]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'processed' => $processed, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
