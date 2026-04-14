<?php
/**
 * Webhook configuration for Bitrix24 REST API (source cloud portal)
 *
 * handler_url - публичный URL webhook_lead_handler.php для события ONCRMLEADADD.
 * Должен быть доступен по HTTPS. Заполнить и запустить: php register_lead_webhook.php
 */
return [
    'url' => 'https://milleniummed.bitrix24.ru/rest/1966/701udkzyf4bbyy32',
    'handler_url' => 'https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php',
];
