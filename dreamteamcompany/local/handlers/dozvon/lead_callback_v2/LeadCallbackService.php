<?php

use Bitrix\Main\Loader;

/**
 * Standalone callback logic extracted from lead_callback BP blocks.
 */
class LeadCallbackService
{
    /** @var array */
    private $options;

    /** @var callable|null */
    private $logger;

    public function __construct(array $options = [], $logger = null)
    {
        $this->options = $options;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    public function createJob(array $request)
    {
        $now = $this->nowIso();
        $leadId = $this->extractInt($request['lead_id'] ?? 0);

        return [
            'job_id' => $this->generateJobId($leadId),
            'lead_id' => $leadId,
            'state' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
            'next_run_at' => $now,
            'initialized_at' => '',
            'attempt' => 0,
            'pick_retries' => 0,
            'op_offset' => 0,
            'operator_id' => 0,
            'operator_extension' => '',
            'operator_result' => '',
            'operators_count' => 0,
            'status_id' => trim((string)($request['status_id'] ?? '')),
            'source_id' => trim((string)($request['source_id'] ?? '')),
            'city_id' => $this->extractInt($request['city_id'] ?? 0),
            'phone' => trim((string)($request['phone'] ?? '')),
            'attempt_count_snapshot' => $this->extractInt($request['attempt_count'] ?? 0),
            'portal_host' => trim((string)($request['portal_host'] ?? $this->option('default_portal_host', 'bitrix.dreamteamcompany.ru'))),
            'sip_line' => '',
            'sip_password' => '',
            'routing_lead' => '',
            'timezone' => '',
            'worktime' => '',
            'call_id' => '',
            'vox_session_id' => '',
            'started_at' => '',
            'test_mode' => $this->isTestModeEnabled() ? 'Y' : 'N',
            'fix_status' => '',
            'fix_result' => '',
            'fix_label' => '',
            'result_message' => '',
            'last_error' => '',
            'logs' => [],
        ];
    }

    public function mergeRequestIntoJob(array $job, array $request)
    {
        $map = [
            'status_id' => 'status_id',
            'source_id' => 'source_id',
            'city_id' => 'city_id',
            'phone' => 'phone',
            'attempt_count' => 'attempt_count_snapshot',
            'portal_host' => 'portal_host',
        ];

        foreach ($map as $requestKey => $jobKey) {
            if (!array_key_exists($requestKey, $request)) {
                continue;
            }

            if (in_array($requestKey, ['city_id', 'attempt_count'], true)) {
                $value = $this->extractInt($request[$requestKey]);
                if ($value > 0 || $requestKey === 'attempt_count') {
                    $job[$jobKey] = $value;
                }
                continue;
            }

            $value = trim((string)$request[$requestKey]);
            if ($value !== '') {
                $job[$jobKey] = $value;
            }
        }

        $job['updated_at'] = $this->nowIso();
        return $job;
    }

    public function isActiveState($state)
    {
        return !in_array((string)$state, ['done', 'failed', 'skipped'], true);
    }

    public function formatPublicJob(array $job)
    {
        return [
            'job_id' => (string)($job['job_id'] ?? ''),
            'lead_id' => (int)($job['lead_id'] ?? 0),
            'state' => (string)($job['state'] ?? ''),
            'attempt' => (int)($job['attempt'] ?? 0),
            'pick_retries' => (int)($job['pick_retries'] ?? 0),
            'next_run_at' => (string)($job['next_run_at'] ?? ''),
            'call_id' => (string)($job['call_id'] ?? ''),
            'vox_session_id' => (string)($job['vox_session_id'] ?? ''),
            'fix_status' => (string)($job['fix_status'] ?? ''),
            'fix_result' => (string)($job['fix_result'] ?? ''),
            'result_message' => (string)($job['result_message'] ?? ''),
            'last_error' => (string)($job['last_error'] ?? ''),
            'updated_at' => (string)($job['updated_at'] ?? ''),
        ];
    }

    public function processJob(array $job)
    {
        $nowIso = $this->nowIso();
        $job['updated_at'] = $nowIso;
        $this->appendJobLog($job, 'process', ['state' => $job['state'] ?? '']);

        $leadId = (int)($job['lead_id'] ?? 0);
        if ($leadId <= 0) {
            return $this->markFailed($job, 'lead_id is required');
        }

        $leadData = $this->loadLeadData($leadId);
        if (!$leadData['found']) {
            return $this->markFailed($job, (string)($leadData['message'] ?? ('Lead not found: ' . $leadId)));
        }

        $job = $this->applyLeadSnapshot($job, $leadData);

        if (($job['state'] ?? '') === 'waiting_result') {
            return $this->handleWaitingResult($job, $nowIso);
        }

        $eligibility = $this->validateLeadForStart($job);
        if ($eligibility['status'] !== 'ok') {
            if ($eligibility['status'] === 'final_failed') {
                $this->updateLeadFields($leadId, [
                    $this->option('lead_final_status_field', 'STATUS_ID') => $this->option('lead_final_failed_status', 'PROCESSED'),
                    $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => $this->operatorNoAnswerLabel(),
                ]);
                $job['fix_status'] = 'final_failed';
                $job['fix_result'] = 'operator_no_answer';
                $job['fix_label'] = $this->operatorNoAnswerLabel();
                return $this->completeJob($job, 'done', $eligibility['message']);
            }

            return $this->completeJob($job, 'skipped', $eligibility['message']);
        }

        if (empty($job['initialized_at'])) {
            $job = $this->initializeJob($job);
            if (!$this->isActiveState($job['state'])) {
                return $job;
            }
        }

        return $this->attemptCallback($job, $nowIso);
    }

    private function initializeJob(array $job)
    {
        $phone = $this->normalizePhone($job['phone'] ?? '');
        if ($phone === '') {
            return $this->completeJob($job, 'skipped', 'У лида нет корректного телефона');
        }

        $job['phone'] = $phone;

        $cityConfig = $this->loadCityConfig((int)($job['city_id'] ?? 0));
        if (!$cityConfig['ok']) {
            return $this->markFailed($job, $cityConfig['message']);
        }

        $job['sip_line'] = $cityConfig['sip_line'];
        $job['sip_password'] = $cityConfig['sip_password'];
        $job['routing_lead'] = $cityConfig['routing_lead'];
        $job['timezone'] = $cityConfig['timezone'];
        $job['worktime'] = $cityConfig['worktime'];
        $job['city_name'] = (string)($cityConfig['city_name'] ?? '');
        $this->appendJobLog($job, 'city:loaded', [
            'city_id' => (int)$job['city_id'],
            'city_name' => (string)($job['city_name'] ?? ''),
            'timezone' => (string)$job['timezone'],
            'worktime' => (string)$job['worktime'],
            'routing_lead' => (string)$job['routing_lead'],
            'sip_line' => (string)$job['sip_line'],
            'sip_password' => (string)$job['sip_password'],
        ]);

        $worktimeCheck = $this->evaluateWorktime($job['timezone'], $job['worktime']);
        $this->appendJobLog($job, 'worktime:check', [
            'city_id' => (int)$job['city_id'],
            'city_name' => (string)($job['city_name'] ?? ''),
            'timezone' => (string)$job['timezone'],
            'worktime' => (string)$job['worktime'],
            'basis' => (string)$worktimeCheck['basis'],
            'server_timezone' => (string)$worktimeCheck['server_timezone'],
            'utc_now' => (string)$worktimeCheck['utc_now'],
            'msk_now' => (string)$worktimeCheck['msk_now'],
            'city_now' => (string)$worktimeCheck['city_now'],
            'current_minutes' => (int)$worktimeCheck['current_minutes'],
            'from_minutes' => (int)$worktimeCheck['from_minutes'],
            'to_minutes' => (int)$worktimeCheck['to_minutes'],
            'cross_midnight' => (bool)$worktimeCheck['cross_midnight'],
            'allowed' => (bool)$worktimeCheck['allowed'],
            'reason' => (string)$worktimeCheck['reason'],
        ]);

        if (!$worktimeCheck['allowed']) {
            return $this->completeJob($job, 'skipped', 'Вне рабочего времени КЦ');
        }

        $job['initialized_at'] = $this->nowIso();

        $leadFields = [
            $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => $this->option('lead_in_progress_label', 'В процессе callback'),
            $this->option('lead_started_flag_field', 'UF_CRM_1772538740') => '1',
        ];
        $this->updateLeadFields((int)$job['lead_id'], $leadFields);

        $this->appendJobLog($job, 'init', [
            'attempt_snapshot' => $this->extractInt($job['attempt_count_snapshot'] ?? 0),
            'phone' => $phone,
            'city_id' => (int)$job['city_id'],
            'city_name' => (string)($job['city_name'] ?? ''),
            'routing_lead' => $job['routing_lead'],
            'sip_line' => $job['sip_line'],
            'sip_password' => $job['sip_password'],
        ]);

        return $job;
    }

    private function attemptCallback(array $job, $nowIso)
    {
        $pick = $this->pickOperator($job);
        $job = $pick['job'];

        if (($pick['status'] ?? '') === 'max_retries') {
            $job['fix_status'] = 'final_failed';
            $job['fix_result'] = 'operator_no_answer';
            $job['fix_label'] = $this->operatorNoAnswerLabel();
            $this->updateLeadFields((int)$job['lead_id'], [
                $this->option('lead_final_status_field', 'STATUS_ID') => $this->option('lead_final_failed_status', 'PROCESSED'),
                $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => $job['fix_label'],
            ]);
            return $this->completeJob($job, 'done', 'Лимит повторов выбора оператора исчерпан');
        }

        if (($pick['status'] ?? '') !== 'found') {
            return $this->scheduleRetry($job, 'Оператор не найден: ' . ($pick['message'] ?? $pick['status']), (int)$this->option('retry_delay_sec', 10));
        }

        $attemptReserve = $this->reserveAttempt($job);
        $job = $attemptReserve['job'];
        if (($attemptReserve['status'] ?? '') === 'final_failed') {
            $job['fix_status'] = 'final_failed';
            $job['fix_result'] = 'operator_no_answer';
            $job['fix_label'] = $this->operatorNoAnswerLabel();
            $this->updateLeadFields((int)$job['lead_id'], [
                $this->option('lead_final_status_field', 'STATUS_ID') => $this->option('lead_final_failed_status', 'PROCESSED'),
                $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => $job['fix_label'],
            ]);
            return $this->completeJob($job, 'done', $attemptReserve['message'] ?? 'Лимит попыток дозвона исчерпан');
        }

        $start = $this->startScenario($job);
        $job = $start['job'];
        if (($start['status'] ?? '') !== 'started') {
            return $this->scheduleRetry($job, $start['message'] ?? 'Ошибка запуска звонка', (int)$this->option('retry_delay_sec', 10));
        }

        $job['state'] = 'waiting_result';
        $job['next_run_at'] = $this->addSecondsToIso($nowIso, (int)$this->option('result_wait_delay_sec', 25));
        $job['updated_at'] = $this->nowIso();
        $job['last_error'] = '';
        $job['result_message'] = 'Callback запущен';

        return $job;
    }

    private function handleWaitingResult(array $job, $nowIso)
    {
        $result = $this->resolveResult($job);
        $job = $result['job'];

        if (($result['status'] ?? '') === 'connected') {
            $this->updateLeadFields((int)$job['lead_id'], [
                $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => (string)$job['fix_label'],
            ]);
            return $this->completeJob($job, 'done', $result['message'] ?? 'Клиент соединён с оператором');
        }

        if (($result['status'] ?? '') === 'final_failed') {
            $this->updateLeadFields((int)$job['lead_id'], [
                $this->option('lead_final_status_field', 'STATUS_ID') => $this->option('lead_final_failed_status', 'PROCESSED'),
                $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => (string)$job['fix_label'],
            ]);
            return $this->completeJob($job, 'done', $result['message'] ?? 'Финальная неудача callback');
        }

        if (($result['status'] ?? '') === 'retry_wait') {
            return $this->scheduleRetry($job, $result['message'] ?? 'Результат ещё не готов, повторим', (int)$this->option('retry_delay_sec', 10));
        }

        return $this->scheduleRetry($job, $result['message'] ?? 'Ошибка фиксации результата', (int)$this->option('retry_delay_sec', 10));
    }

    private function loadLeadData($leadId)
    {
        $leadId = (int)$leadId;
        if ($leadId <= 0) {
            return ['found' => false, 'message' => 'lead_id is invalid'];
        }

        if (!Loader::includeModule('crm')) {
            return ['found' => false, 'message' => 'crm module not loaded'];
        }

        $cityField = $this->option('lead_city_field', 'UF_CRM_1744362815');
        $attemptField = $this->option('lead_attempts_field', 'UF_CRM_1771439155');
        $select = ['ID', 'STATUS_ID', 'SOURCE_ID', 'ASSIGNED_BY_ID', $cityField, $attemptField];
        $lead = null;

        if (!class_exists('CCrmLead')) {
            return ['found' => false, 'message' => 'CCrmLead class not available'];
        }

        $res = \CCrmLead::GetListEx([], ['ID' => $leadId], false, false, $select);
        if (!$res) {
            return ['found' => false, 'message' => 'CCrmLead::GetListEx returned false'];
        }

        $lead = $res->Fetch();

        if (!is_array($lead)) {
            global $USER;
            return [
                'found' => false,
                'message' => 'Lead query returned empty',
                'debug' => [
                    'lead_id' => $leadId,
                    'current_user_id' => (isset($USER) && $USER instanceof CUser) ? (int)$USER->GetID() : 0,
                    'is_authorized' => (isset($USER) && $USER instanceof CUser) ? $USER->IsAuthorized() : false,
                ],
            ];
        }

        return [
            'found' => true,
            'id' => $leadId,
            'status_id' => trim((string)($lead['STATUS_ID'] ?? '')),
            'source_id' => trim((string)($lead['SOURCE_ID'] ?? '')),
            'assigned_by_id' => $this->extractInt($lead['ASSIGNED_BY_ID'] ?? 0),
            'city_id' => $this->extractInt($lead[$cityField] ?? 0),
            'attempt_count' => $this->extractInt($lead[$attemptField] ?? 0),
            'phone' => $this->loadLeadPhone($leadId),
        ];
    }

    private function applyLeadSnapshot(array $job, array $leadData)
    {
        $job['status_id'] = $leadData['status_id'];
        $job['source_id'] = $leadData['source_id'];
        $job['city_id'] = $leadData['city_id'];
        $job['attempt_count_snapshot'] = $leadData['attempt_count'];

        if ((string)($job['phone'] ?? '') === '' && (string)$leadData['phone'] !== '') {
            $job['phone'] = (string)$leadData['phone'];
        }

        if ((string)($job['portal_host'] ?? '') === '') {
            $job['portal_host'] = (string)$this->option('default_portal_host', 'bitrix.dreamteamcompany.ru');
        }

        return $job;
    }

    private function validateLeadForStart(array $job)
    {
        $statusId = trim((string)($job['status_id'] ?? ''));
        if ($statusId !== (string)$this->option('required_lead_status', 'NEW')) {
            return ['status' => 'skipped', 'message' => 'Лид не в стадии NEW: ' . $statusId];
        }

        $sourceId = trim((string)($job['source_id'] ?? ''));
        $excludedSources = (array)$this->option('excluded_sources', ['62', 'UC_YFLXKA']);
        if ($sourceId !== '' && in_array($sourceId, $excludedSources, true)) {
            return ['status' => 'skipped', 'message' => 'Источник исключён: ' . $sourceId];
        }

        if ($this->extractInt($job['city_id'] ?? 0) <= 0) {
            return ['status' => 'skipped', 'message' => 'Город не заполнен'];
        }

        $attemptSnapshot = $this->extractInt($job['attempt_count_snapshot'] ?? 0);
        if ($attemptSnapshot >= (int)$this->option('max_attempts', 5) && $this->extractInt($job['attempt'] ?? 0) <= 0) {
            return ['status' => 'final_failed', 'message' => 'Лимит попыток уже достигнут'];
        }

        return ['status' => 'ok', 'message' => ''];
    }

    private function loadLeadPhone($leadId)
    {
        $leadId = (int)$leadId;
        if ($leadId <= 0 || !class_exists('CCrmFieldMulti')) {
            return '';
        }

        $res = \CCrmFieldMulti::GetListEx(
            ['ID' => 'ASC'],
            ['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $leadId, 'TYPE_ID' => 'PHONE'],
            false,
            false,
            ['VALUE']
        );

        if ($res && ($row = $res->Fetch())) {
            return trim((string)($row['VALUE'] ?? ''));
        }

        return '';
    }

    private function loadCityConfig($cityId)
    {
        $cityId = (int)$cityId;
        if ($cityId <= 0) {
            return ['ok' => false, 'message' => 'cityId is empty'];
        }

        if (!Loader::includeModule('iblock')) {
            return ['ok' => false, 'message' => 'iblock module not available'];
        }

        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            ['IBLOCK_ID' => (int)$this->option('city_list_id', 22), '=ID' => $cityId, 'CHECK_PERMISSIONS' => 'N'],
            false,
            ['nTopCount' => 1]
        );

        if (!$res || !($ob = $res->GetNextElement())) {
            return ['ok' => false, 'message' => 'Город не найден в списке 22, city_id=' . $cityId];
        }

        $fields = $ob->GetFields();
        $props = $ob->GetProperties();
        $cityName = trim((string)($fields['NAME'] ?? ''));
        $sipLine = $this->normalizeSipLine($this->firstPropValue($props, ['PROPERTY_613', '613']));
        $sipPassword = $this->firstPropValue($props, ['SIP_PAROL', 'PROPERTY_SIP_PAROL', 'PROPERTY_604', '604']);
        $routingLead = $this->firstPropValue($props, ['ROUTING_LEAD', 'PROPERTY_ROUTING_LEAD', 'PROPERTY_920', '920']);
        $timezone = $this->firstPropValue($props, ['CHASOVOY_POYAS', 'PROPERTY_CHASOVOY_POYAS', 'PROPERTY_468', '468']);
        $worktime = $this->firstPropValue($props, ['NACHALO_RABOTY_KLINIKI_MSK__KHKH_', 'PROPERTY_400', '400']);

        if ($sipLine === '' || $sipPassword === '' || $routingLead === '') {
            return [
                'ok' => false,
                'message' => 'Нет SIP/пароля/ROUTING_LEAD в списке 22'
                    . ', city_id=' . $cityId
                    . ', city_name=' . $cityName
                    . ', sip_line=' . $sipLine
                    . ', sip_password=' . $sipPassword
                    . ', routing_lead=' . $routingLead,
            ];
        }

        return [
            'ok' => true,
            'city_name' => $cityName,
            'sip_line' => $sipLine,
            'sip_password' => $sipPassword,
            'routing_lead' => $routingLead,
            'timezone' => $timezone,
            'worktime' => $worktime,
        ];
    }

    private function pickOperator(array $job)
    {
        $job['pick_retries'] = $this->extractInt($job['pick_retries'] ?? 0) + 1;
        if ($job['pick_retries'] > (int)$this->option('max_pick_retries', 5)) {
            $this->appendJobLog($job, 'pick:max_retries', ['pick_retries' => $job['pick_retries']]);
            return ['status' => 'max_retries', 'message' => 'Лимит повторов выбора оператора', 'job' => $job];
        }

        if (!Loader::includeModule('iblock')) {
            return ['status' => 'error', 'message' => 'iblock module not loaded', 'job' => $this->setJobError($job, 'iblock module not loaded')];
        }

        $res = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            [
                'IBLOCK_ID' => (int)$this->option('operators_list_id', 128),
                'CHECK_PERMISSIONS' => 'N',
                'PROPERTY_GOROD' => (int)$job['city_id'],
            ],
            false,
            ['nTopCount' => 50]
        );

        $operators = [];
        if ($res) {
            while ($ob = $res->GetNextElement()) {
                $props = $ob->GetProperties();
                $operatorValue = $props['OPERATOR']['VALUE'] ?? null;
                if (is_array($operatorValue)) {
                    $operatorValue = reset($operatorValue);
                }

                $userId = $this->extractInt($operatorValue);
                if ($userId <= 0) {
                    continue;
                }

                $extension = $props['VNUTRENNIY_NOMER']['VALUE'] ?? '';
                if (is_array($extension)) {
                    $extension = reset($extension);
                }

                if (!isset($operators[$userId])) {
                    $operators[$userId] = [
                        'id' => $userId,
                        'ext' => trim((string)$extension),
                    ];
                }
            }
        }

        $operators = array_values($operators);
        $job['operators_count'] = count($operators);
        if ($job['operators_count'] <= 0) {
            $job['operator_result'] = 'not_found';
            $job['operator_id'] = 0;
            $this->appendJobLog($job, 'pick:not_found');
            return ['status' => 'not_found', 'message' => 'Операторы не найдены', 'job' => $job];
        }

        $cityId = (int)($job['city_id'] ?? 0);
        $rotationOffset = $this->readCityRotationOffset($cityId, $job['operators_count']);
        $offset = $this->extractInt($job['op_offset'] ?? 0);
        $offsetSource = 'job';
        if ($offset < 0 || $offset >= $job['operators_count']) {
            $offset = 0;
        }
        if ($offset === 0 && $this->extractInt($job['pick_retries'] ?? 0) <= 1) {
            $offset = $rotationOffset;
            $offsetSource = 'city_rotation';
        }

        $orderedOperators = [];
        for ($i = 0; $i < $job['operators_count']; $i++) {
            $index = ($offset + $i) % $job['operators_count'];
            $orderedOperators[] = $operators[$index];
        }

        $this->appendJobLog($job, 'pick:candidates_by_city', [
            'city_id' => (int)($job['city_id'] ?? 0),
            'offset' => $offset,
            'offset_source' => $offsetSource,
            'rotation_offset' => $rotationOffset,
            'operators' => $operators,
            'ordered_operators' => $orderedOperators,
            'count' => $job['operators_count'],
        ]);

        $voxLoaded = Loader::includeModule('voximplant');
        $timemanLoaded = Loader::includeModule('timeman');
        $canCheckBusy = $voxLoaded && class_exists('CVoxImplantIncoming') && method_exists('CVoxImplantIncoming', 'getUserInfo');
        $workdayOpened = [];
        $workdayClosed = [];
        $freeOperators = [];
        $busyOperators = [];
        $selectedOperator = null;

        for ($i = 0; $i < $job['operators_count']; $i++) {
            $index = ($offset + $i) % $job['operators_count'];
            $operator = $operators[$index];
            $userId = (int)$operator['id'];
            $isWorkdayOpen = true;
            $operatorInfo = [
                'id' => $userId,
                'ext' => (string)$operator['ext'],
            ];

            if ($timemanLoaded && class_exists('CTimeManUser')) {
                try {
                    $tmUser = new \CTimeManUser($userId);
                    $info = $tmUser->GetCurrentInfo(true);
                    $state = is_array($info) ? (string)($info['STATE'] ?? '') : '';
                    $operatorInfo['state'] = $state;
                    if ($state === 'CLOSED' || $state === 'PAUSED') {
                        $isWorkdayOpen = false;
                        $workdayClosed[] = $operatorInfo;
                        $this->appendJobLog($job, $state === 'PAUSED' ? 'pick:skip_timeman_paused' : 'pick:skip_timeman_closed', [
                            'operator_id' => $userId,
                            'state' => $state,
                        ]);
                        continue;
                    }
                    $workdayOpened[] = $operatorInfo;
                } catch (\Throwable $e) {
                    $operatorInfo['state'] = 'timeman_error';
                    $operatorInfo['timeman_error'] = $e->getMessage();
                    $workdayOpened[] = $operatorInfo;
                    $this->appendJobLog($job, 'pick:timeman_error', ['operator_id' => $userId, 'error' => $e->getMessage()]);
                }
            } else {
                $operatorInfo['state'] = $timemanLoaded ? 'unknown' : 'timeman_not_loaded';
                $workdayOpened[] = $operatorInfo;
            }

            $isBusy = false;
            if ($canCheckBusy) {
                try {
                    $userInfo = \CVoxImplantIncoming::getUserInfo($userId, false);
                    $busy = is_array($userInfo) ? ($userInfo['BUSY'] ?? null) : null;
                    $isBusy = in_array($busy, [true, 1, '1', 'Y', 'y'], true);
                    $operatorInfo['busy'] = $busy;
                } catch (\Throwable $e) {
                    $operatorInfo['busy'] = 'busy_error';
                    $operatorInfo['busy_error'] = $e->getMessage();
                    $this->appendJobLog($job, 'pick:busy_error', ['operator_id' => $userId, 'error' => $e->getMessage()]);
                }
            } else {
                $operatorInfo['busy'] = $canCheckBusy ? 'unknown' : 'busy_check_not_available';
            }

            if ($isBusy) {
                $busyOperators[] = $operatorInfo;
                $this->appendJobLog($job, 'pick:skip_busy', ['operator_id' => $userId]);
                continue;
            }

            if ($isWorkdayOpen) {
                $freeOperators[] = $operatorInfo;
                if ($selectedOperator === null) {
                    $selectedOperator = [
                        'index' => $index,
                        'id' => $userId,
                        'ext' => (string)$operator['ext'],
                    ];
                }
            }
        }

        $this->appendJobLog($job, 'pick:workday_opened', [
            'operators' => $workdayOpened,
            'count' => count($workdayOpened),
        ]);
        $this->appendJobLog($job, 'pick:workday_closed', [
            'operators' => $workdayClosed,
            'count' => count($workdayClosed),
        ]);
        $this->appendJobLog($job, 'pick:free_by_busy', [
            'operators' => $freeOperators,
            'count' => count($freeOperators),
        ]);
        $this->appendJobLog($job, 'pick:busy_by_busy', [
            'operators' => $busyOperators,
            'count' => count($busyOperators),
        ]);

        if ($selectedOperator !== null) {
            $job['operator_id'] = (int)$selectedOperator['id'];
            $job['operator_extension'] = (string)$selectedOperator['ext'];
            $job['operator_result'] = 'found';
            $nextOffset = ((int)$selectedOperator['index'] + 1) % $job['operators_count'];
            $job['op_offset'] = $nextOffset;
            $job['last_error'] = '';
            $this->writeCityRotationOffset($cityId, $nextOffset);
            $this->appendJobLog($job, 'pick:distribution', [
                'city_id' => $cityId,
                'selected_operator_id' => $job['operator_id'],
                'selected_extension' => $job['operator_extension'],
                'used_offset' => $offset,
                'next_offset' => $nextOffset,
                'offset_source' => $offsetSource,
            ]);
            $this->appendJobLog($job, 'pick:found', ['operator_id' => $job['operator_id'], 'extension' => $job['operator_extension']]);

            return ['status' => 'found', 'message' => 'Оператор найден', 'job' => $job];
        }

        $job['operator_result'] = 'all_busy';
        $job['operator_id'] = 0;
        $job['operator_extension'] = '';
        $this->appendJobLog($job, 'pick:all_busy', ['operators_count' => $job['operators_count']]);

        return ['status' => 'all_busy', 'message' => 'Все операторы заняты или недоступны', 'job' => $job];
    }

