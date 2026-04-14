<?php
/**
 * Функции для работы с инфоблоками через нативный API Bitrix24
 * Использует CIBlockElement вместо прямых SQL-запросов
 */

require_once(__DIR__ . '/bitrix_init.php');

/**
 * Получение данных элемента из инфоблока 54 по паре NAME + PROPERTY_199 (siteId)
 * 
 * @param string $nameKey Название элемента (источник)
 * @param string $siteId ID сайта CallTouch (PROPERTY_199)
 * @param array $config Конфигурация
 * @return array|false Массив с данными элемента и свойствами или false
 */
function getElementDataFromIblock54BySubPoolAndSiteId($nameKey, $siteId, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    logMessage("getElementDataFromIblock54BySubPoolAndSiteId: получаем данные для nameKey='$nameKey', siteId='$siteId'", $logFile, $config);
    
    try {
        CModule::IncludeModule("iblock");
        CModule::IncludeModule("lists");
        
        $iblockId = $config['iblock']['iblock_54_id'] ?? 54;
        
        // Фильтр для поиска элемента по NAME и PROPERTY_199 (siteId)
        // ВАЖНО: PROPERTY_195 (наблюдатели) НЕ используется для поиска, только для заполнения лида
        // PROPERTY_199 - свойство типа "Число", поэтому передаем числовое значение
        // В CIBlockElement::GetList свойства фильтруются через ключ 'PROPERTY_XXX'
        // Для числовых свойств пробуем разные варианты: число, строка, массив с VALUE
        $siteIdNum = (int)$siteId;
        $siteIdStr = (string)$siteId;
        
        $element = null;
        $triedVariants = [];
        
        // Вариант 1: Числовое значение напрямую
        $filter = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $nameKey,
            'ACTIVE' => 'Y',
            'PROPERTY_199' => $siteIdNum,
        ];
        $triedVariants[] = "PROPERTY_199=$siteIdNum (число)";
        logMessage("getElementDataFromIblock54BySubPoolAndSiteId: [ВАРИАНТ 1] фильтр поиска: NAME='$nameKey', PROPERTY_199=$siteIdNum (тип: число, исходное значение: '$siteId')", $logFile, $config);
        
        $dbRes = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'CODE', 'XML_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
        );
        $element = $dbRes->Fetch();
        
        // Вариант 2: Строковое значение (если в БД хранится как строка)
        if (!$element && $siteIdNum > 0) {
            $filter['PROPERTY_199'] = $siteIdStr;
            $triedVariants[] = "PROPERTY_199='$siteIdStr' (строка)";
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: [ВАРИАНТ 2] не найден по числу, пробуем строку: PROPERTY_199='$siteIdStr'", $logFile, $config);
            $dbRes = CIBlockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'CODE', 'XML_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
            );
            $element = $dbRes->Fetch();
        }
        
        // Вариант 3: Массив с VALUE (для некоторых типов свойств в Bitrix24)
        if (!$element && $siteIdNum > 0) {
            $filter['PROPERTY_199'] = ['VALUE' => $siteIdNum];
            $triedVariants[] = "PROPERTY_199=['VALUE' => $siteIdNum] (массив с числом)";
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: [ВАРИАНТ 3] не найден, пробуем массив с числом: PROPERTY_199=['VALUE' => $siteIdNum]", $logFile, $config);
            $dbRes = CIBlockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'CODE', 'XML_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
            );
            $element = $dbRes->Fetch();
        }
        
        // Вариант 4: Массив с VALUE и строкой
        if (!$element && $siteIdNum > 0) {
            $filter['PROPERTY_199'] = ['VALUE' => $siteIdStr];
            $triedVariants[] = "PROPERTY_199=['VALUE' => '$siteIdStr'] (массив со строкой)";
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: [ВАРИАНТ 4] не найден, пробуем массив со строкой: PROPERTY_199=['VALUE' => '$siteIdStr']", $logFile, $config);
            $dbRes = CIBlockElement::GetList(
                ['ID' => 'ASC'],
                $filter,
                false,
                ['nTopCount' => 1],
                ['ID', 'NAME', 'CODE', 'XML_ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']
            );
            $element = $dbRes->Fetch();
        }
        
        if (!$element) {
            $variantsStr = implode(', ', $triedVariants);
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: элемент с NAME='$nameKey' не найден. Испробованы варианты: $variantsStr", $logFile, $config);
            return false;
        }
        
        $foundElementId = (int)$element['ID'];
        
        logMessage("getElementDataFromIblock54BySubPoolAndSiteId: найден элемент ID=$foundElementId с NAME='" . $element['NAME'] . "' и PROPERTY_199=$siteIdNum", $logFile, $config);
        
        // Получаем все свойства элемента
        $properties = [];
        $dbProps = CIBlockElement::GetProperty($iblockId, $foundElementId);
        
        while ($arProp = $dbProps->Fetch()) {
            $code = $arProp['CODE'];
            
            // Для свойств типа "Привязка к пользователю" используем VALUE_NUM, если оно есть
            // Иначе используем VALUE
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
                // Обработка множественных значений
                if (!is_array($properties[$code]['VALUE'])) {
                    $properties[$code]['VALUE'] = [$properties[$code]['VALUE'], $value];
                } else {
                    $properties[$code]['VALUE'][] = $value;
                }
            }
        }
        
        // Логируем PROPERTY_195 для отладки
        if (isset($properties['PROPERTY_195'])) {
            logMessage("getElementDataFromIblock54BySubPoolAndSiteId: PROPERTY_195 найдено: " . json_encode($properties['PROPERTY_195']), $logFile, $config);
        }
        
        // Логирование уже выполнено выше
        
        return [
            'element' => $element,
            'properties' => $properties
        ];
        
    } catch (Exception $e) {
        logMessage("getElementDataFromIblock54BySubPoolAndSiteId: ОШИБКА: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

/**
 * Получение SOURCE_ID из списка 19 по ID элемента
 * 
 * @param int $sourceElementId ID элемента в списке 19
 * @param array $config Конфигурация
 * @return string|false Значение PROPERTY_73 или false
 */
function getSourceIdFromList19($sourceElementId, $config) {
    try {
        CModule::IncludeModule("iblock");
        
        $iblockId = $config['iblock']['iblock_19_id'] ?? 19;
        
        // Получаем PROPERTY_73 из элемента
        $dbProps = CIBlockElement::GetProperty($iblockId, $sourceElementId, [], ['CODE' => 'PROPERTY_73']);
        
        while ($arProp = $dbProps->Fetch()) {
            if ($arProp['CODE'] === 'PROPERTY_73' && !empty($arProp['VALUE'])) {
                return $arProp['VALUE'];
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        $logFile = $config['global_log'] ?? 'calltouch_common.log';
        logMessage("getSourceIdFromList19: ОШИБКА: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

/**
 * Получение ASSIGNED_BY_ID из списка 22 по ID города
 * 
 * @param int $cityElementId ID элемента города в списке 22
 * @param array $config Конфигурация
 * @return int|false ID пользователя (ASSIGNED_BY_ID) или false
 */
function getAssignedByIdFromList22($cityElementId, $config) {
    $logFile = $config['global_log'] ?? 'calltouch_common.log';
    logMessage("getAssignedByIdFromList22: начало выполнения для города ID=$cityElementId", $logFile, $config);
    
    try {
        CModule::IncludeModule("iblock");
        
        $iblockId = $config['iblock']['iblock_22_id'] ?? 22;
        
        logMessage("getAssignedByIdFromList22: ищем элемент с ID=$cityElementId в списке 22", $logFile, $config);
        
        // Получаем PROPERTY_185 из элемента списка 22
        $dbProps = CIBlockElement::GetProperty($iblockId, $cityElementId, [], ['CODE' => 'PROPERTY_185']);
        
        while ($arProp = $dbProps->Fetch()) {
            if ($arProp['CODE'] === 'PROPERTY_185' && !empty($arProp['VALUE'])) {
                $assignedById = (int)$arProp['VALUE'];
                logMessage("getAssignedByIdFromList22: найден PROPERTY_185 = $assignedById", $logFile, $config);
                return $assignedById;
            }
        }
        
        logMessage("getAssignedByIdFromList22: PROPERTY_185 не найден или пуст для элемента ID=$cityElementId в списке 22", $logFile, $config);
        return false;
        
    } catch (Exception $e) {
        logMessage("getAssignedByIdFromList22: ОШИБКА: " . $e->getMessage(), $logFile, $config);
        return false;
    }
}

