<?php
declare(strict_types=1);

/**
 * Minimal autoloader (no Composer) for Bitrix Box.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'AcademyProfi\\CatalogApp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

