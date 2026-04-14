## Таблица данных: что берём, откуда, зачем и где используем

Формат “под Excel”: `FIELD_MAPPING.tsv` (открывается Excel/Google Sheets).

### Откуда мы взяли property<ID> и заполненность
- `property<ID>` и их названия/типы — из `catalog.productProperty.list` (см. `webhook_check/catalog.productProperty.list.json`).
- Заполненность (2004 товара) — из скана `catalog.product.list` по всему каталогу `IBLOCK_ID=14` (см. `MISSING_DATA.md`).

### Зачем это нужно
- Макеты/ТЗ клиента требуют конкретные поля на карточке списка и на детальной (`IMG_9517.jpg`, `7c13d1545cafddd1b4f02377f0e924cb.jpg`).
- Так как **каталог read-only**, всё, чего не хватает/плохо заполнено, решаем:
  - fallback’ами в UI,
  - нормализацией/парсингом в модуле,
  - выбором механики детальной страницы на стороне сайта (query/hash/popup).

### Где в модуле это применяется
- `/academyprofi-catalog/products`: список/пагинация + минимальные поля для карточки.
- `/academyprofi-catalog/search`: поиск `%name`, limit=8.
- Детальная карточка требует данных шире списка → либо добавляем отдельный endpoint `/academyprofi-catalog/product?id=...`, либо возвращаем расширенный набор в `/products` (хуже по объёму).

