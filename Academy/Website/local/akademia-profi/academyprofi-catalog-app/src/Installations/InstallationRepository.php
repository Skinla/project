<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Installations;

final class InstallationRepository
{
    public function __construct(private readonly string $dir)
    {
    }

    public function save(string $memberId, array $data): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0770, true);
        }

        $path = $this->path($memberId);
        $payload = array_merge(
            ['member_id' => $memberId, 'updated_at' => gmdate('c')],
            $data
        );

        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function get(string $memberId): ?array
    {
        $path = $this->path($memberId);
        if (!is_file($path)) {
            return null;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function path(string $memberId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $memberId);
        return rtrim($this->dir, '/') . '/' . $safe . '.json';
    }
}

