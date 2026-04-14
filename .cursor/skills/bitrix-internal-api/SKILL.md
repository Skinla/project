---
name: bitrix-internal-api
description: Work with Bitrix internal API (box/on-prem): bootstrap prolog, modules, D7/ORM, legacy C* classes, events/handlers, permissions/auth (system user), CRM entities (leads/deals/contacts/dynamic), iblock/intranet, BizProc internals, timeline/comments, user fields/enums, file handling. Use when the user mentions "внутренний API", "D7", "ORM", "prolog_before.php", "CCrm*", "CIBlock*", "EventManager", or when REST is not suitable.
---

# Bitrix internal API (D7 + legacy)

## 1) Bootstrap Bitrix correctly

- **HTTP script**: подключать `.../bitrix/modules/main/include/prolog_before.php`.
- **CLI/background**:
  - выставлять `$_SERVER['DOCUMENT_ROOT']` (искать папку, где есть `bitrix/`)
  - ставить флаги/константы до prolog, если нужно избежать лишней “визуальной” части и ускорить выполнение:
    - `BX_CRONTAB`, `BX_SKIP_POST_UNPACK`, `BX_NO_ACCELERATOR_RESET`, `BX_WITH_ON_AFTER_EPILOG`
    - часто также `NO_KEEP_STATISTIC`, `NOT_CHECK_PERMISSIONS` (если допустимо)
- **Guard**: если `B_PROLOG_INCLUDED === true`, повторно prolog не подключать.

## 2) Подключение модулей

- D7: `\Bitrix\Main\Loader::includeModule('crm')`, `...('iblock')`, `...('intranet')`, `...('main')`.
- Legacy встречается: `CModule::IncludeModule('crm')`.
- Практика: при недоступности модуля — ранний выход + логирование.
 - **Lists (box)**: для “списков” встречается `\Bitrix\Main\Loader::includeModule('lists')` + `CList($listId)->GetFields()`.

## 3) D7/ORM (современный слой)

### Smart processes (Dynamic entities)

- `\Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)`
- Read/list: `$factory->getItems(['filter'=>..., 'select'=>..., 'order'=>..., 'limit'=>...])`
- Create:
  - `$item = $factory->createItem()`
  - `$item->set('FIELD', $value)` / `$item->setAssignedById($userId)`
  - `$result = $factory->getAddOperation($item)->launch()`

### Timeline/comments (CRM)

- Создание комментария: `\Bitrix\Crm\Timeline\CommentEntry::create([...])`
- Иногда нужны дополнительные операции:
  - `\Bitrix\Crm\Timeline\Entity\TimelineTable::update(...)`
  - `\Bitrix\Crm\Timeline\Entity\TimelineBindingTable::add(...)`

## 4) Legacy C* API (но часто нужно)

- **Leads**: `CCrmLead::Add`, `CCrmLead::Update`, `CCrmLead::GetByID`, `CCrmLead::GetList`
- **Multi-fields (телефон/email)**: `CCrmFieldMulti::GetList(...)` (часто телефон хранится там)
- **Permissions**: `CCrmPerms($userId)->HavePerm('LEAD', ..., 'ADD')`

## 5) Авторизация и права для внутренних методов

- Для внутренних методов CRM часто требуется **авторизованный пользователь**:
  - `global $USER; $USER->Authorize($systemUserId, ...)`
- Практика: заводить “системного” пользователя с нужными правами и авторизовывать его в сервисных скриптах.
- Скрипты “для теста” защищать хотя бы token в GET или admin-check.

## 6) IBlock / Intranet (структура компании, справочники)

- Получение “служебных” настроек: `\COption::GetOptionInt('intranet', 'iblock_structure', 0)`
- Разделы/иерархия подразделений:
  - `CIBlockSection::GetList(...)->Fetch()` + поля вроде `UF_HEAD`, `IBLOCK_SECTION_ID`
- Элементы/свойства:
  - `CIBlockElement::GetList(...)`
  - `CIBlockElement::GetProperty(...)` / `GetNextElement()->GetProperties()`

## 6.1) Прямой SQL (когда надо “как есть”)

- Иногда проще и быстрее получить/обновить “технические” данные через:
  - `global $DB; $DB->Query($sql)` (Bitrix DB layer)
  - или `mysqli` напрямую (например, читая `bitrix/.settings.php`), если скрипт вне полного контекста.
- Это использовалось для:
  - поиска дублей по телефону в `b_crm_field_multi`
  - синхронизации “дат создания” после копирования сущностей

