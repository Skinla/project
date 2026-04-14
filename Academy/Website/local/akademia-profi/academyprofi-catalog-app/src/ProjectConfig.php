<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp;

final class ProjectConfig
{
    public function __construct(public readonly array $raw, public readonly string $loadedFromPath)
    {
    }

    public static function load(): self
    {
        $path = dirname(__DIR__, 4) . '/bitrix.config.json';
        if (!is_file($path)) {
            return new self([], '');
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return new self([], $path);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return new self([], $path);
        }

        return new self($decoded, $path);
    }
}

