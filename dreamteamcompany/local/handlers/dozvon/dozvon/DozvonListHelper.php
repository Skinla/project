<?php
/**
 * Работа со списком «Недозвон» (IBLOCK_ID из конфига, local/handlers/dozvon/).
 * Внутренний API Bitrix: CIBlockElement. PHONE в queue и trigger; LEAD_DATE_CREATE и PROCESSED_AT в trigger; RECORD_TYPE = queue|attempt|cycle|trigger.
 */

class DozvonListHelper
{
    /** Коды свойств типа дата/время: при записи конвертируем в формат Bitrix d.m.Y H:i:s */
    private const DATETIME_PROPERTY_CODES = [
        'CREATED_AT', 'SCHEDULED_AT', 'RETRY_AT', 'PROCESSED_AT', 'ATTEMPTED_AT',
        'UNDIAL_AT', 'LAST_SCHEDULED_CALL_DATE', 'UPDATED_AT', 'LEAD_DATE_CREATE',
        'NEXT_SLOT_AT',
    ];
    /** Коды свойств типа дата (без времени): конвертируем в d.m.Y */
    private const DATE_PROPERTY_CODES = ['CYCLE_END_DATE'];
    /** Коды свойств типа список: при записи передаём enum ID */
    private const LIST_PROPERTY_CODES = ['RECORD_TYPE', 'LEAD_TYPE', 'STATUS', 'ATTEMPT_STATUS', 'IN_CYCLE'];

    private int $listId;
    private array $propertyCodes;
    /** @var array<string, int>|null кэш: код свойства => ID свойства (только существующие в списке) */
    private ?array $existingPropertyCodeToId = null;

    public function __construct(int $listId)
    {
        $this->listId = $listId;
        $this->propertyCodes = [
            'RECORD_TYPE', 'LEAD_ID', 'PHONE', 'LEAD_DATE_CREATE', 'CREATED_AT',
            'SCHEDULED_AT', 'CYCLE_DAY', 'ATTEMPT_NUMBER', 'STATUS', 'RETRY_AT', 'PROCESSED_AT',
            'ATTEMPTED_AT', 'FROM_NUMBER', 'ATTEMPT_STATUS', 'QUEUE_ELEMENT_ID', 'CRM_ACTIVITY_ID',
            'UNDIAL_AT', 'LEAD_TYPE', 'CYCLE_LAST_DAY', 'CYCLE_END_DATE', 'LAST_SCHEDULED_CALL_DATE', 'IN_CYCLE', 'UPDATED_AT',
            'SCHEDULE_FILENAME', 'NEXT_SLOT_AT',
        ];
    }

