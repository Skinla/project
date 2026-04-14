<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

final class CalltouchHandler extends AbstractHandler
{
    public function parse(array $request): array
    {
        $result = $this->defaultResult('calltouch');
        $payload = $this->payload($request);
        $siteId = $this->cleanString((string)($payload['siteId'] ?? ''));
        if ($siteId === '') {
            return $result;
        }

        $this->fixCallUrls($payload);
        $nameKey = $this->nameKey($payload);
        $phone = $this->normalizePhone((string)($payload['callerphone'] ?? $payload['phone'] ?? ''));
        $name = trim((string)($payload['name'] ?? $payload['Name'] ?? ''));
        $comment = trim((string)($payload['comment'] ?? $payload['COMMENTS'] ?? ''));
        $subPoolName = $this->cleanString((string)($payload['subPoolName'] ?? ''));

        $result['parsed_ok'] = true;
        $result['contact']['phone'] = $phone;
        $result['contact']['name'] = $name;
        $result['source']['domain'] = $nameKey;
        $result['source']['lookup_mode'] = 'calltouch_pair';
        $result['source']['lookup_key'] = $nameKey;
        $result['source']['site_id'] = $siteId;
        $result['source']['sub_pool_name'] = $subPoolName;
        $result['source']['source_description'] = 'CallTouch (siteId=' . $siteId . ')';
        $result['source']['lead_title'] = 'Звонок с сайта [' . $nameKey . ']';
        $result['meta']['comment'] = $comment;
        $result['meta']['utm'] = $this->buildUtm($payload);
        $result['meta']['reason'] = 'calltouch_signature';

        return $result;
    }

    private function fixCallUrls(array &$payload): void
    {
        $subPoolName = $this->cleanString((string)($payload['subPoolName'] ?? ''));
        if (($payload['callUrl'] ?? '') === '' && $subPoolName !== '') {
            $payload['callUrl'] = $subPoolName;
        }
        if (($payload['url'] ?? '') === '' && $subPoolName !== '') {
            $payload['url'] = $subPoolName;
        }
    }

    private function nameKey(array $payload): string
    {
        foreach (['hostname', 'url', 'callUrl', 'siteName', 'subPoolName'] as $field) {
            $value = $this->cleanString((string)($payload[$field] ?? ''));
            if ($value === '') {
                continue;
            }

            if (in_array($field, ['url', 'callUrl'], true)) {
                return $this->parseUrlHost($value);
            }

            return $value;
        }

        return 'unknown.domain';
    }

    private function cleanString(string $value): string
    {
        $value = trim($value);
        return strcasecmp($value, 'null') === 0 ? '' : $value;
    }
}
