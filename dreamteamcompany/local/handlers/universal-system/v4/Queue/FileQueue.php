<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Queue;

use UniversalSystem\V4\Support\Json;
use UniversalSystem\V4\Support\Logger;

final class FileQueue
{
    private array $config;
    private Logger $logger;
    private array $dirs;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->dirs = $config['queue_dirs'];
        $this->ensureDirectories();
    }

    public function ensureDirectories(): void
    {
        foreach ($this->dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }

    public function enqueue(array $request): string
    {
        $path = $this->dirs['incoming'] . '/' . $this->buildFileName($request);
        Json::writeFile($path, $request);
        return $path;
    }

    public function findByRequestId(string $requestId): ?array
    {
        foreach ($this->stateDirectories() as $state => $dir) {
            foreach ($this->sortedFiles($dir) as $path) {
                try {
                    $data = Json::decodeFile($path);
                } catch (\Throwable) {
                    continue;
                }

                if (($data['request_id'] ?? '') !== $requestId) {
                    continue;
                }

                return [
                    'state' => $state,
                    'path' => $path,
                    'data' => $data,
                ];
            }
        }

        return null;
    }

    public function claimNext(): ?array
    {
        foreach ($this->sortedFiles($this->dirs['incoming']) as $incomingPath) {
            $processingPath = $this->dirs['processing'] . '/' . basename($incomingPath);
            if (!@rename($incomingPath, $processingPath)) {
                continue;
            }

            try {
                $data = Json::decodeFile($processingPath);
            } catch (\Throwable $e) {
                $fallback = [
                    'request_id' => basename($processingPath, '.json'),
                    'state' => 'error',
                    'status' => [
                        'state' => 'error',
                        'attempts' => 1,
                        'last_error' => $e->getMessage(),
                        'retryable' => false,
                        'lead_id' => null,
                        'duplicate_key' => null,
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ],
                    'result' => [
                        'outcome' => 'error',
                        'error_code' => 'invalid_queue_file',
                        'error_message' => $e->getMessage(),
                    ],
                ];
                $this->moveWithPayload($processingPath, $this->dirs['error'], $fallback);
                $this->logger->error('Invalid queue file moved to error', ['path' => $processingPath, 'error' => $e->getMessage()]);
                continue;
            }

            $attempts = (int)($data['status']['attempts'] ?? 0) + 1;
            $now = date('Y-m-d H:i:s');
            $data['state'] = 'processing';
            $data['status']['state'] = 'processing';
            $data['status']['attempts'] = $attempts;
            $data['status']['claimed_at'] = $now;
            $data['status']['updated_at'] = $now;
            Json::writeFile($processingPath, $data);

            return [
                'path' => $processingPath,
                'data' => $data,
            ];
        }

        return null;
    }

    public function updateProcessing(string $processingPath, array $data): void
    {
        $data['state'] = 'processing';
        $data['status']['state'] = 'processing';
        $data['status']['updated_at'] = date('Y-m-d H:i:s');
        Json::writeFile($processingPath, $data);
    }

    public function finishDone(string $processingPath, array $data): string
    {
        $data['state'] = 'done';
        $data['status']['state'] = 'done';
        $data['status']['updated_at'] = date('Y-m-d H:i:s');
        $data['status']['finished_at'] = date('Y-m-d H:i:s');
        return $this->moveWithPayload($processingPath, $this->dirs['done'], $data);
    }

    public function finishError(string $processingPath, array $data): string
    {
        $data['state'] = 'error';
        $data['status']['state'] = 'error';
        $data['status']['updated_at'] = date('Y-m-d H:i:s');
        $data['status']['finished_at'] = date('Y-m-d H:i:s');
        return $this->moveWithPayload($processingPath, $this->dirs['error'], $data);
    }

    public function requeue(string $processingPath, array $data): string
    {
        $data['state'] = 'incoming';
        $data['status']['state'] = 'incoming';
        $data['status']['updated_at'] = date('Y-m-d H:i:s');
        return $this->moveWithPayload($processingPath, $this->dirs['incoming'], $data);
    }

    public function findDoneByDuplicateKey(string $duplicateKey, string $excludeRequestId): ?array
    {
        foreach ($this->sortedFiles($this->dirs['done']) as $path) {
            try {
                $data = Json::decodeFile($path);
            } catch (\Throwable) {
                continue;
            }

            if (($data['request_id'] ?? '') === $excludeRequestId) {
                continue;
            }

            if (($data['status']['duplicate_key'] ?? null) !== $duplicateKey) {
                continue;
            }

            return [
                'path' => $path,
                'data' => $data,
            ];
        }

        return null;
    }

    public function counts(): array
    {
        $counts = [];
        foreach ($this->stateDirectories() as $state => $dir) {
            $counts[$state] = count($this->sortedFiles($dir));
        }
        return $counts;
    }

    public function recentErrors(int $limit = 20): array
    {
        $files = $this->sortedFiles($this->dirs['error']);
        $files = array_reverse($files);
        $items = [];

        foreach (array_slice($files, 0, $limit) as $path) {
            try {
                $data = Json::decodeFile($path);
            } catch (\Throwable) {
                continue;
            }

            $items[] = [
                'request_id' => $data['request_id'] ?? basename($path),
                'error_code' => $data['result']['error_code'] ?? null,
                'error_message' => $data['result']['error_message'] ?? null,
                'retryable' => $data['status']['retryable'] ?? false,
                'updated_at' => $data['status']['updated_at'] ?? null,
                'path' => $path,
            ];
        }

        return $items;
    }

    public function staleProcessing(): array
    {
        $stale = [];
        $timeout = (int)($this->config['processing_stale_after_seconds'] ?? 300);
        $cutoff = time() - $timeout;

        foreach ($this->sortedFiles($this->dirs['processing']) as $path) {
            try {
                $data = Json::decodeFile($path);
            } catch (\Throwable) {
                continue;
            }

            $claimedAt = (string)($data['status']['claimed_at'] ?? '');
            $claimedTs = $claimedAt !== '' ? strtotime($claimedAt) : false;
            if ($claimedTs === false || $claimedTs >= $cutoff) {
                continue;
            }

            $stale[] = [
                'request_id' => $data['request_id'] ?? basename($path),
                'claimed_at' => $claimedAt,
                'path' => $path,
            ];
        }

        return $stale;
    }

    private function moveWithPayload(string $currentPath, string $targetDir, array $data): string
    {
        $targetPath = $targetDir . '/' . basename($currentPath);
        Json::writeFile($currentPath, $data);
        if (!@rename($currentPath, $targetPath)) {
            Json::writeFile($targetPath, $data);
            @unlink($currentPath);
        }

        return $targetPath;
    }

    private function buildFileName(array $request): string
    {
        $receivedAt = preg_replace('/[^0-9]/', '', (string)($request['received_at'] ?? date('Y-m-d H:i:s')));
        $requestId = substr((string)($request['request_id'] ?? hash('sha256', uniqid('', true))), 0, 16);
        return 'request_' . $receivedAt . '_' . $requestId . '.json';
    }

    private function stateDirectories(): array
    {
        return [
            'incoming' => $this->dirs['incoming'],
            'processing' => $this->dirs['processing'],
            'done' => $this->dirs['done'],
            'error' => $this->dirs['error'],
        ];
    }

    private function sortedFiles(string $dir): array
    {
        $files = glob($dir . '/*.json');
        if (!$files) {
            return [];
        }

        sort($files, SORT_STRING);
        return $files;
    }
}
