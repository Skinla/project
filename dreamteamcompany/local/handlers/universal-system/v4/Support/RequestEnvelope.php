<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Support;

final class RequestEnvelope
{
    public array $rawHeaders;
    public array $parsedData;
    public string $rawBody;
    public string $sourceDomain;
    public string $phone;
    public string $requestId;
    public string $receivedAt;
    public string $method;

    private function __construct()
    {
    }

    public static function fromGlobals(array $server, array $get, array $post, string $rawBody): self
    {
        $instance = new self();
        $instance->method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
        $instance->rawBody = $instance->method === 'GET' ? http_build_query($get) : $rawBody;
        $instance->rawHeaders = [
            'CONTENT_TYPE' => (string)($server['CONTENT_TYPE'] ?? ''),
            'HTTP_REFERER' => (string)($server['HTTP_REFERER'] ?? ''),
            'HTTP_ORIGIN' => (string)($server['HTTP_ORIGIN'] ?? ''),
            'REQUEST_METHOD' => $instance->method,
            'QUERY_STRING' => (string)($server['QUERY_STRING'] ?? ''),
        ];
        $instance->parsedData = self::parseInputData($instance->method, $instance->rawHeaders['CONTENT_TYPE'], $instance->rawBody, $get, $post);
        $instance->sourceDomain = self::detectDomain($instance->method, $instance->parsedData, $instance->rawHeaders);
        $instance->parsedData['source_domain'] = $instance->sourceDomain;
        $instance->phone = self::extractPhone($instance->parsedData, $instance->rawHeaders['QUERY_STRING']);
        $instance->receivedAt = date('Y-m-d H:i:s');
        $instance->requestId = self::buildRequestId([
            'raw_body' => $instance->rawBody,
            'raw_headers' => $instance->rawHeaders,
            'parsed_data' => $instance->parsedData,
            'source_domain' => $instance->sourceDomain,
        ]);

        return $instance;
    }

    public function isTestRequest(): bool
    {
        if (($this->parsedData['test'] ?? null) === 'test') {
            return true;
        }

        return false;
    }

    public function hasProcessablePhone(): bool
    {
        if ($this->phone !== '') {
            return true;
        }

        $query = urldecode((string)($this->rawHeaders['QUERY_STRING'] ?? ''));
        return str_contains($query, 'fields[PHONE]');
    }

    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'received_at' => $this->receivedAt,
            'raw_body' => $this->rawBody,
            'raw_headers' => $this->rawHeaders,
            'parsed_data' => $this->parsedData,
            'source_domain' => $this->sourceDomain,
            'phone' => $this->phone,
            'state' => 'incoming',
            'status' => [
                'state' => 'incoming',
                'attempts' => 0,
                'last_error' => null,
                'retryable' => false,
                'lead_id' => null,
                'duplicate_key' => null,
                'created_at' => $this->receivedAt,
                'updated_at' => $this->receivedAt,
            ],
        ];
    }

    private static function parseInputData(string $method, string $contentType, string $rawBody, array $get, array $post): array
    {
        if ($method === 'GET') {
            return $get;
        }

        $inputData = [];
        if (stripos($contentType, 'application/json') !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && $decoded !== []) {
                $inputData = $decoded;
            }
        }

        if ($inputData === [] && stripos($contentType, 'application/x-www-form-urlencoded') !== false && $rawBody !== '') {
            parse_str($rawBody, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $inputData = $parsed;
            }
        }

        if ($inputData === [] && $post !== []) {
            $inputData = $post;
        }

        if ($inputData === [] && $rawBody !== '' && ($rawBody[0] ?? '') === '{') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded) && $decoded !== []) {
                $inputData = $decoded;
            }
        }

        if ($inputData === []) {
            $inputData = ['rawBody' => $rawBody];
        }

        return $inputData;
    }

    private static function detectDomain(string $method, array $inputData, array $rawHeaders): string
    {
        $domain = 'unknown.domain';

        foreach (['HTTP_REFERER', 'HTTP_ORIGIN'] as $header) {
            $candidate = self::parseHost((string)($rawHeaders[$header] ?? ''));
            if ($candidate !== '') {
                $domain = $candidate;
                break;
            }
        }

        if ($method === 'GET' && !empty($inputData['domain'])) {
            return trim((string)$inputData['domain']);
        }

        if ($domain === 'unknown.domain' && !empty($inputData['extra']['href'])) {
            $domain = self::parseHost((string)$inputData['extra']['href']) ?: $domain;
        }

        if ($domain === 'unknown.domain' && !empty($inputData['ASSIGNED_BY_ID'])) {
            $domain = trim((string)$inputData['ASSIGNED_BY_ID']);
        }

        if ($domain === 'unknown.domain' && !empty($inputData['source_domain'])) {
            $domain = trim((string)$inputData['source_domain']);
        }

        if ($domain === 'unknown.domain' && !empty($inputData['__submission']['source_url'])) {
            $domain = self::parseHost((string)$inputData['__submission']['source_url']) ?: $domain;
        }

        if (($domain === 'unknown.domain' || $domain === 'mrqz.me') && !empty($inputData['extra']['referrer'])) {
            $domain = self::parseHost((string)$inputData['extra']['referrer']) ?: $domain;
        }

        if ($domain === 'unknown.domain' && !empty($inputData['subPoolName'])) {
            $domain = trim((string)$inputData['subPoolName']);
        }

        if ($domain === 'unknown.domain' && !empty($inputData['url'])) {
            $domain = self::parseHost((string)$inputData['url']) ?: $domain;
        }

        return $domain;
    }

    private static function parseHost(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $parts = parse_url($value);
        if (!empty($parts['host'])) {
            return (string)$parts['host'];
        }

        return '';
    }

    private static function extractPhone(array $inputData, string $queryString): string
    {
        foreach ($inputData as $key => $value) {
            if (is_string($key) && is_string($value) && preg_match('/^phone/i', $key) && trim($value) !== '') {
                return self::normalizePhone($value);
            }
        }

        if (!empty($inputData['contacts']['phone'])) {
            return self::normalizePhone((string)$inputData['contacts']['phone']);
        }

        if (!empty($inputData['callerphone'])) {
            return self::normalizePhone((string)$inputData['callerphone']);
        }

        $decodedQuery = urldecode($queryString);
        if (preg_match('/fields\[PHONE\]\[0\]\[VALUE\]=([^&]+)/', $decodedQuery, $matches)) {
            return self::normalizePhone(urldecode($matches[1]));
        }

        return '';
    }

    private static function normalizePhone(string $phone): string
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

        if (strlen($digits) >= 11 && $digits[0] === '7') {
            return '+' . $digits;
        }

        return '+' . $digits;
    }

    private static function buildRequestId(array $payload): string
    {
        return hash('sha256', Json::encode(self::normalizeForHash($payload)));
    }

    private static function normalizeForHash(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalizeForHash($value);
            }
        }

        return $data;
    }
}
