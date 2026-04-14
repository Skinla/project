<?php
/**
 * Файл инициализации для кастомного кода
 * Подключает обработчики, агенты и прочие вспомогательные модули.
 */

\Bitrix\Main\Loader::includeModule('dt.main');

// Определяем константу DIR если не определена (на случай, если не определена в другом месте)
if (!defined('DIR')) {
	define('DIR', __DIR__);
}

// Список файлов для подключения
$initFiles = [
	// Кастомные обработчики
	// DIR . '/include/lead_duplicate_event_handler.php', // отключено: кастомная обработка/поиск дублей
	DIR . '/include/lead_bp224_rest_agent.php',

	// Хелперы
	DIR . '/include/helpers.php',

	// Обработчики событий
	DIR . '/handlers/on_prolog.php',
	DIR . '/handlers/on_epilog.php',
	DIR . '/handlers/message_add.php',
	DIR . '/handlers/user_add.php',
	DIR . '/handlers/user_update.php',
	DIR . '/handlers/on_call_end.php',
	DIR . '/handlers/on_after_add_file.php',
	DIR . '/handlers/on_task_update_bonus.php',

	// Агенты
	DIR . '/agents/birthday_set_flags.php',
	DIR . '/agents/birthday_send_messages.php',
];

foreach ($initFiles as $file) {
	if (file_exists($file)) {
		require_once $file;
	}
}

// ============================================================================
// ВИДЖЕТ "МОИ БОНУСЫ" - Подключение на страницу профиля
// ============================================================================

if (!defined('BONUS_WIDGET_EVENT_ATTACHED')) {
	define('BONUS_WIDGET_EVENT_ATTACHED', true);

	\Bitrix\Main\EventManager::getInstance()->addEventHandler(
		'main',
		'OnEndBufferContent',
		static function (&$content) {
			$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

			// Пропускаем AJAX запросы и POST
			if (
				(defined('PUBLIC_AJAX_MODE') && PUBLIC_AJAX_MODE === true)
				|| (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
				|| isset($_REQUEST['AJAX_CALL'])
				|| !preg_match('#^/company/personal/user/(\d+)/?$#', $uriPath, $matches)
			) {
				return;
			}

			$userId = (int)$matches[1];
			unset($userId); // переменная может быть использована в будущем

			// Проверяем, не добавлен ли уже скрипт
			if (strpos($content, 'intranet-user-profile-bonus-result') !== false) {
				return;
			}

			// Подключаем loader через Asset API
			if (\Bitrix\Main\Loader::includeModule('main')) {
				\Bitrix\Main\Page\Asset::getInstance()->addJs('/local/templates/.default/js/bonus-widget-loader.js');
			}

			// Также вставляем скрипт напрямую в body для надежности
			$scriptTag = '<script src="/local/templates/.default/js/bonus-widget-loader.js"></script>';
			if (strpos($content, '</body>') !== false) {
				$content = str_replace('</body>', $scriptTag . '</body>', $content);
			} else {
				$content .= $scriptTag;
			}
		}
	);
}

// ============================================================================
// Подключение модуля Power BI Widget
// ============================================================================

$powerBiInitPath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/powerbi/init.php';
if ($powerBiInitPath !== '' && file_exists($powerBiInitPath)) {
	require_once $powerBiInitPath;
}

// ============================================================================
// ОБРАБОТЧИК БАЗЫ ЗНАНИЙ - Отслеживание просмотров и запуск БП
// ============================================================================

$kbHandlerInitPath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/widgets/kb-handler/kb_init.php';
if ($kbHandlerInitPath !== '' && file_exists($kbHandlerInitPath)) {
	require_once $kbHandlerInitPath;
}

// ============================================================================
// Подключение виджета "Банк идей"
// ============================================================================

$ideaBankInitPath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/local/widgets/idea-bank/init.php';
if ($ideaBankInitPath !== '' && file_exists($ideaBankInitPath)) {
	require_once $ideaBankInitPath;
}

// ============================================================================
// VOXIMPLANT LINE SAVER - Сохранение выбранного исходящего номера
// ============================================================================

\Bitrix\Main\EventManager::getInstance()->addEventHandler(
	'main',
	'OnProlog',
	static function () {
		global $USER;
		if (is_object($USER) && $USER->IsAuthorized()) {
			\Bitrix\Main\Page\Asset::getInstance()->addJs('/local/js/voximplant-line-saver.js');
		}
	}
);

