<?php

use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

class LevelHappinessComponent extends CBitrixComponent implements Controllerable
{
	private const MIN_LEVEL = 1;
	private const MAX_LEVEL = 5;

	public function configureActions(): array
	{
		return [
			'saveLevel' => [
				'prefilters' => [
					new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
					new ActionFilter\Csrf(),
				],
			],
		];
	}

	public function executeComponent(): void
	{
		global $USER;

		$data = $this->readData();
		$userVotes = $this->normalizeVotes($data['USER'] ?? []);
		$stats = $this->buildStats($userVotes, (int)$USER->GetID());
		$minLevel = $this->getMinLevel();
		$maxLevel = $this->getMaxLevel();

		$this->arResult = [
			'STARS' => $stats['stars'],
			'AVERAGE' => $stats['average'],
			'STAR_CLASSES' => $stats['starClasses'],
			'PERCENT' => $stats['percent'],
			'CURRENT_USER_ID' => (int)$USER->GetID(),
			'COMPANY_NAME' => $this->getCompanyName(),
			'FORM_TITLE' => $this->getConfigString('FORM_TITLE', 'Уровень счастья сотрудников'),
			'FORM_QUESTION' => $this->getConfigString('FORM_QUESTION', 'Какой твой уровень счастья сегодня!?'),
			'ANONYMITY_TEXT' => $this->getConfigString('ANONYMITY_TEXT', 'Статистика полностью 🕵🏻‍♀️ анонимная!'),
			'USER_LEVEL_PREFIX' => $this->getConfigString('USER_LEVEL_PREFIX', 'Ваш уровень счастья:'),
			'BUTTON_TEXT' => $this->getConfigString('BUTTON_TEXT', 'Сохранить'),
			'ENABLE_MANAGEMENT_BUTTON' => $this->getConfigString('ENABLE_MANAGEMENT_BUTTON', 'Y') === 'Y',
			'MANAGEMENT_BUTTON_TEXT' => $this->getConfigString('MANAGEMENT_BUTTON_TEXT', 'Написать руководству'),
			'MANAGEMENT_URL' => $this->getConfigString('MANAGEMENT_URL', 'https://kmechty.ru/'),
			'SELECT_PLACEHOLDER' => $this->getConfigString('SELECT_PLACEHOLDER', 'Мой уровень'),
			'STAR_SYMBOL' => $this->getConfigString('STAR_SYMBOL', '⭐'),
			'STAR_SYMBOL_FILLED' => $this->getConfigString('STAR_SYMBOL_FILLED', '★'),
			'STAR_SYMBOL_EMPTY' => $this->getConfigString('STAR_SYMBOL_EMPTY', '☆'),
			'MIN_LEVEL' => $minLevel,
			'MAX_LEVEL' => $maxLevel,
			'LEVEL_OPTIONS_DESC' => $this->getLevelOptionsDesc(),
			'LEVEL_LABEL_SUFFIX' => $this->getConfigString('LEVEL_LABEL_SUFFIX', 'звезд'),
			'BAR_COLORS' => $this->getBarColors(),
			'FRONTEND_CONFIG' => $this->getFrontendConfig(),
		];

		$this->includeComponentTemplate();
	}

