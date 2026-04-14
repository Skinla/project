<?php
/**
 * Deal sync: cloud → box. Build payload for migrate_deals_from_json.php
 */
require_once __DIR__ . '/ContactBoxSync.php';
require_once dirname(__DIR__, 4) . '/lib/CrmActivityCloudEnrich.php';

class DealSync
{
    /** Провайдеры, привязанные к сделке в облаке; письма/звонки сюда попадают при пагинации. */
    private static function fetchActivitiesPage(BitrixRestClient $client, array $filter, int $start): array
    {
        return $client->call('crm.activity.list', [
            'filter' => $filter,
            'select' => ['*', 'COMMUNICATIONS', 'FILES'],
            'start' => $start,
        ]);
    }

    /**
     * Все активности сделки (следующая страница REST), плюс ссылки на файлы/записи в DESCRIPTION.
     *
     * @param array<string, array<string, int>> $contactMapping
     * @return list<array<string, mixed>>
     */
    private static function collectActivitiesForDeal(
        BitrixRestClient $client,
        int $cloudDealId,
        array $userMapping,
        array $contactMapping,
        ?int $boxDealId = null,
        array $bookingResourceMap = [],
        ?int $fallbackBoxContactId = null
    ): array {
        $out = [];
        $seen = [];
        $start = 0;
        do {
            $resp = self::fetchActivitiesPage($client, [
                'OWNER_TYPE_ID' => 2,
                'OWNER_ID' => $cloudDealId,
            ], $start);
            $rows = $resp['result'] ?? [];
            foreach ($rows as $act) {
                $id = (int)($act['ID'] ?? 0);
                if ($id > 0 && isset($seen[$id])) {
                    continue;
                }
                if ($id > 0) {
                    $seen[$id] = true;
                }
                $out[] = self::normalizeActivityRow($act, $userMapping, $contactMapping, $client, $cloudDealId, $boxDealId, $bookingResourceMap, $fallbackBoxContactId);
            }
            if (!isset($resp['next'])) {
                break;
            }
            $start = (int)$resp['next'];
        } while (true);

        return $out;
    }

    /**
     * @return list<array{url: string, name: string, cloud_disk_id: string}>
     */
    private static function buildMigrationAttachments(array $files, BitrixRestClient $client): array
    {
        $out = [];
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            $url = trim((string)($f['url'] ?? $f['downloadUrl'] ?? ''));
            $diskId = $f['id'] ?? $f['fileId'] ?? null;
            if ($url === '' && $diskId !== null && $diskId !== '') {
                $r = $client->call('disk.file.get', ['id' => $diskId]);
                if (empty($r['error']) && !empty($r['result']) && is_array($r['result'])) {
                    $res = $r['result'];
                    $url = trim((string)($res['DOWNLOAD_URL'] ?? $res['URL'] ?? ''));
                }
            }
            if ($url === '') {
                continue;
            }
            $name = trim((string)($f['name'] ?? $f['NAME'] ?? $f['fileName'] ?? ''));
            if ($name === '') {
                $path = parse_url($url, PHP_URL_PATH);
                $name = $path ? basename($path) : 'recording';
                if ($name === '' || $name === '/') {
                    $name = 'recording';
                }
                if (!preg_match('/\.\w{2,5}$/u', $name)) {
                    $name .= '.mp3';
                }
            }
            $out[] = [
                'url' => $url,
                'name' => $name,
                'cloud_disk_id' => $diskId !== null && $diskId !== '' ? (string)$diskId : '',
            ];
        }

