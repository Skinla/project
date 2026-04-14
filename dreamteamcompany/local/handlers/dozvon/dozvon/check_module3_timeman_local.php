<?php
/**
 * Проверка статуса рабочего дня операторов через внутренний API (CTimeManUser).
 * Работает на сервере Bitrix — не требует webhook.
 *
 * Запуск: php check_module3_timeman_local.php [DOCUMENT_ROOT] [uid1,uid2,...]
 * Пример: php check_module3_timeman_local.php /var/www/bitrix 40,45,46,47,50,49219
 *
 * Или в браузере: /local/handlers/dozvon/check_module3_timeman_local.php?uids=40,45,46,47,50,49219
 */

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? $argv[1] ?? '';
if (empty($_SERVER['DOCUMENT_ROOT']) || !is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php')) {
    die("Usage: php check_module3_timeman_local.php /path/to/site [uid1,uid2,...]\n");
}

define('NOT_CHECK_PERMISSIONS', true);
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$userIds = [];
if (!empty($argv[2])) {
    $userIds = array_map('intval', array_filter(explode(',', $argv[2])));
} elseif (!empty($_GET['uids'])) {
    $userIds = array_map('intval', array_filter(explode(',', $_GET['uids'])));
}
if ($userIds === []) {
    $userIds = [40, 45, 46, 47, 50, 49219];
}

\Bitrix\Main\Loader::includeModule('timeman');

$getUserName = function (int $uid): string {
    $u = CUser::GetByID($uid)->Fetch();
    if (!is_array($u)) return '';
    return trim((string)($u['NAME'] ?? '') . ' ' . (string)($u['LAST_NAME'] ?? ''));
};

header('Content-Type: text/plain; charset=utf-8');
echo "=== Проверка рабочего дня операторов (CTimeManUser::State) ===\n";
echo "Проверяем: " . implode(', ', $userIds) . "\n\n";

$okStates = ['OPENED', 'PAUSED'];

foreach ($userIds as $uid) {
    $name = $getUserName($uid);
    $label = $name !== '' ? "{$uid} ({$name})" : (string)$uid;

    $state = null;
    if (class_exists('CTimeManUser')) {
        $tmUser = new CTimeManUser($uid);
        $state = method_exists($tmUser, 'State') ? $tmUser->State() : null;
    }

    if ($state === null) {
        echo "{$label}: State=null (модуль timeman недоступен или метод не вернул значение)\n";
        continue;
    }

    $accepted = in_array($state, $okStates, true);
    $verdict = $accepted ? 'OK (модуль 3 примет)' : 'workday_not_started';
    echo "{$label}: State={$state} — {$verdict}\n";
}

echo "\n=== Конец проверки ===\n";
echo "Модуль 3 принимает только OPENED и PAUSED.\n";
