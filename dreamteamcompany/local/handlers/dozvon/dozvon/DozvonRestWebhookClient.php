<?php
declare(strict_types=1);

/**
 * Минимальный REST-клиент для Bitrix webhook.
 */
final class DozvonRestWebhookClient
{
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(string $baseUrl, int $timeoutSeconds = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function call(string $method, array $params = []): array
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException('MODULE2_LISTS_WEBHOOK_BASE_URL is empty');
        }

        $ch = curl_init($this->baseUrl . '/' . $method);
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL for REST call');
        }

        $payload = json_encode($params, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            curl_close($ch);
            throw new RuntimeException('Failed to encode REST request payload');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('REST ' . $method . ' request failed: ' . $curlError);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('REST ' . $method . ' returned invalid JSON, HTTP ' . $httpCode);
        }

        if (!empty($decoded['error'])) {
            $message = (string)($decoded['error_description'] ?? $decoded['error']);
            throw new RuntimeException('REST ' . $method . ' error: ' . $message);
        }

        return $decoded;
    }
}
