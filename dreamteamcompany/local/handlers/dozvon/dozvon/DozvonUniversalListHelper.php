<?php
declare(strict_types=1);

/**
 * Универсальный helper для работы с Bitrix-списком по набору кодов полей.
 */
final class DozvonUniversalListHelper
{
    private int $listId;
    private array $propertyCodes;
    private array $listCodes;
    private array $dateTimeCodes;
    private array $dateCodes;
    private ?array $existingPropertyCodeToId = null;

    public function __construct(
        int $listId,
        array $propertyCodes,
        array $listCodes = [],
        array $dateTimeCodes = [],
        array $dateCodes = []
    ) {
        $this->listId = $listId;
        $this->propertyCodes = $propertyCodes;
        $this->listCodes = $listCodes;
        $this->dateTimeCodes = $dateTimeCodes;
        $this->dateCodes = $dateCodes;
    }

    public function getElements(array $filter, int $limit = 100, array $order = ['ID' => 'ASC']): array
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

        $res = CIBlockElement::GetList($order, $iblockFilter, false, ['nTopCount' => $limit], $select);
        if (!$res) {
            return [];
        }

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            $item = [
                'ID' => (int)$fields['ID'],
                'NAME' => (string)($fields['NAME'] ?? ''),
                'DATE_CREATE' => $fields['DATE_CREATE'] ?? null,
            ];
            foreach ($this->propertyCodes as $code) {
                $val = $props[$code]['VALUE'] ?? $fields['PROPERTY_' . $code . '_VALUE'] ?? null;
                $item[$code] = $this->normalizePropertyValueFromBitrix($code, $val);
            }
            $items[$item['ID']] = $item;
        }

        return $items;
    }

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
        if ((int)($fields['IBLOCK_ID'] ?? 0) !== $this->listId) {
            return null;
        }

        $props = $ob->GetProperties();
        $item = [
            'ID' => (int)$fields['ID'],
            'NAME' => (string)($fields['NAME'] ?? ''),
            'DATE_CREATE' => $fields['DATE_CREATE'] ?? null,
        ];
        foreach ($this->propertyCodes as $code) {
            $val = $props[$code]['VALUE'] ?? $fields['PROPERTY_' . $code . '_VALUE'] ?? null;
            $item[$code] = $this->normalizePropertyValueFromBitrix($code, $val);
        }

        return $item;
    }

    public function addElement(array $fields, ?string $name = null): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['error' => 'Module iblock not installed'];
        }

        $elementName = $name ?: $this->buildDefaultName($fields);
        $propValues = $this->preparePropertyValues($fields);

        $el = new CIBlockElement();
        $id = $el->Add([
            'IBLOCK_ID' => $this->listId,
            'NAME' => $elementName,
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => $propValues,
        ]);

        if (!$id) {
            return ['error' => $el->LAST_ERROR ?: 'Add failed'];
        }

        return ['id' => (int)$id];
    }

    public function updateElement(int $elementId, array $fields): array
    {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            return ['error' => 'Module iblock not installed'];
        }

        if ($this->getElementById($elementId) === null) {
            return ['error' => 'Element not found in list'];
        }

        $propValues = $this->preparePropertyValues($fields);
        if (!empty($propValues)) {
            CIBlockElement::SetPropertyValuesEx($elementId, $this->listId, $propValues);
        }

        return ['id' => $elementId];
    }

    public function resolvePropertyEnumId(string $code, string $value): ?int
    {
        return $this->getListPropertyEnumId($code, $value);
    }

    private function preparePropertyValues(array $fields): array
    {
        $propValues = [];
        foreach ($this->propertyCodes as $code) {
            if (!array_key_exists($code, $fields) || $fields[$code] === null || $fields[$code] === '') {
                continue;
            }
            $propValues[$code] = $this->formatPropertyValueForBitrix($code, $fields[$code]);
        }

        $codeToId = $this->getExistingPropertyCodeToId();
        $result = [];
        foreach ($propValues as $code => $value) {
            if (isset($codeToId[$code])) {
                $result[$code] = $value;
            }
        }

        return $result;
    }

    private function buildDefaultName(array $fields): string
    {
        $leadId = trim((string)($fields['LEAD_ID'] ?? ''));
        $attemptNumber = trim((string)($fields['ATTEMPT_NUMBER'] ?? ''));
        $parts = array_filter([
            'Dozvon',
            $leadId !== '' ? 'lead ' . $leadId : null,
            $attemptNumber !== '' ? 'attempt ' . $attemptNumber : null,
            date('Y-m-d H:i:s'),
        ]);

        return implode(' ', $parts);
    }

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

        $resEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $propId, 'VALUE' => $value]);
        if ($resEnum && ($enum = $resEnum->Fetch())) {
            return (int)$enum['ID'];
        }

        $resEnum = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $propId, 'XML_ID' => $value]);
        if ($resEnum && ($enum = $resEnum->Fetch())) {
            return (int)$enum['ID'];
        }

        return null;
    }

    private function formatPropertyValueForBitrixFilter(string $codeWithPrefix, $value)
    {
        $code = ltrim($codeWithPrefix, '<>=');
        return $this->formatPropertyValueForBitrix($code, $value);
    }

    private function formatPropertyValueForBitrix(string $code, $value)
    {
        $str = trim((string)$value);
        if ($str === '') {
            return $value;
        }

        if (in_array($code, $this->listCodes, true)) {
            $enumId = $this->getListPropertyEnumId($code, $str);
            return $enumId ?? $value;
        }

        if (in_array($code, $this->dateCodes, true)) {
            try {
                $dt = new DateTime(str_replace('T', ' ', $str));
                return $dt->format('d.m.Y');
            } catch (Throwable $e) {
                return $value;
            }
        }

        if (in_array($code, $this->dateTimeCodes, true)) {
            try {
                $dt = new DateTime(str_replace('T', ' ', $str));
                return $dt->format('d.m.Y H:i:s');
            } catch (Throwable $e) {
                return $value;
            }
        }

        return $value;
    }

    private function normalizePropertyValueFromBitrix(string $code, $value)
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (in_array($code, $this->listCodes, true)) {
            return trim((string)$value);
        }

        if (in_array($code, $this->dateCodes, true)) {
            try {
                return (new DateTime((string)$value))->format('Y-m-d');
            } catch (Throwable $e) {
                return trim((string)$value);
            }
        }

        if (in_array($code, $this->dateTimeCodes, true)) {
            try {
                return (new DateTime((string)$value))->format('Y-m-d\TH:i:s');
            } catch (Throwable $e) {
                return trim((string)$value);
            }
        }

        return trim((string)$value);
    }
}
