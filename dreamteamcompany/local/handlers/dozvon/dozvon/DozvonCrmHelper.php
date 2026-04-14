<?php
/**
 * Работа с CRM для скриптов недозвона: телефон лида.
 * Размещение: local/handlers/dozvon/DozvonCrmHelper.php.
 * Получение телефона лида из CRM (при необходимости в других скриптах). При обработке очереди и триггеров телефон берётся из полей элементов списка (PHONE).
 */

if (!function_exists('dozvon_get_lead_phone')) {
    /**
     * Получить телефон лида из CRM (CCrmFieldMulti или LeadTable).
     */
    function dozvon_get_lead_phone(int $leadId): ?string
    {
        if (!\Bitrix\Main\Loader::includeModule('crm')) {
            return null;
        }
        if (class_exists('\CCrmFieldMulti')) {
            $res = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                ['ENTITY_ID' => 'LEAD', 'ELEMENT_ID' => $leadId, 'TYPE_ID' => 'PHONE']
            );
            if ($row = $res->Fetch()) {
                return isset($row['VALUE']) ? trim((string)$row['VALUE']) : null;
            }
        }
        if (class_exists('\Bitrix\Crm\LeadTable')) {
            $row = \Bitrix\Crm\LeadTable::getById($leadId)->fetch();
            if ($row && !empty($row['PHONE'])) {
                return trim((string)$row['PHONE']);
            }
        }
        return null;
    }
}
