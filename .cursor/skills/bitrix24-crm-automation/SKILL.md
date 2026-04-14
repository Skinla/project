---
name: bitrix24-crm-automation
description: Bitrix24 (box/cloud) CRM automation knowledge base: smart processes (dynamic entities), leads/deals/contacts, BizProc (business processes) with custom PHP blocks, widgets/placements, REST webhooks vs D7 API, permissions/auth. Use when implementing or debugging Bitrix24 CRM/BizProc automation, webhook handlers, or Bitrix widgets/modules.
---

# Bitrix24 CRM automation

## Scope (what exists in projects)

- **Smart processes (Dynamic entities)**: чтение/поиск/создание элементов через D7 Factory API, обновление через REST (`crm.item.update`), работа с наблюдателями, файлами, стадиями/датами.
- **CRM leads/deals/contacts**: создание лидов, поиск/нормализация контактов, антидубли, работа с полями/UF и форматами (особенно телефоны).
- **BizProc (БП)**: PHP-блоки в дизайнере БП, логирование в трекинг, получение инициатора запуска, запуск БП через REST (`bizproc.workflow.start`) с корректным `DOCUMENT_ID` и `PARAMETERS`.
- **Widgets / local app**: регистрация виджетов (placements), обработчики на сервере, JS-часть через `BX24.*`, сравнение REST vs D7 в коробке.
- **Кастомные модули**: модуль уровня Bitrix (события, обработка результата компонентов), ACL/права, маскирование данных.

## Smart processes (смарт‑процессы)

### D7 / Factory API (коробка)

- Подключение: `\Bitrix\Main\Loader::includeModule('crm')`
- Фабрика: `\Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)`
- Чтение: `$factory->getItems(['filter' => ..., 'select' => ..., 'order' => ..., 'limit' => ...])`
- Создание:
  - `$item = $factory->createItem()`
  - `$item->set('FIELD', $value)` / `$item->setAssignedById($userId)`
  - `$result = $factory->getAddOperation($item)->launch()`
  - Ошибки: `$result->getErrorMessages()`

### REST / Webhook (облако или внешний сервер)

- Универсальные методы: `crm.item.list`, `crm.item.add`, `crm.item.update`, `crm.item.fields`, `crm.type.list`
- Полезный приём: **доополнять элемент после создания** (например, добавить наблюдателя через `crm.item.update` и поле `OBSERVERS`).
- Файлы: на практике встречается рабочий формат `{'fileName': ..., 'fileContent': base64}` (для UF-файловых полей при `crm.item.update`).

### Типовые кейсы

- **Отбор по стадиям**: у стадий часто есть суффиксы вроде `...:SUCCESS` — удобно проверять подстроку `SUCCESS`.
- **Отбор по датам**: у динамических сущностей встречаются поля `CREATED_TIME`, `MOVED_TIME`.
- **Сопоставление полей**: генерировать константы/маппинги по результату `crm.item.fields` и аналогов для lead/deal/contact.

## Leads / Deals / Contacts

### Создание лидов

- **REST-путь**: проще по авторизации (вебхук в URL), быстрее внедрение, но сетевые задержки.
- **Внутренние методы (коробка)**: например, `\CCrmLead::Add` — требуют инициализации Bitrix и авторизованного пользователя (часто решается “системным пользователем”).

### Телефоны и форматы CRM

- Телефон часто хранится как `FM['PHONE'][...]` + форматированные значения.
- Для антидублей/нормализации полезно:
  - чистить до цифр
  - приводить к `+7...`/`+<country>` единому виду

## BizProc (БП) + PHP-блоки

### Базовые правила PHP-блока в БП

- **Без** `<?php` в начале.
- Переменные: `$this->GetVariable('NAME')` / `$this->SetVariable('NAME', $value)`.
- Типы приводить явно (в БП часто “строка” vs “число”, “привязка к пользователю” → `user_123`).
- Логи: `$this->WriteToTrackingService(...)` (и ранние `return` при ошибках).

### Инициатор запуска БП (STARTED_BY)

- Если `WorkflowInstanceTable::getById(...)` не даёт `STARTED_BY`, практичный источник — `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

### Запуск БП извне (REST)

- Метод: `bizproc.workflow.start`
- Важно корректно формировать `DOCUMENT_ID` (для dynamic: `DYNAMIC_<typeId>_<itemId>` или эквивалентное представление) и `PARAMETERS` под шаблон.

## Widgets / placements (Bitrix24)

- Placement-ы в профиле: `USER_PROFILE_MENU`, `USER_PROFILE_TOOLBAR`.
- В коробке обработчик можно держать локально и использовать **D7** напрямую.
- Для облака / внешнего сервера чаще нужен **REST + OAuth/вебхук**.
- JS-навигация по порталу: `BX24.openPath('/company/personal/user/' + userId + '/...')`.

## Кастомный модуль / события / безопасность

- Типовой подход: через `EventManager` подписаться на события компонентов и модифицировать `arResult` (например, маскирование телефонов).
- Права: проверка `IsAdmin()` / группы пользователя; возможность настройки “кто видит реальные данные”.
- Для публичных хендлеров: минимальная защита (IP allowlist / токен) + логирование входящих.

