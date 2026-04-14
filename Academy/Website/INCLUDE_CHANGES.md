# INCLUDE_CHANGES.md

Чеклист внедрённых правок по фидбеку (пункты 1–13).

## Чеклист 1–13
- [x] **1. Каталог**: сетка 3×5 (15 карточек на UI‑страницу), без горизонтального скролла.
- [x] **2. Навигация в деталку**: клик по карточке ведёт на detail‑страницу через hash `#product=<id>` (путь задаётся в настройках блока `data-ap-detail-path`, есть fallback из настроек модуля).
- [x] **3. Фон каталога**: градиент перенесён с карточек на контейнер блока каталога.
- [x] **4. Отступы каталога**: увеличен `gap`, добавлен внутренний padding (слева/справа/сверху как gap).
- [x] **5. Мета карточки**: левый нижний угол (часы/срок), правый нижний угол (цена) прижаты к низу.
- [x] **6. Деталка (layout полей)**: вместо KV‑списка — две строки (лейблы в ряд, значения в ряд) + секции требований/образования.
- [x] **7. “Показывать срок оказания”**: per‑page настройка блока каталога; переключает мету (уч.ч. ↔ раб.дни). API отдаёт `durationWorkDays`.
- [x] **8. Деталка (ширина)**: ограничена ширина контента (max‑width) и центрирование.
- [x] **9. Деталка (фон/пустое состояние)**: фон `#f2f2f2`; без `#product=` показывается заглушка, без демо‑товара.
- [x] **10. Кнопка CTA (“Заказать/Оставить заявку”)**: текст/ссылка/цвет/размер управляются из редактора Bitrix; JS не перетирает; дефолтная ширина “короче”, если редактор не задал padding‑класс.
- [ ] **11.** Не входило в текущий план / не затрагивалось.
- [ ] **12.** Не входило в текущий план / не затрагивалось.
- [x] **13. Поиск**: обеспечен поиск по подстроке внутри слова (fallback через расширенную выборку + пост‑фильтр).

## Затронутые файлы
- `local/akademia-profi/academyprofi-catalog-app/assets/academyprofi-blocks.js`
- `local/akademia-profi/academyprofi-catalog-app/assets/academyprofi-blocks.css`
- `local/akademia-profi/academyprofi-catalog-app/src/Blocks/CatalogBlock.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Blocks/DetailBlock.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Blocks/SearchBlock.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Blocks/BlockRuntimeContext.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Settings.php`
- `local/akademia-profi/academyprofi-catalog-app/install.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Api/ApiController.php`
- `local/akademia-profi/academyprofi-catalog-app/src/Api/ProductMapper.php`
- `MODULE_SETTINGS.example.json`
- `BLOCKS_USER_GUIDE.md`

## Ключевые поведенческие изменения
- **Каталог**: отображает 15 карточек на UI‑страницу (внутри чанка API), сетка 3 колонки (адаптивно 2/1).
- **Каталог/Поиск → деталка**: если в блоке задан `data-ap-detail-path`, клики ведут на `${detailPath}?productId=<id>#product=<id>`. Поле `data-ap-detail-path` доступно в “Настройках” блока (под сценарий “отдельная деталка на каждый раздел”). Если `data-ap-detail-path` пуст — остаётся старое поведение (меняем hash на текущей странице).
- **Каталог (срок оказания)**: при `data-ap-show-duration-work-days` в “левом нижнем” выводятся `durationWorkDays` вместо `hours`.
- **Деталка**: без `#product=` контент скрыт и показывается заглушка “Выберите услугу…”.
- **Деталка (CTA‑кнопка)**: текст/ссылка берутся из узла кнопки (настройки редактора), JS не изменяет `textContent/href`; размер из редактора снова работает (класс `g-btn-size-*` не зафиксирован в `CONTENT`). По умолчанию (без `g-btn-px-*`) кнопка имеет меньший horizontal padding.
- **Деталка (типографика полей)**: лейблы принудительно жирные, значения обычные, чтобы “утечки” стилей Bitrix не переворачивали веса.
- **CRM‑форма (productId)**: при выборе товара автоматически добавляется `?productId=<id>` (синхронизация из `#product=<id>`) — чтобы CRM‑форма могла заполнить скрытое поле лида из URL‑параметра.
- **Привязка товара к лиду (через БП/робота)**: для прикрепления товара используется REST‑метод `crm.lead.productrows.set` (однострочный входящий webhook), берём `PRODUCT_ID` из `UF_CRM_1769508178701`.
- **Цены**: вместо пользовательских money‑свойств (`property152/154`) цена для UI берётся из **цен каталога** (`catalog.price.list`, `catalogGroupId=2`) для списка/поиска/деталки.
- **Поиск**: добавлен fallback‑режим (v2) — если Bitrix‑поиск не даёт подстроки внутри слова, делается более широкая выборка и фильтрация `mb_stripos(name, q) !== false`.

