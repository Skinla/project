<?php
/**
 * Боевой помощник для блока "Произвольный PHP код" в Конструкторе БП (Bitrix коробка).
 *
 * Вход (все строки, BP переменные):
 * - LEAD_ID
 * - PHONE            (пример: +79165970992)
 * - CHANNEL          (whatsapp|telegram|max)
 * - SINCE_MINUTES    ("5" боевое, "0" = за всё время)
 *
 * Выход (все строки, BP переменные):
 * - CHAT_ID          ("637587" или "")
 * - WZ_MESSAGE       ("чат не создан" | "=== SYSTEM WZ === ..." | "OK")
 *
 * Как использовать в БП (вставить в активность "Произвольный PHP код"):
 *
 * require_once($_SERVER['DOCUMENT_ROOT'] . '/local/php_interface/handlers/wz_bp_chat_check.php');
 * $rootActivity = $this->GetRootActivity();
 * \Wz\Bizproc\WzBpChatCheck::execute($rootActivity);
 *
 * Важно:
 * - Скрипт ищет чат по системному сообщению Wazzup с ссылкой:
 *   https://app.wazzup24.com/.../chat/<channel>/<phoneDigits>/...
 * - Если чат найден, проверяет последние сообщения на маркер:
 *   === SYSTEM WZ === / === СИСТЕМА WZ ===
 */

declare(strict_types=1);

namespace Wz\Bizproc;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

final class WzBpChatCheck
{
	private const OUT_CHAT_ID = 'CHAT_ID';
	private const OUT_WZ_MESSAGE = 'WZ_MESSAGE';

	private const IN_LEAD_ID = 'LEAD_ID';
	private const IN_PHONE = 'PHONE';
	private const IN_CHANNEL = 'CHANNEL';
	private const IN_SINCE_MINUTES = 'SINCE_MINUTES';

	/**
	 * @param object $rootActivity Должен иметь методы GetVariable/SetVariable (CBPActivity).
	 */
	public static function execute(object $rootActivity): void
	{
		$leadId = (int)self::getVar($rootActivity, self::IN_LEAD_ID);
		$phone = trim((string)self::getVar($rootActivity, self::IN_PHONE));
		$channel = mb_strtolower(trim((string)self::getVar($rootActivity, self::IN_CHANNEL)));
		$sinceMinutes = (int)trim((string)self::getVar($rootActivity, self::IN_SINCE_MINUTES));

		// Нормализация / валидация
		$phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
		if ($phoneDigits === '' || $channel === '') {
			self::setResult($rootActivity, '', 'чат не создан');
			return;
		}
		if (!in_array($channel, ['whatsapp', 'telegram', 'max'], true)) {
			// Канал неожиданный — лучше считать как "не создан", чтобы BP мог перейти к следующему шагу
			self::setResult($rootActivity, '', 'чат не создан');
			return;
		}

		// Подключаем модули
		if (!Loader::includeModule('im')) {
			self::setResult($rootActivity, '', 'чат не создан');
			return;
		}
		// imopenlines не обязателен для определения CHAT_ID (мы ищем по IM-сообщению),
		// но полезен как fallback.
		Loader::includeModule('imopenlines');

		$sinceDate = null;
		if ($sinceMinutes > 0) {
			$sinceTs = time() - ($sinceMinutes * 60);
			$sinceDate = DateTime::createFromTimestamp($sinceTs);
		}

		$chatId = self::findChatIdByWazzupLink($channel, $phoneDigits, $sinceDate, $sinceMinutes);
		if ($chatId <= 0) {
			self::setResult($rootActivity, '', 'чат не создан');
			return;
		}

		// Чат найден — проверяем наличие SYSTEM WZ
		$wzMessage = self::analyzeChatForWz($chatId);
		self::setResult($rootActivity, (string)$chatId, $wzMessage);
	}