    /**
     * Получить элементы списка с фильтром.
     *
     * @param array $filter ['RECORD_TYPE' => 'queue', 'STATUS' => 'pending', '<=SCHEDULED_AT' => $now, ...]
     * @param int   $limit
     * @return array<int, array<string, mixed>>
     */
    public function getElements(array $filter, int $limit = 100): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return [];
        }

        $iblockFilter = ['IBLOCK_ID' => $this->listId, 'CHECK_PERMISSIONS' => 'N'];
        foreach ($filter as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $filterValue = $this->formatPropertyValueForBitrixFilter($key, $value);
            if (strpos($key, '<=') === 0) {
                $code = substr($key, 2);
                $iblockFilter['<=PROPERTY_' . $code] = $filterValue;
            } elseif (strpos($key, '>=') === 0) {
                $code = substr($key, 2);
                $iblockFilter['>=PROPERTY_' . $code] = $filterValue;
            } else {
                $iblockFilter['PROPERTY_' . $key] = $filterValue;
            }
        }

        $select = ['ID', 'NAME', 'DATE_CREATE'];
        foreach ($this->propertyCodes as $code) {
            $select[] = 'PROPERTY_' . $code;
        }

        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $iblockFilter,
            false,
            ['nTopCount' => $limit],
            $select
        );

        if (!$res) {
            return [];
        }

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            $item = ['ID' => (int)$fields['ID'], 'NAME' => $fields['NAME'] ?? '', 'DATE_CREATE' => $fields['DATE_CREATE'] ?? null];
            foreach ($this->propertyCodes as $code) {
                $val = $props[$code]['VALUE'] ?? $fields['PROPERTY_' . $code . '_VALUE'] ?? null;
                $item[$code] = $this->normalizePropertyValueFromBitrix($code, $val);
            }
            $items[$item['ID']] = $item;
        }
        return $items;
    }

    /**
     * Получить один элемент списка по ID.
     *
     * @return array<string, mixed>|null Элемент в том же формате, что и в getElements, или null.
     */
    public function getElementById(int $elementId): ?array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return null;
        }
        $res = CIBlockElement::GetByID($elementId);
        if (!$res || !($ob = $res->GetNextElement())) {
            return null;
        }
        $fields = $ob->GetFields();
        if (((int)($fields['IBLOCK_ID'] ?? 0)) !== $this->listId) {
            return null;
        }
        $props = $ob->GetProperties();
        $item = ['ID' => (int)$fields['ID'], 'NAME' => $fields['NAME'] ?? '', 'DATE_CREATE' => $fields['DATE_CREATE'] ?? null];
        foreach ($this->propertyCodes as $code) {
            $val = $props[$code]['VALUE'] ?? $fields['PROPERTY_' . $code . '_VALUE'] ?? null;
            $item[$code] = $this->normalizePropertyValueFromBitrix($code, $val);
        }
        return $item;
    }

    /**
     * Добавить элемент в список (queue, attempt или cycle).
     *
     * @param array<string, mixed> $fields Ключи — коды полей (RECORD_TYPE, LEAD_ID, ...)
     * @return array{id: int}|array{error: string}
     */
    public function addElement(array $fields): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['error' => 'Module iblock not installed'];
        }

        $name = 'Dozvon ' . ($fields['RECORD_TYPE'] ?? '') . ' ' . ($fields['LEAD_ID'] ?? '') . ' ' . date('Y-m-d H:i:s');
        $propValues = [];
        foreach ($this->propertyCodes as $code) {
            if (!array_key_exists($code, $fields) || $fields[$code] === null || $fields[$code] === '') {
                continue;
            }
            $value = $fields[$code];
            $propValues[$code] = $this->formatPropertyValueForBitrix($code, $value);
        }
        $codeToId = $this->getExistingPropertyCodeToId();
        $propValuesFiltered = [];
        foreach ($propValues as $code => $value) {
            if (isset($codeToId[$code])) {
                $propValuesFiltered[$code] = $value;
            }
        }
        $propValues = $propValuesFiltered;

        $el = new CIBlockElement();
        $id = $el->Add([
            'IBLOCK_ID' => $this->listId,
            'NAME' => $name,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => $propValues,
        ]);

        if (!$id) {
            return ['error' => $el->LAST_ERROR ?: 'Add failed'];
        }
        return ['id' => (int)$id];
    }

    /**
     * Карта код свойства => ID свойства для всех свойств инфоблока.
     * SetPropertyValuesEx в ряде конфигураций Bitrix стабильнее работает с ключами = ID.
     *
     * @return array<string, int>
     */
    private function getExistingPropertyCodeToId(): array
    {
        if ($this->existingPropertyCodeToId !== null) {
            return $this->existingPropertyCodeToId;
        }
        $this->existingPropertyCodeToId = [];
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return $this->existingPropertyCodeToId;
        }
        $res = CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $this->listId]);
        if ($res) {
            while ($row = $res->Fetch()) {
                $code = trim((string)($row['CODE'] ?? ''));
                $id = (int)($row['ID'] ?? 0);
                if ($code !== '' && $id > 0) {
                    $this->existingPropertyCodeToId[$code] = $id;
                }
            }
        }
        return $this->existingPropertyCodeToId;
    }

    /**
     * Для свойства типа список возвращает enum ID по VALUE или XML_ID; иначе null.
     */
    private function getListPropertyEnumId(string $propertyCode, string $value): ?int
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return null;
        }
        $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $this->listId, 'CODE' => $propertyCode]);
        if (!$res || !($prop = $res->Fetch()) || (string)($prop['PROPERTY_TYPE'] ?? '') !== 'L') {
            return null;
        }
        $propId = (int)$prop['ID'];
        $value = trim($value);
        $resEnum = CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['PROPERTY_ID' => $propId, 'VALUE' => $value]
        );
        if ($resEnum && ($enum = $resEnum->Fetch())) {
            return (int)$enum['ID'];
        }
        $resEnum = CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC'],
            ['PROPERTY_ID' => $propId, 'XML_ID' => $value]
        );
        if ($resEnum && ($enum = $resEnum->Fetch())) {
            return (int)$enum['ID'];
        }
        return null;
    }

    /**
     * Формат значения свойства для записи в Bitrix: дата/время — d.m.Y H:i:s, дата — d.m.Y, список — enum ID.
     */
    private function formatPropertyValueForBitrix(string $code, $value): mixed
    {
        $str = trim((string)$value);
        if ($str === '') {
            return $value;
        }
        if (in_array($code, self::LIST_PROPERTY_CODES, true)) {
            $enumId = $this->getListPropertyEnumId($code, $str);
            if ($enumId !== null) {
                return $enumId;
            }
            return $value;
        }
        if (in_array($code, self::DATE_PROPERTY_CODES, true)) {
            try {
                $dt = new DateTime(str_replace('T', ' ', $str));
                return $dt->format('d.m.Y');
            } catch (Throwable $e) {
                return $value;
            }
        }
        if (in_array($code, self::DATETIME_PROPERTY_CODES, true)) {
            try {
                $dt = new DateTime(str_replace('T', ' ', $str));
                return $dt->format('d.m.Y H:i:s');
            } catch (Throwable $e) {
                return $value;
            }
        }
        return $value;
    }

    /**
     * Формат значения для фильтра Bitrix: даты — d.m.Y H:i:s, списки — enum ID.
     */
    private function formatPropertyValueForBitrixFilter(string $filterKey, $value): mixed
    {
        $code = (strpos($filterKey, '<=') === 0 || strpos($filterKey, '>=') === 0) ? substr($filterKey, 2) : $filterKey;
        if (in_array($code, self::LIST_PROPERTY_CODES, true)) {
            $enumId = $this->getListPropertyEnumId($code, trim((string)$value));
            return $enumId !== null ? $enumId : $value;
        }
        if (in_array($code, self::DATE_PROPERTY_CODES, true) || in_array($code, self::DATETIME_PROPERTY_CODES, true)) {
            return $this->formatPropertyValueForBitrix($code, $value);
        }
        return $value;
    }

    /**
     * Нормализация значения свойства при чтении из Bitrix: d.m.Y H:i:s → Y-m-d\TH:i:s, d.m.Y → Y-m-d.
     */
    private function normalizePropertyValueFromBitrix(string $code, $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $str = trim((string)$value);
        if ($str === '') {
            return $value;
        }
        if (in_array($code, self::DATE_PROPERTY_CODES, true)) {
            $dt = DateTime::createFromFormat('d.m.Y', $str);
            if ($dt === false) {
                try {
                    $dt = new DateTime(str_replace('T', ' ', $str));
                } catch (Throwable $e) {
                    return $value;
                }
            }
            return $dt->format('Y-m-d');
        }
        if (in_array($code, self::DATETIME_PROPERTY_CODES, true)) {
            $dt = DateTime::createFromFormat('d.m.Y H:i:s', $str)
                ?: DateTime::createFromFormat('d.m.Y H:i', $str);
            if ($dt === false) {
                try {
                    $dt = new DateTime(str_replace('T', ' ', $str));
                } catch (Throwable $e) {
                    return $value;
                }
            }
            return $dt->format('Y-m-d\TH:i:s');
        }
        return $value;
    }

    /**
     * Обновить элемент по ID (поля queue: STATUS, PROCESSED_AT, RETRY_AT и т.д.).
     *
     * @param array<string, mixed> $fields
     * @return array{success: bool, error?: string}
     */
    public function updateElement(int $elementId, array $fields): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['success' => false, 'error' => 'Module iblock not installed'];
        }

        $codeToId = $this->getExistingPropertyCodeToId();
        $propValues = [];
        foreach ($this->propertyCodes as $code) {
            if (!array_key_exists($code, $fields) || !isset($codeToId[$code])) {
                continue;
            }
            $propValues[$code] = $this->formatPropertyValueForBitrix($code, $fields[$code]);
        }
        if (empty($propValues)) {
            return ['success' => true];
        }

        $el = new CIBlockElement();
        $ok = $el->SetPropertyValuesEx($elementId, $this->listId, $propValues);
        if (!$ok) {
            $msg = trim((string)$el->LAST_ERROR);
            if ($msg !== '') {
                return ['success' => false, 'error' => $msg];
            }
            // В части конфигураций Bitrix SetPropertyValuesEx возвращает false при успешной записи. Проверяем по факту.
            $updated = $this->getElementById($elementId);
            if ($updated !== null) {
                foreach (array_keys($propValues) as $code) {
                    $expected = $fields[$code] ?? null;
                    $actual = $updated[$code] ?? null;
                    if ($code === 'RECORD_TYPE' && (string)$actual === (string)$expected) {
                        return ['success' => true];
                    }
                }
            }
            return ['success' => false, 'error' => 'SetPropertyValuesEx failed'];
        }
        return ['success' => true];
    }

    /**
     * Отменить очередь по LEAD_ID: удалить файл расписания (если есть), пометить cycle IN_CYCLE=N,
     * отменить старые записи queue (обратная совместиость).
     *
     * @param DozvonScheduleStorage|null $scheduleStorage при передаче — удаление файла по SCHEDULE_FILENAME
     * @return array{cancelled: int}
     */
    public function cancelQueueByLeadId(int $leadId, ?DozvonScheduleStorage $scheduleStorage = null): array
    {
        $cancelled = 0;

        $cycles = $this->getElements(['RECORD_TYPE' => 'cycle', 'LEAD_ID' => $leadId], 10);
        foreach ($cycles as $cycle) {
            if ($scheduleStorage !== null) {
                $filename = trim((string)($cycle['SCHEDULE_FILENAME'] ?? ''));
                if ($filename !== '') {
                    $scheduleStorage->delete($filename);
                }
            }
            $this->updateElement((int)$cycle['ID'], ['IN_CYCLE' => 'N']);
            $cancelled++;
        }

        $queueItems = $this->getElements(['RECORD_TYPE' => 'queue', 'LEAD_ID' => $leadId], 500);
        foreach ($queueItems as $item) {
            $status = $item['STATUS'] ?? '';
            if ($status === 'cancelled') {
                continue;
            }
            $res = $this->updateElement((int)$item['ID'], ['STATUS' => 'cancelled']);
            if (!empty($res['success'])) {
                $cancelled++;
            }
        }
        return ['cancelled' => $cancelled];
    }
}
