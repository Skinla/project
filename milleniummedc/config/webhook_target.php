<?php
/**
 * Webhook configuration for TARGET Bitrix24 portal (box)
 * Used for migration: stages, categories, etc.
 *
 * Create inbound webhook in target portal:
 * Настройки → Разработчикам → Входящий вебхук → Добавить
 * Права: CRM
 */
return [
    'url' => 'https://bitrix.milleniummedc.ru/rest/1/XXXXXXXX/',  // TODO: заменить на реальный код вебхука
];
