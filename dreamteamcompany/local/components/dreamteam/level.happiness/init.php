<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	return;
}

global $APPLICATION;
if (!isset($APPLICATION) || !is_object($APPLICATION))
{
	return;
}

$componentVendor = basename(dirname(__DIR__));
$componentCode = basename(__DIR__);
$config = require __DIR__.'/config.php';
$componentName = (string)($config['COMPONENT_NAME'] ?? ($componentVendor.':'.$componentCode));
$requestedTemplate = strtolower(trim((string)($config['WIDGET_STYLE'] ?? 'custom')));
$allowedTemplates = ['custom', 'bitrix'];
$templateName = in_array($requestedTemplate, $allowedTemplates, true) ? $requestedTemplate : 'custom';
if (!is_dir(__DIR__.'/templates/'.$templateName))
{
	$templateName = is_dir(__DIR__.'/templates/custom') ? 'custom' : '.default';
}

$APPLICATION->IncludeComponent(
	$componentName,
	$templateName,
	[
		'CACHE_TYPE' => 'N',
		'CACHE_TIME' => 0,
	],
	false,
	['HIDE_ICONS' => 'N']
);
