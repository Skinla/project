<?php
declare(strict_types=1);

/**
 * Создание и дозаполнение двух списков модуля 2 через REST webhook Bitrix.
 */
final class DozvonModule2ListProvisioner
{
    private array $config;
    private DozvonRestWebhookClient $client;

    public function __construct(array $config, ?DozvonRestWebhookClient $client = null)
    {
        $this->config = $config;
        $baseUrl = (string)($config['MODULE2_LISTS_WEBHOOK_BASE_URL'] ?? '');
        $this->client = $client ?? new DozvonRestWebhookClient($baseUrl);
    }

    public function ensureLists(): array
    {
        $typeId = (string)($this->config['MODULE2_LISTS_IBLOCK_TYPE_ID'] ?? 'lists');
        $masterCode = (string)($this->config['MODULE2_MASTER_LIST_CODE'] ?? DozvonModule2Schema::MASTER_LIST_CODE);
        $attemptsCode = (string)($this->config['MODULE2_ATTEMPTS_LIST_CODE'] ?? DozvonModule2Schema::ATTEMPTS_LIST_CODE);

        $masterListId = $this->ensureList(
            $typeId,
            $masterCode,
            (string)($this->config['MODULE2_MASTER_LIST_NAME'] ?? DozvonModule2Schema::MASTER_LIST_NAME),
            $this->messagesForList('Циклы', 'Цикл'),
            'Список циклов автодозвона'
        );
        $this->ensureFields($typeId, $masterListId, DozvonModule2Schema::masterListFields());

        $attemptsListId = $this->ensureList(
            $typeId,
            $attemptsCode,
            (string)($this->config['MODULE2_ATTEMPTS_LIST_NAME'] ?? DozvonModule2Schema::ATTEMPTS_LIST_NAME),
            $this->messagesForList('Попытки', 'Попытка'),
            'Список попыток автодозвона'
        );
        $this->ensureFields($typeId, $attemptsListId, DozvonModule2Schema::attemptListFields($masterListId));

        return [
            'master_list_id' => $masterListId,
            'master_list_code' => $masterCode,
            'attempts_list_id' => $attemptsListId,
            'attempts_list_code' => $attemptsCode,
        ];
    }

    private function ensureList(string $typeId, string $code, string $name, array $messages, string $description): int
    {
        $existing = $this->findListByCode($typeId, $code);
        if ($existing > 0) {
            return $existing;
        }

        $params = [
            'IBLOCK_TYPE_ID' => $typeId,
            'IBLOCK_CODE' => $code,
            'FIELDS' => [
                'NAME' => $name,
                'DESCRIPTION' => $description,
                'SORT' => 500,
                'BIZPROC' => 'Y',
            ],
            'MESSAGES' => $messages,
        ];

        $rights = $this->config['MODULE2_LISTS_RIGHTS'] ?? null;
        if (is_array($rights) && !empty($rights)) {
            $params['RIGHTS'] = $rights;
        }

        $response = $this->client->call('lists.add', $params);
        $result = (int)($response['result'] ?? 0);
        if ($result <= 0) {
            throw new RuntimeException('Failed to create list ' . $code);
        }

        return $result;
    }

    private function findListByCode(string $typeId, string $code): int
    {
        $response = $this->client->call('lists.get', [
            'IBLOCK_TYPE_ID' => $typeId,
            'IBLOCK_CODE' => $code,
        ]);

        $result = $response['result'] ?? [];
        if (!is_array($result) || empty($result)) {
            return 0;
        }

        $first = reset($result);
        if (!is_array($first)) {
            return 0;
        }

        return (int)($first['ID'] ?? 0);
    }

    private function ensureFields(string $typeId, int $listId, array $definitions): void
    {
        $existingCodes = $this->getExistingFieldCodes($typeId, $listId);
        foreach ($definitions as $definition) {
            $code = (string)($definition['CODE'] ?? '');
            if ($code === '' || isset($existingCodes[$code])) {
                continue;
            }

            $this->client->call('lists.field.add', [
                'IBLOCK_TYPE_ID' => $typeId,
                'IBLOCK_ID' => $listId,
                'FIELDS' => $definition['FIELDS'],
            ]);
        }
    }

    private function getExistingFieldCodes(string $typeId, int $listId): array
    {
        $response = $this->client->call('lists.field.get', [
            'IBLOCK_TYPE_ID' => $typeId,
            'IBLOCK_ID' => $listId,
        ]);

        $result = $response['result'] ?? [];
        if (!is_array($result)) {
            return [];
        }

        $codes = [];
        foreach ($result as $field) {
            if (!is_array($field)) {
                continue;
            }
            $code = trim((string)($field['CODE'] ?? ''));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    private function messagesForList(string $elementsName, string $elementName): array
    {
        return [
            'ELEMENTS_NAME' => $elementsName,
            'ELEMENT_NAME' => $elementName,
            'ELEMENT_ADD' => 'Добавить ' . mb_strtolower($elementName),
            'ELEMENT_EDIT' => 'Изменить ' . mb_strtolower($elementName),
            'ELEMENT_DELETE' => 'Удалить ' . mb_strtolower($elementName),
            'SECTIONS_NAME' => 'Разделы',
            'SECTION_NAME' => 'Раздел',
            'SECTION_ADD' => 'Добавить раздел',
            'SECTION_EDIT' => 'Изменить раздел',
            'SECTION_DELETE' => 'Удалить раздел',
        ];
    }
}
