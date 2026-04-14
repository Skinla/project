<?php
/**
 * Диагностический скрипт для Bitrix (коробка).
 *
 * Назначение:
 * - по lead_id (и опционально phone) показать, какие OL-сессии/чаты создаются;
 * - подсветить сессии, которые реально биндятся к этому лиду через CRM_ACTIVITY_ID;
 * - показать последние сообщения в найденных чатах и проверить маркер SYSTEM WZ.
 *
 * Куда класть:
 * - рекомендуется: /bitrix/admin/wz_debug_ol_find_chat.php
 *   (тогда будет админский пролог и проверка прав)
 *
 * Как запускать:
 * - /bitrix/admin/wz_debug_ol_find_chat.php?lead_id=568365&phone=%2B79165970992
 */
declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

@ini_set('display_errors', '1');
@ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$docRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$isAdminProlog = $docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/prolog_admin_before.php');

if ($isAdminProlog) {
	require($docRoot . '/bitrix/modules/main/include/prolog_admin_before.php');
} elseif ($docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/prolog_before.php')) {
	require($docRoot . '/bitrix/modules/main/include/prolog_before.php');
}

header('Content-Type: text/html; charset=UTF-8');

/**
 * @param scalar|null $v
 */
function h($v): string
{
	$s = is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	if (function_exists('htmlspecialcharsbx')) {
		/** @var callable $f */
		$f = 'htmlspecialcharsbx';
		return $f($s);
	}
	return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pre(string $title, $value): void
{
	echo '<h3 style="margin:16px 0 6px 0;">' . h($title) . '</h3>';
	echo '<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">' . h(print_r($value, true)) . '</pre>';
}

function section(string $title): void
{
	echo '<h2 style="margin:22px 0 8px 0;">' . h($title) . '</h2>';
}

function startsWith(string $s, string $prefix): bool
{
	return $prefix === '' || strncmp($s, $prefix, strlen($prefix)) === 0;
}

/**
 * Классификация SYSTEM WZ сообщений для BP-логики.
 * Возвращает:
 * - type: send_error | client_deleted | client_renamed_or_deleted | other_wz
 * - reason: краткая причина (1-2 строки)
 */
function classifyWz(string $text): array
{
	$normalized = trim($text);
	if ($normalized === '') {
		return ['type' => null, 'reason' => null];
	}

	// Вытащим “хвост” после маркера === ... WZ ===
	$reason = $normalized;
	if (preg_match('/===\s*(?:SYSTEM\s*WZ|СИСТЕМА\s*WZ)\s*===\s*(.+)$/iu', $normalized, $m) === 1) {
		$reason = trim((string)$m[1]);
	}

	$low = mb_strtolower($reason);
	if (mb_strpos($low, 'ошибка отправки') !== false) {
		return ['type' => 'send_error', 'reason' => $reason];
	}
	if (mb_strpos($low, 'клиент удалил аккаунт') !== false) {
		return ['type' => 'client_deleted', 'reason' => $reason];
	}
	if (
		mb_strpos($low, 'клиент сменил имя пользователя') !== false
		|| mb_strpos($low, 'сменил имя пользователя') !== false
	) {
		// Часто формулировка: “Клиент сменил имя пользователя или удалил аккаунт.”
		return ['type' => 'client_renamed_or_deleted', 'reason' => $reason];
	}

	// Любые другие WZ-системные маркеры
	return ['type' => 'other_wz', 'reason' => $reason];
}

$request = Context::getCurrent()->getRequest();
$rawGet = $_GET ?? [];
$rawRequest = $_REQUEST ?? [];
/** @var array<string, mixed> $rawPost */
$rawPost = $_POST ?? [];
/** @var array<string, mixed> $server */
$server = $_SERVER ?? [];

// Принимаем несколько алиасов, потому что в админке иногда удобнее передавать ?id=
$leadId = (int)($request->get('lead_id') ?? 0);
if ($leadId <= 0) {
	$leadId = (int)($request->get('id') ?? 0);
}
if ($leadId <= 0) {
	$leadId = (int)($request->get('lead') ?? 0);
}
if ($leadId <= 0) {
	$leadId = (int)($request->get('leadId') ?? 0);
}
if ($leadId <= 0 && isset($rawGet['lead_id'])) {
	$leadId = (int)$rawGet['lead_id'];
}
if ($leadId <= 0 && isset($rawGet['id'])) {
	$leadId = (int)$rawGet['id'];
}
if ($leadId <= 0 && isset($rawRequest['lead_id'])) {
	$leadId = (int)$rawRequest['lead_id'];
}
if ($leadId <= 0 && isset($rawRequest['id'])) {
	$leadId = (int)$rawRequest['id'];
}

$phone = trim((string)$request->get('phone'));
$channel = trim((string)($request->get('channel') ?? ''));
$verbose = (string)($request->get('verbose') ?? '') !== '';
$sinceMinutes = (int)($request->get('since_minutes') ?? 0);
$limit = (int)$request->get('limit');
if ($limit <= 0 || $limit > 200) {
	$limit = 50;
}

echo '<div style="font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding: 16px;">';
echo '<h1 style="margin:0 0 12px 0;">WZ debug: OL/IM поиск чата</h1>';
echo '<div style="margin: 0 0 14px 0; color:#444;">lead_id=' . h($leadId) . ', phone=' . h($phone) . ', channel=' . h($channel) . ', limit=' . h($limit) . '</div>';
if ($verbose) {
	pre('Raw $_GET', $rawGet);
	pre('Raw $_REQUEST', $rawRequest);
	pre('Raw $_POST', $rawPost);
	pre('$_SERVER (uri/query/method)', [
		'REQUEST_METHOD' => $server['REQUEST_METHOD'] ?? null,
		'REQUEST_URI' => $server['REQUEST_URI'] ?? null,
		'QUERY_STRING' => $server['QUERY_STRING'] ?? null,
		'SCRIPT_NAME' => $server['SCRIPT_NAME'] ?? null,
		'PHP_SELF' => $server['PHP_SELF'] ?? null,
	]);
}

// Если GET/REQUEST не работают (бывает из-за включения файла из админки), дадим форму POST.
echo '<div style="margin: 14px 0; padding: 12px; border: 1px solid #e2e3e5; background: #f8f9fa;">';
echo '<div style="margin:0 0 8px 0; font-weight:600;">Запуск через форму (POST)</div>';
echo '<form method="post">';
if (function_exists('bitrix_sessid_post')) {
	/** @var callable $sess */
	$sess = 'bitrix_sessid_post';
	echo $sess();
}
echo '<div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">';
echo '<label style="display:flex; flex-direction:column; gap:4px;">';
echo '<span>lead_id</span>';
echo '<input name="lead_id" value="' . h($leadId > 0 ? $leadId : '') . '" style="padding:6px 8px; min-width:160px;" />';
echo '</label>';
echo '<label style="display:flex; flex-direction:column; gap:4px;">';
echo '<span>phone</span>';
echo '<input name="phone" value="' . h($phone) . '" style="padding:6px 8px; min-width:220px;" placeholder="+7916..." />';
echo '</label>';
echo '<label style="display:flex; flex-direction:column; gap:4px;">';
echo '<span>channel</span>';
echo '<input name="channel" value="' . h($channel) . '" style="padding:6px 8px; min-width:160px;" placeholder="whatsapp|telegram|max" />';
echo '</label>';
echo '<label style="display:flex; flex-direction:column; gap:4px;">';
echo '<span>since_minutes</span>';
echo '<input name="since_minutes" value="' . h((string)$sinceMinutes) . '" style="padding:6px 8px; min-width:120px;" placeholder="0 = all time; 5 = last 5 minutes" />';
echo '</label>';
echo '<label style="display:flex; flex-direction:column; gap:4px;">';
echo '<span>limit</span>';
echo '<input name="limit" value="' . h($limit) . '" style="padding:6px 8px; min-width:90px;" />';
echo '</label>';
echo '<label style="display:flex; align-items:center; gap:8px; padding:6px 0;">';
echo '<input type="checkbox" name="verbose" value="1"' . ($verbose ? ' checked' : '') . ' />';
echo '<span>verbose (много вывода, медленнее)</span>';
echo '</label>';
echo '<button type="submit" style="padding:7px 12px;">Run</button>';
echo '</div>';
echo '</form>';
echo '<div style="margin-top:8px; color:#6c757d;">Подсказка: since_minutes=0 означает поиск "за всё время". Для боевого BP обычно достаточно 5–10 минут.</div>';
echo '</div>';

// Подхват из POST (в админке GET может быть пустой)
$postLeadId = (int)($request->getPost('lead_id') ?? 0);
if ($postLeadId > 0) {
	$leadId = $postLeadId;
}
$postPhone = trim((string)($request->getPost('phone') ?? ''));
if ($postPhone !== '') {
	$phone = $postPhone;
}
$postChannel = trim((string)($request->getPost('channel') ?? ''));
if ($postChannel !== '') {
	$channel = $postChannel;
}
// since_minutes: важно различать "0" (all time) и пусто. Пусто трактуем как 0.
$postSinceRaw = (string)($request->getPost('since_minutes') ?? '');
if ($postSinceRaw !== '') {
	$sinceMinutes = (int)$postSinceRaw;
} elseif ($request->isPost()) {
	$sinceMinutes = 0;
}
$verbose = (string)($request->getPost('verbose') ?? ($verbose ? '1' : '')) !== '';
$postLimit = (int)($request->getPost('limit') ?? 0);
if ($postLimit > 0 && $postLimit <= 200) {
	$limit = $postLimit;
}

if ($isAdminProlog) {
	// Минимальная защита: только админы
	if (!defined('ADMIN_SECTION')) {
		define('ADMIN_SECTION', true);
	}
	/** @global CUser $USER */
	global $USER;
	if (!is_object($USER) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
		echo '<div style="padding:12px;border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;">Нет прав (нужен админ).</div>';
		echo '</div>';
		if ($isAdminProlog && $docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/epilog_admin.php')) {
			require($docRoot . '/bitrix/modules/main/include/epilog_admin.php');
		}
		return;
	}
}

if ($leadId <= 0) {
	echo '<div style="padding:12px;border:1px solid #ffeeba;background:#fff3cd;color:#856404;">Нужно передать lead_id.</div>';
	echo '</div>';
	if ($isAdminProlog && $docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/epilog_admin.php')) {
		require($docRoot . '/bitrix/modules/main/include/epilog_admin.php');
	}
	return;
}

section('Модули');
$modules = [
	'main',
	'crm',
	'im',
	'imopenlines',
	'imconnector',
	'bizproc',
];
$loaded = [];
foreach ($modules as $m) {
	$loaded[$m] = Loader::includeModule($m) ? 'ok' : 'no';
}
if ($verbose) {
	pre('Loader::includeModule()', $loaded);
}

// =========================
// FAST PATH (channel задан)
// =========================
$selectedChannel = $channel !== '' ? mb_strtolower($channel) : '';
if ($selectedChannel !== '' && $phone !== '') {
	// В "быстром режиме" выводим только результат, пригодный для BP.
	// verbose=1 включает подробности.
	if ($verbose) {
		section('Быстрый режим (channel задан): чат + ошибка');
	}

	// Окно поиска:
	// - since_minutes <= 0  => "за всё время" (без фильтра по DATE_CREATE)
	// - since_minutes > 0   => фильтр "не старше X минут"
	$sinceDate = null;
	if ($sinceMinutes > 0) {
		$sinceTs = time() - ($sinceMinutes * 60);
		$sinceDate = DateTime::createFromTimestamp($sinceTs);
	}

	$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
	$variants = [];
	if ($phoneDigits !== '') {
		$variants[] = $phoneDigits;
		if (mb_strlen($phoneDigits) === 11 && startsWith($phoneDigits, '7')) {
			$variants[] = '8' . mb_substr($phoneDigits, 1);
		}
		if (mb_strlen($phoneDigits) === 11 && startsWith($phoneDigits, '8')) {
			$variants[] = '7' . mb_substr($phoneDigits, 1);
		}
	}
	$variants = array_values(array_unique(array_filter($variants)));

	$targetNeedle = '/chat/' . $selectedChannel . '/';
	$best = null; // найденное wazzup-сообщение, дающее CHAT_ID

	// Супер-быстрый путь (когда есть окно времени):
	// ищем ровно подстроку "/chat/<channel>/<phoneDigits>/" в IM сообщениях.
	// Это не зависит от количества OL-сессий и обычно срабатывает мгновенно.
	if (
		$best === null
		&& $sinceDate instanceof DateTime
		&& $phoneDigits !== ''
		&& class_exists(\Bitrix\Im\Model\MessageTable::class)
	) {
		$needle = $targetNeedle . $phoneDigits . '/';
		try {
			$row = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
				'filter' => [
					'>=DATE_CREATE' => $sinceDate,
					'%MESSAGE' => $needle,
				],
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			])->fetch();
			if (is_array($row)) {
				// Доп. страховка: это точно wazzup-линк
				$text = (string)($row['MESSAGE'] ?? '');
				if ($text !== '' && mb_strpos($text, 'wazzup24.com') !== false) {
					$best = $row;
				}
			}
		} catch (Throwable $e) {
			// молча, уйдём в другие стратегии
		}
	}

	// Оптимизация: НЕ делаем глобальный LIKE по сообщениям (дорого).
	// Вместо этого берём последние OL-сессии по DATE_CREATE (индекс), фильтруем по SOURCE в PHP,
	// и уже в их чатах смотрим последние сообщения (малый объём).
	if (class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class) && class_exists(\Bitrix\Im\Model\MessageTable::class)) {
		// Чем шире окно, тем больше нужно смотреть сессий/чатов.
		// Для "за всё время" оставляем предохранители (иначе можно сильно нагрузить БД).
		$sessionLimit = 200;
		$chatCandidatesLimit = 12;
		$messagesPerChat = 15;
		if ($sinceMinutes === 0) {
			$sessionLimit = 2000;
			$chatCandidatesLimit = 60;
			$messagesPerChat = 30;
		} elseif ($sinceMinutes >= 240) { // 4 часа и больше
			$sessionLimit = 800;
			$chatCandidatesLimit = 60;
			$messagesPerChat = 25;
		} elseif ($sinceMinutes > 60) {
			$sessionLimit = 500;
			$chatCandidatesLimit = 40;
			$messagesPerChat = 20;
		}

		$sessionFilter = [
			'>CHAT_ID' => 0,
		];
		if ($sinceDate instanceof DateTime) {
			$sessionFilter['>=DATE_CREATE'] = $sinceDate;
		}

		$sessions = \Bitrix\ImOpenLines\Model\SessionTable::getList([
			'select' => ['ID', 'CHAT_ID', 'SOURCE', 'DATE_CREATE'],
			'filter' => $sessionFilter,
			'order' => ['ID' => 'DESC'],
			'limit' => $sessionLimit,
		])->fetchAll();

		$sourcePrefix = 'wz_' . $selectedChannel . '_';
		$candidates = [];
		foreach ($sessions as $s) {
			$src = (string)($s['SOURCE'] ?? '');
			if ($src !== '' && startsWith($src, $sourcePrefix)) {
				$candidates[] = $s;
			}
		}

		// Просматриваем несколько самых свежих чатов этого канала
		$candidates = array_slice($candidates, 0, $chatCandidatesLimit);

		foreach ($candidates as $s) {
			$cid = (int)($s['CHAT_ID'] ?? 0);
			if ($cid <= 0) continue;

			$recent = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
				'filter' => ['=CHAT_ID' => $cid],
				'order' => ['ID' => 'DESC'],
				'limit' => $messagesPerChat,
			])->fetchAll();

			foreach ($recent as $m) {
				$text = (string)($m['MESSAGE'] ?? '');
				if ($text === '') continue;
				if (mb_strpos($text, 'wazzup24.com') === false) continue;
				if (mb_strpos($text, $targetNeedle) === false) continue;

				$matchedPhone = false;
				foreach ($variants as $v) {
					if ($v !== '' && mb_strpos($text, $v) !== false) {
						$matchedPhone = true;
						break;
					}
				}
				if (!$matchedPhone) continue;

				$best = $m;
				break 2;
			}
		}
	} elseif (class_exists(\Bitrix\Im\Model\MessageTable::class)) {
		// Fallback: старый способ (глобальный LIKE). Оставляем только как резерв.
		foreach ($variants as $v) {
			$rows = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
				'filter' => [
					'>=DATE_CREATE' => $sinceDate,
					'%MESSAGE' => $v,
				],
				'order' => ['ID' => 'DESC'],
				'limit' => 30,
			])->fetchAll();

			foreach ($rows as $m) {
				$text = (string)($m['MESSAGE'] ?? '');
				if ($text === '') continue;
				if (mb_strpos($text, 'wazzup24.com') === false) continue;
				if (mb_strpos($text, $targetNeedle) === false) continue;
				$best = $m;
				break 2;
			}
		}
	}

	$chatId = $best ? (int)($best['CHAT_ID'] ?? 0) : 0;
	$markerRegex = '/(^|\s)===[\s\S]*(SYSTEM\s*WZ|СИСТЕМА\s*WZ)[\s\S]*===/iu';
	$errorContains = 'Ошибка отправки';

	$hasWzMarker = false;
	$hasSendError = false;
	$hit = null;
	$wzType = null;
	$wzReason = null;
	$sessionRow = null;

	if ($chatId > 0 && class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class)) {
		$sessionRow = \Bitrix\ImOpenLines\Model\SessionTable::getList([
			'select' => ['ID', 'CHAT_ID', 'SOURCE', 'USER_CODE', 'CRM_ACTIVITY_ID', 'DATE_CREATE', 'DATE_LAST_MESSAGE', 'CLOSED'],
			'filter' => ['=CHAT_ID' => $chatId],
			'order' => ['ID' => 'DESC'],
			'limit' => 1,
		])->fetch();
	}

	if ($chatId > 0 && class_exists(\Bitrix\Im\Model\MessageTable::class)) {
		$recent = \Bitrix\Im\Model\MessageTable::getList([
			'select' => ['ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
			'filter' => ['=CHAT_ID' => $chatId],
			'order' => ['ID' => 'DESC'],
			'limit' => 50,
		])->fetchAll();

		foreach ($recent as $m) {
			$text = (string)($m['MESSAGE'] ?? '');
			$isMarker = $text !== '' && preg_match($markerRegex, $text) === 1;
			$isSendError = $text !== '' && (mb_strpos($text, $errorContains) !== false);
			if ($isMarker) $hasWzMarker = true;
			if ($isSendError) $hasSendError = true;
			if ($hit === null && ($isMarker || $isSendError)) {
				$wz = $isMarker ? classifyWz($text) : ['type' => null, 'reason' => null];
				$wzType = $wz['type'];
				$wzReason = $wz['reason'];
				$hit = [
					'ID' => $m['ID'] ?? null,
					'AUTHOR_ID' => $m['AUTHOR_ID'] ?? null,
					'DATE_CREATE' => $m['DATE_CREATE'] ?? null,
					'markerMatch' => $isMarker,
					'containsSendError' => $isSendError,
					'MESSAGE' => mb_substr($text, 0, 1200),
				];
			}
		}
	}

	// Флаг для BP: стоит ли переключаться на следующий канал
	// (по умолчанию: любое SYSTEM WZ считаем фейлом шага; при желании можно ужесточить до send_error)
	$shouldFallback = $chatId <= 0 || $hasWzMarker || $hasSendError;

	// Минимальный вывод (то, что ты просил): 2 состояния + параметры для BP.
	// BP_STATUS: NO_CHAT | ERROR | OK
	$bpStatus = 'OK';
	$bpMessage = 'чат создан, SYSTEM WZ не найден';
	if ($chatId <= 0) {
		$bpStatus = 'NO_CHAT';
		$bpMessage = 'чат не создан (нет wazzup-ссылки по channel+phone в окне поиска)';
	} elseif ($hasWzMarker || $hasSendError) {
		$bpStatus = 'ERROR';
		$bpMessage = $wzReason ? ('=== SYSTEM WZ === ' . $wzReason) : 'обнаружен SYSTEM WZ';
	}

	echo '<pre style="padding:12px;border:1px solid #ddd;background:#fafafa;white-space:pre-wrap;">';
	echo h('BP_STATUS=' . $bpStatus) . "\n";
	echo h('CHANNEL=' . $selectedChannel) . "\n";
	echo h('CHAT_ID=' . ($chatId > 0 ? (string)$chatId : '')) . "\n";
	echo h('WZ_TYPE=' . ($wzType ?? '')) . "\n";
	echo h('WZ_MESSAGE=' . $bpMessage) . "\n";
	echo h('SHOULD_FALLBACK=' . ($shouldFallback ? '1' : '0')) . "\n";
	echo '</pre>';

	if ($verbose) {
		pre('Debug (fast details)', [
			'since_minutes' => $sinceMinutes,
			'sinceDate' => $sinceDate,
			'phone' => $phone,
			'phoneDigits' => $phoneDigits,
			'wazzupLinkMessage' => $best,
			'olSession' => $sessionRow,
			'hasWzMarker' => $hasWzMarker,
			'hasSendErrorText' => $hasSendError,
			'wzType' => $wzType,
			'wzReason' => $wzReason,
			'firstHit' => $hit,
		]);
	}

	echo '</div>';
	if ($isAdminProlog && $docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/epilog_admin.php')) {
		require($docRoot . '/bitrix/modules/main/include/epilog_admin.php');
	}
	return;
}

