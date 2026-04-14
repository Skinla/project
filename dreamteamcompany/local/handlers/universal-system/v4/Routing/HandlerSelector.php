<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Routing;

final class HandlerSelector
{
    public function select(array $request): array
    {
        if ($this->extractBitrix24SourceDescription($request) !== '') {
            return ['handler_code' => 'bitrix24_source_description', 'reason' => 'source_description'];
        }

        if ($this->extractBushuevaAssignedBy($request) !== '') {
            return ['handler_code' => 'bushueva_supplier', 'reason' => 'assigned_by_prefix_bushueva'];
        }

        if ($this->isCalltouch($request)) {
            return ['handler_code' => 'calltouch', 'reason' => 'calltouch_signature'];
        }

        if ($this->looksLikeUniversalEnvelope($request)) {
            return ['handler_code' => 'universal', 'reason' => 'recognized_webhook_shape'];
        }

        return ['handler_code' => 'unknown', 'reason' => 'no_standard_handler'];
    }

    private function looksLikeUniversalEnvelope(array $request): bool
    {
        if (isset($request['parsed_data']) && is_array($request['parsed_data'])) {
            return true;
        }

        if (!empty($request['raw_body']) || !empty($request['raw_headers']) || !empty($request['source_domain'])) {
            return true;
        }

        return false;
    }

    private function extractBitrix24SourceDescription(array $request): string
    {
        $rawHeaders = isset($request['raw_headers']) && is_array($request['raw_headers']) ? $request['raw_headers'] : [];
        $queryString = (string)($rawHeaders['QUERY_STRING'] ?? '');
        if ($queryString !== '') {
            $decoded = urldecode($queryString);
            if (preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', $decoded, $matches)) {
                return trim((string)urldecode($matches[1]));
            }
        }

        $rawBody = (string)($request['raw_body'] ?? '');
        if ($rawBody !== '') {
            if (preg_match('/fields\[SOURCE_DESCRIPTION\]=([^&]*)/', urldecode($rawBody), $matches)) {
                return trim((string)urldecode($matches[1]));
            }

            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && !empty($decoded['fields']['SOURCE_DESCRIPTION'])) {
                return trim((string)$decoded['fields']['SOURCE_DESCRIPTION']);
            }
        }

        return '';
    }

    private function extractBushuevaAssignedBy(array $request): string
    {
        $payload = isset($request['parsed_data']) && is_array($request['parsed_data']) ? $request['parsed_data'] : $request;
        $assignedBy = trim((string)($payload['ASSIGNED_BY_ID'] ?? $payload['assigned_by_id'] ?? ''));

        if ($assignedBy === '' && !empty($request['raw_headers']['QUERY_STRING'])) {
            $qs = [];
            parse_str((string)$request['raw_headers']['QUERY_STRING'], $qs);
            $assignedBy = trim((string)($qs['ASSIGNED_BY_ID'] ?? ''));
        }

        if ($assignedBy === '' && !empty($request['raw_body']) && preg_match('/[^=&]+=/', (string)$request['raw_body'])) {
            $bodyFields = [];
            parse_str((string)$request['raw_body'], $bodyFields);
            $assignedBy = trim((string)($bodyFields['ASSIGNED_BY_ID'] ?? ''));
        }

        if ($assignedBy !== '' && mb_strpos($assignedBy, 'Заявка от Bushueva') === 0) {
            return $assignedBy;
        }

        return '';
    }

    private function isCalltouch(array $request): bool
    {
        $payload = isset($request['parsed_data']) && is_array($request['parsed_data']) ? $request['parsed_data'] : $request;
        $rawHeaders = isset($request['raw_headers']) && is_array($request['raw_headers']) ? $request['raw_headers'] : [];
        $rawBody = (string)($request['raw_body'] ?? '');

        if ($rawBody !== '' && (stripos((string)($rawHeaders['CONTENT_TYPE'] ?? ''), 'application/x-www-form-urlencoded') !== false || preg_match('/[^=&]+=/', $rawBody))) {
            $bodyFields = [];
            parse_str($rawBody, $bodyFields);
            foreach ($bodyFields as $key => $value) {
                if (!isset($payload[$key]) || $payload[$key] === '') {
                    $payload[$key] = $value;
                }
            }
        }

        $siteId = trim((string)($payload['siteId'] ?? ''));
        if ($siteId === '' || strcasecmp($siteId, 'null') === 0) {
            return false;
        }

        foreach (['callerphone', 'subPoolName', 'ctCallerId', 'callphase', 'leadtype', 'hostname', 'siteName', 'url', 'callUrl'] as $key) {
            $value = trim((string)($payload[$key] ?? ''));
            if ($value !== '' && strcasecmp($value, 'null') !== 0) {
                return true;
            }
        }

        return false;
    }
}