    private function startScenario(array $job)
    {
        $voxAccountId = $this->resolveVoxAccountId();
        $voxApiKey = $this->resolveVoxApiKey();
        if (!$this->isTestModeEnabled() && ($voxAccountId === '' || $voxApiKey === '')) {
            return ['status' => 'error', 'message' => 'Не заданы данные Voximplant API', 'job' => $this->setJobError($job, 'Не заданы данные Voximplant API')];
        }

        $customData = [
            'lead_id' => (int)$job['lead_id'],
            'attempt_number' => (int)$job['attempt'],
            'client_number' => (string)$job['phone'],
            'sip_line' => (string)$job['sip_line'],
            'sip_password' => (string)$job['sip_password'],
            'operator_user_id' => (int)$job['operator_id'],
            'operator_extension' => (string)$job['operator_extension'],
            'operator_name' => '',
            'portal_host' => (string)($job['portal_host'] ?? ''),
            'TEXT_TO_PRONOUNCE' => (string)$this->option('text_to_pronounce', 'Звоним новому лид'),
        ];
        $customData = $this->applyOperatorDestination($customData, $job);

        $payload = http_build_query([
            'account_id' => $voxAccountId,
            'api_key' => $voxApiKey,
            'rule_id' => (string)$job['routing_lead'],
            'script_custom_data' => json_encode($customData, JSON_UNESCAPED_UNICODE),
        ]);

        if ($this->isTestModeEnabled()) {
            $job['call_id'] = 'dryrun_' . (int)$job['lead_id'] . '_' . (int)$job['attempt'];
            $job['vox_session_id'] = 'dryrun_' . substr(md5((string)$job['job_id'] . '_' . (string)$job['attempt']), 0, 12);
            $job['started_at'] = $this->nowHuman();
            $job['result_message'] = 'TEST MODE: запуск в Vox пропущен';
            $job['last_error'] = '';
            $job['test_mode'] = 'Y';

            $this->appendJobLog($job, 'call:test_mode_skip', [
                'payload' => $customData,
                'attempt' => (int)$job['attempt'],
                'max_attempts' => (int)$this->option('max_attempts', 5),
                'routing_lead' => (string)$job['routing_lead'],
                'sip_line' => (string)$job['sip_line'],
                'operator_id' => (int)$job['operator_id'],
                'operator_extension' => (string)$job['operator_extension'],
            ]);

            return ['status' => 'started', 'message' => 'TEST MODE: запуск в Vox пропущен', 'job' => $job];
        }

        $ch = curl_init('https://api.voximplant.com/platform_api/StartScenarios/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['status' => 'error', 'message' => 'Vox curl error: ' . $curlError, 'job' => $this->setJobError($job, 'Vox curl error: ' . $curlError)];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return ['status' => 'error', 'message' => 'Vox invalid JSON, HTTP ' . $httpCode, 'job' => $this->setJobError($job, 'Vox invalid JSON, HTTP ' . $httpCode)];
        }

        $errorMsg = '';
        if (isset($decoded['error_description'])) {
            $errorMsg = (string)$decoded['error_description'];
        } elseif (isset($decoded['error'])) {
            $errorMsg = is_scalar($decoded['error']) ? (string)$decoded['error'] : json_encode($decoded['error'], JSON_UNESCAPED_UNICODE);
        }

        if ($errorMsg !== '' || $httpCode >= 400) {
            $message = 'Vox API: ' . ($errorMsg !== '' ? $errorMsg : 'HTTP ' . $httpCode);
            return ['status' => 'error', 'message' => $message, 'job' => $this->setJobError($job, $message)];
        }

        $callId = '';
        $voxSessionId = '';
        if (!empty($decoded['call_session_history_id'])) {
            $voxSessionId = (string)$decoded['call_session_history_id'];
            $callId = 'vox_' . $voxSessionId;
        } elseif (!empty($decoded['result']['CALL_ID'])) {
            $callId = (string)$decoded['result']['CALL_ID'];
        }

        $job['call_id'] = $callId;
        $job['vox_session_id'] = $voxSessionId;
        $job['started_at'] = $this->nowHuman();
        $job['result_message'] = 'Callback запущен';
        $job['last_error'] = '';

        $this->appendJobLog($job, 'call:started', [
            'call_id' => $callId,
            'vox_session_id' => $voxSessionId,
            'attempt' => (int)$job['attempt'],
            'max_attempts' => (int)$this->option('max_attempts', 5),
        ]);

        return ['status' => 'started', 'message' => 'Callback запущен', 'job' => $job];
    }

