<?php
$componentVendor = basename(dirname(__DIR__));
$componentCode = basename(__DIR__);
$componentName = $componentVendor.':'.$componentCode;
$dataFile = '/local/components/'.$componentVendor.'/'.$componentCode.'/data/levelHappiness.json';

return [
	'DATA_FILE' => $dataFile,
	'WIDGET_STYLE' => 'custom',
	'COMPANY_NAME' => 'Команда Мечты',
	'ENABLE_SYSTEM_NOTIFY' => 'Y',

	'COMPONENT_NAME' => $componentName,
	'SAVE_ACTION_NAME' => 'saveLevel',

	'MIN_LEVEL' => 1,
	'MAX_LEVEL' => 5,
	'STAR_SYMBOL' => '⭐',
	'STAR_SYMBOL_FILLED' => '★',
	'STAR_SYMBOL_EMPTY' => '☆',
	'LEVEL_LABEL_SUFFIX' => 'звезд',
	'BAR_COLORS' => [
		5 => '#4CAF50',
		4 => '#2196F3',
		3 => '#00bcd4',
		2 => '#ff9800',
		1 => '#f44336',
	],

	'FORM_TITLE' => 'Уровень счастья сотрудников',
	'FORM_QUESTION' => 'Какой твой уровень счастья сегодня!?',
	'ANONYMITY_TEXT' => 'Статистика полностью 🕵🏻‍♀️ анонимная!',
	'USER_LEVEL_PREFIX' => 'Ваш уровень счастья:',
	'BUTTON_TEXT' => 'Сохранить',
	'ENABLE_MANAGEMENT_BUTTON' => 'Y',
	'MANAGEMENT_BUTTON_TEXT' => 'Написать руководству',
	'MANAGEMENT_URL' => 'https://kmechty.ru/',
	'SELECT_PLACEHOLDER' => 'Мой уровень',

	'ENABLE_WIDGET_MOVE' => 'Y',
	'WIDGET_MOVE_TARGET_ID' => 'pulse_open_btn',
	'WIDGET_MOVE_POSITION' => 'afterend',
	'DOM_OBSERVER_TIMEOUT_MS' => 5000,
	'UI_NOTIFY_AUTOHIDE_MS' => 3000,

	'UI_MSG_SELECT_LEVEL' => 'Выбери уровень от 1 до 5 звезд.',
	'UI_MSG_SAVE_SUCCESS' => 'Оценка сохранена.',
	'UI_MSG_SAVE_ERROR' => 'Не удалось сохранить оценку.',
	'API_ERROR_EMPTY' => 'Заполнены не все поля.',
	'API_ERROR_INVALID' => 'Некорректный уровень счастья.',
	'API_ERROR_SAVE' => 'Не удалось сохранить оценку.',
	'API_ERROR_UNAUTHORIZED' => 'Пользователь не авторизован.',

	'NOTIFY_MESSAGE_SUCCESS' => 'Ваш уровень счастья изменен! 🚀',
	'NOTIFY_MESSAGE_EMPTY' => 'Ошибка! Заполнены не все поля! ❌',
	'NOTIFY_MESSAGE_RANGE' => "Ошибка!\nЗначение выходит за пределы диапазона! Маленький ты хакер 😼",
	'NOTIFY_MESSAGE_REASON_REQUIRED' => 'Укажите причину низкой оценки в форме обращения.',
	'NOTIFY_MESSAGE_LIST_SAVE_FAIL' => 'Не удалось сохранить обращение в список. Оценка не изменена.',

	'LOW_SCORE_MAX_LEVEL' => 3,
	'LIST_IBLOCK_TYPE_ID' => 'lists_socnet',
	'LIST_IBLOCK_ID' => 142,
	'LIST_SOCNET_GROUP_ID' => 1,
	'LIST_CREATED_BY_USER_ID' => 1,
	'LIST_USE_SERVICE_USER_AUTHORIZATION' => 'Y',
	'LIST_PROPERTY_SOTRUDNIK' => 'SOTRUDNIK',
	'LIST_PROPERTY_OTSENKA' => 'OTSENKA',
	'LIST_PROPERTY_SOOBSHCHENIE' => 'SOOBSHCHENIE',
	'LIST_ELEMENT_NAME_TEMPLATE' => 'Уровень счастья — #DATE#, #USER#',
	'MESSAGE_MAX_LENGTH' => 4000,

	'API_ERROR_REASON_REQUIRED' => 'Для низкой оценки укажите причину в поле сообщения.',
	'API_ERROR_LIST_SAVE' => 'Не удалось сохранить обращение в список. Попробуйте позже или обратитесь к администратору.',

	'UI_MSG_REASON_REQUIRED' => 'Опишите, что вас не устраивает (обязательно для низких оценок).',
	'UI_LOW_SCORE_MODAL_TITLE' => 'Что пошло не так?',
	'UI_LOW_SCORE_MODAL_HINT' => 'Кратко опишите причину — сообщение увидит руководство. Без текста оценка не будет сохранена.',
	'UI_LOW_SCORE_MODAL_PLACEHOLDER' => 'Например: нагрузка, процессы, обратная связь…',
	'UI_LOW_SCORE_MODAL_SUBMIT' => 'Отправить оценку',
	'UI_LOW_SCORE_MODAL_CANCEL' => 'Отмена',
];
