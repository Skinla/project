<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Support;

final class Logger
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if (!($this->config['enable_debug_logging'] ?? false)) {
            return;
        }

        $this->log('DEBUG', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $logsDir = $this->config['logs_dir'] ?? (__DIR__ . '/../logs');
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0777, true);
        }

        $payload = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message;
        if ($context !== []) {
            $payload .= ' | ' . Json::encode($context);
        }

        file_put_contents(
            $logsDir . '/' . ($this->config['log_file'] ?? 'v4.log'),
            $payload . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
