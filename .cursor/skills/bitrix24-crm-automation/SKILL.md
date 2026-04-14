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


---

## Дополнение (источник: `dreamteamcompany/local/handlers/calltouch/.cursor/skills/bitrix24-crm-automation/SKILL.md`)

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

### Правило: автозапуск robots и BizProc при создании через внутренний API / D7

- Если CRM-сущность создается не через стандартный UI Bitrix, а через сервисный PHP-скрипт, webhook, CLI, фонового обработчика или внутренний API, **не полагаться молча на автозапуск**.
- Для legacy CRM (`\CCrmLead::Add`, `\CCrmDeal::Add`, `\CCrmContact::Add`) передавать корректный `CURRENT_USER` в параметры `Add`.
- После успешного `Add` для лидов/сделок/контактов в коробке использовать явный post-create шаг:
  - robots: `\Bitrix\Crm\Automation\Starter(<ownerTypeId>, $entityId)->setUserId($userId)->runOnAdd()`
  - BizProc: `\CCrmBizProcHelper::AutoStartWorkflows(<ownerTypeName>, $entityId, \CCrmBizProcEventType::Create, $errors)`
- Ошибки запуска robots/BizProc логировать отдельно, но не откатывать уже успешно созданную сущность без явного бизнес-требования.
- Для D7 / Factory API сначала использовать штатное создание через `getAddOperation(...)->launch()` с корректным пользовательским контекстом. Если сущность создается из техскрипта и автоматизация не стартует стабильно, добавлять явную проверку/запуск robots и BizProc после успешного создания тем же пользователем.
- Практическое правило для агентов: если пользователь просит "создать сущность через внутренний API", "через D7", "из обработчика", "из CLI", "из webhook" и ожидает запуск роботов/БП, нужно сразу проверить:
  - кто является `CURRENT_USER` / user context
  - стартуют ли robots на `runOnAdd`
  - нужен ли явный `AutoStartWorkflows(..., Create, ...)`

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


---

## Дополнение (источник: `dreamteamcompany/local/handlers/dozvon/.cursor/skills/bitrix24-crm-automation/SKILL.md`)

---
name: bitrix24-crm-automation
description: Bitrix24 (box/cloud) CRM automation knowledge base: smart processes (dynamic entities), leads/deals/contacts, BizProc (business processes) with custom PHP blocks, widgets/placements, REST webhooks vs D7 API, permissions/auth, BPT file generation. Use when implementing or debugging Bitrix24 CRM/BizProc automation, webhook handlers, or Bitrix widgets/modules.
---

# Bitrix24 CRM automation

## Scope (what exists in projects)

- **Smart processes (Dynamic entities)**: чтение/поиск/создание элементов через D7 Factory API, обновление через REST (`crm.item.update`), работа с наблюдателями, файлами, стадиями/датами.
- **CRM leads/deals/contacts**: создание лидов, поиск/нормализация контактов, антидубли, работа с полями/UF и форматами (особенно телефоны).
- **BizProc (БП)**: PHP-блоки в дизайнере БП, логирование в трекинг, получение инициатора запуска, запуск БП через REST (`bizproc.workflow.start`) с корректным `DOCUMENT_ID` и `PARAMETERS`.
- **Widgets / local app**: регистрация виджетов (placements), обработчики на сервере, JS-часть через `BX24.*`, сравнение REST vs D7 в коробке.
- **Кастомные модули**: модуль уровня Bitrix (события, обработка результата компонентов), ACL/права, маскирование данных.

## Smart processes (смарт-процессы)

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
- **Внутренние методы (коробка)**: например, `\CCrmLead::Add` — требуют инициализации Bitrix и авторизованного пользователя (часто решается "системным пользователем").

### Телефоны и форматы CRM

- Телефон часто хранится как `FM['PHONE'][...]` + форматированные значения.
- Для антидублей/нормализации полезно:
  - чистить до цифр
  - приводить к `+7...`/`+<country>` единому виду

## BizProc (БП) + PHP-блоки

### Архитектурный принцип

- **Максимум штатных блоков** конструктора БП (Условие, Изменение документа, Переменная, Установить статус, Запись в отчёт).
- **PHP-блоки** (`CodeActivity`) только для того, что штатными блоками невозможно:
  - `CCrmFieldMulti` (телефон лида)
  - `CIBlockElement::GetList` (чтение списков 22/128)
  - `CTimeManUser` (проверка рабочего дня оператора)
  - `\Bitrix\Voximplant\StatisticTable` (статистика звонков)
  - `curl` (вызов Voximplant API)
  - `DateTimeImmutable` + часовые пояса
  - `sleep()` (кастомный таймер)
- Каждый PHP-блок пишет результат в переменные БП → штатное "Условие" ветвится по ним.

### Базовые правила PHP-блока в БП

- **Без** `<?php`, **без** `declare(strict_types=1)`, **без** `require`/`include`.
- Переменные: `$this->GetVariable('NAME')` / `$this->SetVariable('NAME', $value)`.
- Типы приводить явно.
- Логи: `$this->WriteToTrackingService(...)`.
- Оборачивать в `try/catch (\Throwable $e)`.

### StateMachineWorkflowActivity (БП со статусами)

