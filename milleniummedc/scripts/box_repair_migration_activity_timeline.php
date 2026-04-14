#!/usr/bin/env php
<?php
/**
 * Run ON Bitrix box: php box_repair_migration_activity_timeline.php
 *
 * Для активностей с ORIGINATOR_ID=migration без записи в b_crm_timeline создаёт
 * элемент таймлайна (как при обычном CCrmActivity::Add с корректным STATUS).
 * Типичная причина пропуска: облако отдало STATUS=3 (AutoCompleted), а
 * ActivityController для писем добавляет таймлайн только при STATUS=2 (Completed).
 */
$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
\Bitrix\Main\Loader::includeModule('crm');

global $USER;
if (is_object($USER) && method_exists($USER, 'Authorize')) {
    $USER->Authorize(1);
}

global $DB;

$sql = <<<'SQL'
SELECT a.ID, a.TYPE_ID, a.PROVIDER_ID, a.AUTHOR_ID, a.CREATED
FROM b_crm_act a
WHERE a.ORIGINATOR_ID = 'migration'
AND NOT EXISTS (
  SELECT 1 FROM b_crm_timeline t
  WHERE t.TYPE_ID = 1
  AND t.ASSOCIATED_ENTITY_TYPE_ID = 6
  AND t.ASSOCIATED_ENTITY_ID = a.ID
)
LIMIT 500
SQL;

$allFixed = [];
$totalSkipped = 0;
$batch = 0;

do {
    $batch++;
    $fixed = [];
    $skipped = 0;
    $r = $DB->Query($sql);

while ($row = $r->Fetch()) {
    $id = (int)$row['ID'];
    $bindings = CCrmActivity::GetBindings($id);
    if (!is_array($bindings) || $bindings === []) {
        $skipped++;
        continue;
    }
    $mapBindings = array_map(
        static function ($b) {
            return [
                'ENTITY_TYPE_ID' => (int)$b['OWNER_TYPE_ID'],
                'ENTITY_ID' => (int)$b['OWNER_ID'],
            ];
        },
        $bindings
    );
    $authorId = (int)($row['AUTHOR_ID'] ?? 0);
    if ($authorId <= 0) {
        $authorId = 1;
    }
    $tid = (int)($row['TYPE_ID'] ?? 0);
    if ($tid <= 0) {
        $skipped++;
        continue;
    }
    $pid = (string)($row['PROVIDER_ID'] ?? '');

    $createdDt = new \Bitrix\Main\Type\DateTime();
    if (!empty($row['CREATED'])) {
        try {
            $createdDt = new \Bitrix\Main\Type\DateTime($row['CREATED']);
        } catch (\Throwable $e) {
            // оставляем текущее время
        }
    }

    try {
        $entryId = (int)\Bitrix\Crm\Timeline\ActivityEntry::create([
            'ACTIVITY_TYPE_ID' => $tid,
            'ACTIVITY_PROVIDER_ID' => $pid,
            'ENTITY_ID' => $id,
            'AUTHOR_ID' => $authorId,
            'CREATED' => $createdDt,
            'BINDINGS' => $mapBindings,
        ]);
    } catch (\Throwable $e) {
        $skipped++;
        continue;
    }
    if ($entryId > 0) {
        $fixed[] = $id;
    }
}

    $allFixed = array_merge($allFixed, $fixed);
    $totalSkipped += $skipped;
} while (count($fixed) >= 500);

$DB->Query("UPDATE b_crm_act SET STATUS = '2' WHERE ORIGINATOR_ID = 'migration' AND COMPLETED = 'Y' AND STATUS = '3'");

$out = [
    'batches' => $batch,
    'fixed_count' => count($allFixed),
    'skipped_no_bindings_or_type' => $totalSkipped,
];
if (count($allFixed) > 40) {
    $out['fixed_activity_ids_head'] = array_slice($allFixed, 0, 20);
    $out['fixed_activity_ids_tail'] = array_slice($allFixed, -20);
} else {
    $out['fixed_activity_ids'] = $allFixed;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