## 7) User fields / enums

- Enum value по ID: `CUserFieldEnum::GetList(...)->Fetch()`
- Метаданные UF: `CUserTypeEntity` (когда нужно проверить, что поле существует/как называется).

## 8) BizProc internals (коробка)

- В PHP‑блоках БП:
  - `$this->GetVariable(...)`, `$this->SetVariable(...)`, `$this->WriteToTrackingService(...)`
  - тип “привязка к пользователю” часто ждёт `user_<id>`
- Инициатор БП:
  - на практике `STARTED_BY` надёжно берётся из `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

## 9) Events / handlers

- Legacy: `AddEventHandler('crm', 'OnAfterCrmLeadAdd', 'HandlerFn')`
- D7: `\Bitrix\Main\EventManager::getInstance()->addEventHandler(...)`
- Компонентные события для “post-process arResult”:
  - `main: OnAfterComponentPrepareResult`, `main: OnBeforeComponentTemplate`
  - кейс: маскирование телефонов в `arResult` CRM-компонентов.

## 10) Files (внутренний способ)

- Сохранение файла в Bitrix: `\CFile::SaveFile($fileArray, 'crm')` (часто через временный файл).
- Для UF-файловых полей смарт‑процессов/CRM: важно учитывать формат поля и права пользователя.

## 11) Константы для сервисных скриптов (только когда это оправдано)

- В “техскриптах” встречается отключение части проверок для ускорения/устранения блокировок:
  - `NOT_CHECK_PERMISSIONS`, `NO_KEEP_STATISTIC`, `NO_AGENT_CHECK`
- Использовать осторожно: это меняет модель безопасности и может “скрыть” проблемы прав.


---

## Дополнение (источник: `dreamteamcompany/local/handlers/calltouch/.cursor/skills/bitrix-internal-api/SKILL.md`)

---
name: bitrix-internal-api
description: Work with Bitrix internal API (box/on-prem): bootstrap prolog, modules, D7/ORM, legacy C* classes, events/handlers, permissions/auth (system user), CRM entities (leads/deals/contacts/dynamic), iblock/intranet, BizProc internals, timeline/comments, user fields/enums, file handling. Use when the user mentions "внутренний API", "D7", "ORM", "prolog_before.php", "CCrm*", "CIBlock*", "EventManager", or when REST is not suitable.
---

# Bitrix internal API (D7 + legacy)

## 1) Bootstrap Bitrix correctly

- **HTTP script**: подключать `.../bitrix/modules/main/include/prolog_before.php`.
- **CLI/background**:
  - выставлять `$_SERVER['DOCUMENT_ROOT']` (искать папку, где есть `bitrix/`)
  - ставить флаги/константы до prolog, если нужно избежать лишней “визуальной” части и ускорить выполнение:
    - `BX_CRONTAB`, `BX_SKIP_POST_UNPACK`, `BX_NO_ACCELERATOR_RESET`, `BX_WITH_ON_AFTER_EPILOG`
    - часто также `NO_KEEP_STATISTIC`, `NOT_CHECK_PERMISSIONS` (если допустимо)
- **Guard**: если `B_PROLOG_INCLUDED === true`, повторно prolog не подключать.

## 2) Подключение модулей

- D7: `\Bitrix\Main\Loader::includeModule('crm')`, `...('iblock')`, `...('intranet')`, `...('main')`.
- Legacy встречается: `CModule::IncludeModule('crm')`.
- Практика: при недоступности модуля — ранний выход + логирование.
 - **Lists (box)**: для “списков” встречается `\Bitrix\Main\Loader::includeModule('lists')` + `CList($listId)->GetFields()`.

## 3) D7/ORM (современный слой)

### Smart processes (Dynamic entities)

- `\Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)`
- Read/list: `$factory->getItems(['filter'=>..., 'select'=>..., 'order'=>..., 'limit'=>...])`
- Create:
  - `$item = $factory->createItem()`
  - `$item->set('FIELD', $value)` / `$item->setAssignedById($userId)`
  - `$result = $factory->getAddOperation($item)->launch()`

### Timeline/comments (CRM)

- Создание комментария: `\Bitrix\Crm\Timeline\CommentEntry::create([...])`
- Иногда нужны дополнительные операции:
  - `\Bitrix\Crm\Timeline\Entity\TimelineTable::update(...)`
  - `\Bitrix\Crm\Timeline\Entity\TimelineBindingTable::add(...)`

## 4) Legacy C* API (но часто нужно)

- **Leads**: `CCrmLead::Add`, `CCrmLead::Update`, `CCrmLead::GetByID`, `CCrmLead::GetList`
- **Multi-fields (телефон/email)**: `CCrmFieldMulti::GetList(...)` (часто телефон хранится там)
- **Permissions**: `CCrmPerms($userId)->HavePerm('LEAD', ..., 'ADD')`

### Правило: при создании сущностей через внутренний API запускать automation/BizProc осознанно

- Если сущность CRM создается внутренним PHP API, а не через UI Bitrix, агент должен **явно проверить**, будет ли запущена автоматизация.
- Для legacy `CCrm*::Add(...)`:
  - передавать корректный `CURRENT_USER`
  - после успешного создания при необходимости явно запускать robots через `\Bitrix\Crm\Automation\Starter`
  - для CRM BizProc явно вызывать `\CCrmBizProcHelper::AutoStartWorkflows(..., \CCrmBizProcEventType::Create, $errors)`
- Для D7 / Factory API:
  - сначала использовать штатную операцию создания с корректным user/context
  - если создание идет из техскрипта, обработчика, webhook или CLI и robots/БП не стартуют надежно, добавлять post-create шаг для явного запуска automation/BizProc
- Для коробки это особенно важно в сервисных сценариях с `NOT_CHECK_PERMISSIONS`, `BX_CRONTAB`, фоновой обработкой и кастомным bootstrap: создание сущности может пройти успешно, а automation/BizProc не стартовать без дополнительного шага.
- Если требуется запуск роботов/БП, агент должен проверить не только сам `Add/launch()`, но и:
  - подключен ли `crm`
  - подключен ли `bizproc`
  - есть ли валидный пользовательский контекст
  - логируются ли ошибки автозапуска отдельно от ошибки создания сущности

## 5) Авторизация и права для внутренних методов

- Для внутренних методов CRM часто требуется **авторизованный пользователь**:
  - `global $USER; $USER->Authorize($systemUserId, ...)`
- Практика: заводить “системного” пользователя с нужными правами и авторизовывать его в сервисных скриптах.
- Скрипты “для теста” защищать хотя бы token в GET или admin-check.

## 6) IBlock / Intranet (структура компании, справочники)

- Получение “служебных” настроек: `\COption::GetOptionInt('intranet', 'iblock_structure', 0)`
- Разделы/иерархия подразделений:
  - `CIBlockSection::GetList(...)->Fetch()` + поля вроде `UF_HEAD`, `IBLOCK_SECTION_ID`
- Элементы/свойства:
  - `CIBlockElement::GetList(...)`
  - `CIBlockElement::GetProperty(...)` / `GetNextElement()->GetProperties()`

## 6.1) Прямой SQL (когда надо “как есть”)

- Иногда проще и быстрее получить/обновить “технические” данные через:
  - `global $DB; $DB->Query($sql)` (Bitrix DB layer)
  - или `mysqli` напрямую (например, читая `bitrix/.settings.php`), если скрипт вне полного контекста.
- Это использовалось для:
  - поиска дублей по телефону в `b_crm_field_multi`
  - синхронизации “дат создания” после копирования сущностей

## 7) User fields / enums

- Enum value по ID: `CUserFieldEnum::GetList(...)->Fetch()`
- Метаданные UF: `CUserTypeEntity` (когда нужно проверить, что поле существует/как называется).

## 8) BizProc internals (коробка)

- В PHP‑блоках БП:
  - `$this->GetVariable(...)`, `$this->SetVariable(...)`, `$this->WriteToTrackingService(...)`
  - тип “привязка к пользователю” часто ждёт `user_<id>`
- Инициатор БП:
  - на практике `STARTED_BY` надёжно берётся из `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

