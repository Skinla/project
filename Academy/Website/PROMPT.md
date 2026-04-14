## PROMPT: серверный модуль + registry блоков (Bitrix Box local app)

Контекст: мы делаем **встраиваемые блоки** в конструктор сайта Битрикс24. Клиентский JS **не должен** знать токены/секреты Bitrix24. Нужен **server-side модуль** (локальное приложение на Bitrix Box), который:
- отдаёт нормализованные данные для блоков (search / list / detail),
- и **регистрирует эти блоки** в конструкторе сайта через `landing.repo.*` при установке приложения.

Источник данных: Bitrix24 Cloud Catalog через REST.

### 0) Окружение/ограничения
- Хостинг кода: Bitrix Box (`bitrix.axiom24.ru`), каталог приложения:
  - `install.php`: `/local/akademia-profi/academyprofi-catalog-app/install.php`
  - `handler.php`: `/local/akademia-profi/academyprofi-catalog-app/handler.php`
- В проекте есть:
  - `bitrix-checks.md` — команды проверок
  - `bitrix.config.json` — выбранные сущности/поля/правила API
  - `bitrix.access.env.example` + гайд `DEPLOYMENT.md`
- Реальные секреты на сервере лежат в `/etc/academyprofi/bitrix.access.env` (см. `DEPLOYMENT.md`).

### 0.1) Жёсткие правила разработки (важно)
- **Запрещено** вносить изменения в каталог Bitrix24:
  - не добавлять/не менять свойства товаров
  - не добавлять поля/индексы/шаблоны URL
  - не рассчитывать на “донастройку каталога”
- Разрешены только:
  - чтение данных через REST
  - нормализация/обогащение/индексация **внутри модуля** (на third‑party сервере / в приложении)

### 0.2) Настройки модуля (URL детальной / CTA / лимиты)
Сейчас детальная карточка — это **встроенный блок**, а “Оставить заявку” — якорь на форму на этой же странице. Поэтому всё, что относится к UI‑поведению, держим в настройках:
- `MODULE_SETTINGS.example.json` (шаблон)

В коде модуля это должен быть конфиг (файл/ENV), который можно менять без правок каталога Bitrix24.

### 1) Что модуль обязан делать (функции)
1) **Блоки конструктора (3 штуки)**:
   - `academyprofi.search` — поиск (поле + dropdown до 8 результатов)
   - `academyprofi.catalog` — список/карточки + пагинация
   - `academyprofi.detail` — детальная карточка услуги
2) **Детальная карточка товара** (центральный контракт) — см. `PRODUCT_DTO.md`.
2) **Нормализация данных** в формат, удобный для встраиваемого блока.
3) **Кеширование** (5–15 минут) чтобы не упираться в лимиты Bitrix24 и ускорить загрузку.
4) **Безопасность**: никакие токены Bitrix24 на фронт не отдавать; запретить “случайный публичный дамп” конфигов/секретов.

Out of scope:
- любая “логика заявки” на стороне модуля (лиды/вебхуки/CRM). Заявка — это отдельный **form‑block** на странице.

### 2) Факты про API (подтверждено проверками)
- Webhook base берём **только** из env: `BITRIX24_WEBHOOK_BASE_URL`
- Методы OK:
  - `catalog.product.list`, `catalog.product.get`, `catalog.productProperty.list`, `catalog.section.list`, `catalog.price.list`
- В `catalog.product.list` **нужно** включать `iblockId` в `select` (иначе ошибка “Required select fields: iblockId”).
- Пагинация: параметр `start` (0, 50, 100, …). Поля `total/next/time` находятся **на верхнем уровне ответа**.
- Поиск по подстроке: фильтр **`%name`** работает; `*name` не использовать.
- `total` по каталогу `IBLOCK_ID=14`: 2004 (на 2026‑01‑24).

### 3) Данные/поля (что отдаём фронту)
Базовые поля:
- `id` (ID товара)
- `name` (название)
- `sectionId` (раздел: `iblockSectionId` если доступен/нужен)

