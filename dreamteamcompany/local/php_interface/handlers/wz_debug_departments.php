<?php
/**
 * Debug: вывести список отделов (подразделений) из структуры компании (intranet).
 *
 * Запуск:
 * - /local/php_interface/handlers/wz_debug_departments.php
 * - /local/php_interface/handlers/wz_debug_departments.php?html=1
 * - /local/php_interface/handlers/wz_debug_departments.php?html=1&q=%D0%93%D1%80%D1%83%D0%BF%D0%BF%D0%B0
 * - /local/php_interface/handlers/wz_debug_departments.php?html=1&active_only=0
 * - /local/php_interface/handlers/wz_debug_departments.php?html=1&iblock_id=12
 */

declare(strict_types=1);

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

$wantHtml = (string)($_REQUEST['html'] ?? '') !== '';
header('Content-Type: ' . ($wantHtml ? 'text/html' : 'text/plain') . '; charset=UTF-8');

/** @var array<string, mixed> $request */
$request = $_REQUEST ?? [];
$q = trim((string)($request['q'] ?? ''));
$activeOnly = (string)($request['active_only'] ?? '1') !== '0';
$iblockIdParam = (int)($request['iblock_id'] ?? 0);

function out($s): void
{
	echo $s;
}

function h(string $s): string
{
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function contains(string $haystack, string $needle): bool
{
	if ($needle === '') return true;
	if (function_exists('mb_stripos')) {
		return mb_stripos($haystack, $needle) !== false;
	}
	return stripos($haystack, $needle) !== false;
}

if (!Loader::includeModule('main')) {
	out("ERR=main module not loaded\n");
	return;
}

// Отделы обычно хранятся как разделы инфоблока структуры компании (модуль intranet + iblock)
$hasIblock = Loader::includeModule('iblock');
$hasIntranet = Loader::includeModule('intranet');

if (!$hasIblock) {
	out("ERR=iblock module not loaded\n");
	return;
}
if (!$hasIntranet) {
	out("ERR=intranet module not loaded\n");
	return;
}

// В коробке Bitrix24 ID инфоблока структуры обычно лежит в опции intranet: iblock_structure
$iblockId = $iblockIdParam > 0 ? $iblockIdParam : 0;
if (class_exists(\COption::class)) {
	$iblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
}

if ($iblockId <= 0) {
	// fallback: попробуем найти по типу/коду (если доступно)
	$iblock = \CIBlock::GetList(
		['ID' => 'ASC'],
		[
			'ACTIVE' => 'Y',
			'TYPE' => 'structure',
		],
		true
	)->Fetch();
	if (is_array($iblock)) {
		$iblockId = (int)($iblock['ID'] ?? 0);
	}
}

if ($iblockId <= 0) {
	out("ERR=cannot resolve intranet structure iblock id\n");
	return;
}

if ($wantHtml) {
	out('<div style="font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:16px;">');
	out('<h2 style="margin:0 0 10px 0;">Departments (структура компании)</h2>');
	out('<div style="margin:0 0 10px 0;color:#444;">IBLOCK_ID=' . h((string)$iblockId) . ', active_only=' . h($activeOnly ? '1' : '0') . ', q=' . h($q) . '</div>');
	out('<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">');
} else {
	out("IBLOCK_ID={$iblockId} active_only=" . ($activeOnly ? '1' : '0') . " q=" . $q . "\n";
}

// Выведем все разделы (отделы)
$sections = [];
$rs = \CIBlockSection::GetList(
	['LEFT_MARGIN' => 'ASC'],
	[
		'IBLOCK_ID' => $iblockId,
	],
	false,
	['ID', 'NAME', 'ACTIVE', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID', 'LEFT_MARGIN', 'RIGHT_MARGIN']
);
while ($row = $rs->Fetch()) {
	$sections[] = $row;
}

// Построим путь (chain) по родителям
$byId = [];
foreach ($sections as $s) {
	$byId[(int)$s['ID']] = $s;
}

foreach ($sections as $s) {
	$id = (int)($s['ID'] ?? 0);
	$parent = (int)($s['IBLOCK_SECTION_ID'] ?? 0);
	$depth = (int)($s['DEPTH_LEVEL'] ?? 0);
	$name = (string)($s['NAME'] ?? '');
	$active = (string)($s['ACTIVE'] ?? 'Y');

	$path = [$name];
	$p = $parent;
	$guard = 0;
	while ($p > 0 && isset($byId[$p]) && $guard < 20) {
		$path[] = (string)($byId[$p]['NAME'] ?? '');
		$p = (int)($byId[$p]['IBLOCK_SECTION_ID'] ?? 0);
		$guard++;
	}
	$path = array_reverse($path);
	$full = implode(' / ', $path);

	if ($activeOnly && $active !== 'Y') {
		continue;
	}
	if ($q !== '' && !contains($name, $q) && !contains($full, $q)) {
		continue;
	}

	$indent = str_repeat('  ', max(0, $depth - 1));
	$inactiveMark = $active === 'Y' ? '' : ' [INACTIVE]';
	out($indent . "ID={$id} DEPTH={$depth} NAME={$name}{$inactiveMark} PATH={$full}\n");
}

if ($wantHtml) {
	out('</pre></div>');
}

