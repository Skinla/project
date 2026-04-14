<?php
declare(strict_types=1);

/**
 * Новая реализация модуля 2 поверх двух Bitrix-списков.
 */
final class DozvonModule2Helper
{
    private array $config;
    private DozvonModule2Schedule $schedule;
    private ?DozvonUniversalListHelper $masterHelper = null;
    private ?DozvonUniversalListHelper $attemptHelper = null;
    private ?int $masterListId = null;
    private ?int $attemptListId = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->schedule = new DozvonModule2Schedule((array)($config['MODULE2_WORKING_HOURS'] ?? []));
    }

    public function ensureQueueForMaster(int $masterElementId): array
    {
        $master = $this->getMasterHelper()->getElementById($masterElementId);
        if ($master === null) {
            return ['error' => 'Master element not found'];
        }

        $control = trim((string)($master['CALLING_CONTROL'] ?? DozvonModule2Schema::CONTROL_ACTIVE));
        if ($control !== '' && $control !== DozvonModule2Schema::CONTROL_ACTIVE) {
            return ['error' => 'Master element is not active for queue generation'];
        }

        $masterStatus = trim((string)($master['STATUS'] ?? DozvonModule2Schema::MASTER_STATUS_NEW));
        if (in_array($masterStatus, [DozvonModule2Schema::MASTER_STATUS_COMPLETED, DozvonModule2Schema::MASTER_STATUS_CANCELLED], true)) {
            return ['error' => 'Master element already closed'];
        }

        $existingAttempts = $this->getAttemptsByMasterId($masterElementId);
        if (!empty($existingAttempts)) {
            $stats = $this->recalculateMasterStats($masterElementId);
            return [
                'ok' => true,
                'already_exists' => true,
                'master_id' => $masterElementId,
                'attempts_created' => count($existingAttempts),
                'stats' => $stats,
            ];
        }

        $leadId = (int)($master['LEAD_ID'] ?? 0);
        $phone = trim((string)($master['PHONE'] ?? ''));
        $cityId = trim((string)($master['CITY_ID'] ?? ''));
        if ($leadId <= 0 || $phone === '') {
            return ['error' => 'Master element must contain LEAD_ID and PHONE'];
        }

        $cycleStartDate = $this->resolveCycleStartDate($master);
        $plan = $this->schedule->buildPlan($cycleStartDate, $cityId);
        if (empty($plan)) {
            return ['error' => 'No attempts generated for master element'];
        }

        $leadCreatedTs = strtotime((string)($master['DATE_CREATE'] ?? '')) ?: time();
        $createdAt = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $created = 0;

        foreach ($plan as $slot) {
            $queueSortKey = $this->buildQueueSortKey((int)$slot['cycle_day'], $leadCreatedTs, (int)$slot['attempt_number']);
            $add = $this->getAttemptHelper()->addElement([
                'MASTER_ELEMENT_ID' => $masterElementId,
                'LEAD_ID' => $leadId,
                'PHONE' => $phone,
                'CITY_ID' => $cityId,
                'CYCLE_DAY' => (int)$slot['cycle_day'],
                'ATTEMPT_NUMBER' => (int)$slot['attempt_number'],
                'SCHEDULED_AT' => (string)$slot['scheduled_at'],
                'STATUS' => DozvonModule2Schema::ATTEMPT_STATUS_PLANNED,
                'QUEUE_SORT_KEY' => $queueSortKey,
                'CREATED_AT' => $createdAt,
                'UPDATED_AT' => $createdAt,
            ], $this->buildAttemptName($leadId, (int)$slot['attempt_number'], (int)$slot['cycle_day']));

            if (!empty($add['error'])) {
                return ['error' => 'Failed to create attempt: ' . $add['error']];
            }

            $created++;
        }

        $this->getMasterHelper()->updateElement($masterElementId, [
            'STATUS' => DozvonModule2Schema::MASTER_STATUS_QUEUE_GENERATED,
            'CALLING_CONTROL' => $control !== '' ? $control : DozvonModule2Schema::CONTROL_ACTIVE,
            'CYCLE_DAY_CURRENT' => 1,
            'CYCLE_DAYS_TOTAL' => $this->schedule->getCycleDaysTotal(),
            'QUEUE_GENERATED_AT' => $createdAt,
            'FIRST_ATTEMPT_AT' => (string)$plan[0]['scheduled_at'],
            'NEXT_ATTEMPT_AT' => (string)$plan[0]['scheduled_at'],
            'MODULE2_PROCESSED_AT' => $createdAt,
            'ATTEMPTS_PLANNED_TOTAL' => count($plan),
            'ATTEMPTS_CREATED_TOTAL' => $created,
        ]);

        $stats = $this->recalculateMasterStats($masterElementId);

        return [
            'ok' => true,
            'master_id' => $masterElementId,
            'attempts_created' => $created,
            'stats' => $stats,
        ];
    }

    public function recalculateMasterStats(int $masterElementId): array
    {
        $master = $this->getMasterHelper()->getElementById($masterElementId);
        if ($master === null) {
            return ['error' => 'Master element not found'];
        }

        $attempts = array_values($this->getAttemptsByMasterId($masterElementId));
        $now = new DateTimeImmutable();
        $cycleStartDate = $this->resolveCycleStartDate($master);

        $counts = [
            'planned' => count($attempts),
            'completed' => 0,
            'connected' => 0,
            'client_no_answer' => 0,
            'client_busy' => 0,
            'operator_no_answer' => 0,
            'cancelled' => 0,
            'client_answered' => 0,
        ];

        $lastAttempt = null;
        $nextAttempt = null;

        foreach ($attempts as $attempt) {
            $status = trim((string)($attempt['STATUS'] ?? ''));
            switch ($status) {
                case DozvonModule2Schema::ATTEMPT_STATUS_CONNECTED:
                    $counts['connected']++;
                    $counts['completed']++;
                    break;
                case DozvonModule2Schema::ATTEMPT_STATUS_CLIENT_NO_ANSWER:
                    $counts['client_no_answer']++;
                    $counts['completed']++;
                    break;
                case DozvonModule2Schema::ATTEMPT_STATUS_CLIENT_BUSY:
                    $counts['client_busy']++;
                    $counts['completed']++;
                    break;
                case DozvonModule2Schema::ATTEMPT_STATUS_OPERATOR_NO_ANSWER:
                    $counts['operator_no_answer']++;
                    $counts['completed']++;
                    break;
                case DozvonModule2Schema::ATTEMPT_STATUS_CANCELLED:
                    $counts['cancelled']++;
                    $counts['completed']++;
                    break;
                case DozvonModule2Schema::ATTEMPT_STATUS_CLIENT_ANSWERED:
                    $counts['client_answered']++;
                    break;
            }

            $lastAt = $this->extractLastAttemptTimestamp($attempt);
            if ($lastAt !== null && ($lastAttempt === null || $lastAt > $lastAttempt['sort'])) {
                $lastAttempt = [
                    'sort' => $lastAt,
                    'row' => $attempt,
                ];
            }

            if (in_array($status, DozvonModule2Schema::activeAttemptStatuses(), true)) {
                $scheduledAt = trim((string)($attempt['SCHEDULED_AT'] ?? ''));
                if ($scheduledAt !== '' && ($nextAttempt === null || strcmp($scheduledAt, $nextAttempt['SCHEDULED_AT']) < 0)) {
                    $nextAttempt = $attempt;
                }
            }
        }

        $connected = $counts['connected'] > 0;
        $status = trim((string)($master['STATUS'] ?? DozvonModule2Schema::MASTER_STATUS_NEW));
        if ($connected) {
            $status = DozvonModule2Schema::MASTER_STATUS_COMPLETED;
        } elseif (!empty($attempts) && $counts['completed'] > 0) {
            $status = DozvonModule2Schema::MASTER_STATUS_IN_PROGRESS;
        } elseif (!empty($attempts)) {
            $status = DozvonModule2Schema::MASTER_STATUS_QUEUE_GENERATED;
        }

        $updateFields = [
            'STATUS' => $status,
            'CYCLE_DAY_CURRENT' => $this->schedule->getCycleDay($cycleStartDate, $now),
            'CYCLE_DAYS_TOTAL' => $this->schedule->getCycleDaysTotal(),
            'MODULE2_PROCESSED_AT' => $now->format('Y-m-d\TH:i:s'),
            'ATTEMPTS_PLANNED_TOTAL' => count($attempts),
            'ATTEMPTS_CREATED_TOTAL' => count($attempts),
            'ATTEMPTS_COMPLETED_TOTAL' => $counts['completed'],
            'ATTEMPTS_CONNECTED_TOTAL' => $counts['connected'],
            'ATTEMPTS_CLIENT_NO_ANSWER_TOTAL' => $counts['client_no_answer'],
            'ATTEMPTS_CLIENT_BUSY_TOTAL' => $counts['client_busy'],
            'ATTEMPTS_OPERATOR_NO_ANSWER_TOTAL' => $counts['operator_no_answer'],
            'ATTEMPTS_CANCELLED_TOTAL' => $counts['cancelled'],
            'ATTEMPTS_CLIENT_ANSWERED_TOTAL' => $counts['client_answered'],
            'NEXT_ATTEMPT_AT' => $nextAttempt['SCHEDULED_AT'] ?? '',
        ];

        if ($connected || $this->allAttemptsClosed($attempts)) {
            $updateFields['COMPLETED_AT'] = $now->format('Y-m-d\TH:i:s');
        }

        if ($lastAttempt !== null) {
            $row = $lastAttempt['row'];
            $updateFields['LAST_ATTEMPT_AT'] = $this->extractLastAttemptAtString($row);
            $updateFields['LAST_RESULT'] = trim((string)($row['RESULT_MESSAGE'] ?? $row['STATUS'] ?? ''));
            $updateFields['LAST_ATTEMPT_STATUS'] = trim((string)($row['STATUS'] ?? ''));
            $updateFields['LAST_ATTEMPT_RESULT_CODE'] = trim((string)($row['RESULT_CODE'] ?? ''));
            $updateFields['LAST_ATTEMPT_RESULT_MESSAGE'] = trim((string)($row['RESULT_MESSAGE'] ?? ''));
        }

        if (!empty($attempts)) {
            $updateFields['FIRST_ATTEMPT_AT'] = $attempts[0]['SCHEDULED_AT'] ?? '';
        }

        $this->getMasterHelper()->updateElement($masterElementId, $updateFields);

        return [
            'master_id' => $masterElementId,
            'status' => $status,
            'counts' => $counts,
            'next_attempt_at' => $updateFields['NEXT_ATTEMPT_AT'] ?? '',
        ];
    }

    public function updateAttemptStatus(int $attemptElementId, string $status, array $extra = []): array
    {
        $attempt = $this->getAttemptHelper()->getElementById($attemptElementId);
        if ($attempt === null) {
            return ['error' => 'Attempt element not found'];
        }

        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $fields = [
            'STATUS' => $status,
            'UPDATED_AT' => $now,
        ];

        if (in_array($status, [
            DozvonModule2Schema::ATTEMPT_STATUS_CALLING_CLIENT,
            DozvonModule2Schema::ATTEMPT_STATUS_CLIENT_ANSWERED,
            DozvonModule2Schema::ATTEMPT_STATUS_OPERATOR_CALLING,
        ], true)) {
            $fields['STARTED_AT'] = $extra['STARTED_AT'] ?? $now;
        }

        if (in_array($status, DozvonModule2Schema::finalAttemptStatuses(), true)) {
            $fields['FINISHED_AT'] = $extra['FINISHED_AT'] ?? $now;
        }

        foreach ([
            'RESULT_CODE',
            'RESULT_MESSAGE',
            'CLIENT_CALL_STATUS',
            'OPERATOR_CALL_STATUS',
            'SIP_NUMBER',
            'OPERATOR_ID',
            'CALL_ID',
            'STARTED_AT',
            'FINISHED_AT',
        ] as $code) {
            if (array_key_exists($code, $extra)) {
                $fields[$code] = $extra[$code];
            }
        }

        $result = $this->getAttemptHelper()->updateElement($attemptElementId, $fields);
        if (!empty($result['error'])) {
            return $result;
        }

        $masterElementId = (int)($attempt['MASTER_ELEMENT_ID'] ?? 0);
        if ($masterElementId > 0 && $status === DozvonModule2Schema::ATTEMPT_STATUS_CONNECTED) {
            $this->cancelFutureAttempts($masterElementId, 'connected_completed', 'Cancelled after successful connection');
        }

        $stats = $masterElementId > 0 ? $this->recalculateMasterStats($masterElementId) : [];

        return [
            'ok' => true,
            'attempt_id' => $attemptElementId,
            'master_id' => $masterElementId,
            'stats' => $stats,
        ];
    }

    public function activateDueAttempts(?DateTimeInterface $now = null, int $limit = 200): array
    {
        $nowValue = ($now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $attempts = $this->getAttemptHelper()->getElements([
            'STATUS' => DozvonModule2Schema::ATTEMPT_STATUS_PLANNED,
            '<=SCHEDULED_AT' => $nowValue,
        ], $limit);

        $activated = 0;
        foreach ($attempts as $attempt) {
            $masterId = (int)($attempt['MASTER_ELEMENT_ID'] ?? 0);
            $master = $masterId > 0 ? $this->getMasterHelper()->getElementById($masterId) : null;
            if ($master === null) {
                continue;
            }

            $control = trim((string)($master['CALLING_CONTROL'] ?? DozvonModule2Schema::CONTROL_ACTIVE));
            $masterStatus = trim((string)($master['STATUS'] ?? ''));
            if ($control !== DozvonModule2Schema::CONTROL_ACTIVE) {
                continue;
            }
            if (in_array($masterStatus, [DozvonModule2Schema::MASTER_STATUS_COMPLETED, DozvonModule2Schema::MASTER_STATUS_CANCELLED], true)) {
                continue;
            }

            $this->getAttemptHelper()->updateElement((int)$attempt['ID'], [
                'STATUS' => DozvonModule2Schema::ATTEMPT_STATUS_READY,
                'UPDATED_AT' => $nowValue,
            ]);
            $activated++;
        }

        return [
            'ok' => true,
            'activated' => $activated,
        ];
    }

    public function getActiveQueue(int $limit = 50, ?DateTimeInterface $now = null): array
    {
        $this->activateDueAttempts($now);
        $attempts = array_values($this->getAttemptHelper()->getElements([
            'STATUS' => DozvonModule2Schema::ATTEMPT_STATUS_READY,
        ], max($limit * 5, 100)));

        $queue = [];
        foreach ($attempts as $attempt) {
            $masterId = (int)($attempt['MASTER_ELEMENT_ID'] ?? 0);
            $master = $masterId > 0 ? $this->getMasterHelper()->getElementById($masterId) : null;
            if ($master === null) {
                continue;
            }

            $control = trim((string)($master['CALLING_CONTROL'] ?? DozvonModule2Schema::CONTROL_ACTIVE));
            $masterStatus = trim((string)($master['STATUS'] ?? ''));
            if ($control !== DozvonModule2Schema::CONTROL_ACTIVE) {
                continue;
            }
            if (in_array($masterStatus, [DozvonModule2Schema::MASTER_STATUS_COMPLETED, DozvonModule2Schema::MASTER_STATUS_CANCELLED], true)) {
                continue;
            }

            $attempt['_MASTER_DATE_CREATE_TS'] = strtotime((string)($master['DATE_CREATE'] ?? '')) ?: 0;
            $attempt['_MASTER_CONTROL'] = $control;
            $queue[] = $attempt;
        }

        usort($queue, static function (array $a, array $b): int {
            $cycleCompare = (int)($a['CYCLE_DAY'] ?? 0) <=> (int)($b['CYCLE_DAY'] ?? 0);
            if ($cycleCompare !== 0) {
                return $cycleCompare;
            }

            $leadDateCompare = (int)($b['_MASTER_DATE_CREATE_TS'] ?? 0) <=> (int)($a['_MASTER_DATE_CREATE_TS'] ?? 0);
            if ($leadDateCompare !== 0) {
                return $leadDateCompare;
            }

            return strcmp((string)($a['SCHEDULED_AT'] ?? ''), (string)($b['SCHEDULED_AT'] ?? ''));
        });

        return array_slice($queue, 0, $limit);
    }

    public function cancelFutureAttempts(int $masterElementId, string $resultCode = 'cancelled', string $resultMessage = ''): int
    {
        $attempts = $this->getAttemptsByMasterId($masterElementId);
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $cancelled = 0;

        foreach ($attempts as $attempt) {
            $status = trim((string)($attempt['STATUS'] ?? ''));
            if (!in_array($status, DozvonModule2Schema::activeAttemptStatuses(), true)) {
                continue;
            }

            $this->getAttemptHelper()->updateElement((int)$attempt['ID'], [
                'STATUS' => DozvonModule2Schema::ATTEMPT_STATUS_CANCELLED,
                'UPDATED_AT' => $now,
                'FINISHED_AT' => $now,
                'RESULT_CODE' => $resultCode,
                'RESULT_MESSAGE' => $resultMessage,
            ]);
            $cancelled++;
        }

        return $cancelled;
    }

    public function getMasterListId(): int
    {
        return $this->resolveMasterListId();
    }

    public function getAttemptListId(): int
    {
        return $this->resolveAttemptListId();
    }

    private function getMasterHelper(): DozvonUniversalListHelper
    {
        if ($this->masterHelper === null) {
            $this->masterHelper = new DozvonUniversalListHelper(
                $this->resolveMasterListId(),
                DozvonModule2Schema::masterPropertyCodes(),
                DozvonModule2Schema::masterListCodes(),
                DozvonModule2Schema::masterDateTimeCodes()
            );
        }

        return $this->masterHelper;
    }

    private function getAttemptHelper(): DozvonUniversalListHelper
    {
        if ($this->attemptHelper === null) {
            $this->attemptHelper = new DozvonUniversalListHelper(
                $this->resolveAttemptListId(),
                DozvonModule2Schema::attemptPropertyCodes(),
                DozvonModule2Schema::attemptListCodes(),
                DozvonModule2Schema::attemptDateTimeCodes()
            );
        }

        return $this->attemptHelper;
    }

    private function resolveMasterListId(): int
    {
        if ($this->masterListId !== null) {
            return $this->masterListId;
        }

        $configured = (int)($this->config['MODULE2_MASTER_LIST_ID'] ?? 0);
        if ($configured > 0) {
            return $this->masterListId = $configured;
        }

        return $this->masterListId = $this->resolveListIdByCode(
            (string)($this->config['MODULE2_MASTER_LIST_CODE'] ?? DozvonModule2Schema::MASTER_LIST_CODE)
        );
    }

    private function resolveAttemptListId(): int
    {
        if ($this->attemptListId !== null) {
            return $this->attemptListId;
        }

        $configured = (int)($this->config['MODULE2_ATTEMPTS_LIST_ID'] ?? 0);
        if ($configured > 0) {
            return $this->attemptListId = $configured;
        }

        return $this->attemptListId = $this->resolveListIdByCode(
            (string)($this->config['MODULE2_ATTEMPTS_LIST_CODE'] ?? DozvonModule2Schema::ATTEMPTS_LIST_CODE)
        );
    }

    private function resolveListIdByCode(string $code): int
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new RuntimeException('Module iblock not installed');
        }

        $typeId = (string)($this->config['MODULE2_LISTS_IBLOCK_TYPE_ID'] ?? 'lists');
        $res = CIBlock::GetList(
            ['ID' => 'ASC'],
            [
                'ACTIVE' => 'Y',
                'CODE' => $code,
                'TYPE' => $typeId,
                'CHECK_PERMISSIONS' => 'N',
            ]
        );
        if ($row = $res->Fetch()) {
            return (int)$row['ID'];
        }

        throw new RuntimeException('List not found by code: ' . $code);
    }

    private function getAttemptsByMasterId(int $masterElementId): array
    {
        return $this->getAttemptHelper()->getElements(['MASTER_ELEMENT_ID' => $masterElementId], 1000);
    }

    private function resolveCycleStartDate(array $master): DateTimeImmutable
    {
        $source = trim((string)($master['QUEUE_GENERATED_AT'] ?? ''));
        if ($source === '') {
            $source = trim((string)($master['DATE_CREATE'] ?? ''));
        }
        if ($source === '') {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($source);
        } catch (Throwable $e) {
            return new DateTimeImmutable();
        }
    }

    private function buildQueueSortKey(int $cycleDay, int $leadCreatedTs, int $attemptNumber): string
    {
        $invertedLeadTs = max(0, 9999999999 - $leadCreatedTs);
        return sprintf('%02d_%010d_%03d', $cycleDay, $invertedLeadTs, $attemptNumber);
    }

    private function buildAttemptName(int $leadId, int $attemptNumber, int $cycleDay): string
    {
        return sprintf('Dozvon lead %d attempt %d day %d', $leadId, $attemptNumber, $cycleDay);
    }

    private function extractLastAttemptTimestamp(array $attempt): ?int
    {
        foreach (['FINISHED_AT', 'UPDATED_AT', 'STARTED_AT', 'SCHEDULED_AT', 'DATE_CREATE'] as $code) {
            $value = trim((string)($attempt[$code] ?? ''));
            if ($value === '') {
                continue;
            }
            $ts = strtotime($value);
            if ($ts !== false) {
                return $ts;
            }
        }

        return null;
    }

    private function extractLastAttemptAtString(array $attempt): string
    {
        foreach (['FINISHED_AT', 'UPDATED_AT', 'STARTED_AT', 'SCHEDULED_AT'] as $code) {
            $value = trim((string)($attempt[$code] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function allAttemptsClosed(array $attempts): bool
    {
        if (empty($attempts)) {
            return false;
        }

        foreach ($attempts as $attempt) {
            $status = trim((string)($attempt['STATUS'] ?? ''));
            if (!in_array($status, DozvonModule2Schema::finalAttemptStatuses(), true)) {
                return false;
            }
        }

        return true;
    }
}