Поля детальной карточки (по ТЗ/макету):
- `serviceType` — “Вид услуги” (в каталоге это есть как свойство/enum; в тестовых данных встречается `property106`)
- `registry` — “Реестр / ФИС ФРДО” (`property122`). ФРДО — это значение поля “Реестр” (пример: `id=18` → `property122.value = "ФИС ФРДО"`).
- `requirements` — “Требования к клиенту” (список; в текущем каталоге есть `property126`, но он может быть строкой “-” → модуль приводит к массиву: split по `\\n`, `;`, `,` и чистит мусор)
- `educationLevel` — “Требуемый уровень образования” (`property128`)
- `cta` — действие “Оставить заявку” (на фронте/сайте это кнопка на детальной)

Поля карточки из свойств (через `property<ID>`):
- `hours` ← `property112` (Объём курса, уч.ч.)
- `periodicityMonths` ← `property114`
- `durationWorkDays` ← `property116`
- `registry` ← `property122`
- `format` ← `property124`
- `requirements` ← `property126`
- `educationLevel` ← `property128`
- `priceRetail` ← `property152` (**основная цена для UI**, формат `\"5000|RUB\"`)

Детальная страница:
- На текущем этапе есть только общий URL (плейсхолдер): `https://b24-etzst8.bitrix24site.ru/rabochie_professii_occg/`
- Поскольку **в каталог нельзя добавлять URL**, модуль отдаёт только `id`/`code`, а **detailUrl строится на стороне фронта/сайта** (например `?productId=<id>` или `#product=<id>`), либо используется попап/SPA-деталка без отдельного URL.

### 4) API модуля (то, что будет вызывать клиентский JS в блоках)

#### 4.0 GET `/academyprofi-catalog/product`
Query:
- `id` (number, required)

Ответ: `ProductDto` (см. `PRODUCT_DTO.md`).

#### 4.1 GET `/academyprofi-catalog/search`
Query:
- `q` (string, required, min 3)
- `iblockId` (number, optional; override каталога для поиска)
- `sectionId` (number, optional; ограничение по разделу каталога / “папке”)

Ответ (минимально для dropdown):
- `items: Array<{ id: number; name: string; priceDisplay?: string; hours?: number|null }>`
- `filters: { iblockId: number; sectionId: number|null }` *(диагностика: что реально применилось)*

Примечание: реализация опирается на `catalog.product.list` + фильтр `%name` и лимит 8.

#### 4.2 GET `/academyprofi-catalog/products`
Query:
- `start` (number, optional; 0, 50, 100, ...)
- `iblockId` (number, optional; override каталога)
- `sectionId` (number, optional; ограничение по разделу каталога / “папке”)

Ответ (для карточек списка):
- `items: Array<{ id: number; name: string; hours?: number|null; priceDisplay: string }>`
- `next?: number|null`
- `total?: number`
- `filters: { iblockId: number; sectionId: number|null }` *(диагностика: что реально применилось)*

Примечание: пагинация Bitrix24 через `start`, `total/next/time` на верхнем уровне ответа.

### 4.3 Сценарий “страница = папка каталога”
На сайте могут быть страницы, которые соответствуют “папкам” (разделам) каталога. На каждой такой странице блоки `academyprofi.search` и `academyprofi.catalog` должны быть настроены так, чтобы ограничивать выдачу:
- в настройках блока выставить `IBLOCK_ID` и `SECTION_ID` (раздел/папка)
- тогда `catalog` и `search` работают **только** в выбранном каталоге/разделе и не зависят от URL страницы (страница ≠ раздел).

### 5) Внутренняя логика (как именно дергаем Bitrix24)

#### 5.0 product (детальная карточка)
- `catalog.product.get`
  - `id`: из query
  - маппинг и нормализация: строго по `PRODUCT_DTO.md`

#### 5.1 search (поиск)
- `catalog.product.list`
  - `filter`: `{ iblockId: 14, "%name": q }`
  - `select`: минимум `["id","iblockId","name","property112","property152"]` (или `property*`, если нужно)
  - `limit`: 8