    private function reserveAttempt(array $job)
    {
        $maxAttempts = (int)$this->option('max_attempts', 5);
        $attemptSnapshot = $this->extractInt($job['attempt_count_snapshot'] ?? 0);
        $currentAttempt = max($attemptSnapshot, $this->extractInt($job['attempt'] ?? 0));
        $nextAttempt = $currentAttempt + 1;

        if ($nextAttempt > $maxAttempts) {
            $this->appendJobLog($job, 'attempt:limit_reached', [
                'current_attempt' => $currentAttempt,
                'max_attempts' => $maxAttempts,
            ]);
            return ['status' => 'final_failed', 'message' => 'Лимит попыток дозвона исчерпан', 'job' => $job];
        }

        $job['attempt'] = $nextAttempt;
        $job['attempt_count_snapshot'] = $nextAttempt;
        $this->updateLeadFields((int)$job['lead_id'], [
            $this->option('lead_attempts_field', 'UF_CRM_1771439155') => $nextAttempt,
            $this->option('lead_status_text_field', 'UF_CRM_1773155019732') => $this->option('lead_in_progress_label', 'В процессе callback'),
            $this->option('lead_started_flag_field', 'UF_CRM_1772538740') => '1',
        ]);
        $this->appendJobLog($job, 'attempt:reserved', [
            'attempt' => $nextAttempt,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => max(0, $maxAttempts - $nextAttempt),
        ]);

        return ['status' => 'ok', 'message' => '', 'job' => $job];
    }