## 9) Events / handlers

- Legacy: `AddEventHandler('crm', 'OnAfterCrmLeadAdd', 'HandlerFn')`
- D7: `\Bitrix\Main\EventManager::getInstance()->addEventHandler(...)`
- Компонентные события для “post-process arResult”:
  - `main: OnAfterComponentPrepareResult`, `main: OnBeforeComponentTemplate`
  - кейс: маскирование телефонов в `arResult` CRM-компонентов.

## 10) Files (внутренний способ)

- Сохранение файла в Bitrix: `\CFile::SaveFile($fileArray, 'crm')` (часто через временный файл).
- Для UF-файловых полей смарт‑процессов/CRM: важно учитывать формат поля и права пользователя.

## 11) Константы для сервисных скриптов (только когда это оправдано)

- В “техскриптах” встречается отключение части проверок для ускорения/устранения блокировок:
  - `NOT_CHECK_PERMISSIONS`, `NO_KEEP_STATISTIC`, `NO_AGENT_CHECK`
- Использовать осторожно: это меняет модель безопасности и может “скрыть” проблемы прав.


---

## Дополнение (источник: `dreamteamcompany/local/handlers/dozvon/.cursor/skills/bitrix-internal-api/SKILL.md`)

---
name: bitrix-internal-api
description: Work with Bitrix internal API (box/on-prem): bootstrap prolog, modules, D7/ORM, legacy C* classes, events/handlers, permissions/auth (system user), CRM entities (leads/deals/contacts/dynamic), iblock/intranet, BizProc internals, timeline/comments, user fields/enums, file handling, Voximplant StatisticTable. Use when the user mentions "внутренний API", "D7", "ORM", "prolog_before.php", "CCrm*", "CIBlock*", "EventManager", "StatisticTable", or when REST is not suitable.
---

