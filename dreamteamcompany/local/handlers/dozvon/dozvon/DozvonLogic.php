<?php
/**
 * Логика недозвона: день цикла от DATE_CREATE, тип лида, слоты, пулы, создание очереди и записи cycle.
 * Размещение: local/handlers/dozvon/DozvonLogic.php.
 * Соответствие: MODULE_DEVELOPMENT_PLAN.md, ТЗ п. 3.2. createQueueForLead принимает phone и сохраняет в каждой записи queue.
 */

class DozvonLogic
{
    private const CYCLE_LAST_DAY_DEFAULT = 21;
    private const MORNING_START_H = 9;
    private const DAY_START_H = 11;
    private const DAY_END_H = 13;
    private const DAY_END_M = 0;

    private int $cycleLastDayDefault;

    public function __construct(int $cycleLastDayDefault = self::CYCLE_LAST_DAY_DEFAULT)
    {
        $this->cycleLastDayDefault = $cycleLastDayDefault;
    }

    /**
     * День цикла от DATE_CREATE лида (1..21).
     */
    public function getCycleDayFromDateCreate(DateTimeInterface $dateCreate, ?DateTimeInterface $now = null): int
    {
        $start = $dateCreate instanceof DateTime ? clone $dateCreate : DateTime::createFromInterface($dateCreate);
        $end = $now instanceof DateTime ? clone $now : ($now ? DateTime::createFromInterface($now) : new DateTime());
        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);
        $days = (int)$start->diff($end)->days + 1;
        return max(1, min($this->cycleLastDayDefault, $days));
    }

    /**
     * Тип лида по времени перевода в «Недозвон»: утренний 09:00–10:59, дневной 11:00–13:00.
     *
     * @return 'morning'|'day'
     */
    public function getLeadTypeByUndialTime(DateTimeInterface $undialAt): string
    {
        $h = (int)$undialAt->format('G');
        $m = (int)$undialAt->format('i');
        if ($h < self::MORNING_START_H) {
            return 'day';
        }
        if ($h < self::DAY_START_H) {
            return 'morning';
        }
        if ($h < self::DAY_END_H || ($h === self::DAY_END_H && $m <= self::DAY_END_M)) {
            return 'day';
        }
        return 'day';
    }

    /**
     * Пул номеров по дню цикла: дни 1–3 «Лиды», с 4-го «Карусель Лиды».
     *
     * @return 'Лиды'|'Карусель Лиды'
     */
    public function getPoolNameByCycleDay(int $cycleDay): string
    {
        return $cycleDay <= 3 ? 'Лиды' : 'Карусель Лиды';
    }

    /**
     * Регламент слотов по ТЗ п. 3.2: дни 1–10, утренний/дневной лид.
     * День 1: утренний — 2 утром + 2 вечер 16:00–19:30; дневной — 1 раз 11:00–15:00 + 2 раза 16:00–19:00.
     * Дни 2–3: 4 звонка (2 в 11:00–15:00, 2 в 16:00–19:00).
     * День 4: 3 звонка. Дни 5–7: по 2 звонка. Дни 8–10: 12:00–18:00; день 10 — только 15:15 (после него → Некачественный).
     * Дни 11–21 (если cycleLastDay=21): по 2 звонка как день 7.
     *
     * @param 'morning'|'day' $leadType
     * @return array<int, array{cycleDay: int, time: string, scheduledAt: string}>
     */
    public function getSlotsForDayAndType(int $cycleDay, string $leadType, DateTimeInterface $leadDateCreate, int $cycleLastDay): array
    {
        if ($cycleDay > $cycleLastDay) {
            return [];
        }

        $times = $this->getSlotTimesForDayAndType($cycleDay, $leadType);
        if (empty($times)) {
            return [];
        }

        $baseDate = $leadDateCreate instanceof DateTime ? clone $leadDateCreate : DateTime::createFromInterface($leadDateCreate);
        $baseDate->setTime(0, 0, 0);
        $baseDate->modify('+' . ($cycleDay - 1) . ' days');

        $result = [];
        foreach ($times as $time) {
            [$hh, $mm] = explode(':', $time);
            $d = clone $baseDate;
            $d->setTime((int)$hh, (int)$mm, 0);
            $result[] = [
                'cycleDay' => $cycleDay,
                'time' => $time,
                'scheduledAt' => $d->format('Y-m-d\TH:i:s'),
            ];
        }
        return $result;
    }

    /**
     * Список времён (HH:mm) для слотов по дню и типу лида. ТЗ п. 3.2.
     *
     * @param 'morning'|'day' $leadType
     * @return string[]
     */
    private function getSlotTimesForDayAndType(int $cycleDay, string $leadType): array
    {
        if ($cycleDay <= 0) {
            return [];
        }
        if ($cycleDay === 10) {
            return ['15:15'];
        }
        if ($cycleDay === 1) {
            if ($leadType === 'morning') {
                return ['09:30', '10:30', '16:00', '18:30'];
            }
            return ['13:00', '16:30', '18:00'];
        }
        if ($cycleDay === 2 || $cycleDay === 3) {
            return ['11:30', '13:30', '16:30', '18:30'];
        }
        if ($cycleDay === 4) {
            return ['11:00', '13:00', '17:00'];
        }
        if ($cycleDay >= 5 && $cycleDay <= 7) {
            return ['13:00', '17:30'];
        }
        if ($cycleDay === 8 || $cycleDay === 9) {
            return ['12:00', '15:00', '18:00'];
        }
        if ($cycleDay >= 11 && $cycleDay <= 21) {
            return ['13:00', '17:30'];
        }
        return [];
    }

    /**
     * Построить все слоты на цикл (дни 1 … cycleLastDay).
     *
     * @param 'morning'|'day' $leadType
     * @return array<int, array{cycleDay: int, time: string, scheduledAt: string}>
     */
    public function buildAllSlots(DateTimeInterface $leadDateCreate, string $leadType, int $cycleLastDay): array
    {
        $result = [];
        for ($day = 1; $day <= $cycleLastDay; $day++) {
            $slots = $this->getSlotsForDayAndType($day, $leadType, $leadDateCreate, $cycleLastDay);
            foreach ($slots as $slot) {
                $result[] = $slot;
            }
        }
        return $result;
    }

    /**
     * Дата последнего дня цикла (календарная): DATE_CREATE + (cycleLastDay - 1) дней.
     */
    public function getCycleEndDate(DateTimeInterface $leadDateCreate, int $cycleLastDay): string
    {
        $d = $leadDateCreate instanceof DateTime ? clone $leadDateCreate : DateTime::createFromInterface($leadDateCreate);
        $d->setTime(0, 0, 0);
        $d->modify('+' . ($cycleLastDay - 1) . ' days');
        return $d->format('Y-m-d');
    }

    /**
     * Создать цикл для лида: запись cycle в списке + JSON-файл расписания (слоты в файле).
     * Если передан $triggerElementId — обновляем этот элемент до cycle (один элемент вместо двух).
     *
     * @param DozvonScheduleStorage $scheduleStorage хранилище JSON (папка schedules/)
     * @param int|null $triggerElementId ID элемента-триггера; при переданном — обновить его до cycle, иначе создать новый элемент
     * @return array{cycleRecordId?: int, scheduleFilename: string, lastScheduledCallDate: string, slotsCount: int, error?: string}
     */
    public function createQueueForLead(int $leadId, DateTimeInterface $dateCreate, DateTimeInterface $undialAt, DozvonListHelper $helper, ?int $cycleLastDay = null, ?string $phone = null, ?DozvonScheduleStorage $scheduleStorage = null, ?int $triggerElementId = null): array
    {
        $cycleLastDay = $cycleLastDay ?? $this->cycleLastDayDefault;
        $leadType = $this->getLeadTypeByUndialTime($undialAt);
        $slots = $this->buildAllSlots($dateCreate, $leadType, $cycleLastDay);

        $lastScheduledCallDate = '';
        foreach ($slots as $slot) {
            if ($slot['scheduledAt'] > $lastScheduledCallDate) {
                $lastScheduledCallDate = $slot['scheduledAt'];
            }
        }

        if ($scheduleStorage === null) {
            return ['scheduleFilename' => '', 'lastScheduledCallDate' => $lastScheduledCallDate, 'slotsCount' => 0, 'error' => 'DozvonScheduleStorage required'];
        }

        $scheduleSlots = [];
        foreach ($slots as $i => $slot) {
            $scheduleSlots[] = [
                'scheduled_at' => $slot['scheduledAt'],
                'cycle_day' => $slot['cycleDay'],
                'attempt_number' => $i + 1,
                'status' => 'pending',
                'attempted_at' => null,
                'retry_at' => null,
            ];
        }

        $filename = $scheduleStorage->filenameForLead($leadId);
        $createdAt = (new DateTime())->format('Y-m-d\TH:i:s');
        $data = [
            'lead_id' => $leadId,
            'created_at' => $createdAt,
            'slots' => $scheduleSlots,
        ];
        if (!$scheduleStorage->write($filename, $data)) {
            return ['scheduleFilename' => $filename, 'lastScheduledCallDate' => $lastScheduledCallDate, 'slotsCount' => count($slots), 'error' => 'Failed to write schedule file'];
        }

        $firstSlotAt = $slots[0]['scheduledAt'] ?? $createdAt;
        $cycleEndDate = $this->getCycleEndDate($dateCreate, $cycleLastDay);
        $phoneValue = $phone !== null && $phone !== '' ? trim($phone) : '';
        $cycleFields = [
            'RECORD_TYPE' => 'cycle',
            'LEAD_ID' => $leadId,
            'PHONE' => $phoneValue,
            'SCHEDULE_FILENAME' => $filename,
            'NEXT_SLOT_AT' => $firstSlotAt,
            'UNDIAL_AT' => $undialAt->format('Y-m-d\TH:i:s'),
            'LEAD_TYPE' => $leadType,
            'CYCLE_DAY' => $this->getCycleDayFromDateCreate($dateCreate),
            'CYCLE_LAST_DAY' => $cycleLastDay,
            'CYCLE_END_DATE' => $cycleEndDate,
            'LAST_SCHEDULED_CALL_DATE' => $lastScheduledCallDate,
            'IN_CYCLE' => 'Y',
            'CREATED_AT' => $createdAt,
            'UPDATED_AT' => $createdAt,
        ];

        if ($triggerElementId !== null && $triggerElementId > 0) {
            $cycleUpdate = $helper->updateElement($triggerElementId, $cycleFields);
            $cycleRecordId = $triggerElementId;
            if (isset($cycleUpdate['error'])) {
                $scheduleStorage->delete($filename);
                return ['scheduleFilename' => $filename, 'lastScheduledCallDate' => $lastScheduledCallDate, 'slotsCount' => count($slots), 'error' => $cycleUpdate['error']];
            }
        } else {
            $cycleAdd = $helper->addElement($cycleFields);
            $cycleRecordId = isset($cycleAdd['id']) ? $cycleAdd['id'] : null;
            if (isset($cycleAdd['error'])) {
                $scheduleStorage->delete($filename);
                return ['scheduleFilename' => $filename, 'lastScheduledCallDate' => $lastScheduledCallDate, 'slotsCount' => count($slots), 'error' => $cycleAdd['error']];
            }
        }

        return [
            'cycleRecordId' => $cycleRecordId,
            'scheduleFilename' => $filename,
            'schedulePath' => $scheduleStorage->pathForFilename($filename),
            'lastScheduledCallDate' => $lastScheduledCallDate,
            'slotsCount' => count($slots),
        ];
    }
}
