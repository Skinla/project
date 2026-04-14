<?php
/**
 * Тестовый скрипт: по строке из List_ID_Recipients вытаскивает ID группы и выводит ID сотрудников.
 *
 * Пример входа:
 *   [HRR5]*** group_hrr5***
 *
 * Ожидаемое:
 *   groupId = 5
 *   users: [1, 2, 3, ...]
 *
 * Как запускать (пример):
 *   /local/php_interface/handlers/wz_debug_recipients_group_users.php?value=%5BHRR5%5D***%20group_hrr5***
 *
 * или:
 *   /local/test/wz_debug_recipients_group_users.php?value=...
 */

declare(strict_types=1);

use Bitrix\Main\Context;
use Bitrix\Main\Loader;

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/prolog_admin_before.php')) {
	require($docRoot . '/bitrix/modules/main/include/prolog_admin_before.php');
} elseif ($docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/prolog_before.php')) {
	require($docRoot . '/bitrix/modules/main/include/prolog_before.php');
}

// Если параметры не доходят (как в админке бывает), дадим форму POST.
$wantHtml = (string)($_REQUEST['html'] ?? '') !== '';
header('Content-Type: ' . ($wantHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8');

/**
 * @param scalar|null $v
 */
function s($v): string
{
	return is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function parseGroupIds(string $raw): array
{
	$ids = [];

	// [HRR5] -> 5
	if (preg_match_all('/\[[a-z_]*?(\d+)\]/iu', $raw, $m) === 1 || !empty($m[1])) {
		foreach ($m[1] as $id) {
			$ids[] = (int)$id;
		}
	}

	// group_hrr5 -> 5, group_123 -> 123
	if (preg_match_all('/\bgroup_[a-z0-9_]*?(\d+)\b/iu', $raw, $m2) === 1 || !empty($m2[1])) {
		foreach ($m2[1] as $id) {
			$ids[] = (int)$id;
		}
	}

	// fallback: любые одиночные числа 1-6 цифр
	if (preg_match_all('/\b(\d{1,6})\b/u', $raw, $m3) === 1 || !empty($m3[1])) {
		foreach ($m3[1] as $id) {
			$ids[] = (int)$id;
		}
	}

	$ids = array_values(array_unique(array_filter($ids, static fn(int $v): bool => $v > 0)));
	sort($ids);
	return $ids;
}

function getUserIdsByUserGroupId(int $groupId): array
{
	if (!class_exists(\CUser::class)) {
		return [];
	}

	$ids = [];
	$by = 'id';
	$order = 'asc';
	$filter = [
		'ACTIVE' => 'Y',
		'GROUPS_ID' => [$groupId],
	];
	$params = [
		'FIELDS' => ['ID'],
	];

	$rs = \CUser::GetList($by, $order, $filter, $params);
	while ($u = $rs->Fetch()) {
		$uid = (int)($u['ID'] ?? 0);
		if ($uid > 0) {
			$ids[] = $uid;
		}
	}

	$ids = array_values(array_unique($ids));
	sort($ids);
	return $ids;
}

function getUserIdsBySonetGroupId(int $sonetGroupId): array
{
	if (!Loader::includeModule('socialnetwork')) {
		return [];
	}
	if (!class_exists(\CSocNetUserToGroup::class)) {
		return [];
	}

	$ids = [];
	$rs = \CSocNetUserToGroup::GetList(
		['USER_ID' => 'ASC'],
		[
			'GROUP_ID' => $sonetGroupId,
			'USER_ACTIVE' => 'Y',
		],
		false,
		false,
		['USER_ID', 'ROLE']
	);
	while ($row = $rs->Fetch()) {
		$role = (string)($row['ROLE'] ?? '');
		// исключаем "B" (banned) если встречается
		if ($role === 'B') {
			continue;
		}
		$uid = (int)($row['USER_ID'] ?? 0);
		if ($uid > 0) {
			$ids[] = $uid;
		}
	}

	$ids = array_values(array_unique($ids));
	sort($ids);
	return $ids;
}

$sample = '[HRR5]*** group_hrr5***';

function getUserIdsByDepartmentId(int $departmentId): array
{
	if (!class_exists(\CUser::class)) {
		return [];
	}

	$ids = [];
	$by = 'id';
	$order = 'asc';
	$filter = [
		'ACTIVE' => 'Y',
		'UF_DEPARTMENT' => $departmentId,
	];
	$params = [
		'FIELDS' => ['ID'],
		'SELECT' => ['UF_DEPARTMENT'],
	];

	$rs = \CUser::GetList($by, $order, $filter, $params);
	while ($u = $rs->Fetch()) {
		$uid = (int)($u['ID'] ?? 0);
		if ($uid > 0) {
			$ids[] = $uid;
		}
	}

	$ids = array_values(array_unique($ids));
	sort($ids);
	return $ids;
}

$req = Context::getCurrent()->getRequest();
$raw = (string)($req->get('payload') ?? '');
if ($raw === '') {
	$raw = (string)($req->getPost('payload') ?? '');
}
if ($raw === '') {
	$rawB64 = (string)($req->get('value_b64') ?? '');
	if ($rawB64 === '') {
		$rawB64 = (string)($req->getPost('value_b64') ?? '');
	}
	if ($rawB64 !== '') {
		$decoded = base64_decode($rawB64, true);
		if (is_string($decoded)) {
			$raw = $decoded;
		}
	}
}

if ($wantHtml) {
	echo '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:16px;">';
	echo '<h2 style="margin:0 0 10px 0;">WZ debug recipients: group → users</h2>';
	echo '<form method="post" style="margin:0 0 14px 0; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">';
	if (function_exists('bitrix_sessid_post')) {
		echo bitrix_sessid_post();
	}
	echo '<input type="hidden" name="html" value="1" />';
	echo '<label style="display:flex;flex-direction:column;gap:4px;">';
	echo '<span>payload</span>';
	$prefill = $raw !== '' ? $raw : $sample;
	echo '<input name="payload" value="' . htmlspecialchars($prefill, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="min-width:420px;padding:6px 8px;" />';
	echo '</label>';
	echo '<label style="display:flex;flex-direction:column;gap:4px;">';
	echo '<span>value_b64 (опционально)</span>';
	echo '<input name="value_b64" value="" style="min-width:420px;padding:6px 8px;" placeholder="Base64, если value не доходит" />';
	echo '</label>';
	echo '<button type="submit" style="padding:7px 12px;">Run</button>';
	echo '</form>';
	echo '<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">';
	echo 'RAW_REQUEST=' . s($_REQUEST ?? []) . "\n";
	echo 'RAW=' . s($raw) . "\n";
	echo '</pre>';
} else {
	echo "RAW_REQUEST=" . s($_REQUEST ?? []) . "\n";
	echo "RAW=" . s($raw) . "\n";
}

echo "RAW=" . s($raw) . "\n";

if (!Loader::includeModule('main')) {
	echo "ERR=main module not loaded\n";
	return;
}

$groupIds = parseGroupIds($raw);
echo ($wantHtml ? '<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">' : '');
echo "PARSED_GROUP_IDS=" . s($groupIds) . "\n\n";

if (empty($groupIds)) {
	echo "ERR=group id not found in input\n";
	echo "\nTIP=Если GET не работает, открой с ?html=1 и вставь строку в форму (POST).\n";
	echo "TIP=Или передай value_b64=base64(строки).\n";
	echo ($wantHtml ? '</pre></div>' : '');
	return;
}

foreach ($groupIds as $gid) {
	echo "== GROUP_ID={$gid} ==\n";

	// 1) Пробуем как "отдел" (структура компании): UF_DEPARTMENT содержит ID подразделения.
	$deptUserIds = getUserIdsByDepartmentId($gid);
	echo "DEPARTMENT_USERS_COUNT=" . count($deptUserIds) . "\n";
	echo "DEPARTMENT_USER_IDS=" . s($deptUserIds) . "\n";

	$userGroup = null;
	if (class_exists(\CGroup::class)) {
		$userGroup = \CGroup::GetByID($gid)->Fetch() ?: null;
	}

	if (is_array($userGroup)) {
		echo "USER_GROUP_FOUND=Y NAME=" . s($userGroup['NAME'] ?? '') . "\n";
		$uids = getUserIdsByUserGroupId($gid);
		echo "USER_IDS_COUNT=" . count($uids) . "\n";
		echo "USER_IDS=" . s($uids) . "\n\n";
		continue;
	}

	echo "USER_GROUP_FOUND=N\n";

	// fallback: соц.группа
	$sonetFound = false;
	if (Loader::includeModule('socialnetwork') && class_exists(\CSocNetGroup::class)) {
		$sonet = \CSocNetGroup::GetByID($gid);
		if (is_array($sonet)) {
			$sonetFound = true;
			echo "SONET_GROUP_FOUND=Y NAME=" . s($sonet['NAME'] ?? '') . "\n";
			$uids = getUserIdsBySonetGroupId($gid);
			echo "USER_IDS_COUNT=" . count($uids) . "\n";
			echo "USER_IDS=" . s($uids) . "\n\n";
		}
	}
	if (!$sonetFound) {
		echo "SONET_GROUP_FOUND=N\n\n";
	}
}

echo ($wantHtml ? '</pre></div>' : '');