    private function resolveResult(array $job)
    {
        if ($this->isTestModeEnabled() || (string)($job['test_mode'] ?? '') === 'Y') {
            return $this->resolveTestModeResult($job);
        }

        $record = null;
        $voxHistory = null;

        if ((string)($job['vox_session_id'] ?? '') !== '') {
            $voxHistory = $this->loadVoxHistoryRecord((string)$job['vox_session_id']);
        }

        if ($voxHistory !== null) {
            $record = $this->buildVoxFallbackRecord($voxHistory);
        }

        if ($record === null && $this->extractInt($job['operator_id'] ?? 0) > 0 && (string)($job['phone'] ?? '') !== '') {
            $record = $this->loadStatisticRecord((int)$job['operator_id'], (string)$job['phone'], (string)($job['started_at'] ?? ''));
        }

        if ($record === null) {
            $job['fix_status'] = 'retry_wait';
            $job['fix_result'] = 'operator_no_answer';
            $job['fix_label'] = $this->operatorNoAnswerLabel();
            $job['result_message'] = 'Статистика не найдена, повторим';
            $this->appendJobLog($job, 'fix:not_found', [
                'attempt' => (int)$job['attempt'],
                'max_attempts' => (int)$this->option('max_attempts', 5),
                'call_id' => (string)($job['call_id'] ?? ''),
                'vox_session_id' => (string)($job['vox_session_id'] ?? ''),
            ]);
            return ['status' => 'retry_wait', 'message' => $job['result_message'], 'job' => $job];
        }

        $recordSource = trim((string)($record['__source'] ?? 'unknown'));
        $duration = $this->extractInt($record['CALL_DURATION'] ?? ($record['duration'] ?? 0));
        $status = 'operator_no_answer';
        $scriptStatus = trim((string)($record['status'] ?? ($record['script_result'] ?? ($record['result'] ?? ''))));
        $failedCode = trim((string)($record['CALL_FAILED_CODE'] ?? ($record['call_failed_code'] ?? ($record['code'] ?? ($record['disconnect_code'] ?? '')))));
        $failedReason = trim((string)($record['CALL_FAILED_REASON'] ?? ($record['call_failed_reason'] ?? ($record['finish_reason'] ?? ($record['reason'] ?? '')))));

        if (is_array($voxHistory)) {
            $voxOutcome = $this->resolveVoxHistoryOutcome($voxHistory);
            $voxType = trim((string)($voxOutcome['type'] ?? ''));
            if ($voxType !== '' && $voxType !== 'unknown') {
                if ($voxType === 'human') {
                    $status = 'connected';
                } elseif ($voxType === 'voicemail') {
                    $status = 'client_no_answer';
                } elseif (in_array($voxType, ['client_no_answer', 'client_busy', 'operator_no_answer', 'cancelled'], true)) {
                    $status = $voxType;
                }
            } elseif ($duration > 0) {
                $status = 'connected';
            } elseif (in_array($scriptStatus, ['connected', 'operator_no_answer', 'client_no_answer', 'client_busy'], true)) {
                $status = $scriptStatus;
            } else {
                $status = $this->mapDisconnectCodeToStatus($failedCode);
            }
        } elseif ($duration > 0) {
            $status = 'connected';
        } else {
            if (in_array($scriptStatus, ['connected', 'operator_no_answer', 'client_no_answer', 'client_busy'], true)) {
                $status = $scriptStatus;
            } else {
                $status = $this->mapDisconnectCodeToStatus($failedCode);
            }
        }

        $label = $this->statusToLabel($status);
        $job['fix_result'] = $status;
        $job['fix_label'] = $label;
        $resolvedOperatorId = $this->extractOperatorIdFromRecord($record);
        $selectedOperatorId = $this->extractInt($job['operator_id'] ?? 0);
        $operatorMatched = $resolvedOperatorId > 0 ? ($resolvedOperatorId === $selectedOperatorId) : null;
        $this->appendJobLog($job, 'fix:operator_match', [
            'selected_operator_id' => $selectedOperatorId,
            'resolved_operator_id' => $resolvedOperatorId,
            'matched' => $operatorMatched,
            'source' => $recordSource,
        ]);
        $this->appendJobLog($job, 'fix:resolved', [
            'attempt' => (int)$job['attempt'],
            'max_attempts' => (int)$this->option('max_attempts', 5),
            'source' => $recordSource,
            'status' => $status,
            'duration' => $duration,
            'script_status' => $scriptStatus,
            'failed_code' => $failedCode,
            'failed_reason' => $failedReason,
            'call_id' => (string)($job['call_id'] ?? ''),
            'vox_session_id' => (string)($job['vox_session_id'] ?? ''),
            'vox_finish_reason' => is_array($voxHistory) ? (string)($voxHistory['finish_reason'] ?? '') : '',
            'vox_rule_name' => is_array($voxHistory) ? (string)($voxHistory['rule_name'] ?? '') : '',
            'vox_recording_url' => is_array($voxHistory) ? (string)($voxHistory['record_url'] ?? ($voxHistory['recording_url'] ?? '')) : '',
            'selected_operator_id' => $selectedOperatorId,
            'resolved_operator_id' => $resolvedOperatorId,
            'operator_matched' => $operatorMatched,
        ]);

        if ($status === 'connected') {
            $job['fix_status'] = 'connected';
            $job['result_message'] = $this->buildResultMessage('Клиент соединён с оператором', $voxHistory);
            return ['status' => 'connected', 'message' => $job['result_message'], 'job' => $job];
        }

        if (in_array($status, ['client_no_answer', 'client_busy', 'cancelled'], true)) {
            $job['fix_status'] = 'final_failed';
            $job['result_message'] = $this->buildResultMessage('Оператор ответил, клиент не соединён', $voxHistory);
            return ['status' => 'final_failed', 'message' => $job['result_message'], 'job' => $job];
        }

        $job['fix_status'] = 'retry_wait';
        $job['result_message'] = $this->buildResultMessage('Оператор не ответил, повтор', $voxHistory);
        return ['status' => 'retry_wait', 'message' => $job['result_message'], 'job' => $job];
    }

