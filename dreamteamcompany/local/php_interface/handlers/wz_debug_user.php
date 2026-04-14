<?php
/**
 * Debug: вывести данные пользователя и его отделы (UF_DEPARTMENT).
 *
 * Запуск:
 * - /local/php_interface/handlers/wz_debug_user.php?user_id=1
 * - /local/php_interface/handlers/wz_debug_user.php?user_id=1&html=1
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

$req = Context::getCurrent()->getRequest();
$wantHtml = (string)($req->get('html') ?? '') !== '';
$userId = (int)($req->get('user_id') ?? 0);
if ($userId <= 0) {
	$userId = (int)($req->getPost('user_id') ?? 0);
}

header('Content-Type: ' . ($wantHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8');

function h(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dumpLine(string $label, $value, bool $html): void
{
	$s = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if ($html) {
		echo h($label) . '=' . h($s) . "<br/>\n";
	} else {
		echo $label . '=' . $s . "\n";
	}
}

if (!Loader::includeModule('main')) {
	dumpLine('ERR', 'main module not loaded', $wantHtml);
	return;
}

if ($wantHtml) {
	echo '<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:16px;">';
	echo '<h2 style="margin:0 0 10px 0;">WZ debug user</h2>';
	echo '<form method="post" style="margin:0 0 14px 0; display:flex; gap:12px; align-items:flex-end;">';
	if (function_exists('bitrix_sessid_post')) {
		echo bitrix_sessid_post();
	}
	echo '<input type="hidden" name="html" value="1" />';
	echo '<label style="display:flex;flex-direction:column;gap:4px;">';
	echo '<span>user_id</span>';
	echo '<input name="user_id" value="' . h((string)$userId) . '" style="min-width:140px;padding:6px 8px;" />';
	echo '</label>';
	echo '<button type="submit" style="padding:7px 12px;">Run</button>';
	echo '</form>';
	echo '<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">';
}

if ($userId <= 0) {
	dumpLine('ERR', 'pass user_id', $wantHtml);
	if ($wantHtml) echo '</pre></div>';
	return;
}

$user = \CUser::GetByID($userId)->Fetch();
if (!is_array($user)) {
	dumpLine('ERR', 'user not found', $wantHtml);
	if ($wantHtml) echo '</pre></div>';
	return;
}

// Основные поля + UF_DEPARTMENT
$out = [
	'ID' => $user['ID'] ?? null,
	'LOGIN' => $user['LOGIN'] ?? null,
	'NAME' => $user['NAME'] ?? null,
	'LAST_NAME' => $user['LAST_NAME'] ?? null,
	'ACTIVE' => $user['ACTIVE'] ?? null,
	'EMAIL' => $user['EMAIL'] ?? null,
	'UF_DEPARTMENT' => $user['UF_DEPARTMENT'] ?? null,
];

dumpLine('USER', $out, $wantHtml);

// Попробуем человекочитаемые названия отделов
$deptNames = [];
if (Loader::includeModule('intranet') && Loader::includeModule('iblock')) {
	$iblockId = 0;
	if (class_exists(\COption::class)) {
		$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
	}
	$deptIds = $user['UF_DEPARTMENT'] ?? [];
	if (!is_array($deptIds)) {
		$deptIds = $deptIds ? [(int)$deptIds] : [];
	}
	$deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));

	if ($iblockId > 0 && !empty($deptIds)) {
		$rs = \CIBlockSection::GetList(
			['ID' => 'ASC'],
			['IBLOCK_ID' => $iblockId, 'ID' => $deptIds],
			false,
			['ID', 'NAME']
		);
		while ($row = $rs->Fetch()) {
			$deptNames[] = [
				'ID' => (int)($row['ID'] ?? 0),
				'NAME' => (string)($row['NAME'] ?? ''),
			];
		}
	}
}

dumpLine('DEPARTMENTS', $deptNames, $wantHtml);

if ($wantHtml) {
	echo "\n</pre></div>";
}

