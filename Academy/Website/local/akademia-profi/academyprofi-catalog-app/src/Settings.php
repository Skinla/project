<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp;

final class Settings
{
    public function __construct(
        public readonly array $raw,
        public readonly string $loadedFromPath,
    ) {
    }

    public static function load(array $env): self
    {
        $defaultPaths = [
            Env::get($env, 'ACADEMYPROFI_SETTINGS_PATH'),
            '/etc/academyprofi/academyprofi-catalog-app/settings.json',
            // repo-local fallback (dev)
            dirname(__DIR__, 4) . '/MODULE_SETTINGS.example.json',
        ];

        $paths = array_values(array_filter($defaultPaths, static fn($p) => is_string($p) && $p !== ''));

        $loadedFrom = '';
        $data = [];
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $json = file_get_contents($path);
            if ($json === false) {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $data = $decoded;
            $loadedFrom = $path;
            break;
        }

        return new self(self::normalize($data), $loadedFrom);
    }

    private static function normalize(array $data): array
    {
        $data['meta'] ??= ['name' => 'academyprofi-catalog-module', 'version' => 1];
        $data['api'] ??= ['basePath' => '/academyprofi-catalog', 'cacheTtlSeconds' => 600, 'pageSize' => 50];
        $data['ui'] ??= [
            'detail' => ['mode' => 'embedded', 'productId' => 18, 'pagePath' => '/rabochie_professii_occg/'],
            'cta' => ['label' => 'Оставить заявку', 'mode' => 'anchor', 'anchor' => '#request'],
        ];
        $data['security'] ??= ['cors' => ['allowedDomains' => []]];
        $data['routing'] ??= ['productId' => ['mode' => 'hash', 'param' => 'product']];
        $data['blocks'] ??= [
            // One category in block picker (avoid duplicated sections)
            'sections' => 'academyprofi',
            'assetsBaseUrl' => '',
        ];

        $data['ui']['detail'] ??= [];
        $data['ui']['detail']['pagePath'] ??= '/rabochie_professii_occg/';

        return $data;
    }
}

