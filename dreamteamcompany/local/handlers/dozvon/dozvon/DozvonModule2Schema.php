<?php
declare(strict_types=1);

/**
 * Схема модуля 2: коды списков, статусы и определения полей.
 * Файловой очереди нет — единственный источник данных это два Bitrix-списка.
 */
final class DozvonModule2Schema
{
    public const MASTER_LIST_CODE = 'dozvon_module2_master';
    public const ATTEMPTS_LIST_CODE = 'dozvon_module2_attempts';
    public const MASTER_LIST_NAME = 'Автонедозвон - циклы';
    public const ATTEMPTS_LIST_NAME = 'Автонедозвон - попытки';

    public const MASTER_STATUS_NEW = 'new';
    public const MASTER_STATUS_QUEUE_GENERATED = 'queue_generated';
    public const MASTER_STATUS_IN_PROGRESS = 'in_progress';
    public const MASTER_STATUS_COMPLETED = 'completed';
    public const MASTER_STATUS_CANCELLED = 'cancelled';

    public const CONTROL_ACTIVE = 'active';
    public const CONTROL_PAUSED = 'paused';
    public const CONTROL_EXCLUDED = 'excluded';
    public const CONTROL_MARKED_FOR_DELETE = 'marked_for_delete';

    public const ATTEMPT_STATUS_PLANNED = 'planned';
    public const ATTEMPT_STATUS_READY = 'ready';
    public const ATTEMPT_STATUS_CALLING_CLIENT = 'calling_client';
    public const ATTEMPT_STATUS_CLIENT_BUSY = 'client_busy';
    public const ATTEMPT_STATUS_CLIENT_NO_ANSWER = 'client_no_answer';
    public const ATTEMPT_STATUS_CLIENT_ANSWERED = 'client_answered';
    public const ATTEMPT_STATUS_OPERATOR_CALLING = 'operator_calling';
    public const ATTEMPT_STATUS_OPERATOR_NO_ANSWER = 'operator_no_answer';
    public const ATTEMPT_STATUS_CONNECTED = 'connected';
    public const ATTEMPT_STATUS_CANCELLED = 'cancelled';

    public const CLIENT_CALL_STATUS_DIALING = 'dialing';
    public const CLIENT_CALL_STATUS_ANSWERED = 'answered';
    public const CLIENT_CALL_STATUS_BUSY = 'busy';
    public const CLIENT_CALL_STATUS_NO_ANSWER = 'no_answer';
    public const CLIENT_CALL_STATUS_FAILED = 'failed';
    public const CLIENT_CALL_STATUS_CANCELLED = 'cancelled';

    public const OPERATOR_CALL_STATUS_DIALING = 'dialing';
    public const OPERATOR_CALL_STATUS_ANSWERED = 'answered';
    public const OPERATOR_CALL_STATUS_NO_ANSWER = 'no_answer';
    public const OPERATOR_CALL_STATUS_FAILED = 'failed';
    public const OPERATOR_CALL_STATUS_CANCELLED = 'cancelled';

    public static function masterPropertyCodes(): array
    {
        return [
            'LEAD_ID',
            'LEAD_TITLE',
            'PHONE',
            'CITY_ID',
            'SOURCE_ID',
            'STATUS',
            'CALLING_CONTROL',
            'CYCLE_DAY_CURRENT',
            'CYCLE_DAYS_TOTAL',
            'QUEUE_GENERATED_AT',
            'FIRST_ATTEMPT_AT',
            'LAST_ATTEMPT_AT',
            'NEXT_ATTEMPT_AT',
            'COMPLETED_AT',
            'LAST_RESULT',
            'LAST_ERROR',
            'DELETE_REASON',
            'MODULE2_PROCESSED_AT',
            'ATTEMPTS_PLANNED_TOTAL',
            'ATTEMPTS_CREATED_TOTAL',
            'ATTEMPTS_COMPLETED_TOTAL',
            'ATTEMPTS_CONNECTED_TOTAL',
            'ATTEMPTS_CLIENT_NO_ANSWER_TOTAL',
            'ATTEMPTS_CLIENT_BUSY_TOTAL',
            'ATTEMPTS_OPERATOR_NO_ANSWER_TOTAL',
            'ATTEMPTS_CANCELLED_TOTAL',
            'ATTEMPTS_CLIENT_ANSWERED_TOTAL',
            'LAST_ATTEMPT_STATUS',
            'LAST_ATTEMPT_RESULT_CODE',
            'LAST_ATTEMPT_RESULT_MESSAGE',
        ];
    }

