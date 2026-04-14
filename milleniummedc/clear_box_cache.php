<?php
/**
 * Clear Bitrix cache on box. Run via: php clear_box_cache.php
 * Or deploy to box and run: cd /home/bitrix/www && php clear_box_cache.php
 */
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? (getcwd() ?: '/home/bitrix/www');
if (!is_dir($docRoot . '/bitrix')) $docRoot = '/home/bitrix/www';
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
chdir($docRoot);

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('crm');

// Clear CRM status cache
if (class_exists('\Bitrix\Crm\Status\StatusTable')) {
    \Bitrix\Main\Application::getInstance()->getTaggedCache()->clearByTag('crm_status');
    echo "Cleared crm_status tag.\n";
}

// Clear general cache
if (function_exists('BXClearCache')) {
    BXClearCache(true);
    echo "Cleared BX cache.\n";
}

// Managed cache
$cache = \Bitrix\Main\Data\Cache::createInstance();
if ($cache->cleanDir()) {
    echo "Cleaned cache dir.\n";
}

echo "Done.\n";
