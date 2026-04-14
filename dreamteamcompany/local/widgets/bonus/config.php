<?php
/**
 * Конфигурационный файл для виджета "Мои бонусы"
 * 
 */

return [
    // ID смарт-процесса с бонусами
    // Получить можно через REST API: crm.type.list
    'entity_type_id' => '1144',
    
    // Название поля в смарт-процессе, которое содержит сумму бонусов
    'bonus_field' => 'UF_CRM_26_1764001120',
    
    // Название поля для связи с пользователем (обычно ASSIGNED_BY_ID)
    'user_field' => 'ASSIGNED_BY_ID',
    
    // Настройки отображения
    'currency_name' => 'Дримы - валюта Команды Мечты',
    
    // URL для страниц
    'how_to_spend_url' => 'https://bitrix.dreamteamcompany.ru/knowledge/bonus/',
    'history_url' => '/crm/type/1156/list/category/0/',

    // Настройки виджета "Начисление баллов"
    // Пользовательское поле смарт-процесса 1156 со списком причин начисления
    'scoring_reason_uf_name' => 'UF_CRM_29_1768987790',
    // Текстовое значение списка, доступное обычным сотрудникам (для руководителей доступны все значения)
    'scoring_reason_value_for_non_leaders' => 'ПЕРЕВОД',
    // ID групп пользователей, которые считаются руководителями (например, [1] для администраторов)
    // Если пусто, используется проверка через структуру компании
    'scoring_leader_group_ids' => [1],

    // Настройки запуска бизнес-процесса начисления
    'scoring_bp_enabled' => true,
    // ID шаблона БП (https://bitrix.dreamteamcompany.ru/crm/configs/bp/CRM_DYNAMIC_1156/edit/605/)
    'scoring_bp_template_id' => 605,
    // Тип документа смарт-процесса, к которому привязывается БП, например CRM_DYNAMIC_1156
    'scoring_bp_document_type' => 'CRM_DYNAMIC_1156',
    // ID элемента смарт-процесса (https://bitrix.dreamteamcompany.ru/crm/type/1156/details/152/)
    'scoring_bp_document_id' => 152,
    // Вебхук для bizproc.workflow.start (используется тот же, что и в kb_handler)
    'scoring_bp_webhook_url' => 'https://bitrix.dreamteamcompany.ru/rest/1/7sz1joi0kdkq3523/bizproc.workflow.start',
];