	public function saveLevelAction($level, $message = null)
	{
		global $USER;

		$currentUserId = (int)$USER->GetID();
		if ($currentUserId <= 0)
		{
			return AjaxJson::createError(new Error($this->getConfigString('API_ERROR_UNAUTHORIZED', 'Пользователь не авторизован.')));
		}

		if (!is_numeric($level) || (int)$level === 0)
		{
			$this->notifyUser(
				$currentUserId,
				$this->buildNotifyMessage($this->getConfigString('NOTIFY_MESSAGE_EMPTY', 'Ошибка! Заполнены не все поля! ❌'))
			);

			return AjaxJson::createError(new Error($this->getConfigString('API_ERROR_EMPTY', 'Заполнены не все поля.')));
		}

		$level = (int)$level;
		if (!$this->isValidLevel($level))
		{
			$this->notifyUser(
				$currentUserId,
				$this->buildNotifyMessage($this->getConfigString('NOTIFY_MESSAGE_RANGE', "Ошибка!\nЗначение выходит за пределы диапазона! Маленький ты хакер 😼"))
			);

			return AjaxJson::createError(new Error($this->getConfigString('API_ERROR_INVALID', 'Некорректный уровень счастья.')));
		}

		if (!is_string($message))
		{
			$rawMessage = Context::getCurrent()->getRequest()->getPost('message');
			$message = is_scalar($rawMessage) ? (string)$rawMessage : '';
		}
		$messageText = trim((string)$message);
		if ($this->isLowScoreLevel($level))
		{
			if ($messageText === '')
			{
				$this->notifyUser(
					$currentUserId,
					$this->buildNotifyMessage($this->getConfigString('NOTIFY_MESSAGE_REASON_REQUIRED', 'Укажите причину низкой оценки в форме обращения.'))
				);

				return AjaxJson::createError(new Error($this->getConfigString('API_ERROR_REASON_REQUIRED', 'Для оценки 1–3 укажите причину в поле сообщения.')));
			}

			$listResult = $this->addLowScoreAppealToList($currentUserId, $level, $messageText);
			if (!$listResult['success'])
			{
				$this->notifyUser(
					$currentUserId,
					$this->buildNotifyMessage($this->getConfigString('NOTIFY_MESSAGE_LIST_SAVE_FAIL', 'Не удалось сохранить обращение в список. Оценка не изменена.'))
				);

				return AjaxJson::createError(new Error($listResult['error']));
			}
		}

		$data = $this->readData();
		$data['USER'] = $this->normalizeVotes($data['USER'] ?? []);
		$data['USER'][(string)$currentUserId] = $level;

		if (!$this->writeData($data))
		{
			return AjaxJson::createError(new Error($this->getConfigString('API_ERROR_SAVE', 'Не удалось сохранить оценку.')));
		}

		$this->notifyUser(
			$currentUserId,
			$this->buildNotifyMessage($this->getConfigString('NOTIFY_MESSAGE_SUCCESS', 'Ваш уровень счастья изменен! 🚀'))
		);

		$stats = $this->buildStats($data['USER'], $currentUserId);

		return [
			'status' => 'success',
			'level' => $level,
			'widget' => [
				'stars' => $stats['stars'],
				'average' => $stats['average'],
				'percent' => $stats['percent'],
				'starClasses' => $stats['starClasses'],
				'currentUserId' => $currentUserId,
			],
		];
	}

	private function getDataFilePath(): string
	{
		$relativePath = (string)($this->arParams['DATA_FILE'] ?? $this->getConfigString('DATA_FILE', $this->getDefaultDataFilePath()));
		$relativePath = '/'.ltrim($relativePath, '/\\');

		return rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\').$relativePath;
	}

	private function readData(): array
	{
		$filePath = $this->getDataFilePath();
		if (!is_file($filePath))
		{
			return ['USER' => []];
		}

		$content = file_get_contents($filePath);
		if ($content === false || $content === '')
		{
			return ['USER' => []];
		}

		$decoded = json_decode($content, true);
		if (!is_array($decoded))
		{
			return ['USER' => []];
		}

		return $decoded;
	}

	private function writeData(array $data): bool
	{
		$filePath = $this->getDataFilePath();
		$directory = dirname($filePath);

		if (!is_dir($directory))
		{
			return false;
		}

		$encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if ($encoded === false)
		{
			return false;
		}

		$tempFile = $filePath.'.tmp';
		$bytes = file_put_contents($tempFile, $encoded, LOCK_EX);
		if ($bytes === false)
		{
			return false;
		}

		// На Linux rename поверх существующего файла атомарен.
		if (@rename($tempFile, $filePath))
		{
			return true;
		}

		// Fallback для окружений, где rename не перезаписывает существующий файл.
		if (is_file($filePath) && !unlink($filePath))
		{
			@unlink($tempFile);
			return false;
		}

		$result = @rename($tempFile, $filePath);
		if (!$result)
		{
			@unlink($tempFile);
		}

		return $result;
	}