section('LEAD');
$lead = null;
try {
	if (class_exists(\Bitrix\Crm\LeadTable::class)) {
		$leadRow = \Bitrix\Crm\LeadTable::getById($leadId)->fetch();
		if (is_array($leadRow)) {
			$lead = $leadRow;
		}
	}
} catch (Throwable $e) {
	pre('Ошибка чтения лида (ORM)', $e->getMessage());
}

if (!is_array($lead)) {
	try {
		if (class_exists(\CCrmLead::class)) {
			$leadRow = \CCrmLead::GetByID($leadId);
			if (is_array($leadRow)) {
				$lead = $leadRow;
			}
		}
	} catch (Throwable $e) {
		pre('Ошибка чтения лида (CCrmLead)', $e->getMessage());
	}
}

pre('Lead row (как прочиталось)', $lead);

// Телефоны лида (FIELD_MULTI)
$leadPhones = [];
try {
	if (class_exists(\CCrmFieldMulti::class)) {
		$leadPhones = \CCrmFieldMulti::GetList(
			[],
			[
				'ENTITY_ID' => 'LEAD',
				'ELEMENT_ID' => $leadId,
				'TYPE_ID' => 'PHONE',
			]
		);
		$tmp = [];
		while ($row = $leadPhones->Fetch()) {
			$tmp[] = $row;
		}
		$leadPhones = $tmp;
	}
} catch (Throwable $e) {
	pre('Ошибка чтения телефонов лида', $e->getMessage());
}
pre('Lead phones (CCrmFieldMulti)', $leadPhones);