#### 5.2 products (листинг)
- `catalog.product.list`
  - `filter`: `{ iblockId: 14 }`
  - `order`: по `id desc` (как в проверках)
  - `start`: 0/50/100/...

### 5.3 Картинки и “старая цена”
По твоему решению:
- **картинки товара не используем**
- “старая цена” не нужна

### 6) Кеширование
Кешируем:
- `/product` по ключу `(id)` на 5–15 минут
- `/search` по ключу `(q)` на 1–5 минут
- `/search` по ключу `(q, iblockId?, sectionId?)` на 1–5 минут
- `/products` по ключу `(start, iblockId?, sectionId?)` на 1–5 минут

Требования:
- кеш должен быть простым и не требовать внешних сервисов (файлы или встроенный кеш Bitrix/PHP).
- обязателен “cache stampede guard” (минимум: блокировка на ключ).

### 7) Ошибки/ответы
Модуль должен возвращать:
- 200 + нормальный JSON на успех
- 400 если `id` невалидный
- 502 если Bitrix24 API недоступен/таймаут
- 500 на внутренние ошибки

В лог (серверный файл) писать:
- запрос (endpoint + query)
- время ответа
- факт: cache hit/miss
- ошибки Bitrix24 (`error`, `error_description`)

### 8) Безопасность
- Не отдавать наружу:
  - `bitrix.access.env`
  - токены/секреты/ключи
- CORS:
  - если в конфиге/ENV задан allowlist доменов — разрешаем **только** их,
  - если allowlist пуст — разрешаем всем (режим демо).
- (Опционально) простой shared-secret для запросов блока, если endpoint будет доступен снаружи.

### 9) Навигация и источник `productId` (фиксируем как правило)
Блоки могут стоять на **любой странице**, поэтому `productId` определяется так:
- приоритет: URL текущей страницы (`#product=<id>` или `?productId=<id>`)
- fallback: `ui.detail.productId` из `MODULE_SETTINGS.example.json` (для превью/демо)

CTA “Оставить заявку”:
- `ui.cta.mode="anchor"` + `ui.cta.anchor` (по умолчанию `#request`)

### 9.1) Assets для блоков (где живут)
Assets (CSS/JS), которые подключаются в `manifest.assets.*` при `landing.repo.register`, размещаем рядом с приложением на `bitrix.axiom24.ru` (внутри `/local/akademia-profi/academyprofi-catalog-app/`), чтобы блоки не зависели от домена опубликованного сайта.

### 9.2) Установка local app и регистрация блоков (по версии приложения)
Приложение устанавливается как **server-side local app**.
- `install.php` принимает `ONAPPINSTALL`, сохраняет `access_token/refresh_token`, `member_id`, `domain`, и **`application_token`**, а также `data.VERSION` (версия приложения).
- Затем `install.php` регистрирует блоки через:
  - `landing.repo.checkContent` (если нужно)
  - `landing.repo.register` для `academyprofi.search`, `academyprofi.catalog`, `academyprofi.detail`

При изменении версии приложения — перерегистрируем блоки (при необходимости используем `RESET=Y`).

### 10) Расшифровка макетов (обязательные UI-требования)
Референсы в репозитории:
- `IMG_9517.jpg` — карточки списка/пагинация/hover
- `7c13d1545cafddd1b4f02377f0e924cb.jpg` — состав полей детальной карточки и CTA

Требования из `IMG_9517.jpg`:
- рамка карточки 2px `#808080`
- при hover “вторая рамка” 2px `#57d990`
- Roboto, цвет текста `#f2f2f2`
- фон градиент `#57d990 → #4a4a4a` с прозрачностью 30%
- пагинация:
  - активная страница: рамка 2px `#57d990`
  - hover на номер страницы: подчеркивание `#f2f2f2`

Требования из `7c13d1545cafddd1b4f02377f0e924cb.jpg`:
- детальная карточка отображает: наименование, вид услуги, ID, периодичность, объём/срок, реестр/ФРДО, требования к клиенту (списком), требуемый уровень образования, цену `от X ₽`, и кнопку “Оставить заявку”.

