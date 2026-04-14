## Проверки Bitrix24 API (webhook) и Bitrix Box (local app)

Цель: подтвердить доступы, работоспособность методов и структуру данных до разработки прокси и встраиваемых блоков.

### 1) Требования к среде
- `bash`
- `curl`
- (опционально) `jq` для удобного просмотра JSON

### 2) Переменные (взять из `bitrix.access.env`)

```bash
# локально (если есть приватный файл с секретами)
source /project/Academy/Website/bitrix.access.env

# на Bitrix Box (prod)
# source /etc/academyprofi/bitrix.access.env
```

### 3) Webhook: базовые проверки доступов

#### 3.1 `scope`

```bash
curl -sS -m 40 "${BITRIX24_WEBHOOK_BASE_URL}scope"
```

Ожидаемо: список scope. Фактически получено 2026‑01‑24: `bizproc, catalog, crm, disk, documentgenerator, lists, rpa`.

#### 3.2 `user.current`

```bash
curl -sS -m 40 "${BITRIX24_WEBHOOK_BASE_URL}user.current"
```

Фактически получено 2026‑01‑24: `insufficient_scope` (не блокирует каталог).

### 4) Каталог (IBLOCK_ID=14): методы и контракт данных

Общее правило: в `catalog.product.list` **обязательно** указывать `iblockId` в `select`.

#### 4.1 `catalog.product.list` (первый листинг)

```bash
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"select\":[\"id\",\"iblockId\",\"name\"],\"filter\":{\"iblockId\":${BITRIX24_IBLOCK_ID}},\"order\":{\"id\":\"desc\"},\"start\":0}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.product.list"
```

Проверки:
- В верхнем уровне ответа должны быть `total`, `next`, `time`.
- В `result.products` должны быть элементы.

#### 4.2 Пагинация (start = 0, 50, 100, …)

```bash
for start in 0 50 100; do
  echo "== start=$start =="
  curl -sS -m 40 -X POST -H "Content-Type: application/json" \
    -d "{\"select\":[\"id\",\"iblockId\",\"name\"],\"filter\":{\"iblockId\":${BITRIX24_IBLOCK_ID}},\"order\":{\"id\":\"desc\"},\"start\":${start}}" \
    "${BITRIX24_WEBHOOK_BASE_URL}catalog.product.list" | jq -r '.next, .total'
done
```

Фактически получено 2026‑01‑24: `total=2004`, `next` растёт по 50.

#### 4.3 Поиск по подстроке (от 3 символов) — фильтр `%name`

```bash
q="Инф"
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"select\":[\"id\",\"iblockId\",\"name\"],\"filter\":{\"iblockId\":${BITRIX24_IBLOCK_ID},\"%name\":\"${q}\"},\"order\":{\"id\":\"desc\"},\"start\":0}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.product.list" | jq '.result.products[:10] | map({id,name})'
```

Фактически подтверждено: `%name` работает, `*name` не использовать.

#### 4.4 `catalog.product.get` (пример товара)

```bash
productId="7896"
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"id\":${productId}}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.product.get"
```

Проверка контракта:
- Свойства приходят как `property<ID>` (например `property112`, `property152`), а не как вложенный объект.

#### 4.5 `catalog.productProperty.list` (список свойств)

```bash
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"filter\":{\"iblockId\":${BITRIX24_IBLOCK_ID}},\"select\":[\"id\",\"name\",\"code\",\"propertyType\",\"userType\",\"multiple\"]}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.productProperty.list" | jq '.result.productProperties | length'
```

Фактически получено 2026‑01‑24: 37 свойств; часть без `code` → в реализации опираться на `id`.

#### 4.6 `catalog.section.list` (разделы каталога)

```bash
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"filter\":{\"iblockId\":${BITRIX24_IBLOCK_ID}},\"select\":[\"id\",\"name\",\"sort\",\"sectionId\",\"xmlId\",\"code\"]}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.section.list" | jq '.result.sections | map({id,name})'
```

Фактически подтверждено: разделы 40/68/70/72/74/76/78/80 с правильными названиями.

#### 4.7 `catalog.price.list` (цены через API)

```bash
productId="7896"
curl -sS -m 40 -X POST -H "Content-Type: application/json" \
  -d "{\"select\":[\"id\",\"productId\",\"catalogGroupId\",\"price\",\"currency\"],\"filter\":{\"productId\":${productId}}}" \
  "${BITRIX24_WEBHOOK_BASE_URL}catalog.price.list"
```

Фактически получено 2026‑01‑24: 1 цена, `catalogGroupId=2`, `currency=RUB`, значение 0 (поэтому для UI принято решение использовать `property152` — розничную цену).

### 5) Решения для будущей реализации (зафиксировано)
- Основная цена для UI: `property152` (розничная, Money `\"5000|RUB\"`).
- Детальная страница сейчас: `DETAIL_PAGE_URL_FALLBACK` (пока как общий URL).
- В каталоге нет стабильного поля с URL детальной страницы конкретного товара → позже нужно добавить отдельное свойство/шаблон URL.

### 6) Bitrix Box local app: доступность install/handler (на сервере `bitrix.axiom24.ru`)

```bash
curl -sS -o /dev/null -D - -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_INSTALL_PATH}" | head -n 5
curl -sS -o /dev/null -D - -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}" | head -n 5
```

Ожидаемо:
- `install.php`: `200` (GET) и сообщение “Expected POST with ONAPPINSTALL payload.”
- `handler.php`: `200` на `/health` (см. ниже), `404` на неизвестные пути

Факт:
- 2026‑01‑24: было `HTTP 404` (файлы ещё не были размещены)
- 2026‑01‑25: после деплоя URL начали отвечать (см. `worklog.md`)

#### 6.1 health

```bash
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/health" | jq
```

### 7) Bitrix Box local app: фильтрация по разделу (sectionId) и каталогу (iblockId)

Примечание: приложение возвращает `filters`, чтобы было видно, какие параметры реально применились.

#### 7.1 products (без фильтра / с фильтром)

```bash
# без фильтра
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/academyprofi-catalog/products?start=0" | jq '.total, .filters'

# только по разделу (пример: 40)
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/academyprofi-catalog/products?start=0&sectionId=40" | jq '.total, .filters'

# override каталога + раздел
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/academyprofi-catalog/products?start=0&iblockId=14&sectionId=40" | jq '.total, .filters'
```

#### 7.2 search (поиск внутри раздела)

```bash
q="строи"

# без ограничения
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/academyprofi-catalog/search?q=${q}" | jq '.items|length, .filters'

# внутри раздела (пример: 68)
curl -sS -m 20 "${BITRIX_BOX_BASE_URL}${BITRIX_BOX_HANDLER_PATH}/academyprofi-catalog/search?q=${q}&sectionId=68" | jq '.items|length, .filters'
```
