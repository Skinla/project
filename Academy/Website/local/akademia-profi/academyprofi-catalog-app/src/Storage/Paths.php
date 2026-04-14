<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Storage;

use AcademyProfi\CatalogApp\Env;

final class Paths
{
    /**
     * Returns a writable base directory for app state (installations/cache/logs).
     */
    public static function storageDir(array $env): string
    {
        $configured = Env::get($env, 'ACADEMYPROFI_STORAGE_DIR');
        if (is_string($configured) && $configured !== '' && self::ensureDir($configured)) {
            return rtrim($configured, '/');
        }

        $etc = '/etc/academyprofi/academyprofi-catalog-app';
        if (self::ensureDir($etc)) {
            return $etc;
        }

        // Fallback to repo/app-local storage (dev). Note: should be protected by .htaccess on Bitrix Box.
        $local = dirname(__DIR__, 2) . '/storage';
        self::ensureDir($local);
        return $local;
    }

    public static function cacheDir(array $env): string
    {
        $dir = self::storageDir($env) . '/cache';
        self::ensureDir($dir);
        return $dir;
    }

    public static function logsDir(array $env): string
    {
        $dir = self::storageDir($env) . '/logs';
        self::ensureDir($dir);
        return $dir;
    }

    public static function installationsDir(array $env): string
    {
        $dir = self::storageDir($env) . '/installations';
        self::ensureDir($dir);
        return $dir;
    }

    private static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        @mkdir($dir, 0770, true);
        return is_dir($dir) && is_writable($dir);
    }
}

