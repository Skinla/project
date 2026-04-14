<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Bitrix;

use AcademyProfi\CatalogApp\Logging\Logger;

final class BitrixWebhookClient
{
    public function __construct(
        private readonly string $webhookBaseUrl,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 40,
    ) {
    }

    public function call(string $method, array $payload): array
    {
        if (str_starts_with($this->webhookBaseUrl, 'mock://')) {
            return $this->callMock($method, $payload);
        }

        $url = rtrim($this->webhookBaseUrl, '/') . '/' . ltrim($method, '/');

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new BitrixException('Failed to encode request JSON');
        }

        $started = microtime(true);
        [$resp, $httpCode, $transportError] = $this->postJson($url, $body);
        $elapsedMs = (int) ((microtime(true) - $started) * 1000);

        if ($resp === null) {
            $this->logger->error('Bitrix webhook call failed', [
                'method' => $method,
                'url' => $url,
                'elapsedMs' => $elapsedMs,
                'transportError' => $transportError,
            ]);
            throw new BitrixException('Bitrix24 API unavailable (transport)', null, $transportError, ['method' => $method]);
        }

        $decoded = json_decode((string) $resp, true);
        if (!is_array($decoded)) {
            $this->logger->error('Bitrix webhook returned invalid JSON', [
                'method' => $method,
                'url' => $url,
                'elapsedMs' => $elapsedMs,
                'httpCode' => $httpCode,
                'raw' => substr((string) $resp, 0, 2000),
            ]);
            throw new BitrixException('Bitrix24 API returned invalid JSON', null, null, ['method' => $method]);
        }

        if (isset($decoded['error'])) {
            $this->logger->error('Bitrix webhook returned error', [
                'method' => $method,
                'url' => $url,
                'elapsedMs' => $elapsedMs,
                'httpCode' => $httpCode,
                'error' => $decoded['error'],
                'error_description' => $decoded['error_description'] ?? null,
            ]);
            throw new BitrixException(
                'Bitrix24 API error',
                (string) $decoded['error'],
                isset($decoded['error_description']) ? (string) $decoded['error_description'] : null,
                ['method' => $method]
            );
        }

        $this->logger->info('Bitrix webhook call ok', [
            'method' => $method,
            'elapsedMs' => $elapsedMs,
            'httpCode' => $httpCode,
        ]);

        return $decoded;
    }

    /**
     * Transport: prefer ext-curl, fallback to streams.
     *
     * @return array{0:?string,1:int,2:?string} [body, httpCode, error]
     */
    private function postJson(string $url, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [null, 0, 'curl_init failed'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
            ]);

            $resp = curl_exec($ch);
            if ($resp === false) {
                $err = curl_error($ch);
                curl_close($ch);
                return [null, 0, $err];
            }

            $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return [(string)$resp, $httpCode, null];
        }

        $headers = "Content-Type: application/json\r\n";
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headers,
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $context);
        $httpCode = $this->extractHttpCode($http_response_header ?? []);

        if ($resp === false) {
            return [null, $httpCode, 'file_get_contents failed'];
        }

        return [(string)$resp, $httpCode, null];
    }

    /**
     * @param string[] $headers
     */
    private function extractHttpCode(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }

    private function callMock(string $method, array $payload): array
    {
        $fixturesDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'fixtures';

        if ($method === 'catalog.product.get') {
            $id = (int)($payload['id'] ?? 0);
            $path = $fixturesDir . DIRECTORY_SEPARATOR . 'catalog.product.get_' . $id . '.json';
            return $this->readFixture($method, $path);
        }

        if ($method === 'catalog.product.list') {
            $start = (int)($payload['start'] ?? 0);
            $path = $fixturesDir . DIRECTORY_SEPARATOR . 'catalog.product.list_start_' . $start . '.json';
            return $this->readFixture($method, $path);
        }

        return [
            'result' => [],
            'time' => ['mock' => true],
        ];
    }

    private function readFixture(string $method, string $path): array
    {
        if (!is_file($path)) {
            $this->logger->error('Mock fixture missing', ['method' => $method, 'path' => $path]);
            throw new BitrixException('Mock fixture missing', null, null, ['method' => $method, 'path' => $path]);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new BitrixException('Mock fixture read failed', null, null, ['method' => $method, 'path' => $path]);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new BitrixException('Mock fixture invalid JSON', null, null, ['method' => $method, 'path' => $path]);
        }

        $this->logger->info('Bitrix mock call ok', ['method' => $method, 'fixture' => basename($path)]);
        return $decoded;
    }
}

