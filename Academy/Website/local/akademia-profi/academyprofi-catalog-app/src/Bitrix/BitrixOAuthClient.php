<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Bitrix;

use AcademyProfi\CatalogApp\Logging\Logger;

final class BitrixOAuthClient
{
    public function __construct(
        private readonly string $clientEndpoint, // like https://portal.bitrix24.com/rest/
        private readonly string $accessToken,
        private readonly Logger $logger,
        private readonly int $timeoutSeconds = 40,
    ) {
    }

    public function call(string $method, array $params): array
    {
        $url = rtrim($this->clientEndpoint, '/') . '/' . ltrim($method, '/');

        // Bitrix REST expects auth token as "auth" field for OAuth apps.
        $paramsWithAuth = array_merge(['auth' => $this->accessToken], $params);
        $body = http_build_query($paramsWithAuth);

        $started = microtime(true);
        [$resp, $httpCode, $transportError] = $this->postForm($url, $body);
        $elapsedMs = (int) ((microtime(true) - $started) * 1000);

        if ($resp === null) {
            $this->logger->error('Bitrix OAuth call failed', [
                'method' => $method,
                'url' => $url,
                'elapsedMs' => $elapsedMs,
                'transportError' => $transportError,
            ]);
            throw new BitrixException('Bitrix24 API unavailable (transport)', null, $transportError, ['method' => $method]);
        }

        $decoded = json_decode((string) $resp, true);
        if (!is_array($decoded)) {
            $this->logger->error('Bitrix OAuth returned invalid JSON', [
                'method' => $method,
                'url' => $url,
                'elapsedMs' => $elapsedMs,
                'httpCode' => $httpCode,
                'raw' => substr((string) $resp, 0, 2000),
            ]);
            throw new BitrixException('Bitrix24 API returned invalid JSON', null, null, ['method' => $method]);
        }

        if (isset($decoded['error'])) {
            $this->logger->error('Bitrix OAuth returned error', [
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

        $this->logger->info('Bitrix OAuth call ok', [
            'method' => $method,
            'elapsedMs' => $elapsedMs,
            'httpCode' => $httpCode,
        ]);

        return $decoded;
    }

    /**
     * @return array{0:?string,1:int,2:?string} [body, httpCode, error]
     */
    private function postForm(string $url, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return [null, 0, 'curl_init failed'];
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
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

        $headers = "Content-Type: application/x-www-form-urlencoded\r\n";
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
            if (preg_match('#^HTTP/\\d+\\.\\d+\\s+(\\d+)#', $h, $m)) {
                return (int)$m[1];
            }
        }
        return 0;
    }
}

