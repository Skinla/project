<?php
declare(strict_types=1);

if (defined('DEAL_WEBHOOK_INIT_DONE')) {
    return;
}
define('DEAL_WEBHOOK_INIT_DONE', true);

foreach ([
    'NO_KEEP_STATISTIC',
    'NO_AGENT_STATISTIC',
    'NOT_CHECK_PERMISSIONS',
    'BX_SKIP_PULL_INIT',
    'BX_PULL_SKIP_INIT',
    'BX_COMPRESSION_DISABLED',
] as $const) {
    if (!defined($const)) {
        define($const, true);
    }
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $dir = __DIR__;
    for ($i = 0; $i < 10; $i++) {
        if (is_file($dir . '/bitrix/modules/main/include/prolog_before.php')) {
            $_SERVER['DOCUMENT_ROOT'] = $dir;
            break;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    throw new RuntimeException('DOCUMENT_ROOT не определен');
}
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
}
if (!defined('B_PROLOG_INCLUDED')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
}
if (!\CModule::IncludeModule('crm') || !\CModule::IncludeModule('iblock')) {
    throw new RuntimeException('Не удалось подключить CRM/iblock');
}
