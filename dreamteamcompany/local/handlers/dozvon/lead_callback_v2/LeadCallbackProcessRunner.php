<?php

class LeadCallbackProcessRunner
{
    /** @var LeadCallbackQueueStore */
    private $store;

    /** @var LeadCallbackService */
    private $service;

    /** @var callable|null */
    private $logger;

    public function __construct(LeadCallbackQueueStore $store, LeadCallbackService $service, $logger = null)
    {
        $this->store = $store;
        $this->service = $service;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    public function run($leadId = 0, $limit = 10, $force = false)
    {
        $leadId = (int)$leadId;
        $limit = max(1, (int)$limit);
        $force = (bool)$force;
        $nowIso = date('Y-m-d\TH:i:s');

        $leadIds = $leadId > 0 ? [$leadId] : $this->store->listDueLeadIds($nowIso, $limit);
        $this->log('process:start', [
            'lead_id' => $leadId,
            'limit' => $limit,
            'force' => $force,
            'due_count' => count($leadIds),
        ]);

        $result = [
            'ok' => true,
            'processed' => 0,
            'items' => [],
        ];

        foreach ($leadIds as $currentLeadId) {
            $lock = $this->store->acquireLeadLock($currentLeadId);
            if ($lock === false) {
                $this->log('process:lock_failed', ['lead_id' => (int)$currentLeadId]);
                $result['items'][] = [
                    'lead_id' => (int)$currentLeadId,
                    'ok' => false,
                    'error' => 'Failed to acquire lead lock',
                ];
                continue;
            }

            try {
                $job = $this->store->readJob($currentLeadId);
                if (!is_array($job)) {
                    $this->log('process:job_not_found', ['lead_id' => (int)$currentLeadId]);
                    $result['items'][] = [
                        'lead_id' => (int)$currentLeadId,
                        'ok' => false,
                        'error' => 'Job not found',
                    ];
                    continue;
                }

                $nextRunAt = (string)($job['next_run_at'] ?? '');
                if (!$force && $leadId > 0 && $nextRunAt !== '' && $nextRunAt > $nowIso) {
                    $this->log('process:not_due', [
                        'lead_id' => (int)$currentLeadId,
                        'next_run_at' => $nextRunAt,
                    ]);
                    $result['items'][] = [
                        'lead_id' => (int)$currentLeadId,
                        'ok' => true,
                        'skipped' => true,
                        'reason' => 'Job is not due yet',
                        'job' => $this->service->formatPublicJob($job),
                    ];
                    continue;
                }

                $job = $this->service->processJob($job);
                if (!$this->store->writeJob($job)) {
                    $this->log('process:write_failed', ['lead_id' => (int)$currentLeadId]);
                    $result['items'][] = [
                        'lead_id' => (int)$currentLeadId,
                        'ok' => false,
                        'error' => 'Failed to persist processed job',
                    ];
                    continue;
                }

                $result['processed']++;
                $this->log('process:done', [
                    'lead_id' => (int)$currentLeadId,
                    'state' => $job['state'] ?? '',
                    'fix_status' => $job['fix_status'] ?? '',
                    'fix_result' => $job['fix_result'] ?? '',
                    'call_id' => $job['call_id'] ?? '',
                ]);
                $result['items'][] = [
                    'lead_id' => (int)$currentLeadId,
                    'ok' => true,
                    'job' => $this->service->formatPublicJob($job),
                ];
            } finally {
                $this->store->releaseLeadLock($lock);
            }
        }

        $this->log('process:finish', ['processed' => $result['processed']]);
        return $result;
    }

    private function log($message, array $context = [])
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message, $context);
        }
    }
}
