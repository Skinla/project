<?php
// Обработчик события создания лида для запуска БП 224 - ПРОСТОЙ WEB СКРИПТ
AddEventHandler("crm", "OnAfterCrmLeadAdd", "OnAfterCrmLeadBP224SimpleWebHandler");

function OnAfterCrmLeadBP224SimpleWebHandler($arFields) {
    $leadId = $arFields['ID'];
    $logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/handlers/dreamteamcompany';
    $logFile = $logDir . '/lead_bp224_simple_web.log';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Логируем начало обработки
    $logData = [
        'datetime' => date('Y-m-d H:i:s'),
        'event' => 'OnAfterCrmLeadAdd',
        'lead_id' => $leadId,
        'status' => 'start'
    ];
    @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // Запускаем агент для БП 224 - ТОЧНО КАК В ДУБЛИКАТАХ
    CAgent::AddAgent("ProcessLeadBP224SimpleWebAgent($leadId);", "", "N", 0, "", "Y", "", 1);
}

// Агент для запуска БП 224 - ПРОСТОЙ WEB СКРИПТ
function ProcessLeadBP224SimpleWebAgent($leadId) {
    $logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/handlers/dreamteamcompany';
    $logFile = $logDir . '/lead_bp224_simple_web.log';

    // Ждем 3 секунды чтобы лид точно сохранился в базе данных - КАК В ДУБЛИКАТАХ
    sleep(3);

    global $DB;

    // Получаем лид из базы данных НАПРЯМУЮ - КАК В ДУБЛИКАТАХ
    $sqlResult = $DB->Query("SELECT ID, TITLE, STATUS_ID FROM b_crm_lead WHERE ID = " . intval($leadId));
    $lead = $sqlResult->Fetch();

    if (!$lead) {
        $logData = [
            'datetime' => date('Y-m-d H:i:s'),
            'event' => 'ProcessLeadBP224SimpleWebAgent',
            'lead_id' => $leadId,
            'status' => 'error',
            'error' => 'Лид не найден в базе данных'
        ];
        @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return "";
    }

    // Лид найден - логируем успех
    $logData = [
        'datetime' => date('Y-m-d H:i:s'),
        'event' => 'ProcessLeadBP224SimpleWebAgent',
        'lead_id' => $leadId,
        'status' => 'found_via_sql',
        'title' => $lead['TITLE']
    ];
    @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // Проверяем существование БП 224 через SQL - КАК В ДУБЛИКАТАХ
    $bpResult = $DB->Query("SELECT ID, NAME FROM b_bp_workflow_template WHERE ID = 224");
    $bp = $bpResult->Fetch();

    if (!$bp) {
        $logData = [
            'datetime' => date('Y-m-d H:i:s'),
            'event' => 'ProcessLeadBP224SimpleWebAgent',
            'lead_id' => $leadId,
            'status' => 'error',
            'error' => 'БП 224 не найден в базе данных'
        ];
        @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        return "";
    }

    // БП 224 найден - логируем успех
    $logData = [
        'datetime' => date('Y-m-d H:i:s'),
        'event' => 'ProcessLeadBP224SimpleWebAgent',
        'lead_id' => $leadId,
        'status' => 'bp_found',
        'bp_name' => $bp['NAME']
    ];
    @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // ЗАПУСКАЕМ БП 224 ЧЕРЕЗ ВНУТРЕННИЙ API (без временного CLI-файла)
    try {
        if (
            !\Bitrix\Main\Loader::includeModule('crm')
            || !\Bitrix\Main\Loader::includeModule('bizproc')
        ) {
            $logData = [
                'datetime' => date('Y-m-d H:i:s'),
                'event' => 'ProcessLeadBP224SimpleWebAgent',
                'lead_id' => $leadId,
                'status' => 'error',
                'error' => 'Не удалось подключить модули crm/bizproc'
            ];
            @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            return "";
        }

        // Для сервисного запуска в фоне используем системного пользователя.
        global $USER;
        if (!is_object($USER)) {
            $USER = new CUser();
        }
        if (!(int)$USER->GetID()) {
            $USER->Authorize(1);
        }

        $arErrorsTmp = [];
        $workflowId = CBPDocument::StartWorkflow(
            224,
            ["crm", "CCrmDocumentLead", "CRM_LEAD_" . (int)$leadId],
            ["LeadId" => (int)$leadId],
            $arErrorsTmp
        );

        if ($workflowId) {
            $logData = [
                'datetime' => date('Y-m-d H:i:s'),
                'event' => 'ProcessLeadBP224SimpleWebAgent',
                'lead_id' => $leadId,
                'status' => 'success',
                'workflow_id' => $workflowId,
                'message' => 'БП 224 успешно запущен через внутренний API'
            ];
            @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        } else {
            $logData = [
                'datetime' => date('Y-m-d H:i:s'),
                'event' => 'ProcessLeadBP224SimpleWebAgent',
                'lead_id' => $leadId,
                'status' => 'error',
                'error' => 'StartWorkflow вернул пустой workflowId',
                'bp_errors' => $arErrorsTmp
            ];
            @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
    } catch (Exception $e) {
        $logData = [
            'datetime' => date('Y-m-d H:i:s'),
            'event' => 'ProcessLeadBP224SimpleWebAgent',
            'lead_id' => $leadId,
            'status' => 'error',
            'error' => 'Exception: ' . $e->getMessage()
        ];
        @file_put_contents($logFile, json_encode($logData, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    return "";
}
?>
