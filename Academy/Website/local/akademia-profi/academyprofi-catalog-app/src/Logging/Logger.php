<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Logging;

final class Logger
{
    public function __construct(private readonly string $logFilePath)
    {
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $dir = dirname($this->logFilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $payload = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];

        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        @file_put_contents($this->logFilePath, $line, FILE_APPEND | LOCK_EX);
    }
}

