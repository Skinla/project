<?php
/**
 * File-based storage for lead callback v2 jobs.
 * One JSON file per lead to keep job state idempotent across repeated BP calls.
 */

class LeadCallbackQueueStore
{
    /** @var string */
    private $basePath;

    public function __construct($basePath)
    {
        $this->basePath = rtrim((string)$basePath, '/\\');
    }

    public function getBasePath()
    {
        return $this->basePath;
    }

    public function ensureDirectory()
    {
        if (!is_dir($this->basePath)) {
            return @mkdir($this->basePath, 0755, true);
        }

        return true;
    }

    public function filenameForLeadId($leadId)
    {
        return 'lead_' . (int)$leadId . '.json';
    }

    public function pathForLeadId($leadId)
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $this->filenameForLeadId($leadId);
    }

    public function lockPathForLeadId($leadId)
    {
        return $this->basePath . DIRECTORY_SEPARATOR . 'lead_' . (int)$leadId . '.lock';
    }

    public function acquireLeadLock($leadId)
    {
        $this->ensureDirectory();

        $lockPath = $this->lockPathForLeadId($leadId);
        $fp = @fopen($lockPath, 'cb');
        if (!$fp) {
            return false;
        }

        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        return $fp;
    }

    public function releaseLeadLock($lockHandle)
    {
        if (is_resource($lockHandle)) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function readJob($leadId)
    {
        $path = $this->pathForLeadId($leadId);
        if (!is_file($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    public function writeJob(array $job)
    {
        $leadId = (int)($job['lead_id'] ?? 0);
        if ($leadId <= 0) {
            return false;
        }

        $this->ensureDirectory();

        $path = $this->pathForLeadId($leadId);
        $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return false;
        }

        return @file_put_contents($path, $json . "\n", LOCK_EX) !== false;
    }

    public function deleteJob($leadId)
    {
        $path = $this->pathForLeadId($leadId);
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }

    public function listDueLeadIds($nowIso, $limit)
    {
        $limit = max(1, (int)$limit);
        $this->ensureDirectory();

        $paths = glob($this->basePath . DIRECTORY_SEPARATOR . 'lead_*.json');
        if (!is_array($paths)) {
            return [];
        }

        $due = [];
        foreach ($paths as $path) {
            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }

            $leadId = (int)($job['lead_id'] ?? 0);
            $state = (string)($job['state'] ?? '');
            $nextRunAt = (string)($job['next_run_at'] ?? '');
            if ($leadId <= 0 || $nextRunAt === '') {
                continue;
            }

            if (in_array($state, ['done', 'failed', 'skipped'], true)) {
                continue;
            }

            if ($nextRunAt <= $nowIso) {
                $due[] = [
                    'lead_id' => $leadId,
                    'next_run_at' => $nextRunAt,
                ];
            }
        }

        usort($due, static function ($a, $b) {
            return strcmp((string)$a['next_run_at'], (string)$b['next_run_at']);
        });

        $leadIds = [];
        foreach (array_slice($due, 0, $limit) as $item) {
            $leadIds[] = (int)$item['lead_id'];
        }

        return $leadIds;
    }
}