    private function loadVoxHistoryRecord($voxSessionId)
    {
        $voxAccountId = $this->resolveVoxAccountId();
        $voxApiKey = $this->resolveVoxApiKey();
        if ($voxAccountId === '' || $voxApiKey === '') {
            return null;
        }

        try {
            $payload = http_build_query([
                'account_id' => $voxAccountId,
                'api_key' => $voxApiKey,
                'call_session_history_id' => preg_replace('/\D+/', '', (string)$voxSessionId),
            ]);

            $ch = curl_init('https://api.voximplant.com/platform_api/GetCallHistory/');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            if ($response === false) {
                return null;
            }

            $decoded = json_decode($response, true);
            if (!is_array($decoded) || empty($decoded['result']) || !is_array($decoded['result'])) {
                return null;
            }

            if (!empty($decoded['result'][0]) && is_array($decoded['result'][0])) {
                $row = $decoded['result'][0];
                $row['__source'] = 'vox_history';
                return $row;
            }
        } catch (\Throwable $e) {
            // ignore, fallback to StatisticTable
        }

        return null;
    }

    private function loadStatisticRecord($operatorId, $phone, $startedAt)
    {
        $phoneNorm = preg_replace('/\D+/', '', (string)$phone);
        if (!is_string($phoneNorm) || strlen($phoneNorm) < 10) {
            return null;
        }

        if (!Loader::includeModule('voximplant')) {
            return null;
        }

        $fromTs = $startedAt !== '' ? strtotime($startedAt . ' -2 minutes') : strtotime('-10 minutes');
        if ($fromTs === false) {
            $fromTs = strtotime('-10 minutes');
        }

        $fromDate = new \Bitrix\Main\Type\DateTime(date('d.m.Y H:i:s', $fromTs), 'd.m.Y H:i:s');
        $res = \Bitrix\Voximplant\StatisticTable::getList([
            'filter' => [
                '=PORTAL_USER_ID' => (int)$operatorId,
                '%=PHONE_NUMBER' => '%' . $phoneNorm,
                '>=CALL_START_DATE' => $fromDate,
            ],
            'order' => ['ID' => 'DESC'],
            'limit' => 1,
        ]);

        $row = $res->fetch();
        if (!is_array($row)) {
            return null;
        }

        $row['__source'] = 'statistic_table';
        return $row;
    }

