<?php
/**
 * Create/update leads with timeline and products from JSON on stdin.
 * Run on box via SSH. Input: JSON array of {cloud_lead_id?, box_lead_id?, update_only?, lead, activities, comments, products}
 * If cloud_lead_id in log -> update only. Else create and write to log.
 */
ob_start();
$json = stream_get_contents(STDIN);
$input = json_decode($json, true);
$checkDateModify = true;
if (isset($input['items']) && is_array($input['items'])) {
    $items = $input['items'];
    $checkDateModify = ($input['check_date_modify'] ?? true);
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
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

/**
 * Облачный REST отдаёт даты активности в ISO 8601; CCrmActivity на коробке ожидает формат сайта.
 */
$normalizeLeadActivityDateTimeFields = static function (array &$actFields): void {
    if (!empty($actFields['DEADLINE']) && is_string($actFields['DEADLINE']) && preg_match('/9999-12-31|^\s*9999-/i', $actFields['DEADLINE'])) {
        unset($actFields['DEADLINE']);
    }
    foreach (['START_TIME', 'END_TIME', 'DEADLINE'] as $timeKey) {
        if (empty($actFields[$timeKey]) || !is_string($actFields[$timeKey])) {
            continue;
        }
        $v = trim($actFields[$timeKey]);
        if ($v === '') {
            unset($actFields[$timeKey]);
            continue;
        }
        if (preg_match('~^\d{1,2}\.\d{1,2}\.\d{4}~u', $v)) {
            continue;
        }
        $ts = strtotime($v);
        if ($ts !== false) {
            $actFields[$timeKey] = (string)ConvertTimeStamp($ts, 'FULL');
        }
    }
};

/**
 * CREATED/LAST_UPDATED из облака (ISO) → формат сайта для b_crm_act, по той же логике, что START_TIME.
 */
$normalizeActivityAuditDatetimeForSql = static function ($v): ?string {
    if ($v === null || !is_string($v)) {
        return null;
    }
    $v = trim($v);
    if ($v === '') {
        return null;
    }
    if (preg_match('~^\d{1,2}\.\d{1,2}\.\d{4}~u', $v)) {
        return $v;
    }
    $ts = strtotime($v);
    if ($ts === false) {
        return $v;
    }

    return (string)ConvertTimeStamp($ts, 'FULL');
};

$applyBcrmActAuditDates = static function (int $actId, $actCreated, $actLastUpdated) use ($normalizeActivityAuditDatetimeForSql): void {
    global $DB;
    $upd = [];
    $nc = $normalizeActivityAuditDatetimeForSql($actCreated);
    $nl = $normalizeActivityAuditDatetimeForSql($actLastUpdated);
    if ($nc !== null) {
        $upd[] = "CREATED = '" . $DB->ForSql($nc) . "'";
    }
    if ($nl !== null) {
        $upd[] = "LAST_UPDATED = '" . $DB->ForSql($nl) . "'";
    }
    if ($upd !== []) {
        $DB->Query('UPDATE b_crm_act SET ' . implode(', ', $upd) . ' WHERE ID = ' . (int)$actId);
    }
};

$logPath = $docRoot . '/local/handlers/leadsync/data/lead_sync_log.json';
$actLogPath = $docRoot . '/local/handlers/leadsync/data/activity_sync_log.json';
$readLog = function () use ($logPath) {
    return file_exists($logPath) ? (json_decode(file_get_contents($logPath), true) ?: []) : [];
};
$writeLog = function (array $data) use ($logPath) {
    $dir = dirname($logPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($logPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};
$readActLog = function () use ($actLogPath) {
    return file_exists($actLogPath) ? (json_decode(file_get_contents($actLogPath), true) ?: []) : [];
};
$writeActLog = function (array $data) use ($actLogPath) {
    $dir = dirname($actLogPath);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    file_put_contents($actLogPath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
};

$results = [];
$lastError = '';
set_error_handler(function ($n, $s, $f, $l) use (&$lastError) { $lastError = "$s in $f:$l"; return false; });
try {
foreach ($items as $idx => $item) {
    $skipLeadUpdate = false;
    $leadFields = $item['lead'] ?? [];
    $activities = $item['activities'] ?? [];
    $comments = $item['comments'] ?? [];
    $products = $item['products'] ?? [];
    $cloudLeadId = isset($item['cloud_lead_id']) ? (int)$item['cloud_lead_id'] : null;
    $boxLeadId = isset($item['box_lead_id']) ? (int)$item['box_lead_id'] : null;
    $updateOnly = !empty($item['update_only']);

    $lockFp = null;
    if ($cloudLeadId && $cloudLeadId > 0) {
        $lockFp = @fopen($logPath . '.lock', 'c+');
        if (is_resource($lockFp)) {
            flock($lockFp, LOCK_EX);
        }
    }

    try {
        if ($cloudLeadId) {
            $log = $readLog();
            if (isset($log[(string)$cloudLeadId])) {
                $boxLeadId = (int)$log[(string)$cloudLeadId];
                $updateOnly = true;
            }
        }

        $dateCreate = $leadFields['DATE_CREATE'] ?? null;
        $dateModify = $leadFields['DATE_MODIFY'] ?? null;
        $assignedById = isset($leadFields['ASSIGNED_BY_ID']) ? (int)$leadFields['ASSIGNED_BY_ID'] : null;
        $createdById = isset($leadFields['CREATED_BY_ID']) ? (int)$leadFields['CREATED_BY_ID'] : null;
        $modifyById = isset($leadFields['MODIFY_BY_ID']) ? (int)$leadFields['MODIFY_BY_ID'] : null;
        unset($leadFields['DATE_CREATE'], $leadFields['DATE_MODIFY']);

        if ($updateOnly && $boxLeadId > 0) {
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                $lockFp = null;
            }
            $skipLeadUpdate = false;
            if ($checkDateModify && $dateModify) {
                global $DB;
                $row = $DB->Query("SELECT DATE_MODIFY FROM b_crm_lead WHERE ID = " . (int)$boxLeadId)->Fetch();
                if ($row && !empty($row['DATE_MODIFY']) && strtotime($dateModify) <= strtotime($row['DATE_MODIFY'])) {
                    $skipLeadUpdate = true;
                }
            }
            if (!$skipLeadUpdate) {
                $lead = new \CCrmLead(false);
                $lead->Update($boxLeadId, $leadFields);
            }
            $leadId = $boxLeadId;
        } else {
            $lead = new \CCrmLead(false);
            $leadId = $lead->Add($leadFields);
            if (!$leadId) {
                $ex = $GLOBALS['APPLICATION']->GetException();
                $results[] = ['idx' => $idx, 'error' => $ex ? $ex->GetString() : 'CCrmLead::Add failed'];
                continue;
            }
            if ($cloudLeadId) {
                $log = $readLog();
                if (isset($log[(string)$cloudLeadId])) {
                    $winner = (int)$log[(string)$cloudLeadId];
                    if ($winner !== (int)$leadId) {
                        \CCrmLead::Delete($leadId);
                        $leadId = $winner;
                        (new \CCrmLead(false))->Update($leadId, $leadFields);
                    }
                } else {
                    $log[(string)$cloudLeadId] = $leadId;
                    $writeLog($log);
                }
            }
            if (is_resource($lockFp)) {
                flock($lockFp, LOCK_UN);
                fclose($lockFp);
                $lockFp = null;
            }
        }
    } finally {
        if (is_resource($lockFp)) {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
        }
    }

    $applyLeadSqlDates = !isset($skipLeadUpdate) || !$skipLeadUpdate;
    if ($applyLeadSqlDates && ($dateCreate || $dateModify || $assignedById || $createdById || $modifyById)) {
        global $DB;
        $updates = [];
        if ($dateCreate) $updates[] = "DATE_CREATE = '" . $DB->ForSql($dateCreate) . "'";
        if ($dateModify) $updates[] = "DATE_MODIFY = '" . $DB->ForSql($dateModify) . "'";
        if ($assignedById > 0) $updates[] = 'ASSIGNED_BY_ID = ' . $assignedById;
        if ($createdById > 0) $updates[] = 'CREATED_BY_ID = ' . $createdById;
        if ($modifyById > 0) $updates[] = 'MODIFY_BY_ID = ' . $modifyById;
        if (!empty($updates)) {
            $DB->Query('UPDATE b_crm_lead SET ' . implode(', ', $updates) . ' WHERE ID = ' . (int)$leadId);
        }
    }

    $allowedActFields = ['TYPE_ID','PROVIDER_ID','PROVIDER_TYPE_ID','DIRECTION','SUBJECT','DESCRIPTION','DESCRIPTION_TYPE','STATUS','RESPONSIBLE_ID','AUTHOR_ID','EDITOR_ID','PRIORITY','START_TIME','END_TIME','DEADLINE','COMPLETED','NOTIFY_TYPE','NOTIFY_VALUE','LOCATION','ORIGIN_ID','ORIGINATOR_ID','RESULT_VALUE','RESULT_SUM','RESULT_CURRENCY_ID','RESULT_STATUS_ID','RESULT_STREAM','ASSOCIATED_ENTITY_ID','STORAGE_TYPE_ID','STORAGE_ELEMENT_IDS','PROVIDER_PARAMS','PROVIDER_DATA','SETTINGS'];
    $actLog = $readActLog();
    $actLogKey = $cloudLeadId ? (string)$cloudLeadId . ':' : '';
    $activitiesAdded = 0;
    global $DB;
    foreach ($activities as $act) {
        $cloudActId = $act['cloud_activity_id'] ?? null;
        $actCreated = $act['CREATED'] ?? null;
        $actLastUpdated = $act['LAST_UPDATED'] ?? $actCreated;
        unset($act['cloud_activity_id'], $act['CREATED'], $act['LAST_UPDATED']);
        $comms = $act['COMMUNICATIONS'] ?? [];
        $actFields = array_intersect_key($act, array_flip($allowedActFields));
        if (empty($actFields['SUBJECT'])) $actFields['SUBJECT'] = 'Activity';
        $normalizeLeadActivityDateTimeFields($actFields);
        // Облако для завершённых писем часто отдаёт STATUS=3 (AutoCompleted). В коробке запись в таймлайн
        // для писем/звонков создаётся только при STATUS=Completed (2) — см. ActivityController::onCreate.
        if (($actFields['COMPLETED'] ?? '') === 'Y' && (int)($actFields['STATUS'] ?? 0) === CCrmActivityStatus::AutoCompleted) {
            $actFields['STATUS'] = (string)CCrmActivityStatus::Completed;
        }
        // Чтобы ответственный по лиду видел дела в CRM (иначе остаётся маппинг автора из облака).
        if ($assignedById > 0) {
            $actFields['RESPONSIBLE_ID'] = $assignedById;
        }
        $actFields['BINDINGS'] = [['OWNER_TYPE_ID' => CCrmOwnerType::Lead, 'OWNER_ID' => $leadId]];
        $actFields['ORIGINATOR_ID'] = 'migration';
        $actFields['ORIGIN_ID'] = $cloudActId ? (string)$cloudActId : ('lead' . $leadId . '_' . $activitiesAdded);
        foreach ($comms as &$c) {
            if (isset($c['ENTITY_TYPE_ID']) && (int)$c['ENTITY_TYPE_ID'] === CCrmOwnerType::Lead) {
                $c['ENTITY_ID'] = $leadId;
            }
        }
        unset($c);
        $logKey = $cloudActId ? ($actLogKey . $cloudActId) : null;
        $actLogEntry = $logKey ? ($actLog[$logKey] ?? null) : null;
        $boxActId = null;
        if ($actLogEntry !== null) {
            $boxActId = is_array($actLogEntry) ? ($actLogEntry['box_id'] ?? null) : $actLogEntry;
        }
        if ($boxActId && !CCrmActivity::GetByID((int)$boxActId, false)) {
            $boxActId = null;
            if ($logKey) {
                unset($actLog[$logKey]);
                $writeActLog($actLog);
            }
        }
        if (!$boxActId && $cloudActId) {
            $bestCandidate = null;
            $dbRes = CCrmActivity::GetList(
                [],
                ['ORIGINATOR_ID' => 'migration', 'ORIGIN_ID' => (string)$cloudActId],
                false,
                false,
                ['ID']
            );
            while ($row = $dbRes->Fetch()) {
                $cid = (int)$row['ID'];
                $bindings = CCrmActivity::GetBindings($cid);
                if (!is_array($bindings)) {
                    continue;
                }
                foreach ($bindings as $b) {
                    if ((int)($b['OWNER_TYPE_ID'] ?? 0) === CCrmOwnerType::Lead && (int)($b['OWNER_ID'] ?? 0) === (int)$leadId) {
                        if ($bestCandidate === null || $cid < $bestCandidate) {
                            $bestCandidate = $cid;
                        }
                        break;
                    }
                }
            }
            if ($bestCandidate !== null) {
                $boxActId = $bestCandidate;
                if ($logKey) {
                    $actLog[$logKey] = ['box_id' => $bestCandidate, 'type_id' => $actFields['TYPE_ID'] ?? null];
                    $writeActLog($actLog);
                }
            }
        }
        if ($boxActId) {
            $skipActUpdate = false;
            if ($checkDateModify && $actLastUpdated) {
                $row = $DB->Query("SELECT LAST_UPDATED FROM b_crm_act WHERE ID = " . (int)$boxActId)->Fetch();
                if ($row && !empty($row['LAST_UPDATED']) && strtotime($actLastUpdated) <= strtotime($row['LAST_UPDATED'])) {
                    $skipActUpdate = true;
                }
            }
            if (!$skipActUpdate) {
                CCrmActivity::Update($boxActId, $actFields);
                if (!empty($comms)) {
                    CCrmActivity::SaveCommunications($boxActId, $comms, $actFields, true, false);
                }
            }
            if ($actCreated || $actLastUpdated) {
                $applyBcrmActAuditDates((int)$boxActId, $actCreated, $actLastUpdated);
            }
            $actLog[$logKey] = ['box_id' => (int)$boxActId, 'type_id' => $actFields['TYPE_ID'] ?? null];
            $writeActLog($actLog);
        } else {
            $newActId = CCrmActivity::Add($actFields, false, false);
            if ($newActId && !empty($comms)) {
                CCrmActivity::SaveCommunications($newActId, $comms, $actFields, true, false);
            }
            if ($newActId && ($actCreated || $actLastUpdated)) {
                $applyBcrmActAuditDates((int)$newActId, $actCreated, $actLastUpdated);
            }
            if ($newActId && $logKey) {
                $actLog[$logKey] = ['box_id' => (int)$newActId, 'type_id' => $actFields['TYPE_ID'] ?? null];
                $writeActLog($actLog);
            }
            $activitiesAdded++;
        }
    }

    if ($updateOnly) {
        $results[] = ['idx' => $idx, 'lead_id' => $leadId, 'activities' => count($activities), 'comments' => 0, 'products' => 0, 'updated' => true];
        if (empty($activities)) {
            $results[count($results) - 1]['activities'] = 0;
        }
        continue;
    }


    foreach ($comments as $com) {
        $text = trim($com['COMMENT'] ?? '');
        if ($text === '') continue;
        \Bitrix\Crm\Timeline\CommentEntry::create([
            'TEXT' => $text,
            'AUTHOR_ID' => (int)($com['AUTHOR_ID'] ?? 1),
            'BINDINGS' => [['ENTITY_TYPE_ID' => CCrmOwnerType::Lead, 'ENTITY_ID' => $leadId]],
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
        CCrmLead::SetProductRows($leadId, $rows);
    }

    $results[] = ['idx' => $idx, 'lead_id' => $leadId, 'activities' => count($activities), 'comments' => count($comments), 'products' => count($products), 'updated' => false];
}
} catch (\Throwable $e) {
    $lastError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
}
restore_error_handler();

$out = ['results' => $results];
if ($lastError) $out['error'] = $lastError;
ob_end_clean();
echo json_encode($out, JSON_UNESCAPED_UNICODE);