// Если phone не передан — попробуем взять первый
if ($phone === '' && is_array($leadPhones) && isset($leadPhones[0]['VALUE'])) {
	$phone = trim((string)$leadPhones[0]['VALUE']);
}

section('Схема полей (что реально есть в таблицах)');
try {
	if (class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class)) {
		$fields = \Bitrix\ImOpenLines\Model\SessionTable::getEntity()->getFields();
		$names = [];
		foreach ($fields as $f) {
			$names[] = $f->getName() . ' (' . get_class($f) . ')';
		}
		pre('ImOpenLines SessionTable fields', $names);
	}
} catch (Throwable $e) {
	pre('Ошибка чтения схемы SessionTable', $e->getMessage());
}

try {
	if (class_exists(\Bitrix\Im\Model\MessageTable::class)) {
		$fields = \Bitrix\Im\Model\MessageTable::getEntity()->getFields();
		$names = [];
		foreach ($fields as $f) {
			$names[] = $f->getName() . ' (' . get_class($f) . ')';
		}
		pre('IM MessageTable fields', $names);
	}
} catch (Throwable $e) {
	pre('Ошибка чтения схемы MessageTable', $e->getMessage());
}

section('Поиск OL-сессий (последние N, плюс “матч к лиду”)');
$sessions = [];
try {
	if (class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class)) {
		$sessions = \Bitrix\ImOpenLines\Model\SessionTable::getList([
			'select' => ['*'],
			'order' => ['ID' => 'DESC'],
			'limit' => $limit,
		])->fetchAll();
	}
} catch (Throwable $e) {
	pre('Ошибка чтения SessionTable', $e->getMessage());
}

