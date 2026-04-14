<?php
/**
 * CallTouch IB54 lookup helper for BP.
 */

declare(strict_types=1);

/**
 * @return array<string, string>
 */
function cb_calltouch_lookup_blank_vars(): array
{
    $keys = [
        'CB_RESULT', 'CB_ERROR_MSG', 'CB_DOMAIN', 'CB_SOURCE_ID', 'CB_ASSIGNED_BY_ID', 'CB_OBSERVER_IDS',
        'CB_CITY_ID', 'CB_ISPOLNITEL', 'CB_INFOPOVOD'
    ];
    return array_fill_keys($keys, '');
}

/**
 * @return array{element: array<string, mixed>, properties: array<string, array<string, mixed>>}|false
 */
function cb_calltouch_get_ib54_element_by_name_and_site_id(string $nameKey, string $siteId, int $iblockId = 54)
{
    \CModule::IncludeModule('iblock');
    \CModule::IncludeModule('lists');

    $siteIdNum = (int)$siteId;
    $siteIdStr = (string)$siteId;
    $element = false;
    $variants = [
        $siteIdNum,
        $siteIdStr,
        ['VALUE' => $siteIdNum],
        ['VALUE' => $siteIdStr],
    ];

    foreach ($variants as $variant) {
        if (($siteIdNum <= 0) && is_array($variant)) {
            continue;
        }
        $filter = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $nameKey,
            'ACTIVE' => 'Y',
            'PROPERTY_199' => $variant,
        ];

        $dbRes = \CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'CODE', 'XML_ID', 'PROPERTY_191', 'PROPERTY_192', 'PROPERTY_193', 'PROPERTY_194']
        );
        $element = $dbRes->Fetch();
        if ($element) {
            break;
        }
    }

    if (!$element) {
        return false;
    }

    $properties = [];
    $dbProps = \CIBlockElement::GetProperty($iblockId, (int)$element['ID']);
    while ($arProp = $dbProps->Fetch()) {
        $code = $arProp['CODE'];
        $value = !empty($arProp['VALUE_NUM']) ? $arProp['VALUE_NUM'] : $arProp['VALUE'];

        if (!isset($properties[$code])) {
            $properties[$code] = [
                'CODE' => $code,
                'NAME' => $arProp['NAME'],
                'VALUE' => $value,
                'VALUE_ENUM' => $arProp['VALUE_ENUM'] ?? null,
                'VALUE_NUM' => $arProp['VALUE_NUM'] ?? null,
            ];
        } else {
            if (!is_array($properties[$code]['VALUE'])) {
                $properties[$code]['VALUE'] = [$properties[$code]['VALUE'], $value];
            } else {
                $properties[$code]['VALUE'][] = $value;
            }
        }
    }

    return ['element' => $element, 'properties' => $properties];
}

function cb_calltouch_get_source_id_from_list19(int $sourceElementId, int $iblockId = 19): string
{
    $dbProps = \CIBlockElement::GetProperty($iblockId, $sourceElementId, [], ['CODE' => 'PROPERTY_73']);
    while ($arProp = $dbProps->Fetch()) {
        if ($arProp['CODE'] === 'PROPERTY_73' && !empty($arProp['VALUE'])) {
            return (string)$arProp['VALUE'];
        }
    }
    return '';
}

function cb_calltouch_get_assigned_by_id_from_list22(int $cityElementId, int $iblockId = 22): string
{
    $dbProps = \CIBlockElement::GetProperty($iblockId, $cityElementId, [], ['CODE' => 'PROPERTY_185']);
    while ($arProp = $dbProps->Fetch()) {
        if ($arProp['CODE'] === 'PROPERTY_185' && !empty($arProp['VALUE'])) {
            return (string)(int)$arProp['VALUE'];
        }
    }
    return '';
}

/**
 * @return array<int, string>
 */
function cb_calltouch_extract_observers_from_ib54(int $elementId, int $iblockId = 54): array
{
    $observers = [];
    $propRes = \CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => 'PROPERTY_195']);
    while ($prop = $propRes->GetNext()) {
        if (!empty($prop['VALUE'])) {
            $observers[] = (string)$prop['VALUE'];
        }
    }
    return $observers;
}

