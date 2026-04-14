<?php
/**
 * Create/update deals with timeline and products from JSON on stdin.
 * Run on box via SSH. Input: JSON { items: [...], check_date_modify: bool, strict_booking?: bool }
 * Each item: { cloud_deal_id?, box_deal_id?, update_only?, deal, activities, comments, products }
 * Log: local/handlers/dealsync/data/deal_sync_log.json — cloud_deal_id => box_deal_id
 * Активности: deal_activity_sync_log.json (cloudDealId:cloudActId → box_id) + dedupe по ORIGIN_ID на сделке.
 * Повторная выгрузка: не-CRM_BOOKING не обновляются; CRM_BOOKING — обновление и привязка записи.
 */
ob_start();
$json = stream_get_contents(STDIN);
$input = json_decode($json, true);
$checkDateModify = true;
$strictBooking = false;
if (isset($input['items']) && is_array($input['items'])) {
    $items = $input['items'];
    $checkDateModify = filter_var($input['check_date_modify'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $strictBooking = filter_var($input['strict_booking'] ?? false, FILTER_VALIDATE_BOOLEAN);
} elseif (is_array($input)) {
    $items = $input;
} else {
    echo json_encode(['error' => 'Invalid JSON or not array']);
    exit(1);
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if (empty($docRoot) || !is_dir($docRoot . '/bitrix')) {
    $docRoot = '/home/bitrix/www';
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
if (empty($_SERVER['SERVER_NAME'])) {
    $_SERVER['SERVER_NAME'] = 'localhost';
}
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

/**
 * @param mixed $raw
 * @return list<int>
 */
function migration_normalize_storage_element_ids($raw): array
{
    if ($raw === null || $raw === '' || $raw === false) {
        return [];
    }
    if (is_array($raw)) {
        $out = [];
        foreach ($raw as $v) {
            $n = (int)$v;
            if ($n > 0) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }
    if (is_string($raw)) {
        $un = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($un)) {
            return migration_normalize_storage_element_ids($un);
        }
        $parts = preg_split('~[\s,;]+~', trim($raw));
        $ids = [];
        foreach ($parts as $p) {
            $n = (int)$p;
            if ($n > 0) {
                $ids[] = $n;
            }
        }

        return array_values(array_unique($ids));
    }
    $n = (int)$raw;

    return $n > 0 ? [$n] : [];
}

function migration_get_crm_file_storage_type_id(): int
{
    if (class_exists('\Bitrix\Crm\Integration\StorageType')) {
        return (int)\Bitrix\Crm\Integration\StorageType::File;
    }

    return 1;
}

/**
 * Облачный REST отдаёт даты активности в ISO 8601; CCrmActivity на коробке ожидает формат сайта.
 *
 * @param mixed $v
 */
function migration_normalize_activity_datetime($v): string
{
    if ($v === null) {
        return '';
    }
    if (!is_string($v)) {
        return (string)$v;
    }
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (preg_match('~^\d{1,2}\.\d{1,2}\.\d{4}~u', $v)) {
        return $v;
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return $v;
    }

    return (string)ConvertTimeStamp($ts, 'FULL');
}

/**
 * Подставляет CREATED/LAST_UPDATED активности из облака в b_crm_act (формат сайта, как для START_TIME).
 */
function migration_apply_b_crm_act_audit_dates(int $actId, $actCreated, $actLastUpdated): void
{
    global $DB;
    $upd = [];
    if ($actCreated !== null && trim((string)$actCreated) !== '') {
        $c = migration_normalize_activity_datetime($actCreated);
        if ($c !== '') {
            $upd[] = "CREATED = '" . $DB->ForSql($c) . "'";
        }
    }
    if ($actLastUpdated !== null && trim((string)$actLastUpdated) !== '') {
        $u = migration_normalize_activity_datetime($actLastUpdated);
        if ($u !== '') {
            $upd[] = "LAST_UPDATED = '" . $DB->ForSql($u) . "'";
        }
    }
    if ($upd !== []) {
        $DB->Query('UPDATE b_crm_act SET ' . implode(', ', $upd) . ' WHERE ID = ' . (int)$actId);
    }
}

/**
 * На сделке оставляет одну активность миграции с данным cloud ORIGIN_ID (минимальный ID), остальные удаляет.
 * Защита от дублей при параллельных вебхуках и «потерянном» deal_activity_sync_log.
 */
function migration_dedupe_migration_activities_for_deal_origin(int $dealId, string $cloudOriginId): ?int
{
    if ($cloudOriginId === '') {
        return null;
    }
    global $DB;
    // Главный случай миграции: владелец строки b_crm_act = сделка (GetBindings в CLI часто пустой — дубли не находились).
    $q = 'SELECT ID FROM b_crm_act WHERE ORIGINATOR_ID = \'migration\' AND ORIGIN_ID = \'' . $DB->ForSql($cloudOriginId) . '\''
        . ' AND OWNER_TYPE_ID = ' . (int)CCrmOwnerType::Deal . ' AND OWNER_ID = ' . (int)$dealId
        . ' ORDER BY ID ASC';
    $res = $DB->Query($q);
    $ids = [];
    while ($row = $res->Fetch()) {
        $ids[] = (int)($row['ID'] ?? 0);
    }
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    if (count($ids) < 2) {
        $dbRes = CCrmActivity::GetList(
            [],
            ['ORIGINATOR_ID' => 'migration', 'ORIGIN_ID' => $cloudOriginId],
            false,
            false,
            ['ID']
        );
        while ($row = $dbRes->Fetch()) {
            $cid = (int)($row['ID'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $bindings = CCrmActivity::GetBindings($cid);
            if (!is_array($bindings)) {
                continue;
            }
            foreach ($bindings as $b) {
                if ((int)($b['OWNER_TYPE_ID'] ?? 0) === CCrmOwnerType::Deal && (int)($b['OWNER_ID'] ?? 0) === $dealId) {
                    $ids[] = $cid;
                    break;
                }
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
    }
    if ($ids === []) {
        return null;
    }
    if (count($ids) < 2) {
        return $ids[0];
    }
    $keep = $ids[0];
    for ($i = 1, $n = count($ids); $i < $n; $i++) {
        CCrmActivity::Delete($ids[$i], false);
    }

    return $keep;
}

/**
 * Ищет box_id в deal_activity_sync_log: канонический ключ cloudDealId:cloudActId, затем cloudActId, затем любой *:cloudActId.
 */
function migration_lookup_act_log_box_id(array $actLog, ?int $cloudDealId, string $cloudActId): ?int
{
    if ($cloudActId === '') {
        return null;
    }
    $tryKeys = [];
    if ($cloudDealId !== null && $cloudDealId > 0) {
        $tryKeys[] = (string)$cloudDealId . ':' . $cloudActId;
    }
    $tryKeys[] = $cloudActId;
    foreach ($tryKeys as $k) {
        if (!isset($actLog[$k])) {
            continue;
        }
        $e = $actLog[$k];
        $bid = is_array($e) ? ($e['box_id'] ?? null) : $e;
        if ($bid !== null && (int)$bid > 0) {
            return (int)$bid;
        }
    }
    $suffix = ':' . $cloudActId;
    $suffixLen = strlen($suffix);
    foreach ($actLog as $k => $e) {
        if (!is_string($k)) {
            continue;
        }
        if ($k === $cloudActId || ($suffixLen > 0 && strlen($k) >= $suffixLen && substr($k, -$suffixLen) === $suffix)) {
            $bid = is_array($e) ? ($e['box_id'] ?? null) : $e;
            if ($bid !== null && (int)$bid > 0) {
                return (int)$bid;
            }
        }
    }

    return null;
}

function migration_crm_act_primary_owner_is_deal(int $actId, int $dealId): bool
{
    global $DB;
    $row = $DB->Query('SELECT OWNER_TYPE_ID, OWNER_ID FROM b_crm_act WHERE ID = ' . (int)$actId)->Fetch();
    if (!$row) {
        return false;
    }

    return (int)($row['OWNER_TYPE_ID'] ?? 0) === (int)CCrmOwnerType::Deal
        && (int)($row['OWNER_ID'] ?? 0) === $dealId;
}

/**
 * Создаёт задачу на коробке, привязанную к сделке; cloud_task_id → box_task_id в логе (идемпотентность).
 *
 * @param callable(): array $readTaskLog
 * @param callable(array): void $writeTaskLog
 */
function migration_ensure_box_task(array $migrationTask, int $dealId, callable $readTaskLog, callable $writeTaskLog): int
{
    $cloudTid = (int)($migrationTask['cloud_task_id'] ?? 0);
    if ($cloudTid <= 0) {
        return 0;
    }
    $log = $readTaskLog();
    if (!empty($log[(string)$cloudTid])) {
        return (int)$log[(string)$cloudTid];
    }
    if (!\Bitrix\Main\Loader::includeModule('tasks')) {
        return 0;
    }
    global $USER;
    $createdBy = (int)($migrationTask['createdBy'] ?? 1);
    if ($createdBy <= 0) {
        $createdBy = 1;
    }
    if (is_object($USER) && !$USER->IsAuthorized() && method_exists($USER, 'Authorize')) {
        $USER->Authorize($createdBy);
    }
    $deadline = trim((string)($migrationTask['deadline'] ?? ''));
    if ($deadline !== '') {
        $deadline = migration_normalize_activity_datetime($deadline);
    }
    $title = trim((string)($migrationTask['title'] ?? ''));
    if ($title === '') {
        $title = 'Задача (миграция CRM)';
    }
    $ar = [
        'TITLE' => $title,
        'DESCRIPTION' => (string)($migrationTask['description'] ?? ''),
        'RESPONSIBLE_ID' => (int)($migrationTask['responsibleId'] ?? 1),
        'CREATED_BY' => $createdBy,
        'PRIORITY' => (int)($migrationTask['priority'] ?? 2),
        'ACCOMPLICES' => [],
        'AUDITORS' => [],
        'UF_CRM_TASK' => 'D_' . $dealId,
    ];
    if ($deadline !== '') {
        $ar['DEADLINE'] = $deadline;
    }
    $status = (int)($migrationTask['status'] ?? 0);
    if ($status > 0) {
        $ar['STATUS'] = $status;
    }
    $task = new CTasks();
    $newId = (int)$task->Add($ar);
    if ($newId <= 0) {
        $ar['UF_CRM_TASK'] = ['D_' . $dealId];
        $newId = (int)$task->Add($ar);
    }
    if ($newId <= 0) {
        unset($ar['UF_CRM_TASK']);
        $ar['DESCRIPTION'] = (string)$ar['DESCRIPTION'] . "\n\n[Миграция] Сделка ID " . $dealId;
        $newId = (int)$task->Add($ar);
    }
    if ($newId > 0) {
        $log[(string)$cloudTid] = $newId;
        $writeTaskLog($log);
    }

    return $newId;
}

/**
 * Создаёт запись в модуле «Онлайн-запись» на коробке (ресурсы из cloud resourceIds + маппинг).
 *
 * @param callable(): array $readBookingLog
 * @param callable(array): void $writeBookingLog
 */
function migration_ensure_box_booking(
    array $migrationBooking,
    int $dealId,
    int $runAsUserId,
    callable $readBookingLog,
    callable $writeBookingLog,
    ?callable $logBookingFailure = null
): int {
    $cloudBid = (int)($migrationBooking['cloud_booking_id'] ?? 0);
    if ($cloudBid <= 0) {
        return 0;
    }
    $log = $readBookingLog();
    if (!empty($log[(string)$cloudBid])) {
        return (int)$log[(string)$cloudBid];
    }
    if (!\Bitrix\Main\Loader::includeModule('booking')) {
        if ($logBookingFailure) {
            $logBookingFailure($cloudBid, 'module booking not loaded');
        }

        return 0;
    }
    if (!class_exists(\Bitrix\Booking\Command\Booking\AddBookingCommand::class)
        || !class_exists(\Bitrix\Booking\Entity\Booking\Booking::class)) {
        if ($logBookingFailure) {
            $logBookingFailure($cloudBid, 'AddBookingCommand/Booking class missing');
        }

        return 0;
    }
    $payload = $migrationBooking['payload'] ?? [];
    if (empty($payload['datePeriod']) || empty($payload['resources'])) {
        if ($logBookingFailure) {
            $logBookingFailure($cloudBid, 'empty payload datePeriod or resources');
        }

        return 0;
    }
    // Bitrix\Client::mapFromArray читает id с верхнего уровня; старые payload имели только data.id.
    if (!empty($payload['clients']) && is_array($payload['clients'])) {
        foreach ($payload['clients'] as $k => $cl) {
            if (!is_array($cl)) {
                continue;
            }
            $topId = isset($cl['id']) ? (int)$cl['id'] : 0;
            $dataId = isset($cl['data']['id']) ? (int)$cl['data']['id'] : 0;
            if ($topId <= 0 && $dataId > 0) {
                $payload['clients'][$k]['id'] = $dataId;
            }
        }
    }
    global $USER;
    $uid = $runAsUserId > 0 ? $runAsUserId : 1;
    if (is_object($USER) && !$USER->IsAuthorized() && method_exists($USER, 'Authorize')) {
        $USER->Authorize($uid);
    }
    try {
        $entity = \Bitrix\Booking\Entity\Booking\Booking::mapFromArray($payload);
    } catch (\Throwable $e) {
        if ($logBookingFailure) {
            $logBookingFailure($cloudBid, 'mapFromArray: ' . $e->getMessage());
        }

        return 0;
    }
    $createdBy = (int)(is_object($USER) && method_exists($USER, 'GetID') ? $USER->GetID() : $uid);
    if ($createdBy <= 0) {
        $createdBy = $uid;
    }
    $cmd = new \Bitrix\Booking\Command\Booking\AddBookingCommand(
        createdBy: $createdBy,
        booking: $entity,
        allowOverbooking: true,
    );
    $result = $cmd->run();
    if (!$result->isSuccess()) {
        $msgs = [];
        foreach ($result->getErrors() as $er) {
            $msgs[] = $er->getMessage();
        }
        if ($logBookingFailure) {
            $logBookingFailure($cloudBid, implode('; ', $msgs) ?: 'AddBookingCommand failed');
        }

        return 0;
    }
    $booking = $result->getBooking();
    $newId = $booking ? (int)$booking->getId() : 0;
    if ($newId > 0) {
        $log[(string)$cloudBid] = $newId;
        $writeBookingLog($log);
    } elseif ($logBookingFailure) {
        $logBookingFailure($cloudBid, 'getBooking() returned empty id');
    }

    return $newId;
}

/**
 * Добавляет запись в deal_booking_sync_failures.json (по cloud booking id).
 */
function migration_append_deal_booking_failure(string $bookingFailurePath, int $cloudBid, string $msg): void
{
    $data = file_exists($bookingFailurePath) ? (json_decode((string)file_get_contents($bookingFailurePath), true) ?: []) : [];
    $key = (string)$cloudBid;
    if (!isset($data[$key]) || !is_array($data[$key])) {
        $data[$key] = [];
    }
    $data[$key][] = ['at' => gmdate('c'), 'error' => $msg];
    $dir = dirname($bookingFailurePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($bookingFailurePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Скачивает вложения по URL (как в REST облака) и сохраняет в b_file (модуль crm).
 *
 * @param list<array{url?: string, name?: string}> $attachments
 * @return array{0: list<int>, 1: list<string>}
 */
function migration_download_attachments_to_crm_files(array $attachments): array
{
    $ids = [];
    $errs = [];
    foreach ($attachments as $i => $att) {
        if (!is_array($att)) {
            continue;
        }
        $url = trim((string)($att['url'] ?? ''));
        $name = trim((string)($att['name'] ?? ''));
        if ($name === '') {
            $name = 'recording_' . $i;
        }
        $name = preg_replace('~[\\\\/]+~u', '_', $name);
        if ($url === '') {
            $errs[] = 'attachment#' . $i . ': empty url';
            continue;
        }
        $tmp = @tempnam(sys_get_temp_dir(), 'dmc');
        if ($tmp === false) {
            $errs[] = 'attachment#' . $i . ': tempnam failed';
            continue;
        }
        $ok = false;
        if (class_exists('\Bitrix\Main\Web\HttpClient')) {
            $http = new \Bitrix\Main\Web\HttpClient([
                'socketTimeout' => 600,
                'streamTimeout' => 600,
                'redirect' => true,
                'redirectMax' => 15,
            ]);
            $http->setHeader('User-Agent', 'MillenniumDealSync/1.0');
            if (method_exists($http, 'downloadToFile')) {
                $ok = (bool)$http->downloadToFile($url, $tmp);
            }
            if (!$ok && method_exists($http, 'download')) {
                $ok = (bool)$http->download($url, $tmp);
            }
            if (!$ok) {
                $body = $http->get($url);
                $st = (int)$http->getStatus();
                if ($body !== false && $st > 0 && $st < 400) {
                    $ok = file_put_contents($tmp, $body) !== false && filesize($tmp) > 0;
                }
            }
        }
        if (!$ok) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 600, 'follow_location' => 1, 'max_redirects' => 15],
                'https' => ['timeout' => 600],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false && $body !== '') {
                $ok = file_put_contents($tmp, $body) !== false;
            }
        }
        if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);
            $errs[] = 'attachment#' . $i . ': download failed (url ' . mb_substr($url, 0, 120) . ')';
            continue;
        }
        $arFile = \CFile::MakeFileArray($tmp);
        if (!is_array($arFile) || empty($arFile['tmp_name'])) {
            @unlink($tmp);
            $errs[] = 'attachment#' . $i . ': MakeFileArray failed';
            continue;
        }
        $arFile['name'] = $name;
        if (empty($arFile['type'])) {
            $arFile['type'] = 'application/octet-stream';
        }
        $arFile['MODULE_ID'] = 'crm';
        $fid = (int)\CFile::SaveFile($arFile, 'crm');
        @unlink($tmp);
        if ($fid <= 0) {
            $errs[] = 'attachment#' . $i . ': CFile::SaveFile failed';
            continue;
        }
        $ids[] = $fid;
    }

    return [$ids, $errs];
}

$logPath = $docRoot . '/local/handlers/dealsync/data/deal_sync_log.json';
$actLogPath = $docRoot . '/local/handlers/dealsync/data/deal_activity_sync_log.json';
$taskLogPath = $docRoot . '/local/handlers/dealsync/data/deal_task_sync_log.json';
$bookingLogPath = $docRoot . '/local/handlers/dealsync/data/deal_booking_sync_log.json';
$readLog = function () use ($logPath) {
    return file_exists($logPath) ? (json_decode(file_get_contents($logPath), true) ?: []) : [];
};
$writeLog = function (array $data) use ($logPath) {
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($logPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};
$readActLog = function () use ($actLogPath) {
    return file_exists($actLogPath) ? (json_decode(file_get_contents($actLogPath), true) ?: []) : [];
};
$writeActLog = function (array $data) use ($actLogPath) {
    $dir = dirname($actLogPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($actLogPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};
$readTaskLog = function () use ($taskLogPath) {
    return file_exists($taskLogPath) ? (json_decode(file_get_contents($taskLogPath), true) ?: []) : [];
};
$writeTaskLog = function (array $data) use ($taskLogPath) {
    $dir = dirname($taskLogPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($taskLogPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};
$readBookingLog = function () use ($bookingLogPath) {
    return file_exists($bookingLogPath) ? (json_decode(file_get_contents($bookingLogPath), true) ?: []) : [];
};
$writeBookingLog = function (array $data) use ($bookingLogPath) {
    $dir = dirname($bookingLogPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    file_put_contents($bookingLogPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};
$bookingFailurePath = dirname($bookingLogPath) . '/deal_booking_sync_failures.json';
$logBookingFailure = static function (int $cloudBid, string $msg) use ($bookingFailurePath) {
    migration_append_deal_booking_failure($bookingFailurePath, $cloudBid, $msg);
};

$results = [];
$lastError = '';
set_error_handler(function ($errno, $s, $f, $l) use (&$lastError) {
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
        return false;
    }
    // Не считать WARNING/NOTICE фатальными: иначе в $out попадает шум (например cache Permission denied от другого пользователя PHP) и вебхук ломает JSON.
    if (in_array($errno, [E_WARNING, E_NOTICE, E_USER_WARNING, E_USER_NOTICE, E_STRICT], true)) {
        return false;
    }
    $lastError = "$s in $f:$l";

    return false;
});
try {
    foreach ($items as $idx => $item) {
        $actLogLockFp = null;
        try {
            $itemBookingFailures = [];
            $bookingStatExpected = 0;
            $bookingStatLinked = 0;
            $pushItemBookingFailure = static function (?int $cloudBid, string $msg, $cloudActId = null) use (&$itemBookingFailures, $bookingFailurePath) {
                $entry = ['error' => $msg];
                if ($cloudActId !== null && $cloudActId !== '') {
                    $entry['cloud_activity_id'] = $cloudActId;
                }
                if ($cloudBid !== null && $cloudBid > 0) {
                    $entry['cloud_booking_id'] = $cloudBid;
                    migration_append_deal_booking_failure($bookingFailurePath, $cloudBid, $msg);
                }
                $itemBookingFailures[] = $entry;
            };
            $itemLogBookingFailure = static function (int $cloudBid, string $msg) use ($pushItemBookingFailure) {
                $pushItemBookingFailure($cloudBid, $msg, null);
            };
    
            $dealFields = $item['deal'] ?? [];
            $activities = $item['activities'] ?? [];
            $comments = $item['comments'] ?? [];
            $products = $item['products'] ?? [];
            $cloudDealId = isset($item['cloud_deal_id']) ? (int)$item['cloud_deal_id'] : null;
            $boxDealId = isset($item['box_deal_id']) ? (int)$item['box_deal_id'] : null;
            $updateOnly = !empty($item['update_only']);
            $skipDealUpdate = false;
    
            if (!$boxDealId && $cloudDealId) {
                $log = $readLog();
                $boxDealId = isset($log[(string)$cloudDealId]) ? (int)$log[(string)$cloudDealId] : null;
                if ($boxDealId) {
                    $updateOnly = true;
                }
            }
    
            $dateCreate = $dealFields['DATE_CREATE'] ?? null;
            $dateModify = $dealFields['DATE_MODIFY'] ?? null;
            $assignedById = isset($dealFields['ASSIGNED_BY_ID']) ? (int)$dealFields['ASSIGNED_BY_ID'] : null;
            $createdById = isset($dealFields['CREATED_BY_ID']) ? (int)$dealFields['CREATED_BY_ID'] : null;
            $modifyById = isset($dealFields['MODIFY_BY_ID']) ? (int)$dealFields['MODIFY_BY_ID'] : null;
            unset($dealFields['DATE_CREATE'], $dealFields['DATE_MODIFY']);
    
            if ($updateOnly && $boxDealId > 0) {
                if ($checkDateModify && $dateModify) {
                    global $DB;
                    $row = $DB->Query('SELECT DATE_MODIFY FROM b_crm_deal WHERE ID = ' . (int)$boxDealId)->Fetch();
                    if ($row && !empty($row['DATE_MODIFY']) && strtotime($dateModify) <= strtotime($row['DATE_MODIFY'])) {
                        $skipDealUpdate = true;
                    }
                }
                if (!$skipDealUpdate) {
                    $deal = new CCrmDeal(false);
                    $deal->Update($boxDealId, $dealFields);
                }
                $dealId = $boxDealId;
            } else {
                $deal = new CCrmDeal(false);
                $dealId = $deal->Add($dealFields);
                if (!$dealId) {
                    $ex = $GLOBALS['APPLICATION']->GetException();
                    $results[] = ['idx' => $idx, 'error' => $ex ? $ex->GetString() : 'CCrmDeal::Add failed'];
                    continue;
                }
                if ($cloudDealId) {
                    $log = $readLog();
                    $log[(string)$cloudDealId] = $dealId;
                    $writeLog($log);
                }
            }
    
            $applySqlDates = !isset($skipDealUpdate) || !$skipDealUpdate;
            if ($applySqlDates && ($dateCreate || $dateModify || $assignedById || $createdById || $modifyById)) {
                global $DB;
                $updates = [];
                if ($dateCreate) {
                    $updates[] = "DATE_CREATE = '" . $DB->ForSql($dateCreate) . "'";
                }
                if ($dateModify) {
                    $updates[] = "DATE_MODIFY = '" . $DB->ForSql($dateModify) . "'";
                }
                if ($assignedById > 0) {
                    $updates[] = 'ASSIGNED_BY_ID = ' . $assignedById;
                }
                if ($createdById > 0) {
                    $updates[] = 'CREATED_BY_ID = ' . $createdById;
                }
                if ($modifyById > 0) {
                    $updates[] = 'MODIFY_BY_ID = ' . $modifyById;
                }
                if (!empty($updates)) {
                    $DB->Query('UPDATE b_crm_deal SET ' . implode(', ', $updates) . ' WHERE ID = ' . (int)$dealId);
                }
            }
    
            $allowedActFields = ['TYPE_ID', 'PROVIDER_ID', 'PROVIDER_TYPE_ID', 'DIRECTION', 'SUBJECT', 'DESCRIPTION', 'DESCRIPTION_TYPE', 'STATUS', 'RESPONSIBLE_ID', 'AUTHOR_ID', 'EDITOR_ID', 'PRIORITY', 'START_TIME', 'END_TIME', 'DEADLINE', 'COMPLETED', 'NOTIFY_TYPE', 'NOTIFY_VALUE', 'LOCATION', 'ORIGIN_ID', 'ORIGINATOR_ID', 'RESULT_VALUE', 'RESULT_SUM', 'RESULT_CURRENCY_ID', 'RESULT_STATUS_ID', 'RESULT_STREAM', 'ASSOCIATED_ENTITY_ID', 'STORAGE_TYPE_ID', 'STORAGE_ELEMENT_IDS', 'PROVIDER_PARAMS', 'PROVIDER_DATA', 'SETTINGS'];
            $actLogLockFp = @fopen($actLogPath . '.lock', 'c+');
            if ($actLogLockFp !== false) {
                flock($actLogLockFp, LOCK_EX);
            }
            $actLog = $readActLog();
            $activitiesAdded = 0;
            global $DB;
            $crmActivityAddWithFallback = static function (array $actFields, array $comms, int $dealId) {
                $appEx = static function () {
                    $ex = $GLOBALS['APPLICATION']->GetException();
    
                    return $ex ? $ex->GetString() : '';
                };
                $newActId = CCrmActivity::Add($actFields, false, false);
                if ($newActId) {
                    return [(int)$newActId, 'full', ''];
                }
                $err1 = $appEx();
                $stripKeys = ['PROVIDER_ID', 'PROVIDER_TYPE_ID', 'SETTINGS', 'PROVIDER_PARAMS', 'PROVIDER_DATA', 'ASSOCIATED_ENTITY_ID'];
                $f2 = $actFields;
                foreach ($stripKeys as $k) {
                    unset($f2[$k]);
                }
                $f2['TYPE_ID'] = 1;
                $f2['PROVIDER_ID'] = '';
                $f2['PROVIDER_TYPE_ID'] = '';
                $f2['BINDINGS'] = [['OWNER_TYPE_ID' => CCrmOwnerType::Deal, 'OWNER_ID' => $dealId]];
                $newActId = CCrmActivity::Add($f2, false, false);
                if ($newActId) {
                    return [(int)$newActId, 'stripped_meeting', $err1];
                }
                $err2 = $appEx();
                $desc = (string)($actFields['DESCRIPTION'] ?? '');
                $subj = (string)($actFields['SUBJECT'] ?? 'Активность');
                $f3 = [
                    'TYPE_ID' => 1,
                    'SUBJECT' => mb_substr('[Миграция] ' . $subj, 0, 255),
                    'DESCRIPTION' => $desc,
                    'DESCRIPTION_TYPE' => isset($actFields['DESCRIPTION_TYPE']) ? (int)$actFields['DESCRIPTION_TYPE'] : 1,
                    'RESPONSIBLE_ID' => (int)($actFields['RESPONSIBLE_ID'] ?? 1),
                    'AUTHOR_ID' => (int)($actFields['AUTHOR_ID'] ?? 1),
                    'EDITOR_ID' => (int)($actFields['EDITOR_ID'] ?? 1),
                    'PRIORITY' => $actFields['PRIORITY'] ?? '2',
                    'LOCATION' => $actFields['LOCATION'] ?? '',
                    'DIRECTION' => (int)($actFields['DIRECTION'] ?? 1),
                    'NOTIFY_TYPE' => (int)($actFields['NOTIFY_TYPE'] ?? 0),
                    'NOTIFY_VALUE' => (int)($actFields['NOTIFY_VALUE'] ?? 0),
                    'START_TIME' => $actFields['START_TIME'] ?? '',
                    'END_TIME' => $actFields['END_TIME'] ?? '',
                    'DEADLINE' => $actFields['DEADLINE'] ?? '',
                    'COMPLETED' => $actFields['COMPLETED'] ?? 'Y',
                    'BINDINGS' => [['OWNER_TYPE_ID' => CCrmOwnerType::Deal, 'OWNER_ID' => $dealId]],
                    'ORIGINATOR_ID' => 'migration',
                    'ORIGIN_ID' => (string)($actFields['ORIGIN_ID'] ?? ''),
                ];
                if (!empty($actFields['STORAGE_ELEMENT_IDS']) && is_array($actFields['STORAGE_ELEMENT_IDS'])) {
                    $f3['STORAGE_TYPE_ID'] = (int)($actFields['STORAGE_TYPE_ID'] ?? migration_get_crm_file_storage_type_id());
                    $f3['STORAGE_ELEMENT_IDS'] = $actFields['STORAGE_ELEMENT_IDS'];
                }
                $newActId = CCrmActivity::Add($f3, false, false);
                if ($newActId) {
                    return [(int)$newActId, 'fallback_meeting', $err1 . ' | ' . $err2];
                }
    
                return [0, 'failed', $err1 . ' | ' . $err2 . ' | ' . $appEx()];
            };
            foreach ($activities as $act) {
                $cloudActId = $act['cloud_activity_id'] ?? null;
                $cloudActIdStr = ($cloudActId !== null && (string)$cloudActId !== '') ? (string)$cloudActId : '';
                $rawProviderId = (string)($act['PROVIDER_ID'] ?? '');
                $canonicalLogKey = ($cloudActIdStr !== '')
                    ? (($cloudDealId && (int)$cloudDealId > 0) ? ((string)$cloudDealId . ':' . $cloudActIdStr) : $cloudActIdStr)
                    : null;
    
                $boxActId = null;
                if ($cloudActIdStr !== '') {
                    $boxActId = migration_lookup_act_log_box_id($actLog, $cloudDealId ? (int)$cloudDealId : null, $cloudActIdStr);
                    if ($boxActId && !CCrmActivity::GetByID((int)$boxActId, false)) {
                        $boxActId = null;
                        if ($canonicalLogKey !== null) {
                            unset($actLog[$canonicalLogKey]);
                            $writeActLog($actLog);
                        }
                    } elseif ($boxActId && !migration_crm_act_primary_owner_is_deal((int)$boxActId, (int)$dealId)) {
                        $boxActId = null;
                        if ($canonicalLogKey !== null) {
                            unset($actLog[$canonicalLogKey]);
                            $writeActLog($actLog);
                        }
                    }
                    $deduped = migration_dedupe_migration_activities_for_deal_origin((int)$dealId, $cloudActIdStr);
                    if ($deduped !== null) {
                        $boxActId = $deduped;
                        if ($canonicalLogKey !== null) {
                            $prev = $actLog[$canonicalLogKey] ?? null;
                            $merged = [];
                            if (is_array($prev)) {
                                $merged = $prev;
                            } elseif ($prev !== null && (int)$prev > 0) {
                                $merged = ['box_id' => (int)$prev];
                            }
                            $merged['box_id'] = $deduped;
                            $actLog[$canonicalLogKey] = $merged;
                            $writeActLog($actLog);
                        }
                    }
                }
    
                // Уже перенесённые звонки/задачи/прочее при повторной выгрузке сделки не трогаем (не Update, не дубли Add).
                if ($boxActId && $rawProviderId !== 'CRM_BOOKING') {
                    continue;
                }
    
                $actCreated = $act['CREATED'] ?? null;
                $actLastUpdated = $act['LAST_UPDATED'] ?? $actCreated;
                $migrationProvider = (string)($act['migration_provider_id'] ?? '');
                $migrationTypeId = $act['migration_type_id'] ?? '';
                $migrationAttachments = [];
                if (isset($act['migration_attachments']) && is_array($act['migration_attachments'])) {
                    $migrationAttachments = $act['migration_attachments'];
                }
                $migrationTask = isset($act['migration_task']) && is_array($act['migration_task']) ? $act['migration_task'] : null;
                $migrationBooking = isset($act['migration_booking']) && is_array($act['migration_booking']) ? $act['migration_booking'] : null;
                $crmBookingLinkedToBox = false;
                unset($act['cloud_activity_id'], $act['CREATED'], $act['LAST_UPDATED'], $act['migration_provider_id'], $act['migration_type_id'], $act['migration_attachments'], $act['migration_task'], $act['migration_booking']);
                $comms = $act['COMMUNICATIONS'] ?? [];
                $actFields = array_intersect_key($act, array_flip($allowedActFields));
                unset($actFields['STORAGE_TYPE_ID'], $actFields['STORAGE_ELEMENT_IDS']);
                foreach (['DEADLINE', 'START_TIME', 'END_TIME'] as $timeKey) {
                    if (!array_key_exists($timeKey, $actFields)) {
                        $actFields[$timeKey] = '';
                    }
                }
                foreach (['DEADLINE', 'START_TIME', 'END_TIME'] as $timeKey) {
                    if (isset($actFields[$timeKey]) && $actFields[$timeKey] !== '') {
                        $actFields[$timeKey] = migration_normalize_activity_datetime($actFields[$timeKey]);
                    }
                }
                if ($actFields['END_TIME'] === '' && $actFields['START_TIME'] !== '') {
                    $actFields['END_TIME'] = $actFields['START_TIME'];
                }
                if ($actFields['DEADLINE'] === '' && $actFields['START_TIME'] !== '') {
                    $actFields['DEADLINE'] = $actFields['START_TIME'];
                }
                if (($actFields['PROVIDER_ID'] ?? '') === 'CRM_BOOKING') {
                    $bookingStatExpected++;
                    if (!is_array($migrationBooking)) {
                        $pushItemBookingFailure(
                            null,
                            'CRM_BOOKING without migration_booking payload (check cloud webhook booking.v1.* and booking_resource_mapping.json)',
                            $cloudActId
                        );
                    }
                }
                if (is_array($migrationTask) && ($actFields['PROVIDER_ID'] ?? '') === 'CRM_TASKS_TASK') {
                    $boxTaskId = migration_ensure_box_task($migrationTask, (int)$dealId, $readTaskLog, $writeTaskLog);
                    if ($boxTaskId > 0) {
                        $actFields['ASSOCIATED_ENTITY_ID'] = $boxTaskId;
                        if (!isset($actFields['SETTINGS']) || !is_array($actFields['SETTINGS'])) {
                            $actFields['SETTINGS'] = [];
                        }
                        $actFields['SETTINGS']['TASK_ID'] = $boxTaskId;
                    }
                }
                if (is_array($migrationBooking) && ($actFields['PROVIDER_ID'] ?? '') === 'CRM_BOOKING') {
                    $runAsBooking = (int)($actFields['RESPONSIBLE_ID'] ?? $actFields['AUTHOR_ID'] ?? 1);
                    $boxBookingId = migration_ensure_box_booking($migrationBooking, (int)$dealId, $runAsBooking, $readBookingLog, $writeBookingLog, $itemLogBookingFailure);
                    if ($boxBookingId > 0) {
                        $crmBookingLinkedToBox = true;
                        $bookingStatLinked++;
                        $actFields['ASSOCIATED_ENTITY_ID'] = $boxBookingId;
                        if (!isset($actFields['SETTINGS']) || !is_array($actFields['SETTINGS'])) {
                            $actFields['SETTINGS'] = [];
                        }
                        if (!isset($actFields['SETTINGS']['FIELDS']) || !is_array($actFields['SETTINGS']['FIELDS'])) {
                            $actFields['SETTINGS']['FIELDS'] = [];
                        }
                        $actFields['SETTINGS']['FIELDS']['id'] = $boxBookingId;
                    }
                }
                if (!array_key_exists('DESCRIPTION_TYPE', $actFields)) {
                    $actFields['DESCRIPTION_TYPE'] = 1;
                }
                if (empty($actFields['SUBJECT'])) {
                    $actFields['SUBJECT'] = 'Activity';
                }
                if (($actFields['COMPLETED'] ?? '') === 'Y' && (int)($actFields['STATUS'] ?? 0) === CCrmActivityStatus::AutoCompleted) {
                    $actFields['STATUS'] = (string)CCrmActivityStatus::Completed;
                }
                $actFields['BINDINGS'] = [['OWNER_TYPE_ID' => CCrmOwnerType::Deal, 'OWNER_ID' => $dealId]];
                $actFields['ORIGINATOR_ID'] = 'migration';
                $actFields['ORIGIN_ID'] = $cloudActIdStr !== '' ? $cloudActIdStr : ('deal' . $dealId . '_' . $activitiesAdded);
                foreach ($comms as &$c) {
                    if (isset($c['ENTITY_TYPE_ID']) && (int)$c['ENTITY_TYPE_ID'] === CCrmOwnerType::Deal) {
                        $c['ENTITY_ID'] = $dealId;
                    }
                }
                unset($c);
                $logKey = $canonicalLogKey;
    
                $attachErrs = [];
                if ($migrationAttachments !== [] && (!$boxActId || ($actFields['PROVIDER_ID'] ?? '') === 'CRM_BOOKING')) {
                    $existingFileIds = [];
                    if ($boxActId) {
                        $rowAct = CCrmActivity::GetByID((int)$boxActId, false);
                        if (is_array($rowAct)) {
                            $existingFileIds = migration_normalize_storage_element_ids($rowAct['STORAGE_ELEMENT_IDS'] ?? []);
                        }
                    }
                    if ($existingFileIds === []) {
                        [$downloadedIds, $attachErrs] = migration_download_attachments_to_crm_files($migrationAttachments);
                        if ($downloadedIds !== []) {
                            $actFields['STORAGE_TYPE_ID'] = migration_get_crm_file_storage_type_id();
                            $actFields['STORAGE_ELEMENT_IDS'] = $downloadedIds;
                        }
                    }
                }
    
                // Повторная проверка по БД после сборки полей (гонка параллельных вебхуков): не создаём вторую запись.
                if (!$boxActId && $cloudActIdStr !== '') {
                    $resolvedLate = migration_dedupe_migration_activities_for_deal_origin((int)$dealId, $cloudActIdStr);
                    if ($resolvedLate !== null) {
                        if (($actFields['PROVIDER_ID'] ?? '') !== 'CRM_BOOKING') {
                            if ($logKey) {
                                $actLog[$logKey] = [
                                    'box_id' => $resolvedLate,
                                    'type_id' => $actFields['TYPE_ID'] ?? null,
                                ];
                                $writeActLog($actLog);
                            }
                            continue;
                        }
                        $boxActId = $resolvedLate;
                    }
                }
    
                if ($boxActId) {
                    if (($actFields['PROVIDER_ID'] ?? '') !== 'CRM_BOOKING') {
                        continue;
                    }
                    $skipActUpdate = false;
                    if ($checkDateModify && $actLastUpdated) {
                        $row = $DB->Query('SELECT LAST_UPDATED FROM b_crm_act WHERE ID = ' . (int)$boxActId)->Fetch();
                        if ($row && !empty($row['LAST_UPDATED']) && strtotime((string)$actLastUpdated) <= strtotime((string)$row['LAST_UPDATED'])) {
                            $skipActUpdate = true;
                        }
                    }
                    if (!$skipActUpdate) {
                        CCrmActivity::Update($boxActId, $actFields);
                        if (!empty($comms)) {
                            CCrmActivity::SaveCommunications($boxActId, $comms, $actFields, true, false);
                        }
                    } else {
                        if (!empty($actFields['STORAGE_ELEMENT_IDS'])) {
                            CCrmActivity::Update((int)$boxActId, [
                                'STORAGE_TYPE_ID' => (int)$actFields['STORAGE_TYPE_ID'],
                                'STORAGE_ELEMENT_IDS' => $actFields['STORAGE_ELEMENT_IDS'],
                            ]);
                        }
                        if ($crmBookingLinkedToBox && ($actFields['PROVIDER_ID'] ?? '') === 'CRM_BOOKING') {
                            $bookingPatch = ['ASSOCIATED_ENTITY_ID' => (int)$actFields['ASSOCIATED_ENTITY_ID']];
                            if (!empty($actFields['SETTINGS']) && is_array($actFields['SETTINGS'])) {
                                $bookingPatch['SETTINGS'] = $actFields['SETTINGS'];
                            }
                            CCrmActivity::Update((int)$boxActId, $bookingPatch);
                        }
                    }
                    migration_apply_b_crm_act_audit_dates((int)$boxActId, $actCreated, $actLastUpdated);
                    if ($logKey) {
                        $actLog[$logKey] = [
                            'box_id' => (int)$boxActId,
                            'type_id' => $actFields['TYPE_ID'] ?? null,
                        ];
                        if ($attachErrs !== []) {
                            $actLog[$logKey]['attachment_errors'] = $attachErrs;
                        }
                        if (!empty($actFields['STORAGE_ELEMENT_IDS'])) {
                            $actLog[$logKey]['attachment_file_ids'] = $actFields['STORAGE_ELEMENT_IDS'];
                        }
                        $writeActLog($actLog);
                    }
                } else {
                    if ($migrationProvider !== '' || $migrationTypeId !== '') {
                        $note = "\n\n---\n[Миграция с облака] PROVIDER_ID=" . $migrationProvider . ', TYPE_ID=' . (string)$migrationTypeId;
                        $actFields['DESCRIPTION'] = (string)($actFields['DESCRIPTION'] ?? '') . $note;
                    }
                    [$newActId, $addMode, $addErr] = $crmActivityAddWithFallback($actFields, $comms, (int)$dealId);
                    if ($newActId && !empty($comms)) {
                        CCrmActivity::SaveCommunications($newActId, $comms, $actFields, true, false);
                    }
                    if ($newActId) {
                        migration_apply_b_crm_act_audit_dates((int)$newActId, $actCreated, $actLastUpdated);
                    }
                    if ($logKey) {
                        if ($newActId) {
                            $actLog[$logKey] = [
                                'box_id' => (int)$newActId,
                                'type_id' => $actFields['TYPE_ID'] ?? null,
                                'add_mode' => $addMode,
                            ];
                            if ($attachErrs !== []) {
                                $actLog[$logKey]['attachment_errors'] = $attachErrs;
                            }
                            if (!empty($actFields['STORAGE_ELEMENT_IDS'])) {
                                $actLog[$logKey]['attachment_file_ids'] = $actFields['STORAGE_ELEMENT_IDS'];
                            }
                        } else {
                            $actLog[$logKey] = [
                                'box_id' => null,
                                'type_id' => $actFields['TYPE_ID'] ?? null,
                                'add_mode' => $addMode,
                                'error' => $addErr,
                            ];
                            if ($attachErrs !== []) {
                                $actLog[$logKey]['attachment_errors'] = $attachErrs;
                            }
                        }
                        $writeActLog($actLog);
                    }
                    $activitiesAdded++;
                }
            }
            if (isset($actLogLockFp) && is_resource($actLogLockFp)) {
                flock($actLogLockFp, LOCK_UN);
                fclose($actLogLockFp);
            }
    
            $strictBookingFailed = false;
            if ($strictBooking && $bookingStatExpected > 0) {
                $strictBookingFailed = ($bookingStatLinked < $bookingStatExpected) || ($itemBookingFailures !== []);
            }
    
            $bookingResult = [
                'expected' => $bookingStatExpected,
                'linked' => $bookingStatLinked,
                'failures' => $itemBookingFailures,
            ];
    
            if ($updateOnly) {
                $results[] = [
                    'idx' => $idx,
                    'deal_id' => $dealId,
                    'activities' => count($activities),
                    'comments' => 0,
                    'products' => 0,
                    'updated' => true,
                    'booking' => $bookingResult,
                    'strict_booking_failed' => $strictBookingFailed,
                ];
                continue;
            }
    
            foreach ($comments as $com) {
                $text = trim($com['COMMENT'] ?? '');
                if ($text === '') {
                    continue;
                }
                \Bitrix\Crm\Timeline\CommentEntry::create([
                    'TEXT' => $text,
                    'AUTHOR_ID' => (int)($com['AUTHOR_ID'] ?? 1),
                    'BINDINGS' => [['ENTITY_TYPE_ID' => CCrmOwnerType::Deal, 'ENTITY_ID' => $dealId]],
                    'SETTINGS' => [],
                ]);
            }
    
            if (!empty($products)) {
                $rows = [];
                foreach ($products as $p) {
                    $rows[] = [
                        'PRODUCT_ID' => $p['PRODUCT_ID'] ?? 0,
                        'QUANTITY' => (float)($p['QUANTITY'] ?? 1),
                        'PRICE' => (float)($p['PRICE'] ?? 0),
                        'PRICE_EXCLUSIVE' => (float)($p['PRICE_EXCLUSIVE'] ?? 0),
                        'PRICE_NETTO' => (float)($p['PRICE_NETTO'] ?? 0),
                        'PRICE_BRUTTO' => (float)($p['PRICE_BRUTTO'] ?? 0),
                        'DISCOUNT_TYPE_ID' => $p['DISCOUNT_TYPE_ID'] ?? 0,
                        'DISCOUNT_RATE' => (float)($p['DISCOUNT_RATE'] ?? 0),
                        'DISCOUNT_SUM' => (float)($p['DISCOUNT_SUM'] ?? 0),
                        'TAX_RATE' => (float)($p['TAX_RATE'] ?? 0),
                        'TAX_INCLUDED' => $p['TAX_INCLUDED'] ?? 'N',
                        'MEASURE_CODE' => $p['MEASURE_CODE'] ?? '',
                        'MEASURE_NAME' => $p['MEASURE_NAME'] ?? '',
                    ];
                }
                if (method_exists('CCrmDeal', 'SetProductRows')) {
                    CCrmDeal::SetProductRows($dealId, $rows);
                } elseif (method_exists('CCrmDeal', 'SaveProductRows')) {
                    CCrmDeal::SaveProductRows($dealId, $rows);
                }
            }
    
            $results[] = [
                'idx' => $idx,
                'deal_id' => $dealId,
                'activities' => count($activities),
                'comments' => count($comments),
                'products' => count($products),
                'updated' => false,
                'booking' => $bookingResult,
                'strict_booking_failed' => $strictBookingFailed,
            ];
        } catch (\Throwable $eItem) {
            $results[] = [
                'idx' => $idx,
                'cloud_deal_id' => isset($item['cloud_deal_id']) ? (int)$item['cloud_deal_id'] : null,
                'error' => $eItem->getMessage() . ' @ ' . $eItem->getFile() . ':' . $eItem->getLine(),
            ];
        } finally {
            if (isset($actLogLockFp) && is_resource($actLogLockFp)) {
                @flock($actLogLockFp, LOCK_UN);
                @fclose($actLogLockFp);
            }
        }
    }
} catch (\Throwable $e) {
    $lastError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
restore_error_handler();

$out = ['results' => $results];
if ($lastError) {
    $out['error'] = $lastError;
}
if ($strictBooking) {
    foreach ($results as $row) {
        if (!empty($row['strict_booking_failed'])) {
            $out['strict_booking_failed'] = true;
            break;
        }
    }
}
ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_UNICODE);
