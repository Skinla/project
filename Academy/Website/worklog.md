## Work log

### 2026-01-24

- **Контекст**
  - Проект пока в стадии идеи/плана (`project plan.md`), кода нет.
  - Задача этапа: подтвердить webhooks/методы/структуру данных и подготовить конфиги для дальнейшей разработки.

- **Окружение**
  - Изначально `/project/Academy/Website` был read-only → артефакты складывались временно в `$HOME`.
  - После правки прав (`a+rwX`) каталог стал доступен для чтения/записи всеми пользователями, включая `nobody`.

- **Webhook проверки (Bitrix24)**
  - Webhook base: `https://akademia-profi.bitrix24.ru/rest/16/pw1kpzu5y54lb6ni/`
  - `scope`: OK → `bizproc, catalog, crm, disk, documentgenerator, lists, rpa`
  - `user.current`: `insufficient_scope`
  - `catalog.product.list`: OK
    - Требует `iblockId` в `select`
    - Пагинация: параметр `start`, верхний уровень ответа содержит `total/next/time`
    - `total` по `IBLOCK_ID=14`: **2004**
    - Поиск: `%name` работает; `*name` не дал результатов
  - `catalog.product.get` (id=7896): OK, `iblockId=14`
    - Свойства приходят как `property<ID>`
  - `catalog.productProperty.list` (iblockId=14): OK (37 свойств; часть без `code`)
  - `catalog.section.list` (iblockId=14): OK (8 разделов, ids 40/68/70/72/74/76/78/80)
  - `catalog.price.list` (productId=7896): OK (1 цена, `catalogGroupId=2`, `RUB`, значение 0)

- **Решения**
  - Для UI брать **розничную цену** из `property152` (формат `\"5000|RUB\"`).
  - Детальная страница пока задана как общий URL: `https://b24-etzst8.bitrix24site.ru/rabochie_professii_occg/`
  - В каталоге не найдено стабильного поля/свойства для URL детальной страницы по конкретному товару — нужно будет решить отдельно (свойство или шаблон).

- **Bitrix Box local app**
  - `https://bitrix.axiom24.ru/local/akademia-profi/academyprofi-catalog-app/install.php` → **404**
  - `https://bitrix.axiom24.ru/local/akademia-profi/academyprofi-catalog-app/handler.php` → **404**
  - Значит файлы приложения ещё не размещены по указанному пути на box-сервере.

- **Артефакты**
  - `bitrix.config.json` — список выбранных сущностей/полей/решений
  - `bitrix.access.env` — параметры доступов (с секретом; держать приватно)
  - `bitrix-checks.md` — воспроизводимые команды проверок

- **Double check: картинки (2026‑01‑24)**
  - Просканирован весь каталог `IBLOCK_ID=14` (~2004 товара) на поля/свойства:
    - `previewPicture`, `detailPicture`, `property204 (MORE_PHOTO)`, `property230 (BACKGROUND_IMAGE)`
  - Результат: **0 совпадений** → картинки в каталоге сейчас не заполнены; UI должен иметь плейсхолдер/работать без изображений.

- **Проверка ФРДО (2026‑01‑25)**
  - Взяли полный дамп товара `id=18` (`webhook_check/catalog.product.get_18.pretty.json`).
  - Подтверждено: `property122` (“Реестр”) может содержать значение **`ФИС ФРДО`** → отдельное поле “ФРДО” не нужно, это значение поля “Реестр”.

### 2026-01-25

- **Деплой Bitrix Box local app**
  - Приложение задеплоено в файловую систему Bitrix Box: `/home/bitrix/www/local/akademia-profi/academyprofi-catalog-app/`
  - Публичные URL начали отвечать:
    - `/local/akademia-profi/academyprofi-catalog-app/install.php`
    - `/local/akademia-profi/academyprofi-catalog-app/handler.php/health`
  - Реальные секреты размещены на сервере: `/etc/academyprofi/bitrix.access.env` (права `root:bitrix`, `0640`)

- **Инцидент (prod): отсутствует PHP ext-curl**
  - Симптом: `500` на API (`/product`), в логах: `Call to undefined function ... curl_init()`
  - Причина: на сервере PHP собран/настроен без расширения `curl`, поэтому функции `curl_*` недоступны.
  - Фикс: добавлен fallback транспорта на `file_get_contents()` со stream context в:
    - `local/akademia-profi/academyprofi-catalog-app/src/Bitrix/BitrixWebhookClient.php`
    - `local/akademia-profi/academyprofi-catalog-app/src/Bitrix/BitrixOAuthClient.php`
  - Вывод: в Bitrix Box окружении **нельзя** предполагать наличие `ext-curl`; транспорт должен иметь fallback.

- **Нюанс UX (конструктор сайтов): дублирование секций блоков**
  - Симптом: блоки появились в двух категориях (“services” и “academyprofi”).
  - Причина: `SECTIONS` был задан как `services,academyprofi`.
  - Фикс: дефолт в настройках изменён на одну секцию: `academyprofi`.

- **Нюанс UX (конструктор сайтов): дизайн-настройки “узлов” и применение стилей**
  - **Симптом**: в панели “Дизайн” появлялись отдельные пункты “Поле поиска” и “Текст поля поиска”; изменения иногда “не видны” (радиус/цвет/шрифт).
  - **Причина**:
    - Bitrix отображает **каждый selector из `manifest.style.nodes` как отдельный пункт**. Если нужно “один пункт” — типографику и фон/границы нужно описывать одним selector.
    - Стиль‑контролы Bitrix работают через **CSS-классы**, поэтому любые “жёсткие” значения в нашем CSS (`color`, `font-size`, `background`, `border`, `border-radius`) могут перебивать результат, если не учитывать специфичность/наследование.
  - **Фикс**:
    - Объединять настройки поля поиска в один selector (`.landing-block-node-input-container`) и переносить туда типографику.
    - В CSS для `<input>` использовать `color: inherit; font: inherit; background: transparent; border: none;`, а дефолтные значения держать на контейнере (и задавать их только когда Bitrix не навесил свои классы).