# Bitrix internal API (D7 + legacy)

## Policy: D7 ORM over raw SQL

- **Do not** use raw SQL (`$conn->query()`, `$DB->Query()`, `mysqli`) when a D7 ORM class (`*Table::getList()`) exists for the table.
- D7 ORM provides type safety, caching, and forward compatibility.
- Raw SQL is acceptable only as a last resort when no ORM class exists.

## 1) Bootstrap Bitrix correctly

- **HTTP script**: подключать `.../bitrix/modules/main/include/prolog_before.php`.
- **CLI/background**:
  - выставлять `$_SERVER['DOCUMENT_ROOT']` (искать папку, где есть `bitrix/`)
  - ставить флаги/константы до prolog, если нужно избежать лишней "визуальной" части и ускорить выполнение:
    - `BX_CRONTAB`, `BX_SKIP_POST_UNPACK`, `BX_NO_ACCELERATOR_RESET`, `BX_WITH_ON_AFTER_EPILOG`
    - часто также `NO_KEEP_STATISTIC`, `NOT_CHECK_PERMISSIONS` (если допустимо)
- **Guard**: если `B_PROLOG_INCLUDED === true`, повторно prolog не подключать.

## 2) Подключение модулей

- D7: `\Bitrix\Main\Loader::includeModule('crm')`, `...('iblock')`, `...('intranet')`, `...('main')`, `...('voximplant')`.
- Legacy встречается: `CModule::IncludeModule('crm')`.
- Практика: при недоступности модуля — ранний выход + логирование.
 - **Lists (box)**: для "списков" встречается `\Bitrix\Main\Loader::includeModule('lists')` + `CList($listId)->GetFields()`.

## 3) D7/ORM (современный слой)

### Smart processes (Dynamic entities)

- `\Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId)`
- Read/list: `$factory->getItems(['filter'=>..., 'select'=>..., 'order'=>..., 'limit'=>...])`
- Create:
  - `$item = $factory->createItem()`
  - `$item->set('FIELD', $value)` / `$item->setAssignedById($userId)`
  - `$result = $factory->getAddOperation($item)->launch()`

### Timeline/comments (CRM)

- Создание комментария: `\Bitrix\Crm\Timeline\CommentEntry::create([...])`
- Иногда нужны дополнительные операции:
  - `\Bitrix\Crm\Timeline\Entity\TimelineTable::update(...)`
  - `\Bitrix\Crm\Timeline\Entity\TimelineBindingTable::add(...)`

## 4) Legacy C* API (но часто нужно)

- **Leads**: `CCrmLead::Add`, `CCrmLead::Update`, `CCrmLead::GetByID`, `CCrmLead::GetList`
- **Multi-fields (телефон/email)**: `CCrmFieldMulti::GetList(...)` (часто телефон хранится там)
- **Permissions**: `CCrmPerms($userId)->HavePerm('LEAD', ..., 'ADD')`

## 5) Авторизация и права для внутренних методов

- Для внутренних методов CRM часто требуется **авторизованный пользователь**:
  - `global $USER; $USER->Authorize($systemUserId, ...)`
- Практика: заводить "системного" пользователя с нужными правами и авторизовывать его в сервисных скриптах.
- Скрипты "для теста" защищать хотя бы token в GET или admin-check.

