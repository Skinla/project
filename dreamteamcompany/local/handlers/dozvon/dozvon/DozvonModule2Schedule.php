<?php
declare(strict_types=1);

/**
 * Генерация попыток дозвона по ТЗ модуля 2.
 */
final class DozvonModule2Schedule
{
    private const CYCLE_DAYS_TOTAL = 10;

    private array $workingHoursByCity;

    public function __construct(array $workingHoursByCity = [])
    {
        $this->workingHoursByCity = $workingHoursByCity;
    }

    public function getCycleDaysTotal(): int
    {
        return self::CYCLE_DAYS_TOTAL;
    }

    public function buildPlan(DateTimeInterface $cycleStartDate, string $cityId = ''): array
    {
        $plan = [];
        $attemptNumber = 0;

        for ($cycleDay = 1; $cycleDay <= self::CYCLE_DAYS_TOTAL; $cycleDay++) {
            foreach ($this->rawTimesForDay($cycleDay) as $time) {
                $scheduledAt = $this->buildDateTimeForDay($cycleStartDate, $cycleDay, $time);
                $scheduledAt = $this->normalizeToWorkingWindow($scheduledAt, $cityId);
                $attemptNumber++;
                $plan[] = [
                    'cycle_day' => $cycleDay,
                    'attempt_number' => $attemptNumber,
                    'scheduled_at' => $scheduledAt->format('Y-m-d\TH:i:s'),
                ];
            }
        }

        usort($plan, static function (array $a, array $b): int {
            if ($a['scheduled_at'] === $b['scheduled_at']) {
                return $a['attempt_number'] <=> $b['attempt_number'];
            }
            return strcmp($a['scheduled_at'], $b['scheduled_at']);
        });

        foreach ($plan as $index => &$row) {
            $row['attempt_number'] = $index + 1;
        }
        unset($row);

        return $plan;
    }

    public function getCycleDay(DateTimeInterface $cycleStartDate, ?DateTimeInterface $now = null): int
    {
        $start = DateTimeImmutable::createFromInterface($cycleStartDate)->setTime(0, 0, 0);
        $today = ($now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable())->setTime(0, 0, 0);
        $days = (int)$start->diff($today)->days + 1;

        return max(1, min(self::CYCLE_DAYS_TOTAL, $days));
    }

    public function getCycleEndDate(DateTimeInterface $cycleStartDate): string
    {
        $date = DateTimeImmutable::createFromInterface($cycleStartDate)
            ->setTime(0, 0, 0)
            ->modify('+' . (self::CYCLE_DAYS_TOTAL - 1) . ' days');

        return $date->format('Y-m-d');
    }

    private function rawTimesForDay(int $cycleDay): array
    {
        switch ($cycleDay) {
            case 1:
            case 2:
            case 3:
                return ['11:00', '13:00', '15:00', '17:00'];
            case 4:
                return ['11:00', '13:30', '16:00'];
            case 5:
            case 6:
            case 7:
                return ['11:00', '14:00'];
            case 8:
                return ['16:00'];
            case 9:
                return ['11:00'];
            case 10:
                return ['16:00'];
            default:
                return [];
        }
    }

    private function buildDateTimeForDay(DateTimeInterface $cycleStartDate, int $cycleDay, string $time): DateTimeImmutable
    {
        [$hours, $minutes] = explode(':', $time);
        return DateTimeImmutable::createFromInterface($cycleStartDate)
            ->setTime(0, 0, 0)
            ->modify('+' . ($cycleDay - 1) . ' days')
            ->setTime((int)$hours, (int)$minutes, 0);
    }

    private function normalizeToWorkingWindow(DateTimeImmutable $scheduledAt, string $cityId): DateTimeImmutable
    {
        $window = $this->resolveWorkingWindow($cityId);
        if ($window === null) {
            return $scheduledAt;
        }

        $start = $scheduledAt->setTime($window['start_h'], $window['start_m'], 0);
        $end = $scheduledAt->setTime($window['end_h'], $window['end_m'], 0);

        if ($scheduledAt < $start) {
            return $start;
        }

        if ($scheduledAt > $end) {
            return $scheduledAt
                ->modify('+1 day')
                ->setTime($window['start_h'], $window['start_m'], 0);
        }

        return $scheduledAt;
    }

    private function resolveWorkingWindow(string $cityId): ?array
    {
        $cityId = trim($cityId);
        $source = null;
        if ($cityId !== '' && isset($this->workingHoursByCity[$cityId]) && is_array($this->workingHoursByCity[$cityId])) {
            $source = $this->workingHoursByCity[$cityId];
        } elseif (isset($this->workingHoursByCity['default']) && is_array($this->workingHoursByCity['default'])) {
            $source = $this->workingHoursByCity['default'];
        }

        if ($source === null) {
            return null;
        }

        $start = (string)($source['start'] ?? '');
        $end = (string)($source['end'] ?? '');
        if ($start === '' || $end === '') {
            return null;
        }

        [$startH, $startM] = array_map('intval', explode(':', $start));
        [$endH, $endM] = array_map('intval', explode(':', $end));

        return [
            'start_h' => $startH,
            'start_m' => $startM,
            'end_h' => $endH,
            'end_m' => $endM,
        ];
    }
}