Тип БП для многошаговых сценариев с состояниями:
- `StateActivity` — состояние (статус).
- `StateInitializationActivity` — блоки при входе в статус.
- `SetStateActivity` — переход в другое состояние.
- Внутри состояний — стандартные блоки.

### Штатные типы Activity (проверено на реальных BPT)

| Тип | Описание |
|-----|----------|
| `StateMachineWorkflowActivity` | Корневой — БП со статусами |
| `SequentialWorkflowActivity` | Корневой — последовательный БП |
| `StateActivity` | Состояние |
| `StateInitializationActivity` | Инициализация состояния |
| `SetStateActivity` | Переход в другое состояние |
| `CodeActivity` | Произвольный PHP код |
| `IfElseActivity` | Условие (контейнер веток) |
| `IfElseBranchActivity` | Ветка условия |
| `SetFieldActivity` | Изменение документа (NOT ModifyDocumentActivity!) |
| `SetVariableActivity` | Запись в переменную |
| `LogActivity` | Запись в отчёт |
| `DelayActivity` | Пауза (стандартная, нестабильна) |
| `GetUserInfoActivity` | Получить информацию о сотруднике |
| `GetListsDocumentActivity` | Получить информацию об элементе списка |
| `IMNotifyActivity` | Уведомление |
| `GetUserActivity` | Получить пользователя |
| `ForEachActivity` | Цикл по элементам |
| `StartWorkflowActivity` | Запуск другого БП |
| `EmptyBlockActivity` | Пустой блок |
| `TerminateActivity` | Завершение БП |

**ВАЖНО**:
- `ModifyDocumentActivity` НЕ СУЩЕСТВУЕТ. Изменение документа = `SetFieldActivity`.
- `MathOperationActivity` НЕ СУЩЕСТВУЕТ. Математика делается через калькулятор выражений в `SetVariableActivity`: `{{=intval({=Variable:X})+1}}`.
- Для чтения списков (IBlock) есть штатный `GetListsDocumentActivity` — предпочтительнее PHP.

### Замена PHP штатными блоками — проверенные паттерны

**Телефон из лида (вместо CCrmFieldMulti)**:
```
CB_PHONE_RAW = {{=firstvalue({=Document:PHONE})}}
CB_PHONE = {{="+7" & substr({=Variable:CB_PHONE_RAW}, strlen({=Variable:CB_PHONE_RAW})-9, 9)}}
```

**Инкремент переменной (вместо MathOperationActivity / PHP)**:
```
CB_ATTEMPT = {{=intval({=Variable:CB_ATTEMPT})+1}}
```

**SIP-нормализация (вместо PHP)**:
```
CB_SIP_LINE = {{="sip" & {=Variable:CB_SIP_RAW}}}
```

**Рабочее время с timezone (вместо PHP DateTimeImmutable)**:
```
CB_NOW_MINUTES = {{=intval(date("H", dateadd({=System:Now}, {=Variable:CB_TIMEZONE} & "h"))) * 60 + intval(date("i", dateadd({=System:Now}, {=Variable:CB_TIMEZONE} & "h")))}}
CB_FROM_MINUTES = {{=intval(substr({=Variable:CB_WORKTIME}, 0, 2)) * 60 + intval(substr({=Variable:CB_WORKTIME}, 3, 2))}}
CB_TO_MINUTES = {{=intval(substr({=Variable:CB_WORKTIME}, 6, 2)) * 60 + intval(substr({=Variable:CB_WORKTIME}, 9, 2))}}
```
Формат CB_WORKTIME всегда `HH:MM-HH:MM` (11 символов, дефис на позиции 5).

**Выбор сотрудника (вместо CTimeManUser + round-robin)**:
Штатный `SelectUserActivity` с `SkipFinishedWorkday=Y`, `SkipAbsent=Y`, `SelectionType=sequential`.
Round-robin обеспечивается режимом sequential (Битрикс сам запоминает позицию).

**Что НЕ заменяется штатными блоками**:
- `CVoxImplantIncoming::getUserInfo` (проверка BUSY) — только PHP
- `curl` к внешнему API (Voximplant) с разбором ответа — только PHP
- `StatisticTable::getList` — только PHP
- Выборка нескольких элементов списка по фильтру — только PHP

### Запуск БП извне (REST)

- Метод: `bizproc.workflow.start`
- Важно корректно формировать `DOCUMENT_ID` и `PARAMETERS` под шаблон.

## Widgets / placements (Bitrix24)

- Placement-ы в профиле: `USER_PROFILE_MENU`, `USER_PROFILE_TOOLBAR`.
- В коробке обработчик можно держать локально и использовать **D7** напрямую.
- Для облака / внешнего сервера чаще нужен **REST + OAuth/вебхук**.
- JS-навигация по порталу: `BX24.openPath('/company/personal/user/' + userId + '/...')`.

## Кастомный модуль / события / безопасность

- Типовой подход: через `EventManager` подписаться на события компонентов и модифицировать `arResult` (например, маскирование телефонов).
- Права: проверка `IsAdmin()` / группы пользователя; возможность настройки "кто видит реальные данные".
- Для публичных хендлеров: минимальная защита (IP allowlist / токен) + логирование входящих.
