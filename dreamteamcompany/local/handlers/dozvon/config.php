<?php
/**
 * Конфигурация скриптов недозвона (продакшен).
 * Все настройки в одном месте.
 *
 * Список «Недозвон» (DOZVON_LIST_ID) должен содержать свойства элемента:
 * - PHONE (строка), LEAD_ID (число), LEAD_DATE_CREATE (DateTime), RECORD_TYPE (список: trigger, cycle, …),
 * - SCHEDULE_FILENAME (строка), NEXT_SLOT_AT (дата/время) — для записей cycle (расписание в JSON-файле).
 * Проверка: inspect_list.php выведет все свойства; при отсутствии SCHEDULE_FILENAME/NEXT_SLOT_AT они не сохранятся.
 *
 * CRON_SECRET_KEY можно оставить пустым, если скрипты вызываются только с того же сервера.
 * Проверьте: DOZVON_LIST_ID, коды статусов лидов (LEAD_STATUS_*), CALL_WEBHOOK_URL и пулы — под вашу воронку и телефонию.
 */

return [
    // Путь к папке скриптов относительно DOCUMENT_ROOT (для require в PHP-блоке БП)
    'DOZVON_REL_PATH'   => 'local/handlers/dozvon',
    'DOZVON_ROOT'       => __DIR__,

    // Список «Недозвон»
    'DOZVON_LIST_ID'          => 119,
    'DOZVON_SOCNET_GROUP_ID'  => 1,

    // Модуль 2: новая архитектура из двух списков (без файловых очередей)
    'MODULE2_LISTS_IBLOCK_TYPE_ID'   => 'lists',
    'MODULE2_MASTER_LIST_ID'         => 0, // можно оставить 0, если список будет найден по коду
    'MODULE2_MASTER_LIST_CODE'       => 'dozvon_module2_master',
    'MODULE2_MASTER_LIST_NAME'       => 'Автонедозвон - циклы',
    'MODULE2_ATTEMPTS_LIST_ID'       => 0, // можно оставить 0, если список будет найден по коду
    'MODULE2_ATTEMPTS_LIST_CODE'     => 'dozvon_module2_attempts',
    'MODULE2_ATTEMPTS_LIST_NAME'     => 'Автонедозвон - попытки',
    'MODULE2_LISTS_WEBHOOK_BASE_URL' => '', // пример: https://portal/rest/1/webhook-code
    'MODULE3_REST_WEBHOOK_URL'       => 'https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk', // для REST: lists, voximplant (нужен scope telephony)
    'MODULE2_LISTS_RIGHTS'           => [], // пример: ['U1' => 'X']
    'MODULE2_WORKING_HOURS'          => [
        'default' => ['start' => '09:00', 'end' => '21:00'],
    ],

    // Webhook и пулы номеров для звонков
    'CALL_WEBHOOK_URL'        => 'https://serverhost1.ru/webhook/059c79a6-c97f-44d6-b63f-9875666dff82',
    'CALL_POOL_LEADS'         => 'sip70',
    'CALL_POOL_CAROUSEL'      => 'sip71',

    // Статусы лидов CRM (коды STATUS_ID из вашей воронки)
    'LEAD_STATUS_NEDOZVON'    => 'PROCESSED',   // статус «Недозвон»
    'LEAD_STATUS_POOR'        => '1',          // статус «Некачественный лид»
    'LEAD_STATUS_NOT_RECORDED'=> 'UC_I0XLWE',   // статус «Не записан»

    // Очередь и цикл
    'CALL_QUEUE_BATCH_SIZE'   => 30,
    'RETRY_DELAY_MINUTES'     => 15,
    'CYCLE_LAST_DAY_DEFAULT'  => 21,

    // Интервалы cron (секунды): для настройки cron/агентов Bitrix
    'PROCESS_TRIGGERS_CRON_INTERVAL_SECONDS' => 120,  // process_triggers.php — каждые 2 мин
    'PROCESS_QUEUE_CRON_INTERVAL_SECONDS'    => 300,  // process_queue.php — каждые 5 мин
    'COMPLETE_CYCLE_CRON_INTERVAL_SECONDS'   => 3600, // complete_cycle.php — раз в час

    // Запуск по cron: ключ не обязателен при вызове только с того же сервера (localhost/CLI). Задайте ключ, если cron дергает URL извне.
    'CRON_SECRET_KEY'         => '',
    'ALLOWED_IPS'             => [],            // при необходимости: ['1.2.3.4'] для ограничения по IP

    // Папка JSON-расписаний (одна запись cycle на лида, расписание в файле). Должна быть доступна на запись.
    'DOZVON_SCHEDULES_PATH'   => (function () {
        $p = __DIR__ . DIRECTORY_SEPARATOR . 'dozvon' . DIRECTORY_SEPARATOR . 'schedules';
        $real = realpath($p);
        return $real !== false ? $real : $p;
    })(),

    // Логирование (путь к файлу лога; при CLI — та же папка dozvon)
    'LOG_FILE'                => __DIR__ . DIRECTORY_SEPARATOR . 'dozvon.log',
];
