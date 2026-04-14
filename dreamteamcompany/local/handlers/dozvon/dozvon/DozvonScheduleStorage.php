<?php
/**
 * Хранение расписания слотов в JSON-файлах (local/handlers/dozvon/schedules/).
 * Один файл на лида: lead_{leadId}.json. Чтение/запись с flock.
 */

class DozvonScheduleStorage
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Имя файла по lead_id.
     */
    public function filenameForLead(int $leadId): string
    {
        return 'lead_' . $leadId . '.json';
    }

    /**
     * Полный путь к файлу.
     */
    public function pathForFilename(string $filename): string
    {
        return $this->basePath . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Убедиться, что папка существует.
     */
    public function ensureDirectory(): bool
    {
        if (!is_dir($this->basePath)) {
            return @mkdir($this->basePath, 0755, true);
        }
        return true;
    }

    /**
     * Прочитать расписание из файла. Блокировка на чтение.
     *
     * @return array{lead_id: int, created_at: string, slots: array}|null
     */
    public function read(string $filename): ?array
    {
        $path = $this->pathForFilename($filename);
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return null;
        }
        if (!flock($fp, LOCK_SH)) {
            fclose($fp);
            return null;
        }
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) && isset($data['slots']) ? $data : null;
    }

    /**
     * Записать расписание. Блокировка на запись.
     *
     * @param array{lead_id: int, created_at: string, slots: array} $data
     */
    public function write(string $filename, array $data): bool
    {
        $this->ensureDirectory();
        $path = $this->pathForFilename($filename);
        $fp = fopen($path, 'cb');
        if (!$fp) {
            return false;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }
        ftruncate($fp, 0);
        rewind($fp);
        $written = fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $written !== false;
    }

    /**
     * Удалить файл расписания.
     */
    public function delete(string $filename): bool
    {
        $path = $this->pathForFilename($filename);
        if (!is_file($path)) {
            return true;
        }
        return @unlink($path);
    }

    /**
     * Найти следующий слот по времени (pending или retry с retry_at <= now).
     * Возвращает индекс в slots и время слота; если нет — null.
     *
     * @param array $slots
     * @param string $now Y-m-d\TH:i:s
     * @return array{index: int, scheduled_at: string, cycle_day: int}|null
     */
    public static function findNextDueSlot(array $slots, string $now): ?array
    {
        $candidate = null;
        foreach ($slots as $i => $slot) {
            $status = $slot['status'] ?? 'pending';
            if ($status === 'processed') {
                continue;
            }
            if ($status === 'retry') {
                $retryAt = $slot['retry_at'] ?? '';
                if ($retryAt === '' || $retryAt > $now) {
                    continue;
                }
            }
            $scheduledAt = $slot['scheduled_at'] ?? '';
            if ($scheduledAt === '' || $scheduledAt > $now) {
                continue;
            }
            if ($candidate === null || $scheduledAt < ($candidate['scheduled_at'] ?? '')) {
                $candidate = [
                    'index' => $i,
                    'scheduled_at' => $scheduledAt,
                    'cycle_day' => (int)($slot['cycle_day'] ?? 1),
                ];
            }
        }
        return $candidate;
    }

    /**
     * Вычислить время следующего слота (pending или retry) после текущего.
     * Возвращает Y-m-d\TH:i:s или пустую строку.
     *
     * @param array $slots
     * @param string $now
     * @return string
     */
    public static function computeNextSlotAt(array $slots, string $now): string
    {
        $next = '';
        foreach ($slots as $slot) {
            $status = $slot['status'] ?? 'pending';
            if ($status === 'processed') {
                continue;
            }
            if ($status === 'retry') {
                $at = $slot['retry_at'] ?? '';
            } else {
                $at = $slot['scheduled_at'] ?? '';
            }
            if ($at !== '' && $at > $now && ($next === '' || $at < $next)) {
                $next = $at;
            }
        }
        return $next;
    }
}