	/**
	 * @return int CHAT_ID или 0
	 */
	private static function findChatIdByWazzupLink(string $channel, string $phoneDigits, ?DateTime $sinceDate, int $sinceMinutes): int
	{
		if (!class_exists(\Bitrix\Im\Model\MessageTable::class)) {
			return 0;
		}

		$needle = '/chat/' . $channel . '/' . $phoneDigits . '/';
		$filter = [
			'%MESSAGE' => $needle,
		];
		if ($sinceDate instanceof DateTime) {
			$filter['>=DATE_CREATE'] = $sinceDate;
		}

		// 1) Прямой быстрый поиск по подстроке "/chat/<channel>/<phoneDigits>/"
		try {
			$row = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'CHAT_ID', 'MESSAGE'],
				'filter' => $filter,
				'order' => ['ID' => 'DESC'],
				'limit' => 1,
			])->fetch();
		} catch (\Throwable $e) {
			$row = false;
		}

		if (is_array($row)) {
			$text = (string)($row['MESSAGE'] ?? '');
			if ($text !== '' && mb_strpos($text, 'wazzup24.com') !== false) {
				return (int)($row['CHAT_ID'] ?? 0);
			}
		}

		// 2) Fallback через OL-сессии (если прямой поиск не сработал)
		if (!class_exists(\Bitrix\ImOpenLines\Model\SessionTable::class)) {
			return 0;
		}

		$sessionFilter = [
			'>CHAT_ID' => 0,
		];
		if ($sinceDate instanceof DateTime) {
			$sessionFilter['>=DATE_CREATE'] = $sinceDate;
		}

		// Лимиты под окно
		$sessionLimit = 200;
		$chatCandidatesLimit = 12;
		$messagesPerChat = 15;
		if ($sinceMinutes <= 0) {
			$sessionLimit = 2000;
			$chatCandidatesLimit = 60;
			$messagesPerChat = 30;
		} elseif ($sinceMinutes >= 240) {
			$sessionLimit = 800;
			$chatCandidatesLimit = 60;
			$messagesPerChat = 25;
		} elseif ($sinceMinutes > 60) {
			$sessionLimit = 500;
			$chatCandidatesLimit = 40;
			$messagesPerChat = 20;
		}

		try {
			$sessions = \Bitrix\ImOpenLines\Model\SessionTable::getList([
				'select' => ['ID', 'CHAT_ID', 'SOURCE'],
				'filter' => $sessionFilter,
				'order' => ['ID' => 'DESC'],
				'limit' => $sessionLimit,
			])->fetchAll();
		} catch (\Throwable $e) {
			$sessions = [];
		}

		$sourcePrefix = 'wz_' . $channel . '_';
		$candidates = [];
		foreach ($sessions as $s) {
			$src = (string)($s['SOURCE'] ?? '');
			if ($src !== '' && self::startsWith($src, $sourcePrefix)) {
				$candidates[] = $s;
			}
		}
		$candidates = array_slice($candidates, 0, $chatCandidatesLimit);

		foreach ($candidates as $s) {
			$cid = (int)($s['CHAT_ID'] ?? 0);
			if ($cid <= 0) {
				continue;
			}

			try {
				$recent = \Bitrix\Im\Model\MessageTable::getList([
					'select' => ['ID', 'MESSAGE'],
					'filter' => ['=CHAT_ID' => $cid],
					'order' => ['ID' => 'DESC'],
					'limit' => $messagesPerChat,
				])->fetchAll();
			} catch (\Throwable $e) {
				$recent = [];
			}

			foreach ($recent as $m) {
				$text = (string)($m['MESSAGE'] ?? '');
				if ($text === '') continue;
				if (mb_strpos($text, 'wazzup24.com') === false) continue;
				if (mb_strpos($text, $needle) === false) continue;
				return $cid;
			}
		}

		return 0;
	}

	/**
	 * @return string "OK" | "=== SYSTEM WZ === ..." (включая причину)
	 */
	private static function analyzeChatForWz(int $chatId): string
	{
		if (!class_exists(\Bitrix\Im\Model\MessageTable::class)) {
			return 'OK';
		}

		$markerRegex = '/(^|\s)===[\s\S]*(SYSTEM\s*WZ|СИСТЕМА\s*WZ)[\s\S]*===/iu';

		try {
			$recent = \Bitrix\Im\Model\MessageTable::getList([
				'select' => ['ID', 'MESSAGE'],
				'filter' => ['=CHAT_ID' => $chatId],
				'order' => ['ID' => 'DESC'],
				'limit' => 50,
			])->fetchAll();
		} catch (\Throwable $e) {
			return 'OK';
		}

		foreach ($recent as $m) {
			$text = trim((string)($m['MESSAGE'] ?? ''));
			if ($text === '') continue;
			if (preg_match($markerRegex, $text) !== 1) continue;

			// Нормализуем к "=== SYSTEM WZ === <reason>"
			$reason = $text;
			if (preg_match('/===\s*(?:SYSTEM\s*WZ|СИСТЕМА\s*WZ)\s*===\s*(.+)$/iu', $text, $mm) === 1) {
				$reason = trim((string)$mm[1]);
			}
			if ($reason === '') {
				$reason = 'обнаружен SYSTEM WZ';
			}
			return '=== SYSTEM WZ === ' . $reason;
		}

		return 'OK';
	}

	private static function setResult(object $rootActivity, string $chatId, string $message): void
	{
		self::setVar($rootActivity, self::OUT_CHAT_ID, $chatId);
		self::setVar($rootActivity, self::OUT_WZ_MESSAGE, $message);
	}

	private static function getVar(object $rootActivity, string $name)
	{
		if (method_exists($rootActivity, 'GetVariable')) {
			/** @var mixed $v */
			$v = $rootActivity->GetVariable($name);
			return $v;
		}
		return null;
	}

	private static function setVar(object $rootActivity, string $name, string $value): void
	{
		if (method_exists($rootActivity, 'SetVariable')) {
			$rootActivity->SetVariable($name, $value);
		}
	}

	private static function startsWith(string $s, string $prefix): bool
	{
		return $prefix === '' || strncmp($s, $prefix, strlen($prefix)) === 0;
	}
}