	private function normalizeVotes(array $votes): array
	{
		$normalized = [];
		foreach ($votes as $userId => $value)
		{
			$normalized[(string)$userId] = (int)$value;
		}

		return $normalized;
	}

	private function buildStats(array $votes, int $currentUserId): array
	{
		$levelsDesc = $this->getLevelOptionsDesc();
		$levelsAsc = array_reverse($levelsDesc);

		$static = [];
		foreach ($levelsDesc as $level)
		{
			$static[$level] = 0;
		}

		$stars = [
			'STATIC' => $static,
			'GLAVCOUNT' => 0,
			'USER' => 0,
		];

		foreach ($votes as $userId => $value)
		{
			if (!$this->isValidLevel((int)$value))
			{
				continue;
			}

			$stars['STATIC'][(int)$value] = $stars['STATIC'][(int)$value] + 1;
			$stars['GLAVCOUNT'] = $stars['GLAVCOUNT'] + 1;

			if ((int)$userId === $currentUserId)
			{
				$stars['USER'] = (int)$value;
			}
		}

		$totalVotes = $stars['GLAVCOUNT'];
		$weightedSum = 0.0;
		foreach ($levelsAsc as $level)
		{
			$weightedSum += $level * $stars['STATIC'][$level];
		}

		$average = $totalVotes > 0 ? round($weightedSum / $totalVotes, 2) : 0.0;

		$percent = [];
		foreach ($levelsDesc as $level)
		{
			$percent[$level] = $totalVotes > 0 ? ($stars['STATIC'][$level] * 100) / $totalVotes : 0;
		}

		return [
			'stars' => $stars,
			'average' => $average,
			'percent' => $percent,
			'starClasses' => $this->buildAverageStarClasses($average),
		];
	}

	private function buildAverageStarClasses(float $average): array
	{
		$filledStars = (int)floor($average);
		$hasHalfStar = ($average - $filledStars) >= 0.5;
		$states = [];
		$maxLevel = $this->getMaxLevel();

		for ($position = 1; $position <= $maxLevel; $position++)
		{
			if ($position <= $filledStars)
			{
				$states[] = 'full';
				continue;
			}

			if ($hasHalfStar)
			{
				$states[] = 'half';
				$hasHalfStar = false;
				continue;
			}

			$states[] = 'empty';
		}

		return $states;
	}

	private function isValidLevel(int $level): bool
	{
		return $level >= $this->getMinLevel() && $level <= $this->getMaxLevel();
	}

	private function getLowScoreMaxLevel(): int
	{
		$configured = $this->getConfigInt('LOW_SCORE_MAX_LEVEL', 3);
		$maxLevel = $this->getMaxLevel();
		$minLevel = $this->getMinLevel();
		if ($configured > $maxLevel)
		{
			$configured = $maxLevel;
		}
		if ($configured < $minLevel)
		{
			$configured = $minLevel;
		}

		return $configured;
	}

	private function isLowScoreLevel(int $level): bool
	{
		return $level >= $this->getMinLevel() && $level <= $this->getLowScoreMaxLevel();
	}

	private function sanitizeAppealMessage(string $message): string
	{
		$clean = trim(strip_tags($message));
		$maxLen = $this->getConfigInt('MESSAGE_MAX_LENGTH', 4000);
		if ($maxLen <= 0)
		{
			$maxLen = 4000;
		}
		if (function_exists('mb_substr'))
		{
			if (mb_strlen($clean) > $maxLen)
			{
				$clean = mb_substr($clean, 0, $maxLen);
			}
		}
		elseif (strlen($clean) > $maxLen)
		{
			$clean = substr($clean, 0, $maxLen);
		}

		return $clean;
	}

