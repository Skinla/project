<?php
/**
 * Скопировать в webhook.php на коробке и заполнить.
 *
 * url — base URL входящего вебхука облака (как в корневом config/webhook.php).
 * secret — если непустая строка, запрос к webhook_deal_handler должен содержать тот же secret
 *   (query ?secret=..., JSON "secret", или заголовок X-Sync-Secret).
 *
 * После деплоя: chown -R bitrix:bitrix local/handlers/dealsync/data
 * (иначе логи миграции не пишутся от имени веб-скрипта).
 *
 * Строгая проверка онлайн-записей: в вызов обработчика добавьте strict_booking=1
 * (query или JSON) — при сбое CRM_BOOKING ответ ok будет false, см. booking в JSON.
 */
return [
    'url' => 'https://YOUR_CLOUD.bitrix24.ru/rest/USER/WEBHOOK_CODE',
    'secret' => '',
];