    public static function attemptPropertyCodes(): array
    {
        return [
            'MASTER_ELEMENT_ID',
            'LEAD_ID',
            'PHONE',
            'CITY_ID',
            'CYCLE_DAY',
            'ATTEMPT_NUMBER',
            'SCHEDULED_AT',
            'STATUS',
            'QUEUE_SORT_KEY',
            'SIP_NUMBER',
            'OPERATOR_ID',
            'CALL_ID',
            'VOX_SESSION_ID',
            'VOX_RECORDING_URL',
            'CREATED_AT',
            'UPDATED_AT',
            'STARTED_AT',
            'FINISHED_AT',
            'RESULT_CODE',
            'RESULT_MESSAGE',
            'CLIENT_CALL_STATUS',
            'OPERATOR_CALL_STATUS',
        ];
    }

    public static function masterListFields(): array
    {
        return [
            self::scalarField('LEAD_ID', 'ID лида', 'N', true, 100),
            self::scalarField('LEAD_TITLE', 'Название лида', 'S', false, 110),
            self::scalarField('PHONE', 'Телефон', 'S', true, 120),
            self::scalarField('CITY_ID', 'Город', 'S', true, 130),
            self::scalarField('SOURCE_ID', 'Источник лида', 'S', false, 140),
            self::listField('STATUS', 'Статус цикла', [
                self::MASTER_STATUS_NEW,
                self::MASTER_STATUS_QUEUE_GENERATED,
                self::MASTER_STATUS_IN_PROGRESS,
                self::MASTER_STATUS_COMPLETED,
                self::MASTER_STATUS_CANCELLED,
            ], true, 150),
            self::listField('CALLING_CONTROL', 'Режим обзвона', [
                self::CONTROL_ACTIVE,
                self::CONTROL_PAUSED,
                self::CONTROL_EXCLUDED,
                self::CONTROL_MARKED_FOR_DELETE,
            ], false, 160),
            self::scalarField('CYCLE_DAY_CURRENT', 'Текущий день цикла', 'N', false, 170),
            self::scalarField('CYCLE_DAYS_TOTAL', 'Всего дней цикла', 'N', false, 180),
            self::dateTimeField('QUEUE_GENERATED_AT', 'Дата формирования очереди', false, 190),
            self::dateTimeField('FIRST_ATTEMPT_AT', 'Дата первой попытки', false, 200),
            self::dateTimeField('LAST_ATTEMPT_AT', 'Дата последней попытки', false, 210),
            self::dateTimeField('NEXT_ATTEMPT_AT', 'Дата следующей попытки', false, 220),
            self::dateTimeField('COMPLETED_AT', 'Дата завершения цикла', false, 230),
            self::scalarField('LAST_RESULT', 'Последний результат', 'S', false, 240),
            self::textField('LAST_ERROR', 'Последняя ошибка', false, 250),
            self::textField('DELETE_REASON', 'Причина исключения / удаления', false, 260),
            self::dateTimeField('MODULE2_PROCESSED_AT', 'Последняя обработка модулем 2', false, 270),
            self::scalarField('ATTEMPTS_PLANNED_TOTAL', 'Всего запланировано попыток', 'N', false, 300),
            self::scalarField('ATTEMPTS_CREATED_TOTAL', 'Всего создано попыток', 'N', false, 310),
            self::scalarField('ATTEMPTS_COMPLETED_TOTAL', 'Всего завершено попыток', 'N', false, 320),
            self::scalarField('ATTEMPTS_CONNECTED_TOTAL', 'Успешных соединений', 'N', false, 330),
            self::scalarField('ATTEMPTS_CLIENT_NO_ANSWER_TOTAL', 'Клиент не ответил', 'N', false, 340),
            self::scalarField('ATTEMPTS_CLIENT_BUSY_TOTAL', 'Абонент занят', 'N', false, 350),
            self::scalarField('ATTEMPTS_OPERATOR_NO_ANSWER_TOTAL', 'Оператор не ответил', 'N', false, 360),
            self::scalarField('ATTEMPTS_CANCELLED_TOTAL', 'Отменённых попыток', 'N', false, 370),
            self::scalarField('ATTEMPTS_CLIENT_ANSWERED_TOTAL', 'Клиент ответил', 'N', false, 380),
            self::scalarField('LAST_ATTEMPT_STATUS', 'Последний статус попытки', 'S', false, 390),
            self::scalarField('LAST_ATTEMPT_RESULT_CODE', 'Код последнего результата', 'S', false, 400),
            self::textField('LAST_ATTEMPT_RESULT_MESSAGE', 'Сообщение последнего результата', false, 410),
        ];
    }

