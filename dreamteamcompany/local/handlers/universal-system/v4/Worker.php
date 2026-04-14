<?php

declare(strict_types=1);

namespace UniversalSystem\V4;

use UniversalSystem\V4\Bitrix\LeadCreator;
use UniversalSystem\V4\Bitrix\SourceResolver;
use UniversalSystem\V4\Handlers\Bitrix24SourceDescriptionHandler;
use UniversalSystem\V4\Handlers\BushuevaSupplierHandler;
use UniversalSystem\V4\Handlers\CalltouchHandler;
use UniversalSystem\V4\Handlers\ParserHandlerInterface;
use UniversalSystem\V4\Handlers\UniversalHandler;
use UniversalSystem\V4\Queue\FileQueue;
use UniversalSystem\V4\Routing\HandlerSelector;
use UniversalSystem\V4\Support\Logger;
use UniversalSystem\V4\Support\ProcessingResult;

final class Worker
{
    private array $config;
    private Logger $logger;
    private FileQueue $queue;
    private HandlerSelector $selector;
    private SourceResolver $resolver;
    private LeadCreator $leadCreator;

    public function __construct(
        array $config,
        Logger $logger,
        FileQueue $queue,
        HandlerSelector $selector,
        SourceResolver $resolver,
        LeadCreator $leadCreator
    ) {
        $this->config = $config;
        $this->logger = $logger;
        $this->queue = $queue;
        $this->selector = $selector;
        $this->resolver = $resolver;
        $this->leadCreator = $leadCreator;
    }

    public function processNext(): ?array
    {
        $claimed = $this->queue->claimNext();
        if ($claimed === null) {
            return null;
        }

        $path = $claimed['path'];
        $request = $claimed['data'];
        $selection = $this->selector->select($request);
        $handlerCode = (string)$selection['handler_code'];
        $request['handler'] = $selection;
        $this->queue->updateProcessing($path, $request);

        if ($handlerCode === 'unknown') {
            return $this->complete(
                $path,
                $request,
                ProcessingResult::error('unknown_handler', 'Не удалось подобрать обработчик', false, $handlerCode)
            );
        }

        $parsed = $this->handler($handlerCode)->parse($request);
        $request['parsed'] = $parsed;
        $this->queue->updateProcessing($path, $request);

        if (!($parsed['parsed_ok'] ?? false)) {
            return $this->complete(
                $path,
                $request,
                ProcessingResult::error('parse_failed', 'Обработчик не смог разобрать payload', false, $handlerCode)
            );
        }

        $phone = trim((string)($parsed['contact']['phone'] ?? ''));
        if ($phone === '') {
            return $this->complete(
                $path,
                $request,
                ProcessingResult::error('empty_phone', 'После парсинга не найден телефон', false, $handlerCode)
            );
        }

        $duplicateKey = $this->buildDuplicateKey($parsed);
        $request['status']['duplicate_key'] = $duplicateKey;
        $this->queue->updateProcessing($path, $request);

        $duplicate = $this->queue->findDoneByDuplicateKey($duplicateKey, (string)$request['request_id']);
        if ($duplicate !== null) {
            $leadId = isset($duplicate['data']['result']['lead_id']) ? (int)$duplicate['data']['result']['lead_id'] : null;
            return $this->complete($path, $request, ProcessingResult::duplicate($leadId, $duplicateKey, $handlerCode));
        }

        $resolved = $this->resolver->resolve($parsed);
        if (!($resolved['found'] ?? false)) {
            return $this->complete(
                $path,
                $request,
                ProcessingResult::error(
                    (string)($resolved['error_code'] ?? 'source_resolve_failed'),
                    (string)($resolved['error_message'] ?? 'Не удалось разрешить источник'),
                    (bool)($resolved['retryable'] ?? false),
                    $handlerCode,
                    $duplicateKey
                )
            );
        }

        $request['source_config'] = $resolved['config'];
        $this->queue->updateProcessing($path, $request);

        $lead = $this->leadCreator->create($parsed, $resolved['config']);
        if (!($lead['success'] ?? false)) {
            return $this->complete(
                $path,
                $request,
                ProcessingResult::error(
                    (string)($lead['error_code'] ?? 'lead_create_failed'),
                    (string)($lead['error_message'] ?? 'Не удалось создать лид'),
                    (bool)($lead['retryable'] ?? false),
                    $handlerCode,
                    $duplicateKey
                )
            );
        }

        return $this->complete(
            $path,
            $request,
            ProcessingResult::success((int)$lead['lead_id'], $duplicateKey, $handlerCode)
        );
    }

    public function processBatch(int $limit): array
    {
        $processed = 0;
        $results = [];

        while ($processed < $limit) {
            $result = $this->processNext();
            if ($result === null) {
                break;
            }
            $results[] = $result;
            $processed++;
        }

        return [
            'processed' => $processed,
            'results' => $results,
        ];
    }

    private function complete(string $path, array $request, ProcessingResult $result): array
    {
        $payload = $result->toArray();
        $attempts = (int)($request['status']['attempts'] ?? 1);
        $maxAttempts = (int)($this->config['max_attempts_per_request'] ?? 3);

        $request['result'] = $payload;
        $request['status']['duplicate_key'] = $payload['duplicate_key'] ?? ($request['status']['duplicate_key'] ?? null);
        $request['status']['lead_id'] = $payload['lead_id'] ?? null;
        $request['status']['last_error'] = $payload['error_message'] ?? null;
        $request['status']['retryable'] = (bool)($payload['retryable'] ?? false);

        if (($payload['outcome'] ?? '') === 'error' && ($payload['retryable'] ?? false) && $attempts < $maxAttempts) {
            $request['status']['state'] = 'incoming';
            $target = $this->queue->requeue($path, $request);
            $this->logger->warning('Request requeued for retry', ['request_id' => $request['request_id'], 'path' => $target, 'attempts' => $attempts]);
            return ['request_id' => $request['request_id'], 'state' => 'incoming', 'path' => $target, 'result' => $payload];
        }

        $request['status']['state'] = $payload['state'];
        if (($payload['outcome'] ?? '') === 'error') {
            $target = $this->queue->finishError($path, $request);
            $this->logger->error('Request finished with error', ['request_id' => $request['request_id'], 'error' => $payload['error_message'] ?? null]);
        } else {
            $target = $this->queue->finishDone($path, $request);
            $this->logger->info('Request finished', ['request_id' => $request['request_id'], 'outcome' => $payload['outcome'] ?? 'done']);
        }

        return ['request_id' => $request['request_id'], 'state' => $request['status']['state'], 'path' => $target, 'result' => $payload];
    }

    private function buildDuplicateKey(array $parsed): string
    {
        $phone = preg_replace('/\D+/', '', (string)($parsed['contact']['phone'] ?? ''));
        $sourceKey = trim((string)($parsed['source']['lookup_key'] ?? $parsed['source']['domain'] ?? ''));
        return $phone . '|' . $sourceKey;
    }

    private function handler(string $handlerCode): ParserHandlerInterface
    {
        return match ($handlerCode) {
            'bitrix24_source_description' => new Bitrix24SourceDescriptionHandler(),
            'bushueva_supplier' => new BushuevaSupplierHandler(),
            'calltouch' => new CalltouchHandler(),
            default => new UniversalHandler(),
        };
    }
}