	private function buildListElementName(int $userId): string
	{
		$template = $this->getConfigString('LIST_ELEMENT_NAME_TEMPLATE', 'Уровень счастья — #DATE#, #USER#');
		$userLabel = '';
		if ($userId > 0)
		{
			$rsUser = \CUser::GetByID($userId);
			if ($arUser = $rsUser->Fetch())
			{
				$full = trim((string)($arUser['NAME'] ?? '').' '.(string)($arUser['LAST_NAME'] ?? ''));
				$userLabel = $full !== '' ? $full : (string)($arUser['LOGIN'] ?? (string)$userId);
			}
		}
		if ($userLabel === '')
		{
			$userLabel = (string)$userId;
		}
		$dateStr = date('d.m.Y H:i');
		$name = str_replace(['#DATE#', '#USER#'], [$dateStr, $userLabel], $template);
		if (function_exists('mb_substr'))
		{
			if (mb_strlen($name) > 255)
			{
				$name = mb_substr($name, 0, 252).'…';
			}
		}
		elseif (strlen($name) > 255)
		{
			$name = substr($name, 0, 252).'…';
		}

		return $name;
	}

	/**
	 * @return array{success: bool, error: string}
	 */
	private function addLowScoreAppealToList(int $userId, int $level, string $message): array
	{
		$iblockId = $this->getConfigInt('LIST_IBLOCK_ID', 0);
		if ($iblockId <= 0)
		{
			return [
				'success' => false,
				'error' => $this->getConfigString('API_ERROR_LIST_SAVE', 'Не удалось сохранить обращение в список. Попробуйте позже или обратитесь к администратору.'),
			];
		}

		if (!Loader::includeModule('iblock'))
		{
			return [
				'success' => false,
				'error' => $this->getConfigString('API_ERROR_LIST_SAVE', 'Не удалось сохранить обращение в список. Попробуйте позже или обратитесь к администратору.'),
			];
		}

		Loader::includeModule('lists');

		$messageClean = $this->sanitizeAppealMessage($message);
		if ($messageClean === '')
		{
			return [
				'success' => false,
				'error' => $this->getConfigString('API_ERROR_REASON_REQUIRED', 'Для оценки 1–3 укажите причину в поле сообщения.'),
			];
		}

		// Поле «привязка к сотруднику»: обычно достаточно user id. Если CIBlockElement::Add вернёт ошибку
		// формата свойства — на портале проверьте тип поля и при необходимости задайте значение как ['VALUE' => $userId].
		$propEmployee = $this->getConfigString('LIST_PROPERTY_SOTRUDNIK', 'SOTRUDNIK');
		$propScore = $this->getConfigString('LIST_PROPERTY_OTSENKA', 'OTSENKA');
		$propText = $this->getConfigString('LIST_PROPERTY_SOOBSHCHENIE', 'SOOBSHCHENIE');
		$listCreatedByUserId = $this->getConfigInt('LIST_CREATED_BY_USER_ID', 1);
		if ($listCreatedByUserId <= 0)
		{
			$listCreatedByUserId = 1;
		}

		$arFields = [
			'IBLOCK_ID' => $iblockId,
			'NAME' => $this->buildListElementName($userId),
			'ACTIVE' => 'Y',
			'CREATED_BY' => $listCreatedByUserId,
			'MODIFIED_BY' => $listCreatedByUserId,
			'PROPERTY_VALUES' => [
				$propEmployee => $userId,
				$propScore => $level,
				$propText => $messageClean,
			],
		];

		$groupId = $this->getConfigInt('LIST_SOCNET_GROUP_ID', 0);
		if ($groupId > 0)
		{
			$arFields['SOCNET_GROUP_ID'] = $groupId;
		}

		$el = new \CIBlockElement();
		$newId = (int)$el->Add($arFields);
		if ($newId <= 0)
		{
			$lastError = (string)$el->LAST_ERROR;
			if ($lastError !== '')
			{
				AddMessage2Log('[level.happiness] List element add failed: '.$lastError, 'level.happiness');
			}

			return [
				'success' => false,
				'error' => $this->getConfigString('API_ERROR_LIST_SAVE', 'Не удалось сохранить обращение в список. Попробуйте позже или обратитесь к администратору.'),
			];
		}

		return ['success' => true, 'error' => ''];
	}