// Функция “матчит ли сессия этот lead” через CRM_ACTIVITY_ID bindings
$matchSessionToLead = static function (array $sessionRow) use ($leadId): array {
	$activityId = (int)($sessionRow['CRM_ACTIVITY_ID'] ?? 0);
	if ($activityId <= 0 || !class_exists(\Bitrix\Crm\ActivityBindingTable::class)) {
		return [
			'isMatch' => false,
			'activityId' => $activityId,
			'bindings' => [],
			'reason' => $activityId <= 0 ? 'no CRM_ACTIVITY_ID' : 'ActivityBindingTable missing',
		];
	}

	$bindings = [];
	try {
		$bindings = \Bitrix\Crm\ActivityBindingTable::getList([
			'select' => ['OWNER_ID', 'OWNER_TYPE_ID'],
			'filter' => ['=ACTIVITY_ID' => $activityId],
		])->fetchAll();
	} catch (Throwable $e) {
		return [
			'isMatch' => false,
			'activityId' => $activityId,
			'bindings' => [],
			'reason' => 'bindings error: ' . $e->getMessage(),
		];
	}

	foreach ($bindings as $b) {
		if ((int)($b['OWNER_TYPE_ID'] ?? 0) === \CCrmOwnerType::Lead && (int)($b['OWNER_ID'] ?? 0) === $leadId) {
			return [
				'isMatch' => true,
				'activityId' => $activityId,
				'bindings' => $bindings,
				'reason' => 'match via ActivityBindingTable',
			];
		}
	}

	return [
		'isMatch' => false,
		'activityId' => $activityId,
		'bindings' => $bindings,
		'reason' => 'no lead binding',
	];
};