        return $out;
    }

    /**
     * Активность CRM_TASKS_TASK ссылается на задачу (ASSOCIATED_ENTITY_ID). На коробке нужен свой ID в b_tasks + привязка к сделке.
     *
     * @param array<string, mixed> $act
     * @return array<string, mixed>
     */
    private static function enrichTaskActivityFromCloud(array $act, BitrixRestClient $client, array $userMapping): array
    {
        if (($act['PROVIDER_ID'] ?? '') !== 'CRM_TASKS_TASK') {
            return $act;
        }
        $users = $userMapping['users'] ?? [];
        $mapU = static function ($id) use ($users) {
            return $users[(string)$id] ?? 1;
        };
        $cloudTid = (int)($act['ASSOCIATED_ENTITY_ID'] ?? 0);
        if ($cloudTid <= 0 && isset($act['SETTINGS']['TASK_ID'])) {
            $cloudTid = (int)$act['SETTINGS']['TASK_ID'];
        }
        if ($cloudTid <= 0) {
            return $act;
        }
        $r = $client->call('tasks.task.get', ['taskId' => $cloudTid]);
        if (!empty($r['error']) || empty($r['result']['task']) || !is_array($r['result']['task'])) {
            return $act;
        }
        $t = $r['result']['task'];
        $act['migration_task'] = [
            'cloud_task_id' => $cloudTid,
            'title' => (string)($t['title'] ?? $act['SUBJECT'] ?? ''),
            'description' => (string)($t['description'] ?? $act['DESCRIPTION'] ?? ''),
            'priority' => (int)($t['priority'] ?? 2),
            'status' => (int)($t['status'] ?? 2),
            'deadline' => (string)($t['deadline'] ?? ''),
            'createdBy' => $mapU($t['createdBy'] ?? $act['AUTHOR_ID'] ?? 1),
            'responsibleId' => $mapU($t['responsibleId'] ?? $act['RESPONSIBLE_ID'] ?? 1),
        ];
        unset($act['ASSOCIATED_ENTITY_ID']);
        if (isset($act['SETTINGS']) && is_array($act['SETTINGS'])) {
            unset($act['SETTINGS']['TASK_ID']);
            if ($act['SETTINGS'] === []) {
                unset($act['SETTINGS']);
            }
        }

        return $act;
    }

    /**
     * В SETTINGS.FIELDS онлайн-записи хранятся ID облака (контакт, сделка, автор). На коробке UI/чат ориентируются на локальные ID — иначе пропадают кнопки (например «Сообщение»).
     *
     * @param array<string, mixed> $act
     * @return array<string, mixed>
     */
    private static function rewriteBookingActivitySettings(
        array $act,
        int $cloudDealId,
        ?int $boxDealId,
        array $contactMapping,
        array $userMapping
    ): array {
        if (($act['PROVIDER_ID'] ?? '') !== 'CRM_BOOKING') {
            return $act;
        }
        $fields = $act['SETTINGS']['FIELDS'] ?? null;
        if (!is_array($fields)) {
            return $act;
        }
        $contacts = $contactMapping['contacts'] ?? [];
        $users = $userMapping['users'] ?? [];
        if (!empty($fields['clients']) && is_array($fields['clients'])) {
            foreach ($fields['clients'] as $k => $cl) {
                if (!is_array($cl)) {
                    continue;
                }
                if (($cl['typeModule'] ?? '') === 'crm' && ($cl['typeCode'] ?? '') === 'CONTACT' && isset($cl['id'])) {
                    $cid = (string)$cl['id'];
                    if (isset($contacts[$cid])) {
                        $fields['clients'][$k]['id'] = (int)$contacts[$cid];
                    }
                }
            }
        }
        if ($boxDealId !== null && $boxDealId > 0 && !empty($fields['externalData']) && is_array($fields['externalData'])) {
            foreach ($fields['externalData'] as $k => $ex) {
                if (!is_array($ex)) {
                    continue;
                }
                if (($ex['moduleId'] ?? '') !== 'crm') {
                    continue;
                }
                $et = $ex['entityTypeId'] ?? '';
                $isDeal = ((string)$et === 'DEAL' || (string)$et === '2' || (int)$et === 2);
                if (!$isDeal) {
                    continue;
                }
                if ((string)($ex['value'] ?? '') === (string)$cloudDealId) {
                    $fields['externalData'][$k]['value'] = (string)$boxDealId;
                }
            }
        }
        if (isset($fields['createdBy'])) {
            $cb = (string)$fields['createdBy'];
            if (isset($users[$cb])) {
                $fields['createdBy'] = (int)$users[$cb];
            }
        }
        $act['SETTINGS']['FIELDS'] = $fields;

        return $act;
    }

    /**
     * Имена типов ресурсов (как в b_booking_resource_type на коробке / облаке typeId).
     */
    private static function bookingTypeNameFromCloudTypeId(int $typeId): string
    {
        static $names = [
            1 => 'Врач',
            2 => 'Оборудование',
            3 => 'Специалист',
            4 => 'Автомобиль',
            5 => 'Помещение',
        ];

        return $names[$typeId] ?? 'Врач';
    }

    /**
     * Сущность брони в модуле «Онлайн-запись» (resourceIds) не совпадает с текстовым blocks resources в активности.
     * Подготовка payload для AddBookingCommand на коробке.
     *
     * @param array<string, mixed> $act
     * @param array<string, mixed> $bookingResourceMap из data/booking_resource_mapping.json
     * @return array<string, mixed>
     */
    private static function enrichBookingActivityFromCloud(
        array $act,
        BitrixRestClient $client,
        array $bookingResourceMap,
        ?int $fallbackBoxContactId = null
    ): array {
        if (($act['PROVIDER_ID'] ?? '') !== 'CRM_BOOKING') {
            return $act;
        }
        $cloudBid = (int)($act['SETTINGS']['FIELDS']['id'] ?? 0);
        if ($cloudBid <= 0) {
            return $act;
        }
        $r = $client->call('booking.v1.booking.get', ['id' => $cloudBid]);
        if (!empty($r['error']) || empty($r['result']['booking']) || !is_array($r['result']['booking'])) {
            return $act;
        }
        $b = $r['result']['booking'];
        $map = $bookingResourceMap['resources'] ?? [];
        $cloudResIds = $b['resourceIds'] ?? [];
        if (!is_array($cloudResIds)) {
            $cloudResIds = [];
        }
        $resourcesPayload = [];
        foreach ($cloudResIds as $rid) {
            $key = (string)(int)$rid;
            $boxRid = isset($map[$key]) ? (int)$map[$key] : (int)$rid;
            if ($boxRid > 0) {
                $resourcesPayload[] = ['id' => $boxRid];
            }
        }
        if ($resourcesPayload === []) {
            return $act;
        }
        $datePeriod = $b['datePeriod'] ?? null;
        if (!is_array($datePeriod) || empty($datePeriod['from']['timestamp'])) {
            $fdp = $act['SETTINGS']['FIELDS']['datePeriod'] ?? null;
            if (is_array($fdp) && isset($fdp['from'], $fdp['to'])) {
                $tzFrom = (string)($fdp['fromTimezone'] ?? 'Europe/Moscow');
                $tzTo = (string)($fdp['toTimezone'] ?? $tzFrom);
                $datePeriod = [
                    'from' => ['timestamp' => (int)$fdp['from'], 'timezone' => $tzFrom],
                    'to' => ['timestamp' => (int)$fdp['to'], 'timezone' => $tzTo],
                ];
            } else {
                return $act;
            }
        }
        $fields = $act['SETTINGS']['FIELDS'] ?? [];
        if (is_array($datePeriod) && isset($datePeriod['from']['timestamp'], $datePeriod['to']['timestamp'])) {
            $tzFrom = (string)($datePeriod['from']['timezone'] ?? 'Europe/Moscow');
            $tzTo = (string)($datePeriod['to']['timezone'] ?? $tzFrom);
            $fields['datePeriod'] = [
                'from' => (int)$datePeriod['from']['timestamp'],
                'to' => (int)$datePeriod['to']['timestamp'],
                'fromTimezone' => $tzFrom,
                'toTimezone' => $tzTo,
            ];
        }
        $orderedCloudResIds = array_values(array_filter(array_map('intval', $cloudResIds), static fn(int $x): bool => $x > 0));
        $newFieldResources = [];
        foreach ($orderedCloudResIds as $i => $cRid) {
            $key = (string)$cRid;
            $boxRid = isset($map[$key]) ? (int)$map[$key] : $cRid;
            if ($boxRid <= 0) {
                continue;
            }
            $meta = [];
            if (isset($fields['resources'][$i]) && is_array($fields['resources'][$i])) {
                $meta = $fields['resources'][$i];
            }
            $typeName = isset($meta['typeName']) ? (string)$meta['typeName'] : '';
            $name = isset($meta['name']) ? (string)$meta['name'] : '';
            if ($name === '' || $typeName === '') {
                $gr = $client->call('booking.v1.resource.get', ['id' => $cRid]);
                $resObj = isset($gr['result']['resource']) && is_array($gr['result']['resource']) ? $gr['result']['resource'] : null;
                if ($resObj !== null) {
                    if ($name === '' && isset($resObj['name']) && (string)$resObj['name'] !== '') {
                        $name = (string)$resObj['name'];
                    }
                    if ($typeName === '') {
                        $typeName = self::bookingTypeNameFromCloudTypeId((int)($resObj['typeId'] ?? 1));
                    }
                }
            }
            if ($typeName === '') {
                $typeName = self::bookingTypeNameFromCloudTypeId(1);
            }
            if ($name === '') {
                $name = 'Resource #' . $boxRid;
            }
            $newFieldResources[] = [
                'id' => $boxRid,
                'typeName' => $typeName,
                'name' => $name,
            ];
        }
        if ($newFieldResources !== []) {
            $fields['resources'] = $newFieldResources;
        }
        $act['SETTINGS']['FIELDS'] = $fields;
        $clientsPayload = [];
        foreach ($fields['clients'] ?? [] as $cl) {
            if (!is_array($cl)) {
                continue;
            }
            $cid = isset($cl['id']) ? (int)$cl['id'] : 0;
            $data = [];
            if ($cid > 0) {
                $data['id'] = $cid;
            }
            if (!empty($cl['phones']) && is_array($cl['phones'])) {
                $data['phones'] = $cl['phones'];
            }
            if ($data === []) {
                continue;
            }
            $clientsPayload[] = [
                'id' => $cid > 0 ? $cid : null,
                'type' => [
                    'module' => (string)($cl['typeModule'] ?? 'crm'),
                    'code' => (string)($cl['typeCode'] ?? 'CONTACT'),
                ],
                'data' => $data,
            ];
        }
        if ($clientsPayload === [] && $fallbackBoxContactId !== null && $fallbackBoxContactId > 0) {
            $clientsPayload[] = [
                'id' => $fallbackBoxContactId,
                'type' => [
                    'module' => 'crm',
                    'code' => 'CONTACT',
                ],
                'data' => [
                    'id' => $fallbackBoxContactId,
                ],
            ];
        }
        $extPayload = [];
        foreach ($fields['externalData'] ?? [] as $ex) {
            if (!is_array($ex)) {
                continue;
            }
            $extPayload[] = [
                'moduleId' => (string)($ex['moduleId'] ?? 'crm'),
                'entityTypeId' => (string)($ex['entityTypeId'] ?? 'DEAL'),
                'value' => (string)($ex['value'] ?? ''),
            ];
        }
        // AUTHOR_ID уже замаплен на пользователя коробки в начале normalizeActivityRow
        $createdBy = (int)($act['AUTHOR_ID'] ?? 1);
        if ($createdBy <= 0) {
            $createdBy = 1;
        }
        $payload = [
            'datePeriod' => $datePeriod,
            'resources' => $resourcesPayload,
            'createdBy' => $createdBy,
        ];
        if ($clientsPayload !== []) {
            $payload['clients'] = $clientsPayload;
        }
        if ($extPayload !== []) {
            $payload['externalData'] = $extPayload;
        }
        if (isset($b['name']) && $b['name'] !== null && (string)$b['name'] !== '') {
            $payload['name'] = (string)$b['name'];
        }
        if (isset($b['description']) && $b['description'] !== null && (string)$b['description'] !== '') {
            $payload['description'] = (string)$b['description'];
        }
        $act['migration_booking'] = [
            'cloud_booking_id' => $cloudBid,
            'payload' => $payload,
        ];

        return $act;
    }

    private static function normalizeActivityRow(
        array $act,
        array $userMapping,
        array $contactMapping,
        BitrixRestClient $client,
        int $cloudDealId,
        ?int $boxDealId = null,
        array $bookingResourceMap = [],
        ?int $fallbackBoxContactId = null
    ): array {
        $act = CrmActivityCloudEnrich::mergeFullFieldsFromGet($client, $act);
        $cloudActId = $act['ID'] ?? null;
        $act['RESPONSIBLE_ID'] = $userMapping['users'][(string)($act['RESPONSIBLE_ID'] ?? '')] ?? 1;
        $act['AUTHOR_ID'] = $userMapping['users'][(string)($act['AUTHOR_ID'] ?? '')] ?? 1;
        $act['EDITOR_ID'] = $userMapping['users'][(string)($act['EDITOR_ID'] ?? '')] ?? 1;
        $contacts = $contactMapping['contacts'] ?? [];
        if (!empty($act['COMMUNICATIONS']) && is_array($act['COMMUNICATIONS'])) {
            foreach ($act['COMMUNICATIONS'] as $k => $c) {
                if (!is_array($c)) {
                    continue;
                }
                if ((int)($c['ENTITY_TYPE_ID'] ?? 0) === 3 && isset($c['ENTITY_ID'])) {
                    $cc = (string)$c['ENTITY_ID'];
                    if (!empty($contacts[$cc])) {
                        $act['COMMUNICATIONS'][$k]['ENTITY_ID'] = (string)$contacts[$cc];
                    }
                }
            }
        }
        $act = self::enrichTaskActivityFromCloud($act, $client, $userMapping);
        $act = self::rewriteBookingActivitySettings($act, $cloudDealId, $boxDealId, $contactMapping, $userMapping);
        $act = self::enrichBookingActivityFromCloud($act, $client, $bookingResourceMap, $fallbackBoxContactId);
        $fileRows = $act['FILES'] ?? null;
        if (is_array($fileRows) && $fileRows !== []) {
            $act['migration_attachments'] = self::buildMigrationAttachments($fileRows, $client);
        } else {
            $act['migration_attachments'] = [];
        }
        $act = self::appendFilesToDescription($act);
        unset($act['ID'], $act['OWNER_ID']);
        $act['cloud_activity_id'] = $cloudActId;
        $act['migration_provider_id'] = $act['PROVIDER_ID'] ?? '';
        $act['migration_type_id'] = $act['TYPE_ID'] ?? '';

        return $act;
    }

    /**
     * @param array<string, mixed> $act
     * @return array<string, mixed>
     */
    private static function appendFilesToDescription(array $act): array
    {
        $files = $act['FILES'] ?? null;
        if (empty($files) || !is_array($files)) {
            unset($act['FILES']);

            return $act;
        }
        $urls = [];
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            $u = $f['url'] ?? '';
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        unset($act['FILES']);
        if ($urls !== []) {
            $block = "\n\n---\nФайлы / запись звонка (облако Bitrix24):\n" . implode("\n", $urls);
            $act['DESCRIPTION'] = (string)($act['DESCRIPTION'] ?? '') . $block;
        }

        return $act;
    }

    /**
     * Путь к booking_resource_mapping.json: MILLENNIUM_BOOKING_RESOURCE_MAP, затем dealsync/data, затем project data.
     */
    private static function resolveBookingResourceMapPath(): ?string
    {
        $env = getenv('MILLENNIUM_BOOKING_RESOURCE_MAP');
        if (is_string($env) && $env !== '' && is_readable($env)) {
            return $env;
        }
        $libDir = __DIR__;
        $parentName = basename(dirname($libDir));
        $candidates = [];
        if ($parentName === 'dealsync') {
            $dealsyncRoot = dirname($libDir);
            $candidates[] = $dealsyncRoot . '/data/booking_resource_mapping.json';
            $projectRoot = dirname($dealsyncRoot, 3);
            $candidates[] = $projectRoot . '/data/booking_resource_mapping.json';
        } else {
            $projectRoot = dirname($libDir);
            $candidates[] = $projectRoot . '/data/booking_resource_mapping.json';
            $candidates[] = $projectRoot . '/local/handlers/dealsync/data/booking_resource_mapping.json';
        }
        foreach ($candidates as $p) {
            if (is_readable($p)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadBookingResourceMap(): array
    {
        $path = self::resolveBookingResourceMapPath();
        if ($path === null) {
            return [];
        }
        $decoded = json_decode((string)file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Map cloud deal stage to box; if mapping is null, use first stage of target funnel.
     *
     * @param array<string, mixed|null> $dealStages from stage_mapping.json deal_stages
     */
    public static function mapStageId(string $cloudStageId, int $boxCategoryId, array $dealStages): string
    {
        $mapped = $dealStages[$cloudStageId] ?? null;
        if ($mapped !== null && $mapped !== '') {
            return (string)$mapped;
        }
        return $boxCategoryId === 0 ? 'NEW' : ('C' . $boxCategoryId . ':NEW');
    }

    /**
     * @param array<string, int|string> $categoryMapping cloud category id string => box category id
     * @param array<string, mixed|null> $dealStages
     * @param array<string, string|null> $dealFields field_mapping deal_fields
     * @param array<string, array<string, int>> $userMapping
     * @param array<string, array<string, int>> $contactMapping
     * @param array<string, array<string, int>> $sourceMapping optional sources
     * @param array<string, array<string, int>> $companyMapping optional companies
     * @param array<string, array<string, int>> $leadMapping cloud_lead_id => box_lead_id (from lead_sync_log)
     * @param int|null $boxDealId если сделка уже на коробке — подставляется в SETTINGS онлайн-записи (контакт/сделка)
     * @return array{deal: array, activities: array, comments: array, products: array}|null
     */
    public static function buildDealPayload(
        BitrixRestClient $client,
        int $dealId,
        array $categoryMapping,
        array $dealStages,
        array $dealFields,
        array $userMapping,
        array $contactMapping = [],
        array $sourceMapping = [],
        array $companyMapping = [],
        array $leadMapping = [],
        ?int $boxDealId = null
    ): ?array {
        $dealResp = $client->call('crm.deal.get', ['id' => $dealId]);
        if (isset($dealResp['error']) || empty($dealResp['result'])) {
            return null;
        }
        $deal = $dealResp['result'];

        $cloudCat = isset($deal['CATEGORY_ID']) ? (string)$deal['CATEGORY_ID'] : '0';
        $boxCategory = isset($categoryMapping[$cloudCat]) ? (int)$categoryMapping[$cloudCat] : (int)$cloudCat;

        $mapped = [];
        foreach ($deal as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $targetKey = $dealFields[$key] ?? null;
            if ($targetKey === null || $targetKey === '' || $key === 'ID') {
                continue;
            }

            if ($key === 'CATEGORY_ID') {
                $mapped[$targetKey] = $boxCategory;
                continue;
            }
            if ($key === 'STAGE_ID') {
                $mapped[$targetKey] = self::mapStageId((string)$value, $boxCategory, $dealStages);
                continue;
            }
            if ($key === 'SOURCE_ID' && !empty($sourceMapping['sources']) && is_array($sourceMapping['sources'])) {
                $mapped[$targetKey] = $sourceMapping['sources'][$value] ?? $value;
                continue;
            }
            if (in_array($key, ['ASSIGNED_BY_ID', 'CREATED_BY_ID', 'MODIFY_BY_ID', 'MOVED_BY_ID'], true) && is_numeric($value)) {
                $mapped[$targetKey] = $userMapping['users'][(string)$value] ?? 1;
                continue;
            }
            if ($key === 'CONTACT_ID' && is_numeric($value)) {
                $contacts = $contactMapping['contacts'] ?? [];
                $boxContact = $contacts[(string)$value] ?? null;
                if ($boxContact === null) {
                    continue;
                }
                $mapped[$targetKey] = (int)$boxContact;
                continue;
            }
            if ($key === 'COMPANY_ID' && is_numeric($value) && !empty($companyMapping['companies'])) {
                $boxCo = $companyMapping['companies'][(string)$value] ?? null;
                if ($boxCo === null) {
                    continue;
                }
                $mapped[$targetKey] = (int)$boxCo;
                continue;
            }
            if ($key === 'LEAD_ID' && is_numeric($value)) {
                $leads = $leadMapping['leads'] ?? [];
                $boxLead = $leads[(string)$value] ?? null;
                if ($boxLead === null) {
                    continue;
                }
                $mapped[$targetKey] = (int)$boxLead;
                continue;
            }

            $mapped[$targetKey] = $value;
        }

        $comments = [];
        $comResp = $client->call('crm.timeline.comment.list', [
            'filter' => ['ENTITY_ID' => $dealId, 'ENTITY_TYPE' => 'deal'],
            'select' => ['ID', 'CREATED', 'AUTHOR_ID', 'COMMENT', 'FILES'],
        ]);
        foreach ($comResp['result'] ?? [] as $com) {
            $com['AUTHOR_ID'] = $userMapping['users'][(string)($com['AUTHOR_ID'] ?? '')] ?? 1;
            unset($com['ID']);
            $comments[] = $com;
        }

        $products = [];
        $prodResp = $client->call('crm.deal.productrows.get', ['id' => $dealId]);
        foreach ($prodResp['result'] ?? [] as $p) {
            unset($p['ID'], $p['OWNER_ID']);
            $products[] = $p;
        }

        $contactTargetKey = $dealFields['CONTACT_ID'] ?? null;
        if ($contactTargetKey && empty($mapped[$contactTargetKey])) {
            $contacts = $contactMapping['contacts'] ?? [];
            foreach (ContactBoxSync::collectContactIdsFromDeal($deal) as $cloudCid) {
                if (!empty($contacts[(string)$cloudCid])) {
                    $mapped[$contactTargetKey] = (int)$contacts[(string)$cloudCid];
                    break;
                }
            }
        }

        $fallbackBoxContactId = null;
        if ($contactTargetKey && !empty($mapped[$contactTargetKey])) {
            $c = (int)$mapped[$contactTargetKey];
            if ($c > 0) {
                $fallbackBoxContactId = $c;
            }
        }
        if ($fallbackBoxContactId === null || $fallbackBoxContactId <= 0) {
            $contacts = $contactMapping['contacts'] ?? [];
            foreach (ContactBoxSync::collectContactIdsFromDeal($deal) as $cloudCid) {
                if (!empty($contacts[(string)$cloudCid])) {
                    $fallbackBoxContactId = (int)$contacts[(string)$cloudCid];
                    break;
                }
            }
        }
        if ($fallbackBoxContactId !== null && $fallbackBoxContactId <= 0) {
            $fallbackBoxContactId = null;
        }

        $bookingResourceMap = self::loadBookingResourceMap();
        $activities = self::collectActivitiesForDeal(
            $client,
            $dealId,
            $userMapping,
            $contactMapping,
            $boxDealId,
            $bookingResourceMap,
            $fallbackBoxContactId
        );

        return [
            'deal' => $mapped,
            'activities' => $activities,
            'comments' => $comments,
            'products' => $products,
        ];
    }
}
