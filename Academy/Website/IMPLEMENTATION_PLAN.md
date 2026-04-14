# План реализации (academyprofi-catalog-app)

Формат: чеклист. Выполненные пункты помечаем как ~~текст~~ и `[x]`.

## Документация/контракты
- [x] ~~Синхронизировать `PROMPT.md` под 3 блока + install/registry + CORS allowlist~~
- [x] ~~Синхронизировать `AGENT_RULES.md` под 3 блока и правила productId (URL → fallback)~~
- [x] ~~Обновить `MODULE_SETTINGS.example.json` (CORS allowlist, routing productId, assetsBaseUrl, sections)~~
- [x] ~~Обновить `bitrix.config.json` (секреты → ENV, detail page → “через URL param”)~~
- [x] ~~Обновить `project plan.md` под 3 блока~~

## Структура приложения (Bitrix Box)
- [x] ~~Создать каталог приложения: `/local/akademia-profi/academyprofi-catalog-app/`~~
- [x] ~~Добавить `install.php` (обработка `ONAPPINSTALL`, versioned install)~~
- [x] ~~Добавить `handler.php` (роутер API и/или обработчик событий)~~
- [x] ~~Добавить `assets/` (JS/CSS, versioned URL)~~
- [x] ~~Добавить `src/` (классы: env, storage, cache, http client, dto mapper, blocks registry)~~
- [x] ~~Добавить `storage/` с защитой от публичного доступа (и fallback на `/etc/academyprofi/...`)~~

## Установка / версионирование
- [x] ~~Принять `ONAPPINSTALL` (POST) и сохранить installation (member_id/domain/endpoints/tokens/application_token/version)~~
- [ ] Верифицировать источник события (валидировать access_token через `app.info` или аналог)
- [x] ~~Реализовать миграции по `data.VERSION` (если версия ↑ → перерегистрировать блоки)~~
- [ ] Реализовать `ONAPPUNINSTALL` (опционально) + проверка `application_token`

## Registry блоков (Landing / Sites24)
- [x] ~~Реализовать генерацию 3 блоков: `academyprofi.search`, `academyprofi.catalog`, `academyprofi.detail`~~
- [x] ~~Для каждого блока: `fields` + `manifest` + `CONTENT`~~
- [ ] Перед регистрацией прогонять `landing.repo.checkContent` при необходимости
- [x] ~~Регистрировать через `landing.repo.register` (по install / по апдейту версии)~~
- [x] ~~Dev-стратегия обновления блоков: `RESET=Y` включён всегда (re-install обновляет CONTENT существующих блоков на страницах)~~
- [x] ~~Cache-busting ассетов: `assetsVersion` считается по `filemtime` и добавляется как `?v=` к CSS/JS/preview~~

## API для блоков (без утечки секретов)
- [x] ~~`GET /academyprofi-catalog/product?id=...` → `ProductDto`~~
- [x] ~~`GET /academyprofi-catalog/search?q=...` → dropdown items (limit=8)~~
- [x] ~~`GET /academyprofi-catalog/products?start=...` → карточки + `next/total`~~
- [x] ~~Фильтрация выдачи по настройкам блока: `iblockId` и `sectionId` (и отражение применённых фильтров в ответе как `filters`)~~
- [x] ~~Нормализация:~~
  - [x] ~~`property126` → `requirements[]` (split + trim + de-dup)~~
  - [x] ~~Money `"N|RUB"` → `{amount,currency}` + `priceDisplay`~~
  - [x] ~~`"-"`/пусто → `null`/`[]`~~
- [x] ~~Кеш:~~
  - [x] ~~`/product` TTL 5–15 мин~~
  - [x] ~~`/search` TTL 1–5 мин~~
  - [x] ~~`/products` TTL 1–5 мин~~
  - [x] ~~stampede guard (lock per key)~~
- [x] ~~CORS:~~
  - [x] ~~если `allowedDomains` заполнен → только allowlist~~
  - [x] ~~если пуст → allow all (demo)~~

## Локальный запуск и самопроверки
- [ ] Локальный запуск PHP (built-in server) и smoke-тесты `curl`
- [x] ~~`php -l` на все PHP-файлы~~
- [x] ~~Тестовый прогон DTO маппера на fixtures (минимум 1–2 примера)~~

## Дизайн/настройки блоков в конструкторе
- [x] ~~Search: кнопка сделана одним узлом (`.landing-block-node-button-container`, тип `link`), чтобы работали стандартные настройки (цвет/радиус/паддинги/шрифт)~~
- [x] ~~Search: “Поле поиска” объединено в один пункт (типографика перенесена на `.landing-block-node-input-container`)~~
- [x] ~~Detail: CTA настраивается так же, как в search (один узел-кнопка, `link`)~~
- [x] ~~Catalog/Search: добавлены attrs для настроек `IBLOCK_ID`/`SECTION_ID` (`data-ap-iblock-id`, `data-ap-section-id`)~~
- [x] ~~CSS: применён подход `:where(...)` + `:not([class*="g-"])`, чтобы Bitrix-классы из “Дизайн” переопределяли дефолтные стили~~