	private function getMinLevel(): int
	{
		$minLevel = $this->getConfigInt('MIN_LEVEL', self::MIN_LEVEL);

		return $minLevel > 0 ? $minLevel : self::MIN_LEVEL;
	}

	private function getMaxLevel(): int
	{
		$minLevel = $this->getMinLevel();
		$maxLevel = $this->getConfigInt('MAX_LEVEL', self::MAX_LEVEL);

		return $maxLevel >= $minLevel ? $maxLevel : $minLevel;
	}

	private function getLevelOptionsDesc(): array
	{
		$minLevel = $this->getMinLevel();
		$maxLevel = $this->getMaxLevel();
		$levels = [];

		for ($level = $maxLevel; $level >= $minLevel; $level--)
		{
			$levels[] = $level;
		}

		return $levels;
	}

	private function getBarColors(): array
	{
		$defaultColors = [
			5 => '#4CAF50',
			4 => '#2196F3',
			3 => '#00bcd4',
			2 => '#ff9800',
			1 => '#f44336',
		];
		$configColors = $this->getConfigArray('BAR_COLORS');
		$colors = [];

		foreach ($this->getLevelOptionsDesc() as $level)
		{
			$levelKey = (string)$level;
			$colors[$level] = isset($configColors[$levelKey])
				? (string)$configColors[$levelKey]
				: ($defaultColors[$level] ?? '#6f7bb2');
		}

		return $colors;
	}

	private function getFrontendConfig(): array
	{
		$minLevel = $this->getMinLevel();
		$maxLevel = $this->getMaxLevel();
		$lowScoreMax = $this->getLowScoreMaxLevel();

		return [
			'componentName' => $this->getDetectedComponentName(),
			'saveActionName' => $this->getConfigString('SAVE_ACTION_NAME', 'saveLevel'),
			'minLevel' => $minLevel,
			'maxLevel' => $maxLevel,
			'lowScoreMaxLevel' => $lowScoreMax,
			'levelOptions' => $this->getLevelOptionsDesc(),
			'starSymbol' => $this->getConfigString('STAR_SYMBOL', '⭐'),
			'starSymbolFilled' => $this->getConfigString('STAR_SYMBOL_FILLED', '★'),
			'starSymbolEmpty' => $this->getConfigString('STAR_SYMBOL_EMPTY', '☆'),
			'enableWidgetMove' => $this->getConfigBool('ENABLE_WIDGET_MOVE', true),
			'widgetMoveTargetId' => $this->getConfigString('WIDGET_MOVE_TARGET_ID', 'pulse_open_btn'),
			'widgetMovePosition' => $this->getConfigString('WIDGET_MOVE_POSITION', 'afterend'),
			'domObserverTimeoutMs' => $this->getConfigInt('DOM_OBSERVER_TIMEOUT_MS', 5000),
			'uiNotifyAutohideMs' => $this->getConfigInt('UI_NOTIFY_AUTOHIDE_MS', 3000),
			'messages' => [
				'selectLevel' => $this->getConfigString('UI_MSG_SELECT_LEVEL', 'Выбери уровень от '.$minLevel.' до '.$maxLevel.' звезд.'),
				'saveSuccess' => $this->getConfigString('UI_MSG_SAVE_SUCCESS', 'Оценка сохранена.'),
				'saveError' => $this->getConfigString('UI_MSG_SAVE_ERROR', 'Не удалось сохранить оценку.'),
				'reasonRequired' => $this->getConfigString(
					'UI_MSG_REASON_REQUIRED',
					'Опишите, что вас не устраивает (обязательно для оценок '.$minLevel.'–'.$lowScoreMax.' звёзд).'
				),
				'lowScoreModalTitle' => $this->getConfigString('UI_LOW_SCORE_MODAL_TITLE', 'Что пошло не так?'),
				'lowScoreModalHint' => $this->getConfigString('UI_LOW_SCORE_MODAL_HINT', 'Кратко опишите причину — сообщение увидит руководство. Без текста оценка не будет сохранена.'),
				'lowScoreModalPlaceholder' => $this->getConfigString('UI_LOW_SCORE_MODAL_PLACEHOLDER', 'Например: нагрузка, процессы, обратная связь…'),
				'lowScoreModalSubmit' => $this->getConfigString('UI_LOW_SCORE_MODAL_SUBMIT', 'Отправить оценку'),
				'lowScoreModalCancel' => $this->getConfigString('UI_LOW_SCORE_MODAL_CANCEL', 'Отмена'),
			],
		];
	}