    private function resolveVoxHistoryOutcome(array $history)
    {
        $duration = (int)($history['duration'] ?? 0);
        $finishReason = trim((string)($history['finish_reason'] ?? ''));
        $resourceUsage = is_array($history['other_resource_usage'] ?? null) ? $history['other_resource_usage'] : [];
        $hasVoicemail = false;

        foreach ($resourceUsage as $usage) {
            if (!is_array($usage)) {
                continue;
            }

            $type = strtoupper(trim((string)($usage['resource_type'] ?? '')));
            $desc = strtoupper(trim((string)($usage['description'] ?? '')));
            if ($type === 'VOICEMAILDETECTION' || $desc === 'VOICEMAIL') {
                $hasVoicemail = true;
                break;
            }
        }

        $finishReasonLc = function_exists('mb_strtolower') ? mb_strtolower($finishReason) : strtolower($finishReason);
        $type = 'unknown';
        $code = 'OTHER';
        $message = $finishReason !== '' ? $finishReason : 'Vox history fallback';

        if ($hasVoicemail) {
            $type = 'voicemail';
            $code = '304';
            $message = 'VoiceMail';
        } elseif ($duration > 0) {
            $type = 'human';
            $code = '200';
        } elseif (strpos($finishReasonLc, 'busy') !== false || strpos($finishReasonLc, '486') !== false) {
            $type = 'client_busy';
            $code = '486';
        } elseif (
            strpos($finishReasonLc, 'operator') !== false
            && (strpos($finishReasonLc, 'no answer') !== false || strpos($finishReasonLc, 'unanswered') !== false)
        ) {
            $type = 'operator_no_answer';
            $code = '603';
        } elseif (
            strpos($finishReasonLc, 'no answer') !== false
            || strpos($finishReasonLc, 'no_answer') !== false
            || strpos($finishReasonLc, 'not answered') !== false
            || strpos($finishReasonLc, 'unanswered') !== false
            || strpos($finishReasonLc, 'timeout') !== false
            || strpos($finishReasonLc, '404') !== false
            || strpos($finishReasonLc, '403') !== false
            || strpos($finishReasonLc, '480') !== false
            || strpos($finishReasonLc, '484') !== false
        ) {
            $type = 'client_no_answer';
            $code = '304';
        } elseif (
            strpos($finishReasonLc, 'fail') !== false
            || strpos($finishReasonLc, 'error') !== false
            || strpos($finishReasonLc, 'reject') !== false
            || strpos($finishReasonLc, 'declin') !== false
            || strpos($finishReasonLc, 'cancel') !== false
        ) {
            $type = 'cancelled';
            $code = '402';
        }

        return [
            'type' => $type,
            'code' => $code,
            'message' => $message,
            'duration' => $duration,
        ];
    }

    private function buildVoxFallbackRecord(array $history)
    {
        $outcome = $this->resolveVoxHistoryOutcome($history);

        return [
            '__source' => 'vox_history',
            'CALL_FAILED_CODE' => (string)($outcome['code'] ?? 'OTHER'),
            'CALL_FAILED_REASON' => (string)($outcome['message'] ?? 'Vox history fallback'),
            'CALL_DURATION' => (int)($outcome['duration'] ?? 0),
            'finish_reason' => (string)($history['finish_reason'] ?? ''),
            'rule_name' => (string)($history['rule_name'] ?? ''),
            'application_name' => (string)($history['application_name'] ?? ''),
            'record_url' => (string)($history['record_url'] ?? ($history['recording_url'] ?? '')),
        ];
    }