/**
 * @return array{
 *   result: 'parsed'|'not_parsed'|'error',
 *   error_msg: string,
 *   vars: array<string, string>,
 *   log_line?: string
 * }
 */
function cb_calltouch_lookup_ib54(string $nameKey, string $siteId, string $subPoolName = ''): array
{
    $blank = cb_calltouch_lookup_blank_vars();

    try {
        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            $blank['CB_RESULT'] = 'not parsed';
            $blank['CB_ERROR_MSG'] = 'Модуль iblock не доступен';
            return ['result' => 'error', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
        }

        $lookupName = trim($nameKey);
        $lookupSiteId = trim($siteId);
        $fallbackSubPool = trim($subPoolName);

        if ($lookupName === '' || $lookupSiteId === '') {
            $blank['CB_RESULT'] = 'not parsed';
            $blank['CB_ERROR_MSG'] = 'CallTouch lookup requires nameKey and siteId';
            return ['result' => 'not_parsed', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
        }

        $elementData = cb_calltouch_get_ib54_element_by_name_and_site_id($lookupName, $lookupSiteId, 54);
        $effectiveName = $lookupName;

        if (!$elementData && $fallbackSubPool !== '' && strcasecmp($fallbackSubPool, 'null') !== 0 && $fallbackSubPool !== $lookupName) {
            $elementData = cb_calltouch_get_ib54_element_by_name_and_site_id($fallbackSubPool, $lookupSiteId, 54);
            if ($elementData) {
                $effectiveName = $fallbackSubPool;
            }
        }

        if (!$elementData) {
            $blank['CB_RESULT'] = 'not parsed';
            $blank['CB_ERROR_MSG'] = "CallTouch IB54 pair not found: NAME='$lookupName', PROPERTY_199='$lookupSiteId'";
            return ['result' => 'not_parsed', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
        }

        $element = $elementData['element'];
        $properties = $elementData['properties'];

        $vars = $blank;
        $vars['CB_DOMAIN'] = (string)($element['NAME'] ?? $effectiveName);
        $vars['CB_RESULT'] = 'parsed';
        $vars['CB_ERROR_MSG'] = '';

        if (!empty($properties['PROPERTY_192']['VALUE'])) {
            $vars['CB_SOURCE_ID'] = cb_calltouch_get_source_id_from_list19((int)$properties['PROPERTY_192']['VALUE'], 19);
        }
        if (!empty($properties['PROPERTY_191']['VALUE'])) {
            $vars['CB_CITY_ID'] = (string)$properties['PROPERTY_191']['VALUE'];
            $vars['CB_ASSIGNED_BY_ID'] = cb_calltouch_get_assigned_by_id_from_list22((int)$properties['PROPERTY_191']['VALUE'], 22);
        }
        if (!empty($properties['PROPERTY_193']['VALUE'])) {
            $vars['CB_ISPOLNITEL'] = (string)$properties['PROPERTY_193']['VALUE'];
        }
        if (!empty($properties['PROPERTY_194']['VALUE'])) {
            $vars['CB_INFOPOVOD'] = (string)$properties['PROPERTY_194']['VALUE'];
        }

        $observers = cb_calltouch_extract_observers_from_ib54((int)$element['ID'], 54);
        if (!empty($observers)) {
            $vars['CB_OBSERVER_IDS'] = implode(',', $observers);
        }

        return [
            'result' => 'parsed',
            'error_msg' => '',
            'vars' => $vars,
            'log_line' => '[calltouch_lookup] parsed | name=' . $vars['CB_DOMAIN'] . ' | siteId=' . $lookupSiteId . ' | elementId=' . (string)($element['ID'] ?? ''),
        ];
    } catch (Throwable $e) {
        $blank['CB_RESULT'] = 'not parsed';
        $blank['CB_ERROR_MSG'] = 'Lookup error: ' . $e->getMessage();
        return ['result' => 'error', 'error_msg' => $blank['CB_ERROR_MSG'], 'vars' => $blank];
    }
}