	private function getCompanyName(): string
	{
		$companyName = trim((string)($this->arParams['COMPANY_NAME'] ?? $this->getConfigString('COMPANY_NAME', 'Аксиом')));

		return $companyName !== '' ? $companyName : $this->getConfigString('COMPANY_NAME', 'Аксиом');
	}

	private function isSystemNotifyEnabled(): bool
	{
		return strtoupper((string)($this->arParams['ENABLE_SYSTEM_NOTIFY'] ?? ($this->getConfigBool('ENABLE_SYSTEM_NOTIFY', true) ? 'Y' : 'N'))) === 'Y';
	}

	private function buildNotifyMessage(string $message): string
	{
		return '[ '.$this->getCompanyName().' ]'."\n".$message;
	}

	private function getDetectedComponentName(): string
	{
		return basename(dirname(__DIR__)).':'.basename(__DIR__);
	}

	private function getDefaultDataFilePath(): string
	{
		return '/local/components/'.basename(dirname(__DIR__)).'/'.basename(__DIR__).'/data/levelHappiness.json';
	}

	private function getDefaultConfig(): array
	{
		static $config;

		if (is_array($config))
		{
			return $config;
		}

		$configPath = __DIR__.'/config.php';
		$loadedConfig = is_file($configPath) ? require $configPath : [];
		$config = is_array($loadedConfig) ? $loadedConfig : [];

		return $config;
	}

	private function getConfigString(string $key, string $fallback): string
	{
		$config = $this->getDefaultConfig();

		return isset($config[$key]) ? (string)$config[$key] : $fallback;
	}

	private function getConfigInt(string $key, int $fallback): int
	{
		$config = $this->getDefaultConfig();
		if (!isset($config[$key]))
		{
			return $fallback;
		}

		return (int)$config[$key];
	}

	private function getConfigBool(string $key, bool $fallback): bool
	{
		$config = $this->getDefaultConfig();
		if (!isset($config[$key]))
		{
			return $fallback;
		}

		$value = $config[$key];
		if (is_bool($value))
		{
			return $value;
		}

		return strtoupper((string)$value) === 'Y' || (string)$value === '1';
	}

	private function getConfigArray(string $key): array
	{
		$config = $this->getDefaultConfig();

		return isset($config[$key]) && is_array($config[$key]) ? $config[$key] : [];
	}

	private function notifyUser(int $staffId, string $message): void
	{
		if (!$this->isSystemNotifyEnabled())
		{
			return;
		}

		if (!Loader::includeModule('im') || !class_exists('CIMNotify'))
		{
			return;
		}

		\CIMNotify::Add([
			'TO_USER_ID' => $staffId,
			'FROM_USER_ID' => 0,
			'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
			'NOTIFY_MODULE' => 'intranet',
			'NOTIFY_EVENT' => 'level_happiness',
			'NOTIFY_MESSAGE' => $message,
		]);
	}
}
