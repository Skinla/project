<?php
declare(strict_types=1);

return [
    'cloud' => [
        // Example: https://yourportal.bitrix24.ru/rest/<user_id>/<webhook_token>/
        'webhook_base' => 'https://crmmarketing25.bitrix24.ru/rest/21/dmrorztmyraz6tkz/',
    ],

    'log' => [
        'file' => __DIR__ . '/var/deal_to_lead_webhook.log',
        'max_bytes' => 2 * 1024 * 1024,
    ],

    'storage' => [
        'deal_lead_map_file' => __DIR__ . '/deal_lead_pairs.json',
    ],

    'iblock' => [
        'main_id' => 54,
        'source_list_id' => 19,
        'city_list_id' => 22,
        // Optional: direct source element id from list 19 for "Турбо-сайт".
        // If empty/0, handler will try to find by source_turbo_element_name.
        'source_turbo_element_id' => 0,
        'source_turbo_element_name' => 'Турбо-сайт',
    ],

    'lead' => [
        'city_field' => 'UF_CRM_1744362815',
        'executor_field' => 'UF_CRM_1745957138',
        'default_status_id' => 'NEW',
        // Fallback SOURCE_ID for leads when source mapping from lists is unavailable.
        'default_source_id' => 'UC_HJOQ1Q',
    ],

    'notifications' => [
        'error_chat_id' => 'chat69697',
        'cloud_portal_link' => 'https://crmmarketing25.bitrix24.ru',
        'iblock_link' => 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/view/0/?list_section_id=',
        // View URL template for list 54 element. {id} is replaced with created element id.
        'iblock_element_edit_url_template' => 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/element/0/{id}/?list_section_id=',
        // Public URL of this handler for one-click reprocess from chat.
        'reprocess_url_base' => 'https://bitrix.dreamteamcompany.ru/local/handlers/crmmarketing25/deal_webhook_handler.php',
        // Lead detail URL template in local box portal.
        'lead_detail_url_template' => 'https://bitrix.dreamteamcompany.ru/crm/lead/details/{id}/',
        'iblock_not_found_text' => "🚨 Ошибка обработчика Турбо-сайт\n\n"
            . "❌ Не найден элемент в инфоблоке 54\n\n"
            . "📋 Название: {deal_title}\n"
            . "🆔 ID сделки: {deal_id}\n"
            . "⏰ Время: {time}\n\n"
            . "ℹ️ Для повторной выгрузки после добавления элемента в список 54:\n"
            . "1. Перейдите в [URL={cloud_portal_link}]crmmarketing25.bitrix24.ru[/URL]\n"
            . "2. Найдите сделку #{deal_id}\n"
            . "3. Выгрузите её повторно\n\n"
            . "Быстрый сценарий:\n"
            . "- [URL={create_and_edit_url}]Создать элемент в списке 54 и открыть для редактирования[/URL]\n"
            . "И после добавления элемента нажмите: [URL={reprocess_url}]Автоматически перевыгрузить сделку #{deal_id}[/URL]\n\n"
            . "📝 [URL={iblock_link}]Открыть список 54[/URL]",
        'webhook_unavailable_text' => "🚨 Ошибка по интеграции Турбо-сайт\n\n"
            . "Не удалось подключиться к облачному webhook\n\n"
            . "Метод: {method}\n"
            . "Ошибка: {error}\n"
            . "Время: {time}\n"
            . "Webhook: {webhook_base}",
    ],
];
