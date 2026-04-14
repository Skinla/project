<?php
/**
 * Simple HTTP client for Bitrix24 REST API
 * Uses cURL if available; otherwise stream wrapper (file_get_contents) for hosts without ext-curl.
 */
class BitrixRestClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Call a Bitrix24 REST method
     *
     * @param string $method Method name (e.g. crm.deal.list)
     * @param array<string, mixed> $params Method parameters
     * @param int $timeoutSec HTTP timeout (большие ответы, например crm.activity.get с телом письма)
     * @return array{result?: mixed, total?: int, error?: string, error_description?: string}
     */
    public function call(string $method, array $params = [], int $timeoutSec = 30): array
    {
        $url = $this->baseUrl . '/' . $method . '.json';
        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['error' => 'JSON_ENCODE', 'error_description' => json_last_error_msg()];
        }

        if (function_exists('curl_init')) {
            return $this->callViaCurl($url, $payload, $timeoutSec);
        }

        return $this->callViaStream($url, $payload, $timeoutSec);
    }

    /**
     * @return array{result?: mixed, total?: int, error?: string, error_description?: string}
     */
    private function callViaCurl(string $url, string $payload, int $timeoutSec): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
            CURLOPT_TIMEOUT => $timeoutSec > 0 ? $timeoutSec : 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['error' => 'CURL_ERROR', 'error_description' => $error];
        }

        return $this->decodeResponse($response, $httpCode);
    }

    /**
     * @return array{result?: mixed, total?: int, error?: string, error_description?: string}
     */
    private function callViaStream(string $url, string $payload, int $timeoutSec): array
    {
        $t = $timeoutSec > 0 ? $timeoutSec : 30;
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload),
                ]),
                'content' => $payload,
                'timeout' => $t,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);

        $rh = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $httpCode = 0;
        foreach ($rh as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $httpCode = (int)$m[1];
                break;
            }
        }

        if ($response === false && $httpCode === 0) {
            $last = error_get_last();
            $msg = $last['message'] ?? 'file_get_contents failed (allow_url_fopen?)';

            return ['error' => 'HTTP_ERROR', 'error_description' => $msg];
        }

        return $this->decodeResponse($response !== false ? $response : '', $httpCode);
    }

    /**
     * @return array{result?: mixed, total?: int, error?: string, error_description?: string}
     */
    private function decodeResponse(string $response, int $httpCode): array
    {
        $data = json_decode($response ?: '{}', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'JSON_ERROR', 'error_description' => json_last_error_msg()];
        }

        if ($httpCode >= 400) {
            return [
                'error' => $data['error'] ?? 'HTTP_' . $httpCode,
                'error_description' => $data['error_description'] ?? $response,
            ];
        }

        return is_array($data) ? $data : [];
    }
}
