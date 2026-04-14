<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$config = require __DIR__.'/config.php';

$arComponentParameters = [
	'PARAMETERS' => [
		'DATA_FILE' => [
			'PARENT' => 'BASE',
			'NAME' => 'Путь к JSON файлу',
			'TYPE' => 'STRING',
			'DEFAULT' => (string)($config['DATA_FILE'] ?? '/local/components/dreamteam/level.happiness/data/levelHappiness.json'),
		],
		'COMPANY_NAME' => [
			'PARENT' => 'BASE',
			'NAME' => 'Название компании',
			'TYPE' => 'STRING',
			'DEFAULT' => (string)($config['COMPANY_NAME'] ?? 'Аксиом'),
		],
		'ENABLE_SYSTEM_NOTIFY' => [
			'PARENT' => 'BASE',
			'NAME' => 'Отправлять системные уведомления',
			'TYPE' => 'CHECKBOX',
			'DEFAULT' => (string)($config['ENABLE_SYSTEM_NOTIFY'] ?? 'Y'),
		],
	],
];
