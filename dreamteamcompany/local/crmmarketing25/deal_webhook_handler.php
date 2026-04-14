<?php
declare(strict_types=1);

require_once __DIR__ . '/bitrix_init.php';

/**
 * Load webhook configuration with optional local overrides.
 */
function dealWebhookLoadConfig(): array
{
    $baseConfigPath = __DIR__ . '/deal_webhook_config.php';
    if (!is_file($baseConfigPath)) {
        throw new RuntimeException('Не найден deal_webhook_config.php');
    }

    $base = require $baseConfigPath;
    if (!is_array($base)) {
        throw new RuntimeException('deal_webhook_config.php должен возвращать массив');
    }

    $localConfigPath = __DIR__ . '/deal_webhook_config.local.php';
    if (is_file($localConfigPath)) {
        $local = require $localConfigPath;
        if (is_array($local)) {
            $base = array_replace_recursive($base, $local);
        }
    }

    return $base;
}

final class DealToLeadWebhookHandler
{
    private array $config;
    private array $fieldMap = [];
    private string $rawRequestBody = '';
    private array $dealLeadPairs = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function handle(): void
    {
        $action = (string)($_GET['action'] ?? '');
        if ($action === 'create_ib54_from_deal') {
            $this->handleCreateIblock54FromDealAction();
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        $this->rawRequestBody = file_get_contents('php://input') ?: '';

        try {
            $dealId = $this->resolveDealId();
            if ($this->hasDealLeadPair($dealId)) {
                $leadId = (int)$this->getDealLeadPair($dealId);
                if ($this->shouldRenderHtmlResponse()) {
                    $this->renderAlreadyProcessedHtml($dealId, $leadId);
                }
                $this->respond([
                    'status' => 'already_processed',
                    'message' => sprintf('Лид уже создан: сделка #%d -> лид #%d', $dealId, $leadId),
                    'pair' => sprintf('%d -> %d', $dealId, $leadId),
                    'lead_id' => $leadId,
                    'deal_id' => $dealId,
                ]);
                return;
            }

            $deal = $this->fetchDeal($dealId);
            $leadId = $this->processDealToLead($deal);
            $this->respond(['status' => 'success', 'lead_id' => $leadId, 'deal_id' => $dealId]);
        } catch (Throwable $e) {
            $this->log('Ошибка: ' . $e->getMessage());
            $this->respond(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function resolveDealId(): int
    {
        foreach ([$_REQUEST, $_POST, $_GET] as $source) {
            $id = $this->extractDealIdFromArray($source);
            if ($id > 0) {
                return $id;
            }
        }

        if ($this->rawRequestBody !== '') {
            $formData = [];
            parse_str($this->rawRequestBody, $formData);
                $id = $this->extractDealIdFromArray($formData);
            if ($id > 0) {
                    return $id;
            }

            $jsonData = json_decode($this->rawRequestBody, true);
            if (is_array($jsonData)) {
                $id = $this->extractDealIdFromArray($jsonData);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        throw new InvalidArgumentException('deal_id обязателен');
    }

    private function extractDealIdFromArray($payload): int
    {
        if (!is_array($payload)) {
            return 0;
        }

        foreach ([['deal_id'], ['data', 'FIELDS', 'ID'], ['data', 'fields', 'ID'], ['FIELDS', 'ID'], ['fields', 'ID']] as $path) {
            $id = (int)$this->findNestedValue($payload, $path);
            if ($id > 0) {
                return $id;
            }
        }

        // In Bitrix event webhooks top-level "id" may represent service/event identifiers,
        // not CRM entity id. Use it only when no explicit webhook event marker is present.
        if (!isset($payload['event'])) {
            foreach ([['id'], ['ID']] as $path) {
                $id = (int)$this->findNestedValue($payload, $path);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        return 0;
    }

    private function findNestedValue(array $data, array $path)
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private function fetchDeal(int $dealId): array
    {
        $deal = $this->callCloudRest('crm.deal.get', ['id' => $dealId])['result'] ?? null;
        if (!is_array($deal) || empty($deal)) {
            throw new RuntimeException("Сделка {$dealId} не найдена");
        }
        return $deal;
    }

    private function processDealToLead(array $deal): int
    {
        $dealId = (int)($deal['ID'] ?? 0);
        $dealTitle = trim((string)($deal['TITLE'] ?? ''));
        if ($dealId <= 0 || $dealTitle === '') {
            throw new RuntimeException('Некорректные данные сделки');
        }

        $element = $this->findIblockElementByName($dealTitle);
        if ($element === null) {
            $this->sendIblock54NotFoundNotification($dealId, $dealTitle);
            throw new RuntimeException("Элемент инфоблока 54 с названием '{$dealTitle}' не найден");
        }

        $person = $this->extractPersonData($deal);
        if ($person['firstName'] === '' && $person['lastName'] === '' && $person['phone'] === '') {
            $contact = $this->fetchContactIfNeeded($deal);
            if ($contact !== null) {
                $person = $this->hydratePersonFromContact($person, $contact);
            }
        }

        $leadId = $this->persistLead($this->buildLeadPayload($dealId, $dealTitle, $person, $element));
        $this->addDealLeadPair($dealId, $leadId);
        return $leadId;
    }

    private function fetchContactIfNeeded(array $deal): ?array
    {
        $contactId = (int)($deal['CONTACT_ID'] ?? 0);
        if ($contactId <= 0) {
            return null;
        }
        $contact = $this->callCloudRest('crm.contact.get', ['id' => $contactId])['result'] ?? null;
        return is_array($contact) ? $contact : null;
    }

    private function hydratePersonFromContact(array $person, array $contact): array
    {
        $person['firstName'] = $person['firstName'] ?: trim((string)($contact['NAME'] ?? ''));
        $person['middleName'] = $person['middleName'] ?: trim((string)($contact['SECOND_NAME'] ?? ''));
        $person['lastName'] = $person['lastName'] ?: trim((string)($contact['LAST_NAME'] ?? ''));
        if ($person['phone'] === '' && !empty($contact['PHONE'][0]['VALUE'])) {
            $person['phone'] = $this->normalizePhone((string)$contact['PHONE'][0]['VALUE']);
        }
        return $person;
    }

    private function extractPersonData(array $deal): array
    {
        $fields = $this->resolveDealFieldCodes();
        $phoneCode = $fields['phone'] ?? null;
        $phone = '';
        if ($phoneCode && isset($deal[$phoneCode])) {
            $raw = $deal[$phoneCode];
            if (is_array($raw)) {
                $raw = $raw[0] ?? '';
            }
            $phone = $this->normalizePhone((string)$raw);
        }
        if ($phone === '' && isset($deal['UF_CRM_PHONE'])) {
            $phone = $this->normalizePhone((string)$deal['UF_CRM_PHONE']);
        }

        return [
            'firstName' => isset($fields['firstName']) ? trim((string)($deal[$fields['firstName']] ?? '')) : '',
            'middleName' => isset($fields['middleName']) ? trim((string)($deal[$fields['middleName']] ?? '')) : '',
            'lastName' => isset($fields['lastName']) ? trim((string)($deal[$fields['lastName']] ?? '')) : '',
            'phone' => $phone,
        ];
    }

    private function normalizePhone(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        return '+' . $digits;
    }

    private function buildLeadPayload(int $dealId, string $dealTitle, array $person, array $element): array
    {
        $props = $element['PROPERTIES'];
        $payload = [
            'TITLE' => (string)$element['NAME'],
            'STATUS_ID' => (string)$this->config['lead']['default_status_id'],
            'SOURCE_DESCRIPTION' => sprintf('Создано из сделки #%d (%s)', $dealId, $dealTitle),
        ];

        foreach (['NAME' => 'firstName', 'SECOND_NAME' => 'middleName', 'LAST_NAME' => 'lastName'] as $field => $key) {
            if (!empty($person[$key])) {
                $payload[$field] = $person[$key];
            }
        }
        if (!empty($person['phone'])) {
            $payload['FM'] = ['PHONE' => [['VALUE' => $person['phone'], 'VALUE_TYPE' => 'WORK']]];
        }

        $sourceElementId = $props['PROPERTY_192']['VALUE_NUM'] ?? ($props['PROPERTY_192']['VALUE'] ?? null);
            if (is_array($sourceElementId)) {
                $sourceElementId = $sourceElementId[0] ?? null;
            }
        if ((int)$sourceElementId > 0) {
            $sourceId = $this->resolveSourceId((int)$sourceElementId);
            if ($sourceId) {
                $payload['SOURCE_ID'] = $sourceId;
            }
        }
        if (empty($payload['SOURCE_ID']) && !empty($this->config['lead']['default_source_id'])) {
            $payload['SOURCE_ID'] = (string)$this->config['lead']['default_source_id'];
        }

        $cityId = (int)($props['PROPERTY_191']['VALUE'] ?? 0);
            if ($cityId > 0) {
            $payload[(string)$this->config['lead']['city_field']] = $cityId;
                $assigned = $this->resolveAssignedById($cityId);
                if ($assigned > 0) {
                    $payload['ASSIGNED_BY_ID'] = $assigned;
            }
        }

        if (!empty($props['PROPERTY_193']['VALUE'])) {
            $payload[(string)$this->config['lead']['executor_field']] = $props['PROPERTY_193']['VALUE'];
        }
        if (!empty($props['PROPERTY_194']['VALUE'])) {
            $payload['COMMENTS'] = trim((string)$props['PROPERTY_194']['VALUE']);
        }

        $observerIds = $this->extractObserverIds($props['PROPERTY_195'] ?? null);
        if (!empty($observerIds)) {
            $payload['OBSERVER_IDS'] = $observerIds;
        }

        return $payload;
    }

    private function persistLead(array $payload): int
    {
        if (!CModule::IncludeModule('crm')) {
            throw new RuntimeException('Модуль CRM не доступен');
        }

        $observerIds = $payload['OBSERVER_IDS'] ?? [];
        unset($payload['OBSERVER_IDS']);

        $arFields = [];
        foreach ($payload as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            if (in_array($key, ['TITLE', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'STATUS_ID', 'SOURCE_ID', 'SOURCE_DESCRIPTION', 'ASSIGNED_BY_ID', 'COMMENTS', 'FM'], true)
                || strpos($key, 'UF_CRM_') === 0
            ) {
                $arFields[$key] = $value;
            }
        }

        if (empty($arFields['STATUS_ID'])) {
            $arFields['STATUS_ID'] = (string)$this->config['lead']['default_status_id'];
        }

        $currentUserId = (int)($arFields['ASSIGNED_BY_ID'] ?? 1);
        if ($currentUserId <= 0) {
            $currentUserId = 1;
        }

        $lead = new \CCrmLead(false);
        $leadId = $lead->Add($arFields, true, ['REGISTER_SONET_EVENT' => 'Y', 'CURRENT_USER' => $currentUserId]);
        if (!$leadId) {
            throw new RuntimeException('Ошибка создания лида: ' . $lead->LAST_ERROR);
        }

        $this->runLeadAutomation((int)$leadId, $currentUserId);
        $this->setLeadObservers((int)$leadId, $observerIds);
        return (int)$leadId;
    }

    private function runLeadAutomation(int $leadId, int $userId): void
    {
        try {
            if (class_exists(\Bitrix\Crm\Automation\Starter::class) && class_exists(\CCrmOwnerType::class)) {
                $starter = new \Bitrix\Crm\Automation\Starter(\CCrmOwnerType::Lead, $leadId);
                if ($userId > 0) {
                    $starter->setUserId($userId);
                }
                $starter->runOnAdd();
            }
        } catch (Throwable $e) {
            $this->log('Ошибка CRM-автоматизации: ' . $e->getMessage());
        }

        try {
            if (class_exists(\CCrmBizProcHelper::class) && class_exists(\CCrmBizProcEventType::class) && class_exists(\CCrmOwnerType::class)) {
                $errors = [];
                \CCrmBizProcHelper::AutoStartWorkflows(\CCrmOwnerType::LeadName, $leadId, \CCrmBizProcEventType::Create, $errors);
                if (!empty($errors)) {
                    $this->log('Ошибки BizProc: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
                }
            }
        } catch (Throwable $e) {
            $this->log('Ошибка BizProc: ' . $e->getMessage());
        }
    }

    private function setLeadObservers(int $leadId, array $observerIds): void
    {
        if (empty($observerIds)) {
            return;
        }
            try {
                $item = \Bitrix\Crm\Item\Lead::createInstance($leadId);
                if ($item) {
                    $item->setObservers($observerIds);
                $item->save();
                }
            } catch (Throwable $e) {
            $this->log('Ошибка наблюдателей: ' . $e->getMessage());
            }
    }

    private function resolveSourceId(int $elementId): ?string
    {
        if ($elementId <= 0 || !CModule::IncludeModule('iblock')) {
            return null;
        }
        $dbProps = CIBlockElement::GetProperty((int)$this->config['iblock']['source_list_id'], $elementId, [], ['CODE' => 'PROPERTY_73']);
        while ($prop = $dbProps->Fetch()) {
            if (!empty($prop['VALUE'])) {
                return trim((string)$prop['VALUE']);
            }
        }
        return null;
    }

    private function resolveAssignedById(int $cityElementId): int
    {
        if ($cityElementId <= 0 || !CModule::IncludeModule('iblock')) {
            return 0;
        }
        $dbProps = CIBlockElement::GetProperty((int)$this->config['iblock']['city_list_id'], $cityElementId, [], ['CODE' => 'PROPERTY_185']);
        while ($prop = $dbProps->Fetch()) {
            $value = (int)($prop['VALUE'] ?? 0);
            if ($value > 0) {
                return $value;
            }
        }
        return 0;
    }

    private function extractObserverIds($property): array
    {
        if (empty($property)) {
            return [];
        }

        $rawValues = [];
        if (is_array($property) && isset($property['VALUE'])) {
            $rawValues = is_array($property['VALUE']) ? $property['VALUE'] : [$property['VALUE']];
        } elseif (is_array($property)) {
            $rawValues = $property;
        }

        return array_values(array_filter(array_map(static function ($value) {
            $candidate = is_array($value) ? ($value['VALUE'] ?? $value['VALUE_NUM'] ?? null) : $value;
            $candidate = (int)$candidate;
            return $candidate > 0 ? $candidate : null;
        }, $rawValues), static fn($value) => $value !== null));
    }

    private function findIblockElementByName(string $name): ?array
    {
        if ($name === '' || !CModule::IncludeModule('iblock')) {
            return null;
        }

        $dbRes = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => (int)$this->config['iblock']['main_id'], 'ACTIVE' => 'Y', '=NAME' => $name],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME']
        );
        $element = $dbRes->Fetch();
        if (!$element) {
            return null;
        }

        $properties = [];
        $dbProps = CIBlockElement::GetProperty((int)$this->config['iblock']['main_id'], (int)$element['ID']);
        while ($prop = $dbProps->Fetch()) {
            $code = $prop['CODE'];
            $value = !empty($prop['VALUE_NUM']) ? $prop['VALUE_NUM'] : $prop['VALUE'];
            if (!isset($properties[$code])) {
                $properties[$code] = [
                    'CODE' => $code,
                    'NAME' => $prop['NAME'] ?? '',
                    'VALUE' => $value,
                    'VALUE_ENUM' => $prop['VALUE_ENUM'] ?? null,
                    'VALUE_NUM' => $prop['VALUE_NUM'] ?? null,
                ];
            } else {
                if (!is_array($properties[$code]['VALUE'])) {
                    $properties[$code]['VALUE'] = [$properties[$code]['VALUE']];
                }
                    $properties[$code]['VALUE'][] = $value;
            }
        }

        $element['PROPERTIES'] = $properties;
        return $element;
    }

    private function resolveDealFieldCodes(): array
    {
        if (!empty($this->fieldMap)) {
            return $this->fieldMap;
        }

        $meta = $this->callCloudRest('crm.deal.fields')['result'] ?? [];
        if (!is_array($meta) || empty($meta)) {
            return [];
        }

        $targets = [
            'firstName' => ['Имя', 'First name', 'First Name'],
            'middleName' => ['Отчество', 'Middle name', 'Middle Name'],
            'lastName' => ['Фамилия', 'Last name', 'Last Name'],
            'phone' => ['Телефон', 'Phone'],
        ];

        foreach ($targets as $key => $labels) {
            $this->fieldMap[$key] = $this->findFieldCodeByLabels($meta, $labels);
        }
        return $this->fieldMap;
    }

    private function findFieldCodeByLabels(array $meta, array $labels): ?string
    {
        foreach ($meta as $code => $definition) {
            $candidates = array_filter([
                $definition['listLabel'] ?? null,
                $definition['formLabel'] ?? null,
                $definition['editFormLabel'] ?? null,
                $definition['filterLabel'] ?? null,
                $definition['title'] ?? null,
            ]);
            foreach ($candidates as $candidate) {
                $candidate = mb_strtolower(trim((string)$candidate));
                foreach ($labels as $label) {
                    if ($candidate === mb_strtolower(trim($label))) {
                        return $code;
                    }
                }
            }
        }
        return null;
    }

    private function callCloudRest(string $method, array $params = []): array
    {
        $baseUrl = rtrim((string)$this->config['cloud']['webhook_base'], '/');
        $ch = curl_init($baseUrl . '/' . $method);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->sendWebhookUnavailableNotification($method, $error);
            throw new RuntimeException("Ошибка REST {$method}: {$error}");
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->sendWebhookUnavailableNotification($method, 'Некорректный JSON в ответе');
            throw new RuntimeException("Некорректный ответ REST {$method}");
        }
        if (!empty($decoded['error'])) {
            $message = $decoded['error_description'] ?? $decoded['error'];
            throw new RuntimeException("REST {$method} вернул ошибку: {$message}");
        }
        return $decoded;
    }

    private function loadDealLeadPairs(): array
    {
        if (!empty($this->dealLeadPairs)) {
            return $this->dealLeadPairs;
        }

        $mapFile = (string)$this->config['storage']['deal_lead_map_file'];
        if (is_file($mapFile)) {
            $data = json_decode((string)file_get_contents($mapFile), true);
            if (is_array($data)) {
                return $this->dealLeadPairs = $data;
            }
        }

        return $this->dealLeadPairs = [];
    }

    private function hasDealLeadPair(int $dealId): bool
    {
        return isset($this->loadDealLeadPairs()[(string)$dealId]);
    }

    private function getDealLeadPair(int $dealId): ?int
    {
        $pairs = $this->loadDealLeadPairs();
        return isset($pairs[(string)$dealId]) ? (int)$pairs[(string)$dealId] : null;
    }

    private function addDealLeadPair(int $dealId, int $leadId): void
    {
        $mapFile = (string)$this->config['storage']['deal_lead_map_file'];
        $lockFile = $mapFile . '.lock';

        $lockHandle = fopen($lockFile, 'c+');
        if ($lockHandle === false) {
            throw new RuntimeException('Не удалось открыть lock-файл для deal_lead_pairs');
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Не удалось получить lock для deal_lead_pairs');
            }

            $pairs = [];
            if (is_file($mapFile)) {
                $data = json_decode((string)file_get_contents($mapFile), true);
            if (is_array($data)) {
                    $pairs = $data;
                }
            }

            $pairs[(string)$dealId] = $leadId;
            $this->dealLeadPairs = $pairs;

            $dir = dirname($mapFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

            $tmp = $mapFile . '.tmp';
            file_put_contents($tmp, json_encode($pairs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            rename($tmp, $mapFile);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function sendIblock54NotFoundNotification(int $dealId, string $dealTitle): void
    {
        $reprocessUrl = $this->buildReprocessUrl($dealId);
        $createAndEditUrl = $this->buildCreateAndEditIblockUrl($dealId);
        $template = (string)$this->config['notifications']['iblock_not_found_text'];
        $message = strtr($template, [
            '{deal_id}' => (string)$dealId,
            '{deal_title}' => $dealTitle,
            '{time}' => date('d.m.Y H:i:s'),
            '{cloud_portal_link}' => (string)$this->config['notifications']['cloud_portal_link'],
            '{iblock_link}' => (string)$this->config['notifications']['iblock_link'],
            '{reprocess_url}' => $reprocessUrl,
            '{create_and_edit_url}' => $createAndEditUrl,
        ]);

        $this->sendChatMessage($message);
    }

    private function buildReprocessUrl(int $dealId): string
    {
        $base = trim((string)($this->config['notifications']['reprocess_url_base'] ?? ''));
        if ($base === '') {
            return '';
        }

        $delimiter = (strpos($base, '?') === false) ? '?' : '&';
        return $base . $delimiter . http_build_query(['deal_id' => $dealId]);
    }

    private function buildCreateAndEditIblockUrl(int $dealId): string
    {
        $base = trim((string)($this->config['notifications']['reprocess_url_base'] ?? ''));
        if ($base === '') {
            return '';
        }

        $delimiter = (strpos($base, '?') === false) ? '?' : '&';
        return $base . $delimiter . http_build_query([
            'action' => 'create_ib54_from_deal',
            'deal_id' => $dealId,
        ]);
    }

    private function handleCreateIblock54FromDealAction(): void
    {
        try {
            $dealId = $this->resolveDealId();
            $deal = $this->fetchDeal($dealId);
            $dealTitle = trim((string)($deal['TITLE'] ?? ''));
            if ($dealTitle === '') {
                throw new RuntimeException("У сделки {$dealId} отсутствует название");
            }

            $existing = $this->findIblockElementByName($dealTitle);
            if (is_array($existing) && (int)($existing['ID'] ?? 0) > 0) {
                $existingId = (int)$existing['ID'];
                $editUrl = $this->buildIblockElementEditUrl($existingId);
                header('Content-Type: text/html; charset=utf-8');
                if ($editUrl !== '') {
                    $safeUrl = htmlspecialchars($editUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Элемент уже добавлен</title>'
                        . '<style>'
                        . 'body{margin:0;padding:24px;background:#f7f8fa;color:#1f2328;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;}'
                        . '.card{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,.06);}'
                        . '.title{font-size:17px;font-weight:600;margin:0 0 8px;}'
                        . '.meta{margin:0 0 14px;color:#535c69;}'
                        . '.btn{display:inline-block;padding:10px 14px;border-radius:8px;background:#2fc6f6;color:#fff;text-decoration:none;font-weight:600;}'
                        . '.hint{margin-top:10px;font-size:12px;color:#8a9099;}'
                        . '</style></head><body><div class="card">'
                        . '<p class="title">Такой элемент уже добавлен</p>'
                        . '<p class="meta">ID: ' . $existingId . '</p>'
                        . '<a id="open-element-link" class="btn" href="' . $safeUrl . '">Открыть элемент</a>'
                        . '<p class="hint">Элемент откроется в окне Bitrix (SidePanel), если доступен JS-контекст портала.</p>'
                        . '</div><script>'
                        . '(function(){'
                        . 'var link=document.getElementById("open-element-link");'
                        . 'if(!link){return;}'
                        . 'link.addEventListener("click",function(e){'
                        . 'if(window.BX&&BX.SidePanel&&BX.SidePanel.Instance){'
                        . 'e.preventDefault();'
                        . 'BX.SidePanel.Instance.open(link.href,{cacheable:false,allowChangeHistory:false});'
                        . '}'
                        . '});'
                        . '})();'
                        . '</script></body></html>';
                } else {
                    echo 'Такой элемент уже добавлен (ID: ' . $existingId . ').';
                }
                exit;
            }

            $elementId = $this->createIblock54Element($dealTitle);
            $editUrl = $this->buildIblockElementEditUrl($elementId);
            if ($editUrl !== '') {
                header('Location: ' . $editUrl);
                exit;
            }

            $this->respond([
                'status' => 'created',
                'element_id' => $elementId,
                'deal_id' => $dealId,
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Ошибка создания элемента ИБ54: ' . $e->getMessage();
            exit;
        }
    }

    private function createIblock54Element(string $title): int
    {
        if (!CModule::IncludeModule('iblock')) {
            throw new RuntimeException('Модуль iblock не доступен');
        }

        $sourceElementId = $this->resolveTurboSourceElementId();
        $element = new CIBlockElement();
        $fields = [
            'IBLOCK_ID' => (int)$this->config['iblock']['main_id'],
            'NAME' => $title,
            'ACTIVE' => 'Y',
        ];

        if ($sourceElementId > 0) {
            $fields['PROPERTY_VALUES'] = [
                'PROPERTY_192' => $sourceElementId,
            ];
        }

        $id = (int)$element->Add($fields);
        if ($id <= 0) {
            throw new RuntimeException('Не удалось создать элемент ИБ54: ' . $element->LAST_ERROR);
        }

        return $id;
    }

    private function resolveTurboSourceElementId(): int
    {
        $configuredId = (int)($this->config['iblock']['source_turbo_element_id'] ?? 0);
        if ($configuredId > 0) {
            return $configuredId;
        }

        $name = trim((string)($this->config['iblock']['source_turbo_element_name'] ?? 'Турбо-сайт'));
        if ($name === '' || !CModule::IncludeModule('iblock')) {
            return 0;
        }

        $dbRes = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => (int)$this->config['iblock']['source_list_id'], 'ACTIVE' => 'Y', '=NAME' => $name],
            false,
            ['nTopCount' => 1],
            ['ID']
        );
        $row = $dbRes->Fetch();
        return is_array($row) ? (int)($row['ID'] ?? 0) : 0;
    }

    private function buildIblockElementEditUrl(int $elementId): string
    {
        if ($elementId <= 0) {
            return '';
        }

        $template = trim((string)($this->config['notifications']['iblock_element_edit_url_template'] ?? ''));
        if ($template !== '') {
            return strtr($template, ['{id}' => (string)$elementId]);
        }

        return '';
    }

    private function shouldRenderHtmlResponse(): bool
    {
        if (PHP_SAPI === 'cli') {
            return false;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET') {
            return false;
        }
        $format = strtolower((string)($_GET['format'] ?? ''));
        return $format !== 'json';
    }

    private function renderAlreadyProcessedHtml(int $dealId, int $leadId): void
    {
        $leadUrl = $this->buildLeadDetailUrl($leadId);
        header('Content-Type: text/html; charset=utf-8');

        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Лид уже создан</title>'
            . '<style>'
            . 'body{margin:0;padding:24px;background:#f7f8fa;color:#1f2328;font:14px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;}'
            . '.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #dfe1e5;border-radius:10px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,.06);}'
            . '.title{font-size:18px;font-weight:600;margin:0 0 8px;}'
            . '.meta{margin:0 0 14px;color:#535c69;}'
            . '.pair{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;background:#f3f4f6;border-radius:6px;padding:8px 10px;display:inline-block;}'
            . '.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:8px;background:#2fc6f6;color:#fff;text-decoration:none;font-weight:600;}'
            . '.hint{margin-top:10px;font-size:12px;color:#8a9099;}'
            . '</style></head><body><div class="card">'
            . '<p class="title">Лид уже создан</p>'
            . '<p class="meta">Перевыгрузка не требуется, пара уже есть в маппинге.</p>'
            . '<div class="pair">Сделка #' . $dealId . ' -> Лид #' . $leadId . '</div>';

        if ($leadUrl !== '') {
            $safeLeadUrl = htmlspecialchars($leadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<br><a id="open-lead-link" class="btn" href="' . $safeLeadUrl . '">Открыть лид</a>'
                . '<p class="hint">Если доступен JS-контекст Bitrix, лид откроется в окне (SidePanel).</p>'
                . '<script>(function(){var l=document.getElementById("open-lead-link");if(!l){return;}l.addEventListener("click",function(e){if(window.BX&&BX.SidePanel&&BX.SidePanel.Instance){e.preventDefault();BX.SidePanel.Instance.open(l.href,{cacheable:false,allowChangeHistory:false});}});})();</script>';
        }

        echo '</div></body></html>';
        exit;
    }

    private function buildLeadDetailUrl(int $leadId): string
    {
        if ($leadId <= 0) {
            return '';
        }

        $template = trim((string)($this->config['notifications']['lead_detail_url_template'] ?? ''));
        if ($template === '') {
            return '';
        }

        return strtr($template, ['{id}' => (string)$leadId]);
    }

    private function sendWebhookUnavailableNotification(string $method, string $error): void
    {
        $template = (string)$this->config['notifications']['webhook_unavailable_text'];
        $message = strtr($template, [
            '{method}' => $method,
            '{error}' => $error,
            '{time}' => date('d.m.Y H:i:s'),
            '{webhook_base}' => (string)$this->config['cloud']['webhook_base'],
        ]);

        $this->sendChatMessage($message);
    }

    private function sendChatMessage(string $message): void
    {
        $chatId = (string)$this->config['notifications']['error_chat_id'];
        if ($chatId === '' || !CModule::IncludeModule('im')) {
            return;
        }

        @CIMMessenger::Add([
            'DIALOG_ID' => $chatId,
            'MESSAGE' => $message,
            'SYSTEM' => 'Y',
        ]);
    }

    private function log(string $message): void
    {
        $logFile = (string)$this->config['log']['file'];
        $logMaxBytes = (int)$this->config['log']['max_bytes'];

        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (file_exists($logFile) && filesize($logFile) > $logMaxBytes) {
            $tail = file_get_contents($logFile);
            file_put_contents($logFile, $tail !== false ? substr($tail, -1 * (int)($logMaxBytes * 0.5)) : '');
        }
        file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
    }

    private function respond(array $payload, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$handler = new DealToLeadWebhookHandler(dealWebhookLoadConfig());
$handler->handle();