    private function buildResultMessage($baseMessage, $voxHistory = null)
    {
        $parts = [];
        $baseMessage = trim((string)$baseMessage);
        if ($baseMessage !== '') {
            $parts[] = $baseMessage;
        }

        if (is_array($voxHistory)) {
            $voxBits = [];
            $ruleName = trim((string)($voxHistory['rule_name'] ?? ''));
            $appName = trim((string)($voxHistory['application_name'] ?? ''));
            $finishReason = trim((string)($voxHistory['finish_reason'] ?? ''));
            $duration = (int)($voxHistory['duration'] ?? 0);

            if ($ruleName !== '') {
                $voxBits[] = 'rule=' . $ruleName;
            }
            if ($appName !== '') {
                $voxBits[] = 'app=' . $appName;
            }
            if ($finishReason !== '') {
                $voxBits[] = 'finish=' . $finishReason;
            }
            if ($duration > 0) {
                $voxBits[] = 'duration=' . $duration . 's';
            }
            if (!empty($voxHistory['log_file_url'])) {
                $voxBits[] = 'log_url=' . $voxHistory['log_file_url'];
            } elseif (!empty($voxHistory['record_url'])) {
                $voxBits[] = 'record_url=' . $voxHistory['record_url'];
            }

            if (!empty($voxBits)) {
                $parts[] = 'Vox: ' . implode(', ', $voxBits);
            }
        }

        return implode(' | ', $parts);
    }

    private function extractOperatorIdFromRecord($record)
    {
        if (!is_array($record)) {
            return 0;
        }

        $candidates = [
            $record['PORTAL_USER_ID'] ?? null,
            $record['USER_ID'] ?? null,
            $record['USER_PHONE_INNER'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $id = (int)preg_replace('/\D+/', '', (string)$candidate);
            if ($id > 0) {
                return $id;
            }
        }

        return 0;
    }

    private function mapDisconnectCodeToStatus($code)
    {
        $map = [
            '200' => 'connected',
            '304' => 'client_no_answer',
            '403' => 'client_no_answer',
            '404' => 'client_no_answer',
            '480' => 'client_no_answer',
            '484' => 'client_no_answer',
            '486' => 'client_busy',
            '503' => 'client_no_answer',
            '603' => 'operator_no_answer',
            '603-S' => 'operator_no_answer',
            '402' => 'cancelled',
            '423' => 'cancelled',
        ];

        return $map[(string)$code] ?? 'operator_no_answer';
    }

    private function statusToLabel($status)
    {
        $map = [
            'connected' => 'Успешно соединён',
            'operator_no_answer' => $this->operatorNoAnswerLabel(),
            'client_no_answer' => 'Не удалось (клиент не ответил)',
            'client_busy' => 'Не удалось (клиент занят)',
            'cancelled' => 'Не удалось (звонок отменён)',
        ];

        return $map[(string)$status] ?? 'Ошибка callback';
    }

    private function operatorNoAnswerLabel()
    {
        return (string)$this->option('operator_no_answer_label', 'Не удалось (оператор не ответил)');
    }

    private function evaluateWorktime($timezone, $worktime)
    {
        $worktime = trim((string)$worktime);
        $timezone = trim((string)$timezone);
        $utcNow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $mskNow = $utcNow->setTimezone(new \DateTimeZone('Europe/Moscow'));
        $offsetHours = is_numeric(str_replace(',', '.', $timezone)) ? (float)str_replace(',', '.', $timezone) : 0.0;
        $cityNowTs = $utcNow->getTimestamp() + (int)round($offsetHours * 3600);
        $currentMinutes = ((int)$mskNow->format('G') * 60) + (int)$mskNow->format('i');
        $result = [
            'allowed' => true,
            'reason' => 'empty_worktime',
            'basis' => 'msk',
            'server_timezone' => (string)date_default_timezone_get(),
            'utc_now' => $utcNow->format('Y-m-d H:i:s'),
            'msk_now' => $mskNow->format('Y-m-d H:i:s'),
            'city_now' => gmdate('Y-m-d H:i:s', $cityNowTs),
            'current_minutes' => $currentMinutes,
            'from_minutes' => 0,
            'to_minutes' => 0,
            'cross_midnight' => false,
        ];

        if ($worktime === '') {
            return $result;
        }

        if (!preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $worktime, $matches)) {
            $result['reason'] = 'invalid_format';
            return $result;
        }

        $fromMinutes = ((int)$matches[1] * 60) + (int)$matches[2];
        $toMinutes = ((int)$matches[3] * 60) + (int)$matches[4];
        $crossMidnight = $fromMinutes > $toMinutes;

        $result['reason'] = 'checked_by_msk_window';
        $result['from_minutes'] = $fromMinutes;
        $result['to_minutes'] = $toMinutes;
        $result['cross_midnight'] = $crossMidnight;

        if ($crossMidnight) {
            $result['allowed'] = $currentMinutes >= $fromMinutes || $currentMinutes <= $toMinutes;
            return $result;
        }

        $result['allowed'] = $currentMinutes >= $fromMinutes && $currentMinutes <= $toMinutes;
        return $result;
    }

    private function updateLeadFields($leadId, array $fields)
    {
        $leadId = (int)$leadId;
        if ($leadId <= 0 || empty($fields) || !Loader::includeModule('crm') || !class_exists('CCrmLead')) {
            return false;
        }

        $entity = new \CCrmLead(false);
        $result = $entity->Update($leadId, $fields);
        return $result !== false;
    }

    private function scheduleRetry(array $job, $message, $delaySeconds)
    {
        $job['state'] = 'retry_wait';
        $job['next_run_at'] = $this->addSecondsToIso($this->nowIso(), (int)$delaySeconds);
        $job['updated_at'] = $this->nowIso();
        $job['last_error'] = (string)$message;
        $job['result_message'] = (string)$message;
        $this->appendJobLog($job, 'retry', ['delay_sec' => (int)$delaySeconds, 'message' => (string)$message]);
        return $job;
    }

    private function completeJob(array $job, $state, $message)
    {
        $job['state'] = (string)$state;
        $job['next_run_at'] = '';
        $job['updated_at'] = $this->nowIso();
        $job['result_message'] = (string)$message;
        if ($state !== 'done') {
            $job['last_error'] = (string)$message;
        }
        $this->appendJobLog($job, 'complete', ['state' => $state, 'message' => (string)$message]);
        return $job;
    }

    private function markFailed(array $job, $message)
    {
        $job['fix_status'] = 'error';
        $job['fix_result'] = 'error';
        $job['fix_label'] = 'Ошибка callback';
        return $this->completeJob($job, 'failed', $message);
    }

    private function setJobError(array $job, $message)
    {
        $job['last_error'] = (string)$message;
        $job['result_message'] = (string)$message;
        $this->appendJobLog($job, 'error', ['message' => (string)$message]);
        return $job;
    }

    private function appendJobLog(array &$job, $message, array $context = [])
    {
        $line = $this->nowIso() . ' ' . (string)$message;
        if (!empty($context)) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        if (!isset($job['logs']) || !is_array($job['logs'])) {
            $job['logs'] = [];
        }

        $job['logs'][] = $line;
        $maxLogs = (int)$this->option('max_job_logs', 50);
        if (count($job['logs']) > $maxLogs) {
            $job['logs'] = array_slice($job['logs'], -1 * $maxLogs);
        }

        if ($this->logger !== null) {
            call_user_func($this->logger, 'lead_callback_v2: ' . (string)$message, $context + ['lead_id' => (int)($job['lead_id'] ?? 0), 'job_id' => (string)($job['job_id'] ?? '')]);
        }
    }

