<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp;

final class Env
{
    /**
     * Loads key/value pairs from a .env-like file.
     *
     * Supported lines:
     * - KEY=value
     * - KEY="value"
     * - comments with #
     */
    public static function loadFile(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $vars = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Strip "export " prefix if present
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, strlen('export ')));
            }

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip inline comments " #..."
            if (($hashPos = strpos($value, ' #')) !== false) {
                $value = trim(substr($value, 0, $hashPos));
            }

            if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                $quote = $value[0];
                if (str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                } else {
                    $value = substr($value, 1);
                }
            }

            if ($key !== '') {
                $vars[$key] = $value;
            }
        }

        return $vars;
    }

    public static function get(array $vars, string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, $vars)) {
            return $vars[$key];
        }

        $env = getenv($key);
        if ($env !== false) {
            return (string) $env;
        }

        return $default;
    }
}

