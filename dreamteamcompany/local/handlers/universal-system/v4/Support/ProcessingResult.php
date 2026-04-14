<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Support;

final class ProcessingResult
{
    private array $payload;

    private function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public static function success(int $leadId, string $duplicateKey, string $handlerCode): self
    {
        return new self([
            'state' => 'done',
            'outcome' => 'success',
            'retryable' => false,
            'lead_id' => $leadId,
            'duplicate_key' => $duplicateKey,
            'handler_code' => $handlerCode,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public static function duplicate(?int $leadId, string $duplicateKey, string $handlerCode): self
    {
        return new self([
            'state' => 'done',
            'outcome' => 'duplicate',
            'retryable' => false,
            'lead_id' => $leadId,
            'duplicate_key' => $duplicateKey,
            'handler_code' => $handlerCode,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    public static function error(string $errorCode, string $errorMessage, bool $retryable, string $handlerCode, ?string $duplicateKey = null): self
    {
        return new self([
            'state' => 'error',
            'outcome' => 'error',
            'retryable' => $retryable,
            'lead_id' => null,
            'duplicate_key' => $duplicateKey,
            'handler_code' => $handlerCode,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ]);
    }

    public function toArray(): array
    {
        return $this->payload;
    }
}
