<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

abstract class AbstractHandler implements ParserHandlerInterface
{
    protected function payload(array $request): array
    {
        $payload = isset($request['parsed_data']) && is_array($request['parsed_data']) ? $request['parsed_data'] : $request;
        $rawHeaders = isset($request['raw_headers']) && is_array($request['raw_headers']) ? $request['raw_headers'] : [];
        $rawBody = (string)($request['raw_body'] ?? '');
        $contentType = (string)($rawHeaders['CONTENT_TYPE'] ?? '');

        if ($rawBody !== '' && (stripos($contentType, 'application/x-www-form-urlencoded') !== false || preg_match('/[^=&]+=/', $rawBody))) {
            $bodyFields = [];
            parse_str($rawBody, $bodyFields);
            foreach ($bodyFields as $key => $value) {
                if (!isset($payload[$key]) || $payload[$key] === '' || $payload[$key] === null) {
                    $payload[$key] = $value;
                }
            }
        } elseif ($rawBody !== '' && ($rawBody[0] ?? '') === '{') {
            $bodyJson = json_decode($rawBody, true);
            if (is_array($bodyJson)) {
                foreach ($bodyJson as $key => $value) {
                    if (!isset($payload[$key]) || $payload[$key] === '' || $payload[$key] === null) {
                        $payload[$key] = $value;
                    }
                }
            }
        }

        return $payload;
    }

    protected function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }

        return '+' . $digits;
    }

    protected function parseUrlHost(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!empty($parts['host'])) {
            return (string)$parts['host'];
        }

        return $url;
    }

    protected function buildUtm(array $payload): array
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $key) {
            if (!empty($payload[$key]) && is_string($payload[$key])) {
                $utm[$key] = trim($payload[$key]);
            }
        }

        return $utm;
    }

    protected function defaultResult(string $handlerCode): array
    {
        return [
            'parsed_ok' => false,
            'contact' => [
                'phone' => '',
                'name' => '',
                'last_name' => '',
                'second_name' => '',
            ],
            'source' => [
                'domain' => '',
                'lookup_mode' => '',
                'lookup_key' => '',
                'site_id' => '',
                'sub_pool_name' => '',
                'source_description' => '',
                'lead_title' => '',
            ],
            'meta' => [
                'handler_code' => $handlerCode,
                'comment' => '',
                'utm' => [],
                'reason' => '',
            ],
        ];
    }
}
