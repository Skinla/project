<?php
/**
 * Конфигурация системы интеграции CallTouch с Bitrix24
 * Версия с нативным API (без вебхуков)
 * Автоматически сгенерировано: 2025-11-14 23:46:08
 */
return array (
  'lead' => 
  array (
    'STATUS_ID' => 'NEW',
    'TITLE' => 'Звонок с сайта {url}',
  ),
  'logs_dir' => '/home/bitrix/www/local/handlers/calltouch/calltouch_native/calltouch_logs',
  'errors_dir' => '/home/bitrix/www/local/handlers/calltouch/calltouch_native/queue_errors',
  'global_log' => 'calltouch_common.log',
  'max_log_size' => 2097152,
  'use_lead_direct' => true,
  'allowed_callphases' => 
  array (
    0 => 'calldisconnected',
  ),
  'deduplication' => 
  array (
    'enabled' => true,
    'title_keywords' => 
    array (
      0 => 'Входящий звонок',
      1 => 'Новый лид по звонку с',
    ),
    'period' => '30d',
  ),
  'ctCallerId' => 
  array (
    'enabled' => true,
    'retention' => '7d',
    'index_file' => '/home/bitrix/www/local/handlers/calltouch/calltouch_native/ctcallerid_index.json',
  ),
  'error_handling' => 
  array (
    'move_to_errors' => true,
    'max_error_files' => 1000,
    'cleanup_old_errors' => true,
    'error_retention_days' => 30,
    'log_api_errors' => true,
    'send_chat_notifications' => true,
  ),
  'chat_notifications' => 
  array (
    'error_chat_id' => 'chat69697',
  ),
  'iblock' => 
  array (
    'iblock_54_id' => 54,
    'iblock_19_id' => 19,
    'iblock_22_id' => 22,
    'iblock_type_id' => 'lists_socnet',
    'socnet_group_id' => 1,
  ),
);