    public static function attemptListFields(int $masterListId): array
    {
        return [
            self::elementLinkField('MASTER_ELEMENT_ID', 'ID элемента списка 1', $masterListId, true, 100),
            self::scalarField('LEAD_ID', 'ID лида', 'N', true, 110),
            self::scalarField('PHONE', 'Телефон', 'S', true, 120),
            self::scalarField('CITY_ID', 'Город', 'S', true, 130),
            self::scalarField('CYCLE_DAY', 'День цикла', 'N', true, 140),
            self::scalarField('ATTEMPT_NUMBER', 'Номер попытки', 'N', true, 150),
            self::dateTimeField('SCHEDULED_AT', 'Плановое время звонка', true, 160),
            self::listField('STATUS', 'Статус попытки', [
                self::ATTEMPT_STATUS_PLANNED,
                self::ATTEMPT_STATUS_READY,
                self::ATTEMPT_STATUS_CALLING_CLIENT,
                self::ATTEMPT_STATUS_CLIENT_BUSY,
                self::ATTEMPT_STATUS_CLIENT_NO_ANSWER,
                self::ATTEMPT_STATUS_CLIENT_ANSWERED,
                self::ATTEMPT_STATUS_OPERATOR_CALLING,
                self::ATTEMPT_STATUS_OPERATOR_NO_ANSWER,
                self::ATTEMPT_STATUS_CONNECTED,
                self::ATTEMPT_STATUS_CANCELLED,
            ], true, 170),
            self::scalarField('QUEUE_SORT_KEY', 'Ключ сортировки', 'S', false, 180),
            self::scalarField('SIP_NUMBER', 'SIP номер', 'S', false, 190),
            self::employeeField('OPERATOR_ID', 'Оператор', false, 200),
            self::scalarField('CALL_ID', 'ID звонка', 'S', false, 210),
            self::scalarField('VOX_SESSION_ID', 'Voximplant session ID', 'S', false, 211),
            self::scalarField('VOX_RECORDING_URL', 'Ссылка на запись (Voximplant)', 'S', false, 212),
            self::dateTimeField('CREATED_AT', 'Дата создания', true, 220),
            self::dateTimeField('UPDATED_AT', 'Дата обновления', true, 230),
            self::dateTimeField('STARTED_AT', 'Дата начала звонка', false, 240),
            self::dateTimeField('FINISHED_AT', 'Дата завершения звонка', false, 250),
            self::scalarField('RESULT_CODE', 'Код результата', 'S', false, 260),
            self::textField('RESULT_MESSAGE', 'Сообщение результата', false, 270),
            self::listField('CLIENT_CALL_STATUS', 'Статус звонка клиенту', [
                self::CLIENT_CALL_STATUS_DIALING,
                self::CLIENT_CALL_STATUS_ANSWERED,
                self::CLIENT_CALL_STATUS_BUSY,
                self::CLIENT_CALL_STATUS_NO_ANSWER,
                self::CLIENT_CALL_STATUS_FAILED,
                self::CLIENT_CALL_STATUS_CANCELLED,
            ], false, 280),
            self::listField('OPERATOR_CALL_STATUS', 'Статус звонка оператору', [
                self::OPERATOR_CALL_STATUS_DIALING,
                self::OPERATOR_CALL_STATUS_ANSWERED,
                self::OPERATOR_CALL_STATUS_NO_ANSWER,
                self::OPERATOR_CALL_STATUS_FAILED,
                self::OPERATOR_CALL_STATUS_CANCELLED,
            ], false, 290),
        ];
    }

