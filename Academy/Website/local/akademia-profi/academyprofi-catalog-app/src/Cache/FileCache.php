<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Cache;

use AcademyProfi\CatalogApp\Logging\Logger;

final class FileCache
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly Logger $logger,
    ) {
    }

    public function get(string $key): array
    {
        $path = $this->dataPath($key);
        if (!is_file($path)) {
            return ['hit' => false, 'value' => null];
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return ['hit' => false, 'value' => null];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['expiresAt'])) {
            return ['hit' => false, 'value' => null];
        }

        if ((int) $decoded['expiresAt'] < time()) {
            @unlink($path);
            return ['hit' => false, 'value' => null];
        }

        return ['hit' => true, 'value' => $decoded['value'] ?? null];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $dir = $this->cacheDir;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $path = $this->dataPath($key);
        $payload = [
            'expiresAt' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * Stampede guard: run callback under per-key lock.
     */
    public function remember(string $key, int $ttlSeconds, callable $compute): array
    {
        $cached = $this->get($key);
        if ($cached['hit'] === true) {
            return ['hit' => true, 'value' => $cached['value']];
        }

        $lockPath = $this->lockPath($key);
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            $value = $compute();
            $this->set($key, $value, $ttlSeconds);
            return ['hit' => false, 'value' => $value];
        }

        try {
            // Wait up to 10 seconds for the lock
            $start = microtime(true);
            while (!flock($fh, LOCK_EX | LOCK_NB)) {
                if ((microtime(true) - $start) > 10.0) {
                    break;
                }
                usleep(25_000);
            }

            // Another worker might have already computed it
            $cached2 = $this->get($key);
            if ($cached2['hit'] === true) {
                return ['hit' => true, 'value' => $cached2['value']];
            }

            $value = $compute();
            $this->set($key, $value, $ttlSeconds);
            return ['hit' => false, 'value' => $value];
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    private function dataPath(string $key): string
    {
        $hash = hash('sha256', $key);
        return rtrim($this->cacheDir, '/') . '/' . $hash . '.json';
    }

    private function lockPath(string $key): string
    {
        $hash = hash('sha256', $key);
        return rtrim($this->cacheDir, '/') . '/' . $hash . '.lock';
    }
}

