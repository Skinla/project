<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Support;

use RuntimeException;

final class Json
{
    public static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_INVALID_UTF8_SUBSTITUTE | JSON_BIGINT_AS_STRING);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON decode failed: ' . json_last_error_msg());
        }

        return $decoded;
    }

    public static function decodeFile(string $path): array
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read file: ' . $path);
        }

        return self::decode($contents);
    }

    public static function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('JSON encode failed: ' . json_last_error_msg());
        }

        return $json;
    }

    public static function writeFile(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $tmpPath = $path . '.tmp';
        file_put_contents($tmpPath, self::encode($data), LOCK_EX);
        rename($tmpPath, $path);
    }
}