$rowsOut = [];
$matchedSessions = [];
foreach ($sessions as $s) {
	$chatId = (int)($s['CHAT_ID'] ?? 0);
	$sessionId = (int)($s['ID'] ?? 0);
	$matchInfo = $matchSessionToLead($s);
	$light = [
		'ID' => $sessionId,
		'CHAT_ID' => $chatId,
		'CRM_ACTIVITY_ID' => (int)($s['CRM_ACTIVITY_ID'] ?? 0),
		'DATE_CREATE' => $s['DATE_CREATE'] ?? null,
		'DATE_CLOSE' => $s['DATE_CLOSE'] ?? null,
		'CLOSED' => $s['CLOSED'] ?? null,
		'USER_CODE' => $s['USER_CODE'] ?? null,
		'CRM' => $s['CRM'] ?? null,
		'MATCH' => $matchInfo,
	];
	$rowsOut[] = $light;

	if ($matchInfo['isMatch'] === true && $chatId > 0) {
		$matchedSessions[] = $s;
	}
}
pre('Последние OL-сессии (light)', $rowsOut);

section('Кандидаты (сессии, которые реально биндятся к lead через CRM_ACTIVITY_ID)');
pre('Matched sessions (raw)', $matchedSessions);

section('Поиск маркера SYSTEM WZ в сообщениях чатов кандидатов');
$markerRegex = '/(^|\s)===[\s\S]*(SYSTEM\s*WZ|СИСТЕМА\s*WZ)[\s\S]*===/iu';
$errorContains = 'Ошибка отправки';

