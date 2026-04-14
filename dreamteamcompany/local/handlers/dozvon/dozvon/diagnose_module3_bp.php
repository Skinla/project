<?php
/**
 * Диагностика логики модуля 3 (как в БП).
 * Запуск на сервере Bitrix: php diagnose_module3_bp.php [DOCUMENT_ROOT]
 *   или в браузере: /local/handlers/dozvon/diagnose_module3_bp.php
 *
 * Выполняет те же запросы, что и bp_module3_process_queue, и выводит результат.
 */

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $argv[1] ?? '';
if (empty($_SERVER['DOCUMENT_ROOT']) || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
    die("Usage: php diagnose_module3_bp.php /path/to/site\n");
}

define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule('iblock');

$attemptListId = 131;
$masterListId = 130;

date_default_timezone_set('Europe/Moscow');
$nowBitrix = date('d.m.Y H:i:s');

$getStatusEnumId = function (int $listId, string $statusValue): ?int {
    $res = CIBlockProperty::GetList([], ['IBLOCK_ID' => $listId, 'CODE' => 'STATUS']);
    if (!$res || !($p = $res->Fetch()) || (string)($p['PROPERTY_TYPE'] ?? '') !== 'L') {
        return null;
    }
    $pid = (int)$p['ID'];
    $e = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pid, 'VALUE' => $statusValue])->Fetch();
    if ($e) {
        return (int)$e['ID'];
    }
    $e = CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pid, 'XML_ID' => $statusValue])->Fetch();
    return $e ? (int)$e['ID'] : null;
};

$getListValue = static function (array $prop): string {
    return mb_strtolower(trim((string)($prop['VALUE_XML_ID'] ?? $prop['VALUE_ENUM'] ?? $prop['VALUE'] ?? '')));
};

$readyEnumId = $getStatusEnumId($attemptListId, 'ready') ?? 1182;
$plannedEnumId = $getStatusEnumId($attemptListId, 'planned') ?? 1181;

$getElement = function (int $iblockId, int $elementId): ?array {
    $res = CIBlockElement::GetByID($elementId);
    if (!$res || !($ob = $res->GetNextElement())) {
        return null;
    }
    $fields = $ob->GetFields();
    if ((int)($fields['IBLOCK_ID'] ?? 0) !== $iblockId) {
        return null;
    }
    return ['props' => $ob->GetProperties(), 'DATE_CREATE' => $fields['DATE_CREATE'] ?? ''];
};

echo "=== Диагностика модуля 3 (логика БП) ===\n";
echo "Время (MSK): {$nowBitrix}\n";
echo "ready enum: {$readyEnumId}, planned enum: {$plannedEnumId}\n\n";

// 1. Запрос ready
$res = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    [
        'IBLOCK_ID' => $attemptListId,
        'CHECK_PERMISSIONS' => 'N',
        'PROPERTY_STATUS' => $readyEnumId,
        '<=PROPERTY_SCHEDULED_AT' => $nowBitrix,
    ],
    false,
    ['nTopCount' => 100]
);
$readyCount = 0;
while ($ob = $res->GetNextElement()) {
    $readyCount++;
}
echo "1. Попытки ready + SCHEDULED_AT<=now: {$readyCount}\n";

// 2. Запрос planned
$resPlanned = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    [
        'IBLOCK_ID' => $attemptListId,
        'CHECK_PERMISSIONS' => 'N',
        'PROPERTY_STATUS' => $plannedEnumId,
        '<=PROPERTY_SCHEDULED_AT' => $nowBitrix,
    ],
    false,
    ['nTopCount' => 50]
);
$plannedPassed = [];
$plannedTotal = 0;
while ($obP = $resPlanned->GetNextElement()) {
    $plannedTotal++;
    $f = $obP->GetFields();
    $p = $obP->GetProperties();
    $aid = (int)$f['ID'];
    $mid = (int)preg_replace('/\D+/', '', (string)($p['MASTER_ELEMENT_ID']['VALUE'] ?? ''));
    if ($mid <= 0) {
        continue;
    }
    $m = $getElement($masterListId, $mid);
    if (!$m) {
        continue;
    }
    $ctrl = $getListValue($m['props']['CALLING_CONTROL'] ?? []);
    $mst = $getListValue($m['props']['STATUS'] ?? []);
    if ($ctrl !== '' && $ctrl !== 'active') {
        continue;
    }
    if (in_array($mst, ['completed', 'cancelled'], true)) {
        continue;
    }
    $plannedPassed[] = ['id' => $aid, 'master' => $mid, 'control' => $ctrl, 'status' => $mst];
}
echo "2. Попытки planned + SCHEDULED_AT<=now: {$plannedTotal} всего\n";
echo "   Прошедших фильтр мастера (active, не completed): " . count($plannedPassed) . "\n";
if (!empty($plannedPassed)) {
    $first = $plannedPassed[0];
    echo "   Первая: attempt_id={$first['id']} master={$first['master']} control={$first['control']} status={$first['status']}\n";
}

echo "\n=== Итог ===\n";
if ($readyCount > 0 || count($plannedPassed) > 0) {
    echo "БП должен найти попытки. Если всё ещё 'no attempts' — проверьте, что код в конструкторе БП обновлён.\n";
} else {
    echo "Попыток для обработки нет. Проверьте: SCHEDULED_AT, статус мастера (CALLING_CONTROL=active).\n";
}
