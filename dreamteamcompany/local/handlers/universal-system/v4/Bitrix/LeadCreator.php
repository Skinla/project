<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Bitrix;

use UniversalSystem\V4\Support\Logger;

final class LeadCreator
{
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function create(array $parsed, array $sourceConfig): array
    {
        $portal = (string)($this->config['default_portal'] ?? 'dreamteamcompany');
        $webhookBase = (string)($this->config['portal_webhooks'][$portal] ?? '');
        if ($webhookBase === '') {
            return [
                'success' => false,
                'retryable' => false,
                'error_code' => 'missing_webhook',
                'error_message' => 'Не настроен webhook портала',
            ];
        }

        $fields = $this->buildFields($parsed, $sourceConfig);
        $url = rtrim($webhookBase, '/') . '/crm.lead.add.json';
        $retries = (int)($this->config['max_api_retries'] ?? 3);
        $delay = (int)($this->config['api_retry_delay_seconds'] ?? 1);

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $result = $this->request($url, $fields);
            if ($result['success']) {
                return $result;
            }

            if (!$result['retryable'] || $attempt === $retries) {
                return $result;
            }

            sleep($delay);
        }

        return [
            'success' => false,
            'retryable' => true,
            'error_code' => 'unexpected_retry_exit',
            'error_message' => 'Неожиданный выход из цикла ретраев',
        ];
    }

    private function buildFields(array $parsed, array $sourceConfig): array
    {
        $phone = (string)($parsed['contact']['phone'] ?? '');
        $name = trim((string)($parsed['contact']['name'] ?? ''));
        $lastName = trim((string)($parsed['contact']['last_name'] ?? ''));
        $secondName = trim((string)($parsed['contact']['second_name'] ?? ''));
        $comment = trim((string)($parsed['meta']['comment'] ?? ''));

        $fields = [
            'TITLE' => (string)($parsed['source']['lead_title'] ?? 'Лид'),
            'PHONE' => [
                ['VALUE' => $phone, 'VALUE_TYPE' => 'WORK'],
            ],
            'COMMENTS' => $comment,
            'OPENED' => 'Y',
            'STATUS_ID' => 'NEW',
            'ASSIGNED_BY_ID' => (int)($sourceConfig['assigned_by_id'] ?? $this->config['default_assigned_by_id'] ?? 1),
            'CREATED_BY_ID' => (int)($sourceConfig['assigned_by_id'] ?? $this->config['default_assigned_by_id'] ?? 1),
            'SOURCE_DESCRIPTION' => (string)($parsed['source']['source_description'] ?? ''),
        ];

        if ($name !== '') {
            $fields['NAME'] = $name;
        } else {
            $fields['NAME'] = 'Имя';
            $fields['LAST_NAME'] = 'Фамилия';
        }

        if ($lastName !== '') {
            $fields['LAST_NAME'] = $lastName;
        }

        if ($secondName !== '') {
            $fields['SECOND_NAME'] = $secondName;
        }

        if (!empty($sourceConfig['source_id'])) {
            $fields['SOURCE_ID'] = $sourceConfig['source_id'];
        }
        if (!empty($sourceConfig['city_id'])) {
            $fields['UF_CRM_1744362815'] = $sourceConfig['city_id'];
        }
        if (!empty($sourceConfig['ispolnitel'])) {
            $fields['UF_CRM_1745957138'] = $sourceConfig['ispolnitel'];
        }
        if (!empty($sourceConfig['infopovod'])) {
            $fields['UF_CRM_5FC49F7DA5470'] = (string)$sourceConfig['infopovod'];
        }
        if (!empty($sourceConfig['observer_ids']) && is_array($sourceConfig['observer_ids'])) {
            $fields['OBSERVER_IDS'] = array_values($sourceConfig['observer_ids']);
        }

        foreach ((array)($parsed['meta']['utm'] ?? []) as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }
            $fields[strtoupper($key)] = $value;
        }

        return $fields;
    }

    private function request(string $url, array $fields): array
    {
        $postData = [];
        foreach ($fields as $key => $value) {
            if ($key === 'PHONE' && is_array($value)) {
                foreach ($value as $index => $item) {
                    foreach ($item as $subKey => $subValue) {
                        $postData["fields[$key][$index][$subKey]"] = $subValue;
                    }
                }
                continue;
            }

            if ($key === 'OBSERVER_IDS' && is_array($value)) {
                foreach ($value as $index => $observerId) {
                    $postData["fields[$key][$index]"] = (int)$observerId;
                }
                continue;
            }

            $postData["fields[$key]"] = $value;
        }
        $postData['params[REGISTER_SONET_EVENT]'] = 'Y';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => (int)($this->config['curl_timeout'] ?? 30),
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            $this->logger->warning('LeadCreator curl error', ['error' => $curlError]);
            return [
                'success' => false,
                'retryable' => true,
                'error_code' => 'curl_error',
                'error_message' => $curlError,
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'retryable' => $httpCode >= 500 || $httpCode === 0,
                'error_code' => 'http_error',
                'error_message' => 'HTTP ' . $httpCode . ': ' . (string)$body,
            ];
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'retryable' => true,
                'error_code' => 'invalid_response',
                'error_message' => 'Не удалось декодировать ответ Bitrix24',
            ];
        }

        if (isset($decoded['result']) && is_numeric($decoded['result'])) {
            return [
                'success' => true,
                'retryable' => false,
                'lead_id' => (int)$decoded['result'],
                'response' => $decoded,
            ];
        }

        return [
            'success' => false,
            'retryable' => false,
            'error_code' => 'bitrix_error',
            'error_message' => JsonEncode::safe($decoded),
        ];
    }
}

final class JsonEncode
{
    public static function safe(array $payload): string
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? 'json_encode_failed' : $encoded;
    }
}