    public static function masterDateTimeCodes(): array
    {
        return [
            'QUEUE_GENERATED_AT',
            'FIRST_ATTEMPT_AT',
            'LAST_ATTEMPT_AT',
            'NEXT_ATTEMPT_AT',
            'COMPLETED_AT',
            'MODULE2_PROCESSED_AT',
        ];
    }

    public static function attemptDateTimeCodes(): array
    {
        return [
            'SCHEDULED_AT',
            'CREATED_AT',
            'UPDATED_AT',
            'STARTED_AT',
            'FINISHED_AT',
        ];
    }

    public static function masterListCodes(): array
    {
        return ['STATUS', 'CALLING_CONTROL'];
    }

    public static function attemptListCodes(): array
    {
        return ['STATUS', 'CLIENT_CALL_STATUS', 'OPERATOR_CALL_STATUS'];
    }

    public static function finalAttemptStatuses(): array
    {
        return [
            self::ATTEMPT_STATUS_CLIENT_BUSY,
            self::ATTEMPT_STATUS_CLIENT_NO_ANSWER,
            self::ATTEMPT_STATUS_OPERATOR_NO_ANSWER,
            self::ATTEMPT_STATUS_CONNECTED,
            self::ATTEMPT_STATUS_CANCELLED,
        ];
    }

    public static function activeAttemptStatuses(): array
    {
        return [
            self::ATTEMPT_STATUS_PLANNED,
            self::ATTEMPT_STATUS_READY,
            self::ATTEMPT_STATUS_CALLING_CLIENT,
            self::ATTEMPT_STATUS_CLIENT_ANSWERED,
            self::ATTEMPT_STATUS_OPERATOR_CALLING,
        ];
    }

    private static function scalarField(string $code, string $name, string $type, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => $type,
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'SETTINGS' => self::defaultFieldSettings(),
            ],
        ];
    }

    private static function textField(string $code, string $name, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => 'S',
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'SETTINGS' => self::defaultFieldSettings([
                    'ROW_COUNT' => '4',
                    'COL_COUNT' => '40',
                ]),
            ],
        ];
    }

    private static function dateTimeField(string $code, string $name, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => 'S:DateTime',
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'SETTINGS' => self::defaultFieldSettings(),
            ],
        ];
    }

    private static function listField(string $code, string $name, array $values, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => 'L',
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'LIST_TEXT_VALUES' => implode("\n", $values),
                'SETTINGS' => self::defaultFieldSettings(),
            ],
        ];
    }

    private static function employeeField(string $code, string $name, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => 'S:employee',
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'SETTINGS' => self::defaultFieldSettings(),
            ],
        ];
    }

    private static function elementLinkField(string $code, string $name, int $linkIblockId, bool $required, int $sort): array
    {
        return [
            'CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'CODE' => $code,
                'TYPE' => 'E:EList',
                'IS_REQUIRED' => $required ? 'Y' : 'N',
                'MULTIPLE' => 'N',
                'SORT' => (string)$sort,
                'SETTINGS' => self::defaultFieldSettings([
                    'LINK_IBLOCK_ID' => (string)$linkIblockId,
                ]),
            ],
        ];
    }

    private static function defaultFieldSettings(array $extra = []): array
    {
        return $extra + [
            'SHOW_ADD_FORM' => 'Y',
            'SHOW_EDIT_FORM' => 'Y',
            'ADD_READ_ONLY_FIELD' => 'N',
            'EDIT_READ_ONLY_FIELD' => 'N',
            'SHOW_FIELD_PREVIEW' => 'N',
        ];
    }
}
