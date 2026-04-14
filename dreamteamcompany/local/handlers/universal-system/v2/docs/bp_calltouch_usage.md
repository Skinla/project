## CallTouch в БП

Используйте сниппеты как сырой PHP-код внутри блоков Bitrix `CodeActivity`. Не вставляйте `<?php` и `?>`.

### Какие файлы вставлять

| Блок БП | Файл |
| --- | --- |
| Селектор парсера | `snippets/bp_selector_code.txt` |
| Парсер CallTouch | `snippets/bp_calltouch_parser_code.txt` |
| Поиск CallTouch в ИБ54 | `snippets/bp_calltouch_ib54_lookup_code.txt` |
| Парсер Bushueva | `snippets/bp_bushueva_supplier_parser_code.txt` |
| Парсер Bitrix24 `SOURCE_DESCRIPTION` | `snippets/bp_bitrix24_source_description_parser_code.txt` |
| Универсальный парсер | `snippets/bp_universal_parser_code.txt` |

### Значения селектора

| `CB_PARSER_NAME` | Блок-обработчик |
| --- | --- |
| `bitrix24_source_description` | `snippets/bp_bitrix24_source_description_parser_code.txt` |
| `bushueva_supplier` | `snippets/bp_bushueva_supplier_parser_code.txt` |
| `calltouch` | `snippets/bp_calltouch_parser_code.txt`, затем `snippets/bp_calltouch_ib54_lookup_code.txt` |
| `universal` | `snippets/bp_universal_parser_code.txt` |
| `narrow` | запасная или ручная ветка |

### Новые переменные БП

Если переменная уже есть в вашем новом обработчике и совпадает по смыслу, новую создавать не нужно.

Для CallTouch обязательно нужны только переменные, у которых нет смыслового аналога среди текущих `CB_*`:

| Переменная | Назначение |
| --- | --- |
| `CB_SITE_ID` | `siteId` из CallTouch, используется как `PROPERTY_199` |
| `CB_SUB_POOL_NAME` | резервный ключ `NAME` для поиска в ИБ54 |

Остальные данные пишутся в уже существующие переменные:
- `CB_PHONE`
- `CB_NAME`
- `CB_DOMAIN`
- `CB_SOURCE_DESCRIPTION`
- `CB_COMMENT`
- `CB_RESULT`
- `CB_SOURCE_ID`
- `CB_ASSIGNED_BY_ID`
- `CB_OBSERVER_IDS`
- `CB_CITY_ID`
- `CB_ISPOLNITEL`
- `CB_INFOPOVOD`

### Ожидаемый порядок работы

1. Выполнить `snippets/bp_selector_code.txt`.
2. Разветвить схему по `CB_PARSER_NAME`.
3. Для `calltouch` выполнить `snippets/bp_calltouch_parser_code.txt`.
4. Если `CB_RESULT = parsed`, выполнить `snippets/bp_calltouch_ib54_lookup_code.txt`.
5. После lookup оставить переименование лида в стандартных блоках БП, используя обновленные `CB_DOMAIN` и другие `CB_*`.

### Что записывают блоки CallTouch

`snippets/bp_calltouch_parser_code.txt` заполняет:
- `CB_TITLE=calltouch`
- `CB_PHONE`, `CB_NAME`, `CB_DOMAIN`
- `CB_SOURCE_DESCRIPTION=CallTouch (siteId=...)`
- `CB_COMMENT`
- `CB_SITE_ID`, `CB_SUB_POOL_NAME`

`snippets/bp_calltouch_ib54_lookup_code.txt` заполняет:
- `CB_DOMAIN` итоговым названием элемента ИБ54
- `CB_SOURCE_ID` из списка `19`, свойство `73`
- `CB_ASSIGNED_BY_ID` из списка городов `22`, свойство `185`
- `CB_CITY_ID`, `CB_ISPOLNITEL`, `CB_INFOPOVOD`
- `CB_OBSERVER_IDS`

### Какие логи ожидать

| Блок | Стартовый лог | Успешный лог |
| --- | --- | --- |
| selector | `[bp_selector_code] start` | `[bp_selector_code] parser=... reason=...` |
| calltouch parser | `[bp_calltouch_parser_code] start` | `[bp_calltouch_parser_code] parsed | nameKey=... | siteId=... | phone=...` |
| calltouch lookup | `[bp_calltouch_ib54_lookup_code] start` | `[bp_calltouch_ib54_lookup_code] parsed | foundName=... | siteId=... | elementId=...` |
