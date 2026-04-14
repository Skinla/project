<?php
declare(strict_types=1);

namespace App;

final class CappedFileLogger
{
    private string $filePath;
    private int $maxBytes;
    private string $timezone;
    private bool $enabled;

    public function __construct(string $filePath, int $maxBytes = 2097152, string $timezone = 'Europe/Moscow', bool $enabled = true)
    {
        $this->filePath = $filePath;
        $this->maxBytes = $maxBytes > 0 ? $maxBytes : 2097152;
        $this->timezone = trim($timezone) !== '' ? $timezone : 'Europe/Moscow';
        $this->enabled = $enabled;
    }

    public function log(string $message): void
    {
        if (!$this->enabled || $this->filePath === '') {
            return;
        }

        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $line = '[' . $this->timestamp() . '] ' . $message . PHP_EOL;
        $currentSize = is_file($this->filePath) ? @filesize($this->filePath) : 0;
        if (!is_int($currentSize) || $currentSize < 0) {
            $currentSize = 0;
        }

        if ($currentSize + strlen($line) > $this->maxBytes) {
            $this->trimFile();
        }

        @file_put_contents($this->filePath, $line, FILE_APPEND | LOCK_EX);
    }

    private function trimFile(): void
    {
        if (!is_file($this->filePath)) {
            return;
        }

        $content = @file_get_contents($this->filePath);
        if (!is_string($content) || $content === '') {
            @file_put_contents($this->filePath, '');
            return;
        }

        $keepBytes = (int) floor($this->maxBytes / 2);
        if ($keepBytes <= 0) {
            $keepBytes = 1024;
        }

        if (strlen($content) > $keepBytes) {
            $content = substr($content, -$keepBytes);
            $firstNewline = strpos($content, "\n");
            if ($firstNewline !== false) {
                $content = substr($content, $firstNewline + 1);
            }
        }

        @file_put_contents($this->filePath, $content, LOCK_EX);
    }

    private function timestamp(): string
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone($this->timezone)))->format('c');
        } catch (\Throwable $e) {
            return gmdate('c');
        }
    }
}
