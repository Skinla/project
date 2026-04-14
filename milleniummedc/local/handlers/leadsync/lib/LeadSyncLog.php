<?php
/**
 * Log cloud_id -> box_id mappings
 */
class LeadSyncLog
{
    private string $logPath;

    public function __construct(string $baseDir)
    {
        $this->logPath = $baseDir . '/data/lead_sync_log.json';
    }

    public function getBoxId(int $cloudId): ?int
    {
        $data = $this->read();
        $id = $data[(string)$cloudId] ?? null;
        return $id !== null ? (int)$id : null;
    }

    public function set(int $cloudId, int $boxId): void
    {
        $data = $this->read();
        $data[(string)$cloudId] = $boxId;
        $this->write($data);
    }

    private function read(): array
    {
        if (!file_exists($this->logPath)) {
            return [];
        }
        $json = file_get_contents($this->logPath);
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function write(array $data): void
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->logPath,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