    private function firstPropValue(array $props, array $codes)
    {
        foreach ($codes as $code) {
            if (!array_key_exists($code, $props)) {
                continue;
            }

            $value = $props[$code]['VALUE'] ?? ($props[$code]['VALUE_ENUM'] ?? ($props[$code]['VALUE_NUM'] ?? ($props[$code]['~VALUE'] ?? null)));
            if (is_array($value)) {
                $value = reset($value);
            }

            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function normalizeSipLine($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (stripos($value, 'sip') === 0) {
            return $value;
        }

        if (ctype_digit($value) || ctype_digit(ltrim($value, '-'))) {
            return 'sip' . ltrim($value, '-');
        }

        return $value;
    }

    private function normalizePhone($value)
    {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (!is_string($digits) || $digits === '') {
            return '';
        }

        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            return '+7' . substr($digits, -10);
        }

        if (strlen($digits) === 10) {
            return '+7' . $digits;
        }

        if ($digits[0] !== '0') {
            return '+' . $digits;
        }

        return '';
    }

    private function resolveVoxAccountId()
    {
        $fromOptions = trim((string)$this->option('vox_account_id', ''));
        if ($fromOptions !== '') {
            return $fromOptions;
        }

        $fromEnv = getenv('LEAD_CALLBACK_V2_VOX_ACCOUNT_ID');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $legacyEnv = getenv('LEAD_CALLBACK_VOX_ACCOUNT_ID');
        return is_string($legacyEnv) ? $legacyEnv : '';
    }

    private function resolveVoxApiKey()
    {
        $fromOptions = trim((string)$this->option('vox_api_key', ''));
        if ($fromOptions !== '') {
            return $fromOptions;
        }

        $fromEnv = getenv('LEAD_CALLBACK_V2_VOX_API_KEY');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $legacyEnv = getenv('LEAD_CALLBACK_VOX_API_KEY');
        return is_string($legacyEnv) ? $legacyEnv : '';
    }

    private function isTestModeEnabled()
    {
        $value = $this->option('vox_test_mode', false);
        return in_array($value, [true, 1, '1', 'Y', 'y', 'true', 'TRUE'], true);
    }

    private function resolveTestModeResult(array $job)
    {
        $testResult = trim((string)$this->option('vox_test_result', 'connected'));
        if (!in_array($testResult, ['connected', 'operator_no_answer', 'client_no_answer', 'client_busy', 'cancelled'], true)) {
            $testResult = 'connected';
        }

        $job['test_mode'] = 'Y';
        $job['fix_result'] = $testResult;
        $job['fix_label'] = $this->statusToLabel($testResult);
        $this->appendJobLog($job, 'fix:test_mode_result', ['result' => $testResult]);

        if ($testResult === 'connected') {
            $job['fix_status'] = 'connected';
            $job['result_message'] = 'TEST MODE: симуляция успешного соединения';
            return ['status' => 'connected', 'message' => $job['result_message'], 'job' => $job];
        }

        if (in_array($testResult, ['client_no_answer', 'client_busy', 'cancelled'], true)) {
            $job['fix_status'] = 'final_failed';
            $job['result_message'] = 'TEST MODE: симуляция финального результата ' . $testResult;
            return ['status' => 'final_failed', 'message' => $job['result_message'], 'job' => $job];
        }

        $job['fix_status'] = 'retry_wait';
        $job['result_message'] = 'TEST MODE: симуляция operator_no_answer';
        return ['status' => 'retry_wait', 'message' => $job['result_message'], 'job' => $job];
    }

    private function applyOperatorDestination(array $customData, array $job)
    {
        $routeMode = trim((string)$this->option('operator_route_mode', 'user'));
        if ($routeMode === 'sip') {
            $destination = $this->buildSipOperatorDestination($job);
            if ($destination !== '') {
                $customData['operator_destination_type'] = 'sip';
                $customData['operator_destination'] = $destination;
            }
            return $customData;
        }

        if ($routeMode === 'user') {
            $customData['operator_destination_type'] = 'user';
            $customData['operator_destination'] = (int)($job['operator_id'] ?? 0);
        }

        return $customData;
    }

    private function buildSipOperatorDestination(array $job)
    {
        $template = trim((string)$this->option('operator_sip_destination_template', ''));
        $extension = trim((string)($job['operator_extension'] ?? ''));
        $userId = (int)($job['operator_id'] ?? 0);

        if ($template !== '') {
            return strtr($template, [
                '{extension}' => $extension,
                '{user_id}' => (string)$userId,
                '{sip_line}' => (string)($job['sip_line'] ?? ''),
            ]);
        }

        if ($extension !== '') {
            if (stripos($extension, 'sip:') === 0) {
                return $extension;
            }
            return 'sip:' . $extension;
        }

        return '';
    }

    private function rotationPathForCity($cityId)
    {
        $basePath = trim((string)$this->option('jobs_path', __DIR__ . '/jobs'));
        if ($basePath === '') {
            $basePath = __DIR__ . '/jobs';
        }

        if (!is_dir($basePath)) {
            @mkdir($basePath, 0755, true);
        }

        return rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'city_rotation_' . (int)$cityId . '.json';
    }

    private function readCityRotationOffset($cityId, $operatorsCount)
    {
        $cityId = (int)$cityId;
        $operatorsCount = (int)$operatorsCount;
        if ($cityId <= 0 || $operatorsCount <= 0) {
            return 0;
        }

        $path = $this->rotationPathForCity($cityId);
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return 0;
        }

        try {
            if (!flock($fp, LOCK_SH)) {
                return 0;
            }

            rewind($fp);
            $content = stream_get_contents($fp);
            $data = is_string($content) && $content !== '' ? json_decode($content, true) : null;
            $offset = is_array($data) ? $this->extractInt($data['next_offset'] ?? 0) : 0;
            flock($fp, LOCK_UN);
            return $offset % $operatorsCount;
        } finally {
            fclose($fp);
        }
    }

    private function writeCityRotationOffset($cityId, $nextOffset)
    {
        $cityId = (int)$cityId;
        if ($cityId <= 0) {
            return false;
        }

        $path = $this->rotationPathForCity($cityId);
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                return false;
            }

            $payload = json_encode([
                'city_id' => $cityId,
                'next_offset' => (int)$nextOffset,
                'updated_at' => $this->nowIso(),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if (!is_string($payload)) {
                return false;
            }

            ftruncate($fp, 0);
            rewind($fp);
            $written = fwrite($fp, $payload . "\n") !== false;
            fflush($fp);
            flock($fp, LOCK_UN);

            return $written;
        } finally {
            fclose($fp);
        }
    }

    private function generateJobId($leadId)
    {
        return 'callback_' . (int)$leadId . '_' . gmdate('Ymd_His') . '_' . substr(md5((string)microtime(true) . '_' . mt_rand()), 0, 8);
    }

    private function option($key, $default = null)
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    private function extractInt($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $clean = preg_replace('/\D+/', '', (string)$value);
        if (!is_string($clean) || $clean === '') {
            return 0;
        }

        return (int)$clean;
    }

    private function nowIso()
    {
        return date('Y-m-d\TH:i:s');
    }

    private function nowHuman()
    {
        return date('d.m.Y H:i:s');
    }

    private function addSecondsToIso($iso, $seconds)
    {
        $timestamp = strtotime((string)$iso);
        if ($timestamp === false) {
            $timestamp = time();
        }

        return date('Y-m-d\TH:i:s', $timestamp + (int)$seconds);
    }
}