## 6) IBlock / Intranet (структура компании, справочники)

- Получение "служебных" настроек: `\COption::GetOptionInt('intranet', 'iblock_structure', 0)`
- Разделы/иерархия подразделений:
  - `CIBlockSection::GetList(...)->Fetch()` + поля вроде `UF_HEAD`, `IBLOCK_SECTION_ID`
- Элементы/свойства:
  - `CIBlockElement::GetList(...)`
  - `CIBlockElement::GetProperty(...)` / `GetNextElement()->GetProperties()`

## 7) Voximplant StatisticTable (статистика звонков)

- **Не использовать прямой SQL** к `b_voximplant_statistic`.
- Подключение: `Loader::includeModule('voximplant')`
- Класс: `\Bitrix\Voximplant\StatisticTable` (проверено на портале DreamTeam, `Model\StatisticTable` не существует).
- Поля: `ID`, `CALL_ID`, `PHONE_NUMBER`, `PORTAL_USER_ID`, `CALL_DURATION`, `CALL_FAILED_CODE`, `CALL_START_DATE`.

Поиск недавних звонков по номеру:
```php
Loader::includeModule('voximplant');
$fromDate = new \Bitrix\Main\Type\DateTime(
    date('d.m.Y H:i:s', strtotime('-10 minutes')), 'd.m.Y H:i:s'
);
$res = \Bitrix\Voximplant\StatisticTable::getList([
    'filter' => [
        '%=PHONE_NUMBER' => '%' . $digits,
        '>=CALL_START_DATE' => $fromDate,
    ],
    'select' => ['ID'],
    'order' => ['ID' => 'DESC'],
    'limit' => 1,
]);
$hasRecent = (bool)$res->fetch();
```

Поиск по оператору + номеру:
```php
$res = \Bitrix\Voximplant\StatisticTable::getList([
    'filter' => [
        '=PORTAL_USER_ID' => $operatorId,
        '%=PHONE_NUMBER' => '%' . $phoneNorm,
        '>=CALL_START_DATE' => $fromDate,
    ],
    'order' => ['ID' => 'DESC'],
    'limit' => 1,
]);
```

## 8) Operator status checks

### TimeMan (рабочий день):
```php
Loader::includeModule('timeman');
$tmUser = new \CTimeManUser($userId);
$tmInfo = $tmUser->GetCurrentInfo();
$dayOpened = is_array($tmInfo) && in_array($tmInfo['STATUS'] ?? '', ['OPENED', 'PAUSED']);
```

### Voximplant BUSY status:
```php
if (class_exists('\Bitrix\Voximplant\Sip\UserInfo') || class_exists('CVoxImplantIncoming')) {
    $userInfo = \CVoxImplantIncoming::getUserInfo($userId);
    $isBusy = ($userInfo['BUSY'] ?? false);
}
```

## 9) User fields / enums

- Enum value по ID: `CUserFieldEnum::GetList(...)->Fetch()`
- Метаданные UF: `CUserTypeEntity` (когда нужно проверить, что поле существует/как называется).

## 10) BizProc internals (коробка)

- В PHP-блоках БП:
  - `$this->GetVariable(...)`, `$this->SetVariable(...)`, `$this->WriteToTrackingService(...)`
  - тип "привязка к пользователю" часто ждёт `user_<id>`
- Инициатор БП:
  - на практике `STARTED_BY` надёжно берётся из `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

## 11) Events / handlers

- Legacy: `AddEventHandler('crm', 'OnAfterCrmLeadAdd', 'HandlerFn')`
- D7: `\Bitrix\Main\EventManager::getInstance()->addEventHandler(...)`
- Компонентные события для "post-process arResult":
  - `main: OnAfterComponentPrepareResult`, `main: OnBeforeComponentTemplate`
  - кейс: маскирование телефонов в `arResult` CRM-компонентов.

## 12) Files (внутренний способ)

- Сохранение файла в Bitrix: `\CFile::SaveFile($fileArray, 'crm')` (часто через временный файл).
- Для UF-файловых полей смарт-процессов/CRM: важно учитывать формат поля и права пользователя.

## 13) Константы для сервисных скриптов (только когда это оправдано)

- В "техскриптах" встречается отключение части проверок для ускорения/устранения блокировок:
  - `NOT_CHECK_PERMISSIONS`, `NO_KEEP_STATISTIC`, `NO_AGENT_CHECK`
- Использовать осторожно: это меняет модель безопасности и может "скрыть" проблемы прав.