- **Инцидент (prod): “переустановка” сохраняется, но блоки не обновляются**
  - **Симптом**: `install.php` пишет только `install: saved installation`, а `landing.repo.register` не выполняется → в редакторе “ничего не меняется”.
  - **Причина**: рассинхрон кода на сервере — `install.php` создаёт `BlockRuntimeContext` с новым полем, а на сервере лежит старая версия класса → фатальная ошибка между сохранением installation и регистрацией блоков.
  - **Фикс**: деплоить `src/Blocks/BlockRuntimeContext.php` вместе с изменениями `install.php` и проверять по `storage/logs/install.log`, что `landing.repo.register` реально вызывается.

- **Стратегия обновления блоков в dev**
  - **Симптом**: Bitrix может “держать” старый `CONTENT` и ассеты; изменения видны только после удаления/повторной вставки блока.
  - **Фикс**:
    - `RESET=Y` включён всегда при `landing.repo.register` (на re-install обновляются существующие блоки на страницах).
    - cache-busting ассетов: `?v=<assetsVersion>`, где `assetsVersion` считается через `filemtime()` (CSS/JS/preview).

- **Дизайн-настройки блоков (Bitrix constructor)**
  - **Симптом**: часть настроек “Дизайн” не применялась (цвет/радиус/шрифт), а пункты “Поле поиска” и “Текст поля поиска” были раздельными.
  - **Причина**:
    - Bitrix применяет стили через классы (`g-*`), а “жёсткие” значения в нашем CSS могут их перебивать.
    - Каждый selector в `manifest.style.nodes` превращается в отдельный пункт UI.
  - **Фикс**:
    - Объединить поле поиска в один узел (`.landing-block-node-input-container`) и перенести туда типографику.
    - Кнопки делать одним узлом `.landing-block-node-button-container` с `type=link`, чтобы стандартные настройки работали ожидаемо.
    - В CSS использовать `:where(...)` (понижение специфичности) и дефолты задавать через `:not([class*="g-"])`, чтобы классы Bitrix переопределяли дефолт.

- **Фильтрация (IBLOCK_ID / SECTION_ID) из настроек блоков**
  - Реализованы attrs-настройки:
    - `data-ap-iblock-id` (IBLOCK_ID) и `data-ap-section-id` (SECTION_ID/iblockSectionId) для `search` и `catalog`.
  - На API добавлены параметры `iblockId/sectionId` и `filters` в ответе, чтобы видеть, что реально применилось.

- **Нюанс: preview-изображения блоков**
  - **Симптом**: `preview-catalog.png`/`preview-detail.png` отсутствуют в `assets/`, при этом блоки ссылались на них.
  - **Фикс**: сделан fallback — `catalog/detail` используют `preview-search.png` как превью (чтобы не было 404 по preview).

### 2026-01-26

- **Срез состояния**
  - Внесены правки по фидбеку заказчика для блоков `catalog/detail/search`.
  - Текущее состояние: ждём обратную связь заказчика (визуал/UX/настройки в редакторе).

- **Детальная страница: CTA‑кнопка (самый болезненный участок)**
  - **Симптом**: в редакторе меняют текст/ссылку/размер, а на сайте:
    - текст “не меняется”,
    - размер “не настраивается”,
    - форма/стили “плывут” (то прямоугольная, то “как-то не так”).
  - **Причины**
    - JS перетирал node‑кнопку (`textContent/href`) значениями из API → редактор бессилен.
    - В `CONTENT` были зафиксированы `g-btn-size-*`/`g-btn-px-*` → настройки размера в UI не могли “переиграть”.
  - **Фикс**
    - В JS кнопка больше **не** модифицируется по `label/href`.
    - В `CONTENT` кнопки оставлены только “базовые” `g-*` классы без фиксации размера, чтобы редактор управлял размером.
    - В CSS дефолт “короче” задан только как fallback, и только если редактор не навесил `g-btn-px-*`.

- **Детальная страница: веса шрифтов (лейблы/значения)**
  - **Симптом**: лейблы не жирные, а значения жирные (визуально “перевёрнуто”).
  - **Причина**: утечки/переопределения типографики Bitrix (классы/наследование).
  - **Фикс**: принудительно закреплены веса на контейнерах “ряд лейблов”/“ряд значений”.

- **Опыт/грабли Bitrix (чтобы не наступать снова)**
  - **Кеши**:
    - Bitrix может держать старый `CONTENT` блока даже после “Переустановить” → иногда нужно удалить/добавить блок заново.
    - Nginx может отдавать ассеты с `max-age` → используем `?v=<filemtime>` как cache-busting и проверяем, что версия реально меняется.
  - **Настройки дизайна в редакторе**:
    - Каждый selector в `manifest.style.nodes` = отдельный пункт UI. Если нужен “один пункт” — делай один selector.
    - Не фиксировать жёстко `color/background/border/radius/font-size` на самом элементе, если хотим чтобы `g-*` классы Bitrix реально управляли видом (помогают `:where(...)` и дефолты через `:not([class*="g-"])`).
  - **Проверка деплоя**:
    - Ошибка пути rsync (файл улетел не в `src/Blocks/...`) выглядит как “ничего не меняется”.
    - Быстрое подтверждение: сравниваем содержимое файлов на сервере + смотрим `storage/logs/install.log` на факт вызова `landing.repo.register`.