$chatReports = [];
foreach ($matchedSessions as $s) {
	$chatId = (int)($s['CHAT_ID'] ?? 0);
	if ($chatId <= 0) {
		continue;
	}

	$messages = [];
	try {
		if (class_exists(\Bitrix\Im\Model\MessageTable::class)) {
			$messages = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
				'filter' => ['=CHAT_ID' => $chatId],
				'order' => ['ID' => 'DESC'],
				'limit' => 50,
			])->fetchAll();
		}
	} catch (Throwable $e) {
		$chatReports[] = [
			'CHAT_ID' => $chatId,
			'ERROR' => $e->getMessage(),
		];
		continue;
	}

	$hits = [];
	foreach ($messages as $m) {
		$text = (string)($m['MESSAGE'] ?? '');
		$isMarker = $text !== '' && preg_match($markerRegex, $text) === 1;
		$hasSendError = $text !== '' && mb_strpos($text, $errorContains) !== false;
		if ($isMarker || $hasSendError) {
			$hits[] = [
				'ID' => $m['ID'] ?? null,
				'AUTHOR_ID' => $m['AUTHOR_ID'] ?? null,
				'DATE_CREATE' => $m['DATE_CREATE'] ?? null,
				'markerMatch' => $isMarker,
				'containsSendError' => $hasSendError,
				'MESSAGE' => mb_substr($text, 0, 1200),
			];
		}
	}

	$chatReports[] = [
		'CHAT_ID' => $chatId,
		'SESSION_ID' => (int)($s['ID'] ?? 0),
		'CRM_ACTIVITY_ID' => (int)($s['CRM_ACTIVITY_ID'] ?? 0),
		'USER_CODE' => $s['USER_CODE'] ?? null,
		'CRM' => $s['CRM'] ?? null,
		'lastMessagesSample' => array_slice($messages, 0, 5),
		'hits' => $hits,
	];
}
pre('Chat reports', $chatReports);

