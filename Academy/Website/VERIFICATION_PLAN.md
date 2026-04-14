# План проверки (что уже работает / что проверяем)

Формат: чеклист. Работает — `[x]`, не проверено/не готово — `[ ]`.

## 0) Preconditions (локально)
- [x] PHP доступен (локально через `C:\xampp\php\php.exe`, в WSL: `/mnt/c/xampp/php/php.exe`)
- [x] ENV подготовлен на prod (`/etc/academyprofi/bitrix.access.env`, `root:bitrix`, `0640`)
- [ ] ENV подготовлен локально (опционально, если нужен локальный прогон без mock)

## 1) Установка local app (install.php)
- [x] `install.php` доступен по URL
- [x] `install.php` принимает `ONAPPINSTALL` и сохраняет installation (member_id/domain/tokens/application_token/version)
- [x] Повторный install не ломает состояние (idempotent на уровне сохранения installation)
- [x] Re-install выполняет перерегистрацию блоков (dev-режим: `RESET=Y` включён всегда)

## 2) Регистрация блоков (landing.repo.*)
- [x] `academyprofi.search` появляется в конструкторе в нужной секции
- [x] `academyprofi.catalog` появляется в конструкторе в нужной секции
- [x] `academyprofi.detail` появляется в конструкторе в нужной секции
- [x] Assets подтягиваются (JS/CSS) без mixed-content (HTTPS)

## 3) API модуля (handler.php)
- [x] CORS preflight (`OPTIONS`) работает *(локально, mock)*
- [x] CORS allowlist: если список доменов задан — режет остальные *(реализовано в `Cors.php`)*
- [x] `GET /academyprofi-catalog/product?id=18` → `ProductDto` (валидный JSON) *(mock HTTP + self_test)*
- [x] `GET /academyprofi-catalog/search?q=инф` → до 8 результатов *(mock HTTP + self_test)*
- [x] `GET /academyprofi-catalog/products?start=0` → items + next/total *(mock HTTP + self_test)*
- [x] `GET /academyprofi-catalog/search?...&sectionId=<id>` → выдача ограничена разделом + `filters` отражает параметры *(prod/UI)*
- [x] `GET /academyprofi-catalog/products?...&sectionId=<id>` → `total` меняется + `filters` отражает параметры *(prod/UI)*
- [ ] Кеширование: повторные запросы дают cache hit *(не проверено отдельным тестом)*

## 4) UI поведение (вставленные блоки)
- [x] Search dropdown показывает результаты и кликом выставляет `#product=<id>`
- [x] Catalog карточка кликом выставляет `#product=<id>`
- [x] Detail блок читает `#product=<id>` и рендерит поля, CTA ведёт на `#request`

## 5) Негативные кейсы
- [ ] `/product` без `id` → 400
- [ ] `/product?id=abc` → 400
- [ ] Bitrix24 webhook timeout → 502
- [ ] Некорректный JSON от Bitrix → 502/500 (с логом)

