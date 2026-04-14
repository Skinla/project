<?php

declare(strict_types=1);

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..') ?: __DIR__;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'UniversalSystem\\V4\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});

if (!function_exists('v4_config')) {
    function v4_config(): array
    {
        static $config;

        if ($config === null) {
            $config = require __DIR__ . '/config.php';
        }

        return $config;
    }
}
