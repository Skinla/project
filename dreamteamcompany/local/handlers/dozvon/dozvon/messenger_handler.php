<?php
/**
 * Обработчик ответа по лиду в мессенджере (опционально).
 * Размещение: local/handlers/dozvon/messenger_handler.php.
 * Действия: отменить очередь по lead_id, перевести лид в «Не записан», создать дело (CRM-активность) «Позвонить через 1 час» на ответственного (ТЗ п. 2).
 * При вызове из БП: передать lead_id и assigned_by_id — запрос к лиду не выполняется.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/DozvonListHelper.php';
require_once __DIR__ . '/DozvonScheduleStorage.php';

$DOZVON_CONFIG = $GLOBALS['DOZVON_CONFIG'];
$listId = $DOZVON_CONFIG['DOZVON_LIST_ID'];
$statusNotRecorded = $DOZVON_CONFIG['LEAD_STATUS_NOT_RECORDED'];
$schedulesPath = $DOZVON_CONFIG['DOZVON_SCHEDULES_PATH'] ?? __DIR__ . '/schedules';

$leadIdParam = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : (isset($_POST['lead_id']) ? (int)$_POST['lead_id'] : 0);
$assignedByIdParam = isset($_GET['assigned_by_id']) ? (int)$_GET['assigned_by_id'] : (isset($_POST['assigned_by_id']) ? (int)$_POST['assigned_by_id'] : 0);

if ($leadIdParam <= 0) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'lead_id required']);
    return;
}

$helper = new DozvonListHelper($listId);
$scheduleStorage = new DozvonScheduleStorage($schedulesPath);
$helper->cancelQueueByLeadId($leadIdParam, $scheduleStorage);

$assignedById = $assignedByIdParam;

if (\Bitrix\Main\Loader::includeModule('crm')) {
    if ($assignedById <= 0) {
        if (class_exists('\Bitrix\Crm\LeadTable')) {
            $lead = \Bitrix\Crm\LeadTable::getById($leadIdParam)->fetch();
            if ($lead) {
                $assignedById = (int)($lead['ASSIGNED_BY_ID'] ?? 0);
            }
        } elseif (class_exists('\CCrmLead')) {
            $res = \CCrmLead::GetListEx([], ['ID' => $leadIdParam], false, false, ['ASSIGNED_BY_ID']);
            if ($row = $res->Fetch()) {
                $assignedById = (int)($row['ASSIGNED_BY_ID'] ?? 0);
            }
        }
    }

    if (class_exists('\Bitrix\Crm\LeadTable')) {
        \Bitrix\Crm\LeadTable::update($leadIdParam, ['STATUS_ID' => $statusNotRecorded]);
    } elseif (class_exists('\CCrmLead')) {
        $entity = new \CCrmLead(false);
        $entity->Update($leadIdParam, ['STATUS_ID' => $statusNotRecorded]);
    }

    if ($assignedById > 0) {
        dozvon_create_call_back_activity($leadIdParam, $assignedById);
    }
}

if (function_exists('dozvon_log')) {
    dozvon_log('messenger_handler', ['lead_id' => $leadIdParam]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'lead_id' => $leadIdParam], JSON_UNESCAPED_UNICODE);

/**
 * Создать дело (CRM-активность) «Позвонить через 1 час» на ответственного, привязка к лиду (ТЗ п. 2).
 */
function dozvon_create_call_back_activity(int $leadId, int $responsibleId): void
{
    if (!\Bitrix\Main\Loader::includeModule('crm')) {
        return;
    }
    $ownerTypeId = defined('\CCrmOwnerType::Lead') ? (int)\CCrmOwnerType::Lead : 1;
    $typeId = defined('\CCrmActivityType::Call') ? (int)\CCrmActivityType::Call : 2;
    $deadline = (new DateTime('+1 hour'))->format('Y-m-d\TH:i:s');
    $subject = 'Позвонить через 1 час';
    $description = 'Ответ по лиду в мессенджере. Лид ID: ' . $leadId;
    $fields = [
        'OWNER_TYPE_ID' => $ownerTypeId,
        'OWNER_ID' => $leadId,
        'TYPE_ID' => $typeId,
        'SUBJECT' => $subject,
        'DESCRIPTION' => $description,
        'START_TIME' => $deadline,
        'END_TIME' => $deadline,
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
