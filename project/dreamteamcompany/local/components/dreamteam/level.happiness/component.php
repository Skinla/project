<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

\CBitrixComponent::includeComponentClass(__FILE__);

$component = new LevelHappinessComponent($this);
$component->executeComponent();