section('Подсказка: какие поля содержат телефон?');
if ($phone !== '') {
	$needle = $phone;
	$phoneHits = [];
	foreach ($sessions as $s) {
		foreach ($s as $k => $v) {
			if (is_string($v) && $v !== '' && mb_strpos($v, $needle) !== false) {
				$phoneHits[] = [
					'SESSION_ID' => (int)($s['ID'] ?? 0),
					'CHAT_ID' => (int)($s['CHAT_ID'] ?? 0),
					'FIELD' => $k,
					'VALUE_FRAGMENT' => mb_substr($v, 0, 400),
				];
			}
		}
	}
	pre('Session rows where some field contains phone substring', $phoneHits);
} else {
	echo '<div style="padding:12px;border:1px solid #ffeeba;background:#fff3cd;color:#856404;">phone не передан и не удалось взять из лида — пропускаю поиск по подстроке.</div>';
}

section('Поиск чатов по телефону через IM сообщения (рекомендуемый способ для Wazzup)');
if ($phone !== '') {
	// В сообщениях телефон часто встречается без "+" (как в ссылках wazzup24.com/.../7916...)
	$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
	$variants = [];
	if ($phone !== '') {
		$variants[] = $phone;
	}
	if ($phoneDigits !== '') {
		$variants[] = $phoneDigits;
		// Частые вариации для РФ
		if (mb_strlen($phoneDigits) === 11 && startsWith($phoneDigits, '7')) {
			$variants[] = '8' . mb_substr($phoneDigits, 1);
		}
		if (mb_strlen($phoneDigits) === 11 && startsWith($phoneDigits, '8')) {
			$variants[] = '7' . mb_substr($phoneDigits, 1);
		}
	}
	$variants = array_values(array_unique(array_filter(array_map('trim', $variants))));

	if ($sinceMinutes <= 0 || $sinceMinutes > 10080) { // до 7 дней
		$sinceMinutes = 240; // 4 часа по умолчанию
	}
	$sinceTs = time() - ($sinceMinutes * 60);
	$sinceDate = DateTime::createFromTimestamp($sinceTs);

	pre('Phone variants', [
		'phone' => $phone,
		'phoneDigits' => $phoneDigits,
		'variants' => $variants,
		'since_minutes' => $sinceMinutes,
		'since' => $sinceDate,
	]);

	$imHits = [];
	$chatIds = [];
	$byChannel = []; // channel => [chatId => lastMessageRow]
	if (class_exists(\Bitrix\Im\Model\MessageTable::class)) {
		foreach ($variants as $v) {
			try {
				$rows = \Bitrix\Im\Model\MessageTable::getList([
					'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
					'filter' => [
						'>=DATE_CREATE' => $sinceDate,
						'%MESSAGE' => $v,
					],
					'order' => ['ID' => 'DESC'],
					'limit' => 50,
				])->fetchAll();
			} catch (Throwable $e) {
				$imHits[] = [
					'variant' => $v,
					'error' => $e->getMessage(),
				];
				continue;
			}

			foreach ($rows as $m) {
				$cid = (int)($m['CHAT_ID'] ?? 0);
				if ($cid > 0) {
					$chatIds[$cid] = true;
				}

				$text = (string)($m['MESSAGE'] ?? '');
				$imHits[] = [
					'variant' => $v,
					'ID' => $m['ID'] ?? null,
					'CHAT_ID' => $cid,
					'AUTHOR_ID' => $m['AUTHOR_ID'] ?? null,
					'DATE_CREATE' => $m['DATE_CREATE'] ?? null,
					'looksLikeWazzupUrl' => ($text !== '' && mb_strpos($text, 'wazzup24.com') !== false),
					'channelHint' => (function () use ($text): ?string {
						if ($text === '') return null;
						if (mb_strpos($text, '/whatsapp/') !== false) return 'whatsapp';
						if (mb_strpos($text, '/telegram/') !== false) return 'telegram';
						if (mb_strpos($text, '/max/') !== false) return 'max';
						return null;
					})(),
					'MESSAGE' => mb_substr($text, 0, 800),
				];

				$hint = null;
				if ($text !== '') {
					if (mb_strpos($text, '/whatsapp/') !== false) $hint = 'whatsapp';
					elseif (mb_strpos($text, '/telegram/') !== false) $hint = 'telegram';
					elseif (mb_strpos($text, '/max/') !== false) $hint = 'max';
				}
				if ($hint !== null && $cid > 0) {
					// Сохраняем самый свежий (по ID) пример сообщения для канала/чата
					$prev = $byChannel[$hint][$cid]['ID'] ?? 0;
					$cur = (int)($m['ID'] ?? 0);
					if ($cur > (int)$prev) {
						$byChannel[$hint][$cid] = $m;
					}
				}
			}
		}
	} else {
		$imHits[] = ['error' => 'Bitrix\\Im\\Model\\MessageTable not available'];
	}

	pre('IM messages that contain phone (last window)', $imHits);
	pre('Unique CHAT_IDs from IM search', array_keys($chatIds));
	pre('Detected chats by channel (from wazzup url hints)', array_map(
		static function (array $chats): array {
			return array_keys($chats);
		},
		$byChannel
	));

	// Для найденных CHAT_ID попробуем сопоставить OL-сессию и проверить привязку к лиду
	$chatToSession = [];
	if (!empty($chatIds) && class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class)) {
		foreach (array_keys($chatIds) as $cid) {
			try {
				$sessionRow = \Bitrix\ImOpenLines\Model\SessionTable::getList([
					'select' => ['ID', 'CHAT_ID', 'SOURCE', 'USER_CODE', 'CRM_ACTIVITY_ID', 'DATE_CREATE', 'DATE_LAST_MESSAGE', 'CLOSED'],
					'filter' => ['=CHAT_ID' => (int)$cid],
					'order' => ['ID' => 'DESC'],
					'limit' => 1,
				])->fetch();
			} catch (Throwable $e) {
				$chatToSession[] = [
					'CHAT_ID' => (int)$cid,
					'error' => $e->getMessage(),
				];
				continue;
			}

			if (!is_array($sessionRow)) {
				$chatToSession[] = [
					'CHAT_ID' => (int)$cid,
					'SESSION' => null,
					'matchToLead' => null,
					'note' => 'No OL session found for this CHAT_ID (maybe not OL chat)',
				];
				continue;
			}

			$matchInfo = $matchSessionToLead($sessionRow);
			$chatToSession[] = [
				'CHAT_ID' => (int)$cid,
				'SESSION' => $sessionRow,
				'matchToLead' => $matchInfo,
			];
		}
	}
	pre('CHAT_ID -> OL session mapping', $chatToSession);

	section('Вердикт по выбранному каналу (создан чат? есть ошибка?)');
	$selected = $channel !== '' ? mb_strtolower($channel) : '';
	if ($selected === '') {
		echo '<div style="padding:12px;border:1px solid #ffeeba;background:#fff3cd;color:#856404;">Укажи channel (whatsapp|telegram|max), чтобы скрипт вывел итог именно для одного шага.</div>';
	} elseif (!isset($byChannel[$selected]) || empty($byChannel[$selected])) {
		pre('Result', [
			'channel' => $selected,
			'chatFound' => false,
			'chatId' => null,
			'note' => 'No wazzup URL message found for this channel+phone in the time window',
		]);
	} else {
		// Берём самый свежий чат по этому каналу (по ID сообщения)
		$bestChatId = 0;
		$bestMsgId = 0;
		$bestMsg = null;
		foreach ($byChannel[$selected] as $cid => $m) {
			$mid = (int)($m['ID'] ?? 0);
			if ($mid > $bestMsgId) {
				$bestMsgId = $mid;
				$bestChatId = (int)$cid;
				$bestMsg = $m;
			}
		}

		$recent = [];
		$hits = [];
		if ($bestChatId > 0 && class_exists(\Bitrix\Im\Model\MessageTable::class)) {
			try {
				$recent = \Bitrix\Im\Model\MessageTable::getList([
					'select' => ['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE'],
					'filter' => ['=CHAT_ID' => $bestChatId],
					'order' => ['ID' => 'DESC'],
					'limit' => 80,
				])->fetchAll();
			} catch (Throwable $e) {
				pre('Ошибка чтения сообщений чата', $e->getMessage());
			}
		}

		$hasWzMarker = false;
		$hasSendError = false;
		foreach ($recent as $m) {
			$text = (string)($m['MESSAGE'] ?? '');
			$isMarker = $text !== '' && preg_match($markerRegex, $text) === 1;
			$isSendError = $text !== '' && (mb_strpos($text, $errorContains) !== false);
			if ($isMarker) $hasWzMarker = true;
			if ($isSendError) $hasSendError = true;
			if ($isMarker || $isSendError) {
				$hits[] = [
					'ID' => $m['ID'] ?? null,
					'AUTHOR_ID' => $m['AUTHOR_ID'] ?? null,
					'DATE_CREATE' => $m['DATE_CREATE'] ?? null,
					'markerMatch' => $isMarker,
					'containsSendError' => $isSendError,
					'MESSAGE' => mb_substr($text, 0, 1200),
				];
			}
		}

		pre('Selected channel summary', [
			'channel' => $selected,
			'chatFound' => $bestChatId > 0,
			'chatId' => $bestChatId > 0 ? $bestChatId : null,
			'wazzupLinkMessage' => $bestMsg,
			'hasWzMarker' => $hasWzMarker,
			'hasSendErrorText' => $hasSendError,
			'hits' => $hits,
		]);
	}
} else {
	echo '<div style="padding:12px;border:1px solid #ffeeba;background:#fff3cd;color:#856404;">phone пустой — пропускаю поиск чатов по телефону.</div>';
}

echo '</div>';

if ($isAdminProlog && $docRoot !== '' && is_file($docRoot . '/bitrix/modules/main/include/epilog_admin.php')) {
	require($docRoot . '/bitrix/modules/main/include/epilog_admin.php');
}

