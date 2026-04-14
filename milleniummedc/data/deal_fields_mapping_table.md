# Сделки: сопоставление полей (облако ↔ коробка)

Дата выгрузки: облако — из `data/source_fields.json` (`crm.deal.fields`), коробка — из `data/target_fields.json` (`get_target_fields.php` по SSH).

Формат как у стадий: **название (подпись)**, **код в облаке**, **код на коробке**; «—» — поля нет на стороне. Код совпадает, если поле заведено с тем же именем на обоих порталах.

- Всего уникальных кодов: **145**
- В обоих порталах: **118**
- Только облако: **25**
- Только коробка: **2**

## Общая таблица (сначала есть везде, затем только облако, затем только коробка)

| Название (подпись) | Код (облако) | Код (коробка) | Примечание |
|:--|:--|:--|:--|
| ID | `ID` | `ID` | тип REST: `integer` → внутр. выгрузка: `string` |
| UF_CRM_1744280242440 | `UF_CRM_1744280242440` | `UF_CRM_1744280242440` | — |
| UF_CRM_1744282605 | `UF_CRM_1744282605` | `UF_CRM_1744282605` | — |
| UF_CRM_1744282618 | `UF_CRM_1744282618` | `UF_CRM_1744282618` | — |
| UF_CRM_1744294910179 | `UF_CRM_1744294910179` | `UF_CRM_1744294910179` | — |
| UF_CRM_1744606921467 | `UF_CRM_1744606921467` | `UF_CRM_1744606921467` | — |
| UF_CRM_1744638573001 | `UF_CRM_1744638573001` | `UF_CRM_1744638573001` | — |
| UF_CRM_1744798273460 | `UF_CRM_1744798273460` | `UF_CRM_1744798273460` | — |
| UF_CRM_1745922084756 | `UF_CRM_1745922084756` | `UF_CRM_1745922084756` | — |
| UF_CRM_1745922119016 | `UF_CRM_1745922119016` | `UF_CRM_1745922119016` | — |
| UF_CRM_1745922132212 | `UF_CRM_1745922132212` | `UF_CRM_1745922132212` | — |
| UF_CRM_1745922150559 | `UF_CRM_1745922150559` | `UF_CRM_1745922150559` | — |
| UF_CRM_1745922324196 | `UF_CRM_1745922324196` | `UF_CRM_1745922324196` | тип REST: `rest_66_b24duplicate__fields_duplicate` → внутр. выгрузка: `string` |
| UF_CRM_1745922855949 | `UF_CRM_1745922855949` | `UF_CRM_1745922855949` | — |
| UF_CRM_1745923182145 | `UF_CRM_1745923182145` | `UF_CRM_1745923182145` | — |
| UF_CRM_1747827168 | `UF_CRM_1747827168` | `UF_CRM_1747827168` | — |
| UF_CRM_1753084950145 | `UF_CRM_1753084950145` | `UF_CRM_1753084950145` | — |
| UF_CRM_1753087023468 | `UF_CRM_1753087023468` | `UF_CRM_1753087023468` | — |
| UF_CRM_1758296848937 | `UF_CRM_1758296848937` | `UF_CRM_1758296848937` | — |
| UF_CRM_1761053263850 | `UF_CRM_1761053263850` | `UF_CRM_1761053263850` | — |
| UF_CRM_1761053484330 | `UF_CRM_1761053484330` | `UF_CRM_1761053484330` | — |
| UF_CRM_1761053580038 | `UF_CRM_1761053580038` | `UF_CRM_1761053580038` | — |
| UF_CRM_1761053657001 | `UF_CRM_1761053657001` | `UF_CRM_1761053657001` | — |
| UF_CRM_1761053747112 | `UF_CRM_1761053747112` | `UF_CRM_1761053747112` | — |
| UF_CRM_682DC63419AD2 | `UF_CRM_682DC63419AD2` | `UF_CRM_682DC63419AD2` | тип REST: `rest_58_multifields_table` → внутр. выгрузка: `string` |
| UF_CRM_6877B9FE1FEF8 | `UF_CRM_6877B9FE1FEF8` | `UF_CRM_6877B9FE1FEF8` | — |
| UF_CRM_6877B9FE30B44 | `UF_CRM_6877B9FE30B44` | `UF_CRM_6877B9FE30B44` | — |
| UF_CRM_6877B9FE3CC74 | `UF_CRM_6877B9FE3CC74` | `UF_CRM_6877B9FE3CC74` | — |
| UF_CRM_6877B9FE47704 | `UF_CRM_6877B9FE47704` | `UF_CRM_6877B9FE47704` | — |
| UF_CRM_68A86DB812898 | `UF_CRM_68A86DB812898` | `UF_CRM_68A86DB812898` | — |
| UF_CRM_68B156B59C962 | `UF_CRM_68B156B59C962` | `UF_CRM_68B156B59C962` | — |
| UF_CRM_69006B083BFB3 | `UF_CRM_69006B083BFB3` | `UF_CRM_69006B083BFB3` | — |
| UF_CRM_69006B084D448 | `UF_CRM_69006B084D448` | `UF_CRM_69006B084D448` | — |
| UF_CRM_69006B085B2BD | `UF_CRM_69006B085B2BD` | `UF_CRM_69006B085B2BD` | — |
| UF_CRM_69006B086A6D9 | `UF_CRM_69006B086A6D9` | `UF_CRM_69006B086A6D9` | — |
| UF_CRM_69006B087A70C | `UF_CRM_69006B087A70C` | `UF_CRM_69006B087A70C` | — |
| UF_CRM_698339EAA5335 | `UF_CRM_698339EAA5335` | `UF_CRM_698339EAA5335` | — |
| UF_CRM_698339EACF14D | `UF_CRM_698339EACF14D` | `UF_CRM_698339EACF14D` | — |
| UF_CRM_698339EADF0A8 | `UF_CRM_698339EADF0A8` | `UF_CRM_698339EADF0A8` | — |
| UF_CRM_698339EAF039A | `UF_CRM_698339EAF039A` | `UF_CRM_698339EAF039A` | — |
| UF_CRM_698339EB0BD17 | `UF_CRM_698339EB0BD17` | `UF_CRM_698339EB0BD17` | — |
| UF_CRM_698339EB1C0ED | `UF_CRM_698339EB1C0ED` | `UF_CRM_698339EB1C0ED` | — |
| UF_CRM_698339EB2C30F | `UF_CRM_698339EB2C30F` | `UF_CRM_698339EB2C30F` | — |
| UF_CRM_69833F8FB7B6F | `UF_CRM_69833F8FB7B6F` | `UF_CRM_69833F8FB7B6F` | — |
| UF_CRM_69833F8FC8941 | `UF_CRM_69833F8FC8941` | `UF_CRM_69833F8FC8941` | — |
| UF_CRM_69833F8FD90E7 | `UF_CRM_69833F8FD90E7` | `UF_CRM_69833F8FD90E7` | — |
| UF_CRM_69833F900EF3F | `UF_CRM_69833F900EF3F` | `UF_CRM_69833F900EF3F` | — |
| UF_CRM_69833F9034501 | `UF_CRM_69833F9034501` | `UF_CRM_69833F9034501` | — |
| UF_CRM_6983446DDDC3B | `UF_CRM_6983446DDDC3B` | `UF_CRM_6983446DDDC3B` | — |
| UF_CRM_6983446E02B97 | `UF_CRM_6983446E02B97` | `UF_CRM_6983446E02B97` | — |
| UF_CRM_6983446EA1721 | `UF_CRM_6983446EA1721` | `UF_CRM_6983446EA1721` | — |
| UF_CRM_69835751D23B5 | `UF_CRM_69835751D23B5` | `UF_CRM_69835751D23B5` | — |
| UF_CRM_69835751E1785 | `UF_CRM_69835751E1785` | `UF_CRM_69835751E1785` | — |
| UF_CRM_698ACE5A57F7D | `UF_CRM_698ACE5A57F7D` | `UF_CRM_698ACE5A57F7D` | — |
| UF_CRM_FSSP_BAILIFF | `UF_CRM_FSSP_BAILIFF` | `UF_CRM_FSSP_BAILIFF` | — |
| UF_CRM_FSSP_DEPARTMENT | `UF_CRM_FSSP_DEPARTMENT` | `UF_CRM_FSSP_DEPARTMENT` | — |
| UF_CRM_FSSP_DETAILS | `UF_CRM_FSSP_DETAILS` | `UF_CRM_FSSP_DETAILS` | — |
| UF_CRM_FSSP_EXE_PRODUCTION | `UF_CRM_FSSP_EXE_PRODUCTION` | `UF_CRM_FSSP_EXE_PRODUCTION` | — |
| UF_CRM_FSSP_IP_END_DATE | `UF_CRM_FSSP_IP_END_DATE` | `UF_CRM_FSSP_IP_END_DATE` | — |
| UF_CRM_FSSP_IP_END_REASON | `UF_CRM_FSSP_IP_END_REASON` | `UF_CRM_FSSP_IP_END_REASON` | — |
| UF_CRM_FSSP_IP_START | `UF_CRM_FSSP_IP_START` | `UF_CRM_FSSP_IP_START` | — |
| UF_CRM_FSSP_NAME | `UF_CRM_FSSP_NAME` | `UF_CRM_FSSP_NAME` | — |
| UF_CRM_FSSP_SUBJECT | `UF_CRM_FSSP_SUBJECT` | `UF_CRM_FSSP_SUBJECT` | — |
| UF_CRM_P_VASNOMERTEL | `UF_CRM_P_VASNOMERTEL` | `UF_CRM_P_VASNOMERTEL` | — |
| UF_CRM_ST_FSSP_DATE_FL | `UF_CRM_ST_FSSP_DATE_FL` | `UF_CRM_ST_FSSP_DATE_FL` | — |
| UF_CRM_ST_FSSP_DATE_UL | `UF_CRM_ST_FSSP_DATE_UL` | `UF_CRM_ST_FSSP_DATE_UL` | — |
| UF_CRM_ST_FSSP_DEBTS_FL | `UF_CRM_ST_FSSP_DEBTS_FL` | `UF_CRM_ST_FSSP_DEBTS_FL` | — |
| UF_CRM_ST_FSSP_DEBTS_UL | `UF_CRM_ST_FSSP_DEBTS_UL` | `UF_CRM_ST_FSSP_DEBTS_UL` | — |
| UF_CRM_ST_FSSP_REPAID_SUMM_FL | `UF_CRM_ST_FSSP_REPAID_SUMM_FL` | `UF_CRM_ST_FSSP_REPAID_SUMM_FL` | — |
| UF_CRM_ST_FSSP_REPAID_SUMM_UL | `UF_CRM_ST_FSSP_REPAID_SUMM_UL` | `UF_CRM_ST_FSSP_REPAID_SUMM_UL` | — |
| UF_CRM_ST_FSSP_REPORT_FL | `UF_CRM_ST_FSSP_REPORT_FL` | `UF_CRM_ST_FSSP_REPORT_FL` | — |
| UF_CRM_ST_FSSP_REPORT_UL | `UF_CRM_ST_FSSP_REPORT_UL` | `UF_CRM_ST_FSSP_REPORT_UL` | — |
| UF_CRM_ST_FSSP_REQUEST_FL | `UF_CRM_ST_FSSP_REQUEST_FL` | `UF_CRM_ST_FSSP_REQUEST_FL` | — |
| UF_CRM_ST_FSSP_REQUEST_UL | `UF_CRM_ST_FSSP_REQUEST_UL` | `UF_CRM_ST_FSSP_REQUEST_UL` | — |
| UF_CRM_ST_FSSP_RESULT_FL | `UF_CRM_ST_FSSP_RESULT_FL` | `UF_CRM_ST_FSSP_RESULT_FL` | — |
| UF_CRM_ST_FSSP_RESULT_UL | `UF_CRM_ST_FSSP_RESULT_UL` | `UF_CRM_ST_FSSP_RESULT_UL` | — |
| UF_CRM_ST_FSSP_SUMM_FL | `UF_CRM_ST_FSSP_SUMM_FL` | `UF_CRM_ST_FSSP_SUMM_FL` | — |
| UF_CRM_ST_FSSP_SUMM_UL | `UF_CRM_ST_FSSP_SUMM_UL` | `UF_CRM_ST_FSSP_SUMM_UL` | — |
| UF_CRM_ST_FSSP_TRACKING_FL | `UF_CRM_ST_FSSP_TRACKING_FL` | `UF_CRM_ST_FSSP_TRACKING_FL` | — |
| UF_CRM_ST_FSSP_TRACKING_UL | `UF_CRM_ST_FSSP_TRACKING_UL` | `UF_CRM_ST_FSSP_TRACKING_UL` | — |
| UF_CRM_T_IP | `UF_CRM_T_IP` | `UF_CRM_T_IP` | — |
| UF_CRM_T_IPLOCATION | `UF_CRM_T_IPLOCATION` | `UF_CRM_T_IPLOCATION` | — |
| UF_CRM_T_NAZVANIEFOR | `UF_CRM_T_NAZVANIEFOR` | `UF_CRM_T_NAZVANIEFOR` | — |
| UF_CRM_T_STRANICA | `UF_CRM_T_STRANICA` | `UF_CRM_T_STRANICA` | — |
| UF_CRM_T_VASEIMA | `UF_CRM_T_VASEIMA` | `UF_CRM_T_VASEIMA` | — |
| UF_CRM_T_YMCLIENTID | `UF_CRM_T_YMCLIENTID` | `UF_CRM_T_YMCLIENTID` | — |
| облако: Валюта | коробка: CURRENCY_ID | `CURRENCY_ID` | `CURRENCY_ID` | тип REST: `crm_currency` → внутр. выгрузка: `string` |
| облако: Вероятность | коробка: PROBABILITY | `PROBABILITY` | `PROBABILITY` | тип REST: `integer` → внутр. выгрузка: `string` |
| облако: Внешний источник | коробка: ORIGINATOR_ID | `ORIGINATOR_ID` | `ORIGINATOR_ID` | — |
| облако: Воронка | коробка: CATEGORY_ID | `CATEGORY_ID` | `CATEGORY_ID` | тип REST: `crm_category` → внутр. выгрузка: `string` |
| облако: Группа стадии | коробка: STAGE_SEMANTIC_ID | `STAGE_SEMANTIC_ID` | `STAGE_SEMANTIC_ID` | — |
| облако: Дата завершения | коробка: CLOSEDATE | `CLOSEDATE` | `CLOSEDATE` | тип REST: `date` → внутр. выгрузка: `string` |
| облако: Дата изменения | коробка: DATE_MODIFY | `DATE_MODIFY` | `DATE_MODIFY` | тип REST: `datetime` → внутр. выгрузка: `string` |
| облако: Дата начала | коробка: BEGINDATE | `BEGINDATE` | `BEGINDATE` | тип REST: `date` → внутр. выгрузка: `string` |
| облако: Дата создания | коробка: DATE_CREATE | `DATE_CREATE` | `DATE_CREATE` | тип REST: `datetime` → внутр. выгрузка: `string` |
| облако: Дополнительно об источнике | коробка: SOURCE_DESCRIPTION | `SOURCE_DESCRIPTION` | `SOURCE_DESCRIPTION` | — |
| облако: Доступна для всех | коробка: OPENED | `OPENED` | `OPENED` | тип REST: `char` → внутр. выгрузка: `string` |
| облако: Идентификатор элемента во внешнем источнике | коробка: ORIGIN_ID | `ORIGIN_ID` | `ORIGIN_ID` | — |
| облако: Источник | коробка: SOURCE_ID | `SOURCE_ID` | `SOURCE_ID` | тип REST: `crm_status` → внутр. выгрузка: `string` |
| облако: Кем изменена | коробка: MODIFY_BY_ID | `MODIFY_BY_ID` | `MODIFY_BY_ID` | тип REST: `user` → внутр. выгрузка: `string` |
| облако: Кем создана | коробка: CREATED_BY_ID | `CREATED_BY_ID` | `CREATED_BY_ID` | тип REST: `user` → внутр. выгрузка: `string` |
| облако: Комментарий | коробка: COMMENTS | `COMMENTS` | `COMMENTS` | — |
| облако: Компания | коробка: COMPANY_ID | `COMPANY_ID` | `COMPANY_ID` | тип REST: `crm_company` → внутр. выгрузка: `string` |
| облако: Контакт | коробка: CONTACT_ID | `CONTACT_ID` | `CONTACT_ID` | тип REST: `crm_contact` → внутр. выгрузка: `string` |
| облако: Лид | коробка: LEAD_ID | `LEAD_ID` | `LEAD_ID` | тип REST: `crm_lead` → внутр. выгрузка: `string` |
| облако: Название | коробка: TITLE | `TITLE` | `TITLE` | — |
| облако: Новая сделка | коробка: IS_NEW | `IS_NEW` | `IS_NEW` | тип REST: `char` → внутр. выгрузка: `string` |
| облако: Обозначение рекламной кампании | коробка: UTM_CAMPAIGN | `UTM_CAMPAIGN` | `UTM_CAMPAIGN` | — |
| облако: Ответственный | коробка: ASSIGNED_BY_ID | `ASSIGNED_BY_ID` | `ASSIGNED_BY_ID` | тип REST: `user` → внутр. выгрузка: `string` |
| облако: Регулярная сделка | коробка: IS_RECURRING | `IS_RECURRING` | `IS_RECURRING` | тип REST: `char` → внутр. выгрузка: `string` |
| облако: Рекламная система | коробка: UTM_SOURCE | `UTM_SOURCE` | `UTM_SOURCE` | — |
| облако: Содержание кампании | коробка: UTM_CONTENT | `UTM_CONTENT` | `UTM_CONTENT` | — |
| облако: Ставка налога | коробка: TAX_VALUE | `TAX_VALUE` | `TAX_VALUE` | тип REST: `double` → внутр. выгрузка: `string` |
| облако: Стадия сделки | коробка: STAGE_ID | `STAGE_ID` | `STAGE_ID` | тип REST: `crm_status` → внутр. выгрузка: `string` |
| облако: Сумма | коробка: OPPORTUNITY | `OPPORTUNITY` | `OPPORTUNITY` | тип REST: `double` → внутр. выгрузка: `string` |
| облако: Тип | коробка: TYPE_ID | `TYPE_ID` | `TYPE_ID` | тип REST: `crm_status` → внутр. выгрузка: `string` |
| облако: Тип трафика | коробка: UTM_MEDIUM | `UTM_MEDIUM` | `UTM_MEDIUM` | — |
| облако: Условие поиска кампании | коробка: UTM_TERM | `UTM_TERM` | `UTM_TERM` | — |
| LAST_ACTIVITY_BY | `LAST_ACTIVITY_BY` | — | — |
| LAST_ACTIVITY_TIME | `LAST_ACTIVITY_TIME` | — | — |
| LAST_COMMUNICATION_TIME | `LAST_COMMUNICATION_TIME` | — | — |
| MOVED_BY_ID | `MOVED_BY_ID` | — | — |
| REPEAT_SALE_SEGMENT_ID | `REPEAT_SALE_SEGMENT_ID` | — | — |
| UF_CRM_1773912365505 | `UF_CRM_1773912365505` | — | — |
| UF_CRM_1773912404736 | `UF_CRM_1773912404736` | — | — |
| UF_CRM_1773912425992 | `UF_CRM_1773912425992` | — | — |
| UF_CRM_1773912464864 | `UF_CRM_1773912464864` | — | — |
| UF_CRM_1773912479576 | `UF_CRM_1773912479576` | — | — |
| UF_CRM_1773912491872 | `UF_CRM_1773912491872` | — | — |
| UF_CRM_1773912504488 | `UF_CRM_1773912504488` | — | — |
| UF_CRM_1773912513641 | `UF_CRM_1773912513641` | — | — |
| UF_CRM_1773912523448 | `UF_CRM_1773912523448` | — | — |
| Дополнительная информация | `ADDITIONAL_INFO` | — | — |
| Задолженности ФССП | `PARENT_ID_1048` | — | — |
| Закрыта | `CLOSED` | — | — |
| Когда передвинут | `MOVED_TIME` | — | — |
| Контакты | `CONTACT_IDS` | — | — |
| Местоположение | `LOCATION_ID` | — | — |
| Повторная сделка | `IS_RETURN_CUSTOMER` | — | — |
| Повторное обращение | `IS_REPEATED_APPROACH` | — | — |
| Предложение | `QUOTE_ID` | — | — |
| Предыдущая стадия | `PREVIOUS_STAGE_ID` | — | — |
| Режим расчёта суммы | `IS_MANUAL_OPPORTUNITY` | — | — |
| ACCOUNT_CURRENCY_ID | — | `ACCOUNT_CURRENCY_ID` | — |
| OPPORTUNITY_ACCOUNT | — | `OPPORTUNITY_ACCOUNT` | — |
