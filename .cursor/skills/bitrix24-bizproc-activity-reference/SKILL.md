---
name: bitrix24-bizproc-activity-reference
description: Practical reference of all BizProc activity types with real Properties, values, and patterns from bitrix.dreamteamcompany.ru. Use when building, generating, or debugging Bitrix24 BizProc templates — especially when you need correct Properties format for any activity block. Covers conditions, CRM blocks, loops, communications, variables, orchestration, and more.
---

# BizProc Activity Reference

Practical reference built from 196 real templates exported from `bitrix.dreamteamcompany.ru`.
Source data: `docs/bizproc_rest_audit/bitrix_dreamteamcompany_ru_2026-04-01_templates_full.json`

Use this when generating BPT files, writing PHP blocks that interact with BP, or debugging existing templates.

---

## 1. Workflow roots

### `SequentialWorkflowActivity`

Root for linear workflows (171 of 196 templates).

```json
{
  "Type": "SequentialWorkflowActivity",
  "Properties": {
    "Title": "Последовательный бизнес-процесс",
    "Permission": []
  }
}
```

### `StateMachineWorkflowActivity`

Root for state-machine workflows (25 templates). States are `StateActivity` children.

```json
{
  "Type": "StateMachineWorkflowActivity",
  "Properties": {
    "Title": "Бизнес-процесс со статусами",
    "InitialStateName": "A69311_57041_84049_53268",
    "Permission": []
  }
}
```

### `StateActivity`

One state inside a state machine. Contains `StateInitializationActivity` as first child.

```json
{
  "Type": "StateActivity",
  "Properties": {
    "Title": "Лиды вручную",
    "Permission": [],
    "PermissionMode": "3",
    "PermissionScope": "1"
  }
}
```

CRM entities: `Permission` must be `[]` (empty), `PermissionMode`/`PermissionScope` empty strings.

### `StateInitializationActivity`

Runs on entry into a state. Properties must have only `Title`.

```json
{
  "Type": "StateInitializationActivity",
  "Properties": { "Title": "Вход в статус" }
}
```

### `SetStateActivity`

Transition to another state by `TargetStateName`.

```json
{
  "Type": "SetStateActivity",
  "Properties": {
    "TargetStateName": "A35883_23582_98539_97658",
    "CancelCurrentState": "N",
    "Title": "Вакансия"
  }
}
```

### `SetStateTitleActivity`

Changes the displayed title of a state at runtime.

```json
{
  "Type": "SetStateTitleActivity",
  "Properties": {
    "TargetStateTitle": "Отчет принят",
    "Title": "Установить текст статуса"
  }
}
```

---

## 2. Conditions

### `IfElseActivity` + `IfElseBranchActivity`

Container + branches. The container itself has no logic — all conditions are on the branches.

**Condition by document field:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Да",
    "fieldcondition": [["PROPERTY_IS_APPROVED", "=", "YES"]]
  }
}
```

**Condition by BP variable:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Да",
    "propertyvariablecondition": [["Counter", ">", "0", "0"]]
  }
}
```

Format: `[field, operator, value, joiner]`. Joiner: `"0"` = AND.

**Mixed condition (field + variable):**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Комбо",
    "mixedcondition": [["STATUS_ID", "=", "NEW", "0"]]
  }
}
```

**Else (always true) branch:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Нет",
    "truecondition": "1"
  }
}
```

Operators observed: `=`, `!=`, `>`, `<`, `>=`, `<=`, `in`, `!in`, `contain`, `!contain`, `empty`, `!empty`.

---

## 3. Loops and iterators

### `WhileActivity`

Loop while condition is true. Condition format is same as `IfElseBranchActivity`.

**By variable (counter loop):**

```json
{
  "Type": "WhileActivity",
  "Properties": {
    "Title": "Цикл (24 прогона)",
    "propertyvariablecondition": [["Counter", ">", "0", "0"]]
  }
}
```

Typical pattern: `SetVariableActivity` (init counter) → `WhileActivity` → body → `SetVariableActivity` (decrement).

**By document field:**

```json
{
  "Type": "WhileActivity",
  "Properties": {
    "Title": "Цикл согласования",
    "fieldcondition": [["PROPERTY_IS_APPROVED", "=", "IN_PROGRESS"]]
  }
}
```

### `ForEachActivity`

Iterates over a list stored in a BP variable.

```json
{
  "Type": "ForEachActivity",
  "Properties": {
    "Variable": "List_ID_Court_Sessions",
    "Object": "Variable",
    "Title": "Итератор (перебор суд. заседаний)"
  }
}
```

- `Object` is always `"Variable"` on this portal.
- `Variable` is the BP variable name holding the array.
- The current element is available inside the loop body as `{=ForEachActivity:CurrentElement}` (check by template context).

### `ParallelActivity`

Runs child `SequenceActivity` branches in parallel.

```json
{
  "Type": "ParallelActivity",
  "Properties": { "Title": "Параллельное выполнение" }
}
```

---

## 4. Variables and logging

### `SetVariableActivity`

Write one or more BP variables.

```json
{
  "Type": "SetVariableActivity",
  "Properties": {
    "VariableValue": {
      "username": "{=Document:CREATED_BY}",
      "Counter": "24"
    },
    "Title": "Изменение переменных"
  }
}
```

Values support template expressions: `{=Document:FIELD}`, `{=Variable:Name}`, `{=Activity:Field}`, `{=Constant:Name}`, `{=GlobalVar:Name}`.

### `SetGlobalVariableActivity`

Write to global (portal-level) variables.

```json
{
  "Type": "SetGlobalVariableActivity",
  "Properties": {
    "GlobalVariableValue": {
      "{=GlobalVar:Variable1749129301199}": []
    },
    "Title": "Изменение глобальных переменных"
  }
}
```

### `LogActivity`

Write to BP tracking log. `SetVariable` = `"0"` means log-only.

```json
{
  "Type": "LogActivity",
  "Properties": {
    "Text": "Поиск начальника для {=Variable:Approver_printable}",
    "SetVariable": "0",
    "Title": "Запись в отчет"
  }
}
```

---

## 5. Document fields

### `SetFieldActivity`

Update document fields. NOT `ModifyDocumentActivity`.

```json
{
  "Type": "SetFieldActivity",
  "Properties": {
    "FieldValue": {
      "SOURCE_ID": "CALL",
      "STATUS_ID": "UC_I0XLWE"
    },
    "ModifiedBy": [],
    "MergeMultipleFields": "N",
    "Title": "Изменение документа"
  }
}
```

`ModifiedBy`: empty array or `["user_1"]`. `MergeMultipleFields`: `"N"` = overwrite, `"Y"` = merge.

### `SetPermissionsActivity`

```json
{
  "Type": "SetPermissionsActivity",
  "Properties": {
    "Permission": { "R": ["{=Variable:username}"] },
    "Rewrite": "",
    "SetMode": "1",
    "SetScope": "1",
    "Title": "Установка прав"
  }
}
```

---

## 6. Lists (iblock) read/write

### `GetListsDocumentActivity`

Read fields from a list element.

```json
{
  "Type": "GetListsDocumentActivity",
  "Properties": {
    "DocumentType": ["lists", "BizprocDocument", "iblock_37"],
    "ElementId": "{=Document:UF_CRM_16_1749464934}",
    "Fields": ["PROPERTY_OTVETSTVENNYY"],
    "FieldsMap": {
      "PROPERTY_OTVETSTVENNYY": {
        "Id": "", "Type": "user", "Name": "Ответственный"
      }
    },
    "Title": "Получить информацию об элементе списка"
  }
}
```

Result fields accessible as `{=ActivityName:PROPERTY_OTVETSTVENNYY}`.

### `UpdateListsDocumentActivity`

```json
{
  "Type": "UpdateListsDocumentActivity",
  "Properties": {
    "Fields": {
      "PROPERTY_PROPERTY_221": "{=SomeActivity:PROPERTY_PROPERTY_206}",
      "PROPERTY_PROPERTY_206": "0"
    },
    "DocumentType": ["lists", "Bitrix\\Lists\\BizprocDocumentLists", "iblock_16"],
    "ElementId": "{=Variable:Counter_4}",
    "Title": "Изменить элемент списка"
  }
}
```

### `CreateListsDocumentActivity`

```json
{
  "Type": "CreateListsDocumentActivity",
  "Properties": {
    "Fields": {
      "CREATED_BY": "{=Document:ASSIGNED_BY_ID}",
      "IBLOCK_ID": "46",
      "NAME": "{=Variable:New_Vacancy}"
    },
    "DocumentType": ["lists", "BizprocDocument", "iblock_46"],
    "Title": "Новый элемент у/с"
  }
}
```

---

## 7. CRM activities

### `CrmGetDynamicInfoActivity`

Read smart-process (dynamic entity) items.

```json
{
  "Type": "CrmGetDynamicInfoActivity",
  "Properties": {
    "DynamicTypeId": "1056",
    "ReturnFields": ["SOURCE_ID", "STAGE_ID"],
    "OnlyDynamicEntities": "N",
    "DynamicFilterFields": {
      "items": [[
        {"object": "Document", "field": "ID", "operator": "=", "value": "{=Document:ID}"},
        "AND"
      ]]
    },
    "DynamicEntityFields": {
      "SOURCE_ID": { "Name": "Источник", "Type": "select" }
    },
    "Title": "Получить информацию об элементе CRM"
  }
}
```

### `CrmUpdateDynamicActivity`

```json
{
  "Type": "CrmUpdateDynamicActivity",
  "Properties": {
    "DynamicTypeId": "1056",
    "DynamicId": "",
    "DynamicFilterFields": {
      "items": [[
        {"object": "Document", "field": "ID", "operator": "=", "value": "{=SomeActivity:ID}"},
        "AND"
      ]]
    },
    "DynamicEntitiesFields": {
      "STAGE_ID": "DT1056_10:CLIENT",
      "UF_CRM_7_1748244889": "{=Document:UF_CRM_12_1749042549}"
    },
    "Title": "Изменить элемент смарт-процесса"
  }
}
```

### `CrmCreateDynamicActivity`

```json
{
  "Type": "CrmCreateDynamicActivity",
  "Properties": {
    "DynamicTypeId": "1096",
    "OnlyDynamicEntities": "Y",
    "DynamicEntitiesFields": {
      "1096_TITLE": "{=Variable:Vacancy_For_Name > printable}",
      "1096_ASSIGNED_BY_ID": "{=SomeActivity:PROPERTY_OTVETSTVENNYY}",
      "1096_OPENED": "Y"
    },
    "Title": "Создать элемент смарт-процесса"
  }
}
```

### `CrmGetDataEntityActivity`

Read a CRM entity (lead, deal, contact, etc.) by ID.

```json
{
  "Type": "CrmGetDataEntityActivity",
  "Properties": {
    "DocumentType": ["crm", "CCrmDocumentDeal", "DEAL"],
    "EntityId": "{=Document:ID}",
    "EntityType": "DEAL",
    "PrintableVersion": "Y",
    "EntityFields": { "SOURCE_ID": { "Name": "Источник", "Type": "select" } },
    "Title": "Выбор данных CRM"
  }
}
```

### `CrmChangeResponsibleActivity`

```json
{
  "Type": "CrmChangeResponsibleActivity",
  "Properties": {
    "Responsible": ["{=SomeActivity:GetUser}"],
    "ModifiedBy": ["{=Document:ASSIGNED_BY_ID}"],
    "GetterType": "f",
    "SkipAbsent": "N",
    "SkipTimeMan": "N",
    "Title": "Изменить ответственного"
  }
}
```

### `CrmChangeStatusActivity`

```json
{
  "Type": "CrmChangeStatusActivity",
  "Properties": {
    "TargetStatus": "UC_I0XLWE",
    "ModifiedBy": [],
    "Title": "Смена статуса"
  }
}
```

### `CrmSetObserverField`

```json
{
  "Type": "CrmSetObserverField",
  "Properties": {
    "ActionOnObservers": "add",
    "Observers": ["group_hr274"],
    "Title": "Изменить наблюдателей"
  }
}
```

`ActionOnObservers`: `"add"` or `"remove"`.

### `CrmChangeDealCategoryActivity`

```json
{
  "Type": "CrmChangeDealCategoryActivity",
  "Properties": {
    "CategoryId": "1",
    "StageId": "C1:NEW",
    "Title": "Сменить воронку"
  }
}
```

### `CrmConvertDocumentActivity`

Convert a lead to deal+contact.

```json
{
  "Type": "CrmConvertDocumentActivity",
  "Properties": {
    "Responsible": ["{=Document:ASSIGNED_BY_ID}"],
    "Items": ["DEAL", "CONTACT"],
    "DealCategoryId": "1",
    "DisableActivityCompletion": "Y",
    "Title": "Создать на основании"
  }
}
```

### `CrmTimelineCommentAdd`

```json
{
  "Type": "CrmTimelineCommentAdd",
  "Properties": {
    "CommentText": "Отправлено СМС-сообщение.",
    "CommentUser": ["{=Document:ASSIGNED_BY_ID}"],
    "Title": "Добавить комментарий в таймлайн"
  }
}
```

### `CrmGenerateEntityDocumentActivity`

Generate a document from CRM template.

```json
{
  "Type": "CrmGenerateEntityDocumentActivity",
  "Properties": {
    "TemplateId": "22",
    "UseSubscription": "Y",
    "EnablePublicUrl": "N",
    "CreateActivity": "N",
    "WithStamps": "N",
    "Values": { "DocumentTitle": "Приказ №{=Variable:Counter}" },
    "MyCompanyId": "",
    "MyCompanyRequisiteId": "",
    "MyCompanyBankDetailId": "",
    "Title": "Создать документ"
  }
}
```

### `CrmGetRelationsInfoActivity`

Read parent CRM entity relations.

```json
{
  "Type": "CrmGetRelationsInfoActivity",
  "Properties": {
    "ParentTypeId": "1056",
    "ParentEntityFields": {
      "CRM_ID": { "Name": "ID элемента CRM", "Type": "string" }
    },
    "Title": "Обращение к Делу"
  }
}
```

### `CrmSendSmsActivity`

```json
{
  "Type": "CrmSendSmsActivity",
  "Properties": {
    "MessageText": "Вы записаны к стоматологу...",
    "ProviderId": "app.58c8d87066a529.45537578|sms.ru.1@rest",
    "RecipientType": "entity",
    "RecipientUser": ["user_9"],
    "PhoneType": "",
    "Title": "Отправить СМС"
  }
}
```

---

## 8. Communications

### `IMNotifyActivity`

IM notification to a user.

```json
{
  "Type": "IMNotifyActivity",
  "Properties": {
    "MessageSite": "Текст уведомления с {=Document:URL_BB}",
    "MessageOut": "",
    "MessageType": "2",
    "MessageUserFrom": ["user_1"],
    "MessageUserTo": ["user_9"],
    "Title": "Уведомление Медведева"
  }
}
```

`MessageType`: `"2"` = notification.

### `MailActivity`

```json
{
  "Type": "MailActivity",
  "Properties": {
    "MailSubject": "Необходимо утвердить документ \"{=Document:NAME}\"",
    "MailText": "Вы должны утвердить или отклонить документ...",
    "MailMessageType": "plain",
    "MailCharset": "UTF-8",
    "MailUserFrom": "",
    "MailUserFromArray": ["user_1"],
    "MailUserTo": "",
    "MailUserToArray": ["Template", "Voters1"],
    "Title": "Почтовое сообщение"
  }
}
```

### `ImAddMessageToGroupChatActivity`

```json
{
  "Type": "ImAddMessageToGroupChatActivity",
  "Properties": {
    "ChatId": "69697",
    "FromMember": "user_9",
    "MessageTemplate": "plain",
    "MessageFields": {
      "MessageText": "Добавлена новая интеграция {=Document:ID}"
    },
    "Title": "Отправить сообщение в групповой чат"
  }
}
```

### `WebHookActivity`

Outgoing HTTP call.

```json
{
  "Type": "WebHookActivity",
  "Properties": {
    "Handler": "https://bitrix.dreamteamcompany.ru/local/handlers/test/sync_comments_by_lead.php?box_lead_id={=Document:ID}",
    "Title": "Исходящий Вебхук"
  }
}
```

---

## 9. Users, tasks, approvals

### `GetUserActivity`

Pick a user by rule (random from group, boss, etc.).

```json
{
  "Type": "GetUserActivity",
  "Properties": {
    "UserType": "random",
    "MaxLevel": "1",
    "UserParameter": ["group_hr38"],
    "ReserveUserParameter": ["user_53"],
    "SkipAbsent": "Y",
    "SkipAbsentReserve": "Y",
    "SkipTimeman": "Y",
    "SkipTimemanReserve": "N",
    "Title": "Выбор сотрудника СПб КЦ"
  }
}
```

`UserType`: `"random"`, `"boss"`, `"queue"`.

### `GetUserInfoActivity`

Read user profile fields.

```json
{
  "Type": "GetUserInfoActivity",
  "Properties": {
    "GetUser": ["{=Document:CREATED_BY}"],
    "UserFields": {
      "USER_ACTIVE": { "Name": "Активен", "Type": "bool" },
      "USER_LAST_NAME": { "Name": "Фамилия", "Type": "string" },
      "USER_PERSONAL_MOBILE": { "Name": "Мобильный телефон", "Type": "string" }
    },
    "Title": "Получить информацию о сотруднике"
  }
}
```

### `ApproveActivity`

Approval task.

```json
{
  "Type": "ApproveActivity",
  "Properties": {
    "ApproveType": "any",
    "OverdueDate": "",
    "ApproveMinPercent": "50",
    "ApproveWaitForAll": "N",
    "Name": "Запрашивает выдачу наличных",
    "Description": "Вам необходимо утвердить заявку...",
    "Parameters": "",
    "StatusMessage": "Утверждение заявки руководителем",
    "SetStatusMessage": "Y",
    "TaskButton1Message": "Утвердить заявку",
    "TaskButton2Message": "Отклонить заявку",
    "CommentLabelMessage": "Комментарий",
    "ShowComment": "N",
    "Users": ["{=Variable:Approver}"],
    "Title": "Утверждение"
  }
}
```

`ApproveType`: `"any"` (first response wins), `"all"` (all must approve).

### `RequestInformationActivity`

Request structured input from a user.

```json
{
  "Type": "RequestInformationActivity",
  "Properties": {
    "Users": ["{=Document:CREATED_BY}"],
    "Name": "Отчет о полученных наличных",
    "Description": "Укажите товары или услуги...",
    "RequestedInformation": [
      {
        "Title": "Отчет", "Name": "Report",
        "Type": "text", "Required": "1", "Multiple": "0"
      }
    ],
    "TaskButtonMessage": "Отправить",
    "ShowComment": "N",
    "StatusMessage": "Ожидание отчета",
    "SetStatusMessage": "Y",
    "Title": "Отчет о полученных наличных"
  }
}
```

### `Task2Activity`

Create a Bitrix task.

```json
{
  "Type": "Task2Activity",
  "Properties": {
    "Fields": {
      "TITLE": "До продажа по сделке: {=Document:TITLE}",
      "CREATED_BY": "user_1",
      "RESPONSIBLE_ID": ["Document", "ASSIGNED_BY_ID"],
      "DESCRIPTION": "Произвести до продажу..."
    },
    "HoldToClose": "0",
    "AUTO_LINK_TO_CRM_ENTITY": "N",
    "Title": "До продажа"
  }
}
```

---

## 10. Delays

### `DelayActivity`

**By absolute time:**

```json
{
  "Type": "DelayActivity",
  "Properties": {
    "TimeoutTime": "=dateadd({=Document:PROPERTY_FROM}, \"-5d\")",
    "WriteToLog": "Y",
    "Title": "Пауза до даты"
  }
}
```

**By duration:**

```json
{
  "Type": "DelayActivity",
  "Properties": {
    "TimeoutDuration": "12",
    "TimeoutDurationType": "h",
    "WriteToLog": "Y",
    "Title": "Пауза 12 часов"
  }
}
```

`TimeoutDurationType`: `"s"` seconds, `"m"` minutes, `"h"` hours, `"d"` days.

### `RobotDelayActivity`

Same as DelayActivity but in robot context. Has `WaitWorkDayUser`.

```json
{
  "Type": "RobotDelayActivity",
  "Properties": {
    "TimeoutTime": "{=Variable:Variable1}",
    "TimeoutTimeIsLocal": "N",
    "WriteToLog": "Y",
    "WaitWorkDayUser": [],
    "Title": "Пауза робота"
  }
}
```

---

## 11. Orchestration

### `StartWorkflowActivity`

Launch a child BP by template ID.

```json
{
  "Type": "StartWorkflowActivity",
  "Properties": {
    "DocumentId": "{=SomeActivity:ID}",
    "TemplateId": "95",
    "UseSubscription": "Y",
    "TemplateParameters": [],
    "Title": "Запуск дочернего БП"
  }
}
```

`UseSubscription`: `"Y"` = wait for child to finish before continuing.

### `TerminateActivity`

Forcefully stop the BP.

```json
{
  "Type": "TerminateActivity",
  "Properties": {
    "Title": "Прерывание процесса"
  }
}
```

---

## 12. Custom PHP code

### `CodeActivity`

```json
{
  "Type": "CodeActivity",
  "Properties": {
    "ExecuteCode": "$rootActivity = $this->GetRootActivity();\n$rootActivity->SetVariable('task_start', GetTime(time() + 60*60*24*30*12, 'SHORT'));",
    "Title": "PHP код"
  }
}
```

See skill `bitrix-bizproc-php-blocks` for detailed PHP block rules.

---

## 13. Other / rare activities

### `EmptyBlockActivity`

Visual container for grouping, no logic.

```json
{
  "Type": "EmptyBlockActivity",
  "Properties": { "Title": "НОЧНЫЕ v.3" }
}
```

### `SequenceActivity`

Sequential container inside other blocks (required by `ForEach`, `While`, `Parallel`, `EmptyBlock`).

```json
{
  "Type": "SequenceActivity",
  "Properties": { "Title": "Последовательность действий" }
}
```

### `PublishDocumentActivity`

Publish a document (lists).

```json
{
  "Type": "PublishDocumentActivity",
  "Properties": { "Title": "Опубликовать документ" }
}
```

### `SocNetMessageActivity`

Post to social network / live feed.

### `AbsenceActivity` / `Calendar2Activity`

HR/calendar events — rarely used.

### `rest_<hash>` activities

Custom activities registered by REST apps. Properties match the app's schema. On this portal:

- `rest_6515462f0a6260c5f68e82ba60f8c4f5` — in 15 templates (WhatsApp/messaging)
- `rest_6e9049d7fbafe1eeb17cc7a385cc8469` — in 3 templates (Telegram)
- `rest_4a64fafd0c72964fd1574767c63c8b12` — in 2 templates

---

## 14. Template-level: Variables, Constants, Parameters

### Variable definition

```json
{
  "Counter": {
    "Name": "Счетчик",
    "Description": "",
    "Type": "int",
    "Required": "0",
    "Multiple": "0",
    "Options": "",
    "Default": "90"
  }
}
```

Common types: `string`, `int`, `double`, `bool`, `datetime`, `date`, `user`, `file`, `text`, `select`, `UF:crm`, `UF:iblock_element`, `UF:iblock_section`, `UF:date`, `UF:crm_status`, `E:EList`, `S:employee`.

### Constant definition

```json
{
  "Manager": {
    "Name": "Кто утверждает",
    "Description": "директор или заместитель",
    "Type": "user",
    "Required": "1",
    "Multiple": "1",
    "Default": ""
  }
}
```

### Parameter definition (passed when BP is launched externally)

```json
{
  "Lead_Name": {
    "Name": "Название Лида",
    "Description": "",
    "Type": "string",
    "Required": "0",
    "Multiple": "0",
    "Options": "",
    "Default": ""
  }
}
```

### Template expressions

In any text/value field, use:

| Expression | Meaning |
|---|---|
| `{=Document:FIELD}` | Current document field |
| `{=Variable:Name}` | BP variable |
| `{=Constant:Name}` | BP constant |
| `{=GlobalVar:Name}` | Global variable |
| `{=ActivityName:Field}` | Output of another activity |
| `{=Variable:Name > printable}` | Printable (human-readable) value |
| `{=Document:CREATED_BY_PRINTABLE}` | Printable version of system field |
| `{=Workflow:id}` | Current workflow instance ID |

---

## 15. Portal statistics (bitrix.dreamteamcompany.ru, 2026-04-01)

| Metric | Value |
|---|---|
| Total templates | 196 |
| CRM / Lists | 154 / 42 |
| Sequential / State machine | 171 / 25 |
| With PHP (`CodeActivity`) | 26 |
| With child BP launch | 29 |
| With webhook activity | 18 |
| With `WhileActivity` | 21 |
| With `ForEachActivity` | 15 |
| With `ParallelActivity` | 6 |
| Unique activity types | 63 |
| Leads templates | 84 |
| Deal templates | 27 |
| Smart-process templates | 40 (16 types) |
| Main author (user_id=9) | 147 templates |


---

## Дополнение (источник: `dreamteamcompany/local/handlers/universal-system/.cursor/skills/bitrix24-bizproc-activity-reference/SKILL.md`)

---
name: bitrix24-bizproc-activity-reference
description: Practical reference of all BizProc activity types with real Properties, values, and patterns from bitrix.dreamteamcompany.ru. Use when building, generating, or debugging Bitrix24 BizProc templates — especially when you need correct Properties format for any activity block. Covers conditions, CRM blocks, loops, communications, variables, orchestration, and more.
---

# BizProc Activity Reference

Practical reference built from 196 real templates exported from `bitrix.dreamteamcompany.ru`.
Source data: `docs/bizproc_rest_audit/bitrix_dreamteamcompany_ru_2026-04-01_templates_full.json`

Use this when generating BPT files, writing PHP blocks that interact with BP, or debugging existing templates.

---

## 1. Workflow roots

### `SequentialWorkflowActivity`

Root for linear workflows (171 of 196 templates).

```json
{
  "Type": "SequentialWorkflowActivity",
  "Properties": {
    "Title": "Последовательный бизнес-процесс",
    "Permission": []
  }
}
```

### `StateMachineWorkflowActivity`

Root for state-machine workflows (25 templates). States are `StateActivity` children.

```json
{
  "Type": "StateMachineWorkflowActivity",
  "Properties": {
    "Title": "Бизнес-процесс со статусами",
    "InitialStateName": "A69311_57041_84049_53268",
    "Permission": []
  }
}
```

### `StateActivity`

One state inside a state machine. Contains `StateInitializationActivity` as first child.

```json
{
  "Type": "StateActivity",
  "Properties": {
    "Title": "Лиды вручную",
    "Permission": [],
    "PermissionMode": "3",
    "PermissionScope": "1"
  }
}
```

CRM entities: `Permission` must be `[]` (empty), `PermissionMode`/`PermissionScope` empty strings.

### `StateInitializationActivity`

Runs on entry into a state. Properties must have only `Title`.

```json
{
  "Type": "StateInitializationActivity",
  "Properties": { "Title": "Вход в статус" }
}
```

### `SetStateActivity`

Transition to another state by `TargetStateName`.

```json
{
  "Type": "SetStateActivity",
  "Properties": {
    "TargetStateName": "A35883_23582_98539_97658",
    "CancelCurrentState": "N",
    "Title": "Вакансия"
  }
}
```

### `SetStateTitleActivity`

Changes the displayed title of a state at runtime.

```json
{
  "Type": "SetStateTitleActivity",
  "Properties": {
    "TargetStateTitle": "Отчет принят",
    "Title": "Установить текст статуса"
  }
}
```

---

## 2. Conditions

### `IfElseActivity` + `IfElseBranchActivity`

Container + branches. The container itself has no logic — all conditions are on the branches.

**Condition by document field:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Да",
    "fieldcondition": [["PROPERTY_IS_APPROVED", "=", "YES"]]
  }
}
```

**Condition by BP variable:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Да",
    "propertyvariablecondition": [["Counter", ">", "0", "0"]]
  }
}
```

Format: `[field, operator, value, joiner]`. Joiner: `"0"` = AND.

**Mixed condition (field + variable):**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Комбо",
    "mixedcondition": [["STATUS_ID", "=", "NEW", "0"]]
  }
}
```

**Else (always true) branch:**

```json
{
  "Type": "IfElseBranchActivity",
  "Properties": {
    "Title": "Нет",
    "truecondition": "1"
  }
}
```

Operators observed: `=`, `!=`, `>`, `<`, `>=`, `<=`, `in`, `!in`, `contain`, `!contain`, `empty`, `!empty`.

---

## 3. Loops and iterators

### `WhileActivity`

Loop while condition is true. Condition format is same as `IfElseBranchActivity`.

**By variable (counter loop):**

```json
{
  "Type": "WhileActivity",
  "Properties": {
    "Title": "Цикл (24 прогона)",
    "propertyvariablecondition": [["Counter", ">", "0", "0"]]
  }
}
```

Typical pattern: `SetVariableActivity` (init counter) → `WhileActivity` → body → `SetVariableActivity` (decrement).

**By document field:**

```json
{
  "Type": "WhileActivity",
  "Properties": {
    "Title": "Цикл согласования",
    "fieldcondition": [["PROPERTY_IS_APPROVED", "=", "IN_PROGRESS"]]
  }
}
```

### `ForEachActivity`

Iterates over a list stored in a BP variable.

```json
{
  "Type": "ForEachActivity",
  "Properties": {
    "Variable": "List_ID_Court_Sessions",
    "Object": "Variable",
    "Title": "Итератор (перебор суд. заседаний)"
  }
}
```

- `Object` is always `"Variable"` on this portal.
- `Variable` is the BP variable name holding the array.
- The current element is available inside the loop body as `{=ForEachActivity:CurrentElement}` (check by template context).

### `ParallelActivity`

Runs child `SequenceActivity` branches in parallel.

```json
{
  "Type": "ParallelActivity",
  "Properties": { "Title": "Параллельное выполнение" }
}
```

---

## 4. Variables and logging

### `SetVariableActivity`

Write one or more BP variables.

```json
{
  "Type": "SetVariableActivity",
  "Properties": {
    "VariableValue": {
      "username": "{=Document:CREATED_BY}",
      "Counter": "24"
    },
    "Title": "Изменение переменных"
  }
}
```

Values support template expressions: `{=Document:FIELD}`, `{=Variable:Name}`, `{=Activity:Field}`, `{=Constant:Name}`, `{=GlobalVar:Name}`.

### `SetGlobalVariableActivity`

Write to global (portal-level) variables.

```json
{
  "Type": "SetGlobalVariableActivity",
  "Properties": {
    "GlobalVariableValue": {
      "{=GlobalVar:Variable1749129301199}": []
    },
    "Title": "Изменение глобальных переменных"
  }
}
```

### `LogActivity`

Write to BP tracking log. `SetVariable` = `"0"` means log-only.

```json
{
  "Type": "LogActivity",
  "Properties": {
    "Text": "Поиск начальника для {=Variable:Approver_printable}",
    "SetVariable": "0",
    "Title": "Запись в отчет"
  }
}
```

---

## 5. Document fields

### `SetFieldActivity`

Update document fields. NOT `ModifyDocumentActivity`.

```json
{
  "Type": "SetFieldActivity",
  "Properties": {
    "FieldValue": {
      "SOURCE_ID": "CALL",
      "STATUS_ID": "UC_I0XLWE"
    },
    "ModifiedBy": [],
    "MergeMultipleFields": "N",
    "Title": "Изменение документа"
  }
}
```

`ModifiedBy`: empty array or `["user_1"]`. `MergeMultipleFields`: `"N"` = overwrite, `"Y"` = merge.

### `SetPermissionsActivity`

```json
{
  "Type": "SetPermissionsActivity",
  "Properties": {
    "Permission": { "R": ["{=Variable:username}"] },
    "Rewrite": "",
    "SetMode": "1",
    "SetScope": "1",
    "Title": "Установка прав"
  }
}
```

---

## 6. Lists (iblock) read/write

### `GetListsDocumentActivity`

Read fields from a list element.

```json
{
  "Type": "GetListsDocumentActivity",
  "Properties": {
    "DocumentType": ["lists", "BizprocDocument", "iblock_37"],
    "ElementId": "{=Document:UF_CRM_16_1749464934}",
    "Fields": ["PROPERTY_OTVETSTVENNYY"],
    "FieldsMap": {
      "PROPERTY_OTVETSTVENNYY": {
        "Id": "", "Type": "user", "Name": "Ответственный"
      }
    },
    "Title": "Получить информацию об элементе списка"
  }
}
```

Result fields accessible as `{=ActivityName:PROPERTY_OTVETSTVENNYY}`.

### Portal pattern: BP 776 lead buffer enrichment

Working pattern observed in exported `BP 776` on `bitrix.dreamteamcompany.ru`:

1. `CodeActivity` finds matching element in list `54` by domain and writes only `id_54`
2. `GetListsDocumentActivity` for `iblock_54` reads:
   - `PROPERTY_PROPERTY_191` (city, linked element from list `22`)
   - `PROPERTY_PROPERTY_192` (source, linked element from list `19`)
   - `PROPERTY_PROPERTY_195` (observers)
3. `GetListsDocumentActivity` for `iblock_19` with `ElementId = {=List54Activity:PROPERTY_PROPERTY_192}` reads:
   - `PROPERTY_PROPERTY_73` (box CRM source code)
4. `GetListsDocumentActivity` for `iblock_22` can read derived city data such as reserve employee when needed
5. `SetFieldActivity` writes list buffer fields from the activity outputs, not from guessed variables

Important field mapping from the working template:

- Buffer list field `PROPERTY_ISTOCHNIK` is written from `{=List54Activity:PROPERTY_PROPERTY_192}`
- CRM lead field `SOURCE_ID` is written from `{=List19Activity:PROPERTY_PROPERTY_73}`
- Buffer list field `PROPERTY_GOROD` is written from `{=List54Activity:PROPERTY_PROPERTY_191}`
- `PROPERTY_ASSIGNED_BY_ID` may come from a city lookup activity, not from the incoming payload
- `PROPERTY_OBSERVER_IDS` is written as a multiple user field from list `54`

Common failure mode:

- Using one variable like `CB_SOURCE_ID` for both the buffer list source field and CRM lead `SOURCE_ID`
- Writing the buffer list field from raw webhook `SOURCE_ID`
- Result: source is erased or written to the wrong field

Rule:

- Separate "linked element id for the list field" from "CRM code for lead SOURCE_ID"
- When debugging a portal BP, trust the exported working template aliases and activity outputs more than inferred generator field names

### `UpdateListsDocumentActivity`

```json
{
  "Type": "UpdateListsDocumentActivity",
  "Properties": {
    "Fields": {
      "PROPERTY_PROPERTY_221": "{=SomeActivity:PROPERTY_PROPERTY_206}",
      "PROPERTY_PROPERTY_206": "0"
    },
    "DocumentType": ["lists", "Bitrix\\Lists\\BizprocDocumentLists", "iblock_16"],
    "ElementId": "{=Variable:Counter_4}",
    "Title": "Изменить элемент списка"
  }
}
```

### `CreateListsDocumentActivity`

```json
{
  "Type": "CreateListsDocumentActivity",
  "Properties": {
    "Fields": {
      "CREATED_BY": "{=Document:ASSIGNED_BY_ID}",
      "IBLOCK_ID": "46",
      "NAME": "{=Variable:New_Vacancy}"
    },
    "DocumentType": ["lists", "BizprocDocument", "iblock_46"],
    "Title": "Новый элемент у/с"
  }
}
```

---

## 7. CRM activities

### `CrmGetDynamicInfoActivity`

Read smart-process (dynamic entity) items.

```json
{
  "Type": "CrmGetDynamicInfoActivity",
  "Properties": {
    "DynamicTypeId": "1056",
    "ReturnFields": ["SOURCE_ID", "STAGE_ID"],
    "OnlyDynamicEntities": "N",
    "DynamicFilterFields": {
      "items": [[
        {"object": "Document", "field": "ID", "operator": "=", "value": "{=Document:ID}"},
        "AND"
      ]]
    },
    "DynamicEntityFields": {
      "SOURCE_ID": { "Name": "Источник", "Type": "select" }
    },
    "Title": "Получить информацию об элементе CRM"
  }
}
```

### `CrmUpdateDynamicActivity`

```json
{
  "Type": "CrmUpdateDynamicActivity",
  "Properties": {
    "DynamicTypeId": "1056",
    "DynamicId": "",
    "DynamicFilterFields": {
      "items": [[
        {"object": "Document", "field": "ID", "operator": "=", "value": "{=SomeActivity:ID}"},
        "AND"
      ]]
    },
    "DynamicEntitiesFields": {
      "STAGE_ID": "DT1056_10:CLIENT",
      "UF_CRM_7_1748244889": "{=Document:UF_CRM_12_1749042549}"
    },
    "Title": "Изменить элемент смарт-процесса"
  }
}
```

### `CrmCreateDynamicActivity`

```json
{
  "Type": "CrmCreateDynamicActivity",
  "Properties": {
    "DynamicTypeId": "1096",
    "OnlyDynamicEntities": "Y",
    "DynamicEntitiesFields": {
      "1096_TITLE": "{=Variable:Vacancy_For_Name > printable}",
      "1096_ASSIGNED_BY_ID": "{=SomeActivity:PROPERTY_OTVETSTVENNYY}",
      "1096_OPENED": "Y"
    },
    "Title": "Создать элемент смарт-процесса"
  }
}
```

### `CrmGetDataEntityActivity`

Read a CRM entity (lead, deal, contact, etc.) by ID.

```json
{
  "Type": "CrmGetDataEntityActivity",
  "Properties": {
    "DocumentType": ["crm", "CCrmDocumentDeal", "DEAL"],
    "EntityId": "{=Document:ID}",
    "EntityType": "DEAL",
    "PrintableVersion": "Y",
    "EntityFields": { "SOURCE_ID": { "Name": "Источник", "Type": "select" } },
    "Title": "Выбор данных CRM"
  }
}
```

### `CrmChangeResponsibleActivity`

```json
{
  "Type": "CrmChangeResponsibleActivity",
  "Properties": {
    "Responsible": ["{=SomeActivity:GetUser}"],
    "ModifiedBy": ["{=Document:ASSIGNED_BY_ID}"],
    "GetterType": "f",
    "SkipAbsent": "N",
    "SkipTimeMan": "N",
    "Title": "Изменить ответственного"
  }
}
```

### `CrmChangeStatusActivity`

```json
{
  "Type": "CrmChangeStatusActivity",
  "Properties": {
    "TargetStatus": "UC_I0XLWE",
    "ModifiedBy": [],
    "Title": "Смена статуса"
  }
}
```

### `CrmSetObserverField`

```json
{
  "Type": "CrmSetObserverField",
  "Properties": {
    "ActionOnObservers": "add",
    "Observers": ["group_hr274"],
    "Title": "Изменить наблюдателей"
  }
}
```

`ActionOnObservers`: `"add"` or `"remove"`.

### `CrmChangeDealCategoryActivity`

```json
{
  "Type": "CrmChangeDealCategoryActivity",
  "Properties": {
    "CategoryId": "1",
    "StageId": "C1:NEW",
    "Title": "Сменить воронку"
  }
}
```

### `CrmConvertDocumentActivity`

Convert a lead to deal+contact.

```json
{
  "Type": "CrmConvertDocumentActivity",
  "Properties": {
    "Responsible": ["{=Document:ASSIGNED_BY_ID}"],
    "Items": ["DEAL", "CONTACT"],
    "DealCategoryId": "1",
    "DisableActivityCompletion": "Y",
    "Title": "Создать на основании"
  }
}
```

### `CrmTimelineCommentAdd`

```json
{
  "Type": "CrmTimelineCommentAdd",
  "Properties": {
    "CommentText": "Отправлено СМС-сообщение.",
    "CommentUser": ["{=Document:ASSIGNED_BY_ID}"],
    "Title": "Добавить комментарий в таймлайн"
  }
}
```

### `CrmGenerateEntityDocumentActivity`

Generate a document from CRM template.

```json
{
  "Type": "CrmGenerateEntityDocumentActivity",
  "Properties": {
    "TemplateId": "22",
    "UseSubscription": "Y",
    "EnablePublicUrl": "N",
    "CreateActivity": "N",
    "WithStamps": "N",
    "Values": { "DocumentTitle": "Приказ №{=Variable:Counter}" },
    "MyCompanyId": "",
    "MyCompanyRequisiteId": "",
    "MyCompanyBankDetailId": "",
    "Title": "Создать документ"
  }
}
```

### `CrmGetRelationsInfoActivity`

Read parent CRM entity relations.

```json
{
  "Type": "CrmGetRelationsInfoActivity",
  "Properties": {
    "ParentTypeId": "1056",
    "ParentEntityFields": {
      "CRM_ID": { "Name": "ID элемента CRM", "Type": "string" }
    },
    "Title": "Обращение к Делу"
  }
}
```

### `CrmSendSmsActivity`

```json
{
  "Type": "CrmSendSmsActivity",
  "Properties": {
    "MessageText": "Вы записаны к стоматологу...",
    "ProviderId": "app.58c8d87066a529.45537578|sms.ru.1@rest",
    "RecipientType": "entity",
    "RecipientUser": ["user_9"],
    "PhoneType": "",
    "Title": "Отправить СМС"
  }
}
```

---

## 8. Communications

### `IMNotifyActivity`

IM notification to a user.

```json
{
  "Type": "IMNotifyActivity",
  "Properties": {
    "MessageSite": "Текст уведомления с {=Document:URL_BB}",
    "MessageOut": "",
    "MessageType": "2",
    "MessageUserFrom": ["user_1"],
    "MessageUserTo": ["user_9"],
    "Title": "Уведомление Медведева"
  }
}
```

`MessageType`: `"2"` = notification.

### `MailActivity`

```json
{
  "Type": "MailActivity",
  "Properties": {
    "MailSubject": "Необходимо утвердить документ \"{=Document:NAME}\"",
    "MailText": "Вы должны утвердить или отклонить документ...",
    "MailMessageType": "plain",
    "MailCharset": "UTF-8",
    "MailUserFrom": "",
    "MailUserFromArray": ["user_1"],
    "MailUserTo": "",
    "MailUserToArray": ["Template", "Voters1"],
    "Title": "Почтовое сообщение"
  }
}
```

### `ImAddMessageToGroupChatActivity`

```json
{
  "Type": "ImAddMessageToGroupChatActivity",
  "Properties": {
    "ChatId": "69697",
    "FromMember": "user_9",
    "MessageTemplate": "plain",
    "MessageFields": {
      "MessageText": "Добавлена новая интеграция {=Document:ID}"
    },
    "Title": "Отправить сообщение в групповой чат"
  }
}
```

### `WebHookActivity`

Outgoing HTTP call.

```json
{
  "Type": "WebHookActivity",
  "Properties": {
    "Handler": "https://bitrix.dreamteamcompany.ru/local/handlers/test/sync_comments_by_lead.php?box_lead_id={=Document:ID}",
    "Title": "Исходящий Вебхук"
  }
}
```

---

## 9. Users, tasks, approvals

### `GetUserActivity`

Pick a user by rule (random from group, boss, etc.).

```json
{
  "Type": "GetUserActivity",
  "Properties": {
    "UserType": "random",
    "MaxLevel": "1",
    "UserParameter": ["group_hr38"],
    "ReserveUserParameter": ["user_53"],
    "SkipAbsent": "Y",
    "SkipAbsentReserve": "Y",
    "SkipTimeman": "Y",
    "SkipTimemanReserve": "N",
    "Title": "Выбор сотрудника СПб КЦ"
  }
}
```

`UserType`: `"random"`, `"boss"`, `"queue"`.

### `GetUserInfoActivity`

Read user profile fields.

```json
{
  "Type": "GetUserInfoActivity",
  "Properties": {
    "GetUser": ["{=Document:CREATED_BY}"],
    "UserFields": {
      "USER_ACTIVE": { "Name": "Активен", "Type": "bool" },
      "USER_LAST_NAME": { "Name": "Фамилия", "Type": "string" },
      "USER_PERSONAL_MOBILE": { "Name": "Мобильный телефон", "Type": "string" }
    },
    "Title": "Получить информацию о сотруднике"
  }
}
```

### `ApproveActivity`

Approval task.

```json
{
  "Type": "ApproveActivity",
  "Properties": {
    "ApproveType": "any",
    "OverdueDate": "",
    "ApproveMinPercent": "50",
    "ApproveWaitForAll": "N",
    "Name": "Запрашивает выдачу наличных",
    "Description": "Вам необходимо утвердить заявку...",
    "Parameters": "",
    "StatusMessage": "Утверждение заявки руководителем",
    "SetStatusMessage": "Y",
    "TaskButton1Message": "Утвердить заявку",
    "TaskButton2Message": "Отклонить заявку",
    "CommentLabelMessage": "Комментарий",
    "ShowComment": "N",
    "Users": ["{=Variable:Approver}"],
    "Title": "Утверждение"
  }
}
```

`ApproveType`: `"any"` (first response wins), `"all"` (all must approve).

### `RequestInformationActivity`

Request structured input from a user.

```json
{
  "Type": "RequestInformationActivity",
  "Properties": {
    "Users": ["{=Document:CREATED_BY}"],
    "Name": "Отчет о полученных наличных",
    "Description": "Укажите товары или услуги...",
    "RequestedInformation": [
      {
        "Title": "Отчет", "Name": "Report",
        "Type": "text", "Required": "1", "Multiple": "0"
      }
    ],
    "TaskButtonMessage": "Отправить",
    "ShowComment": "N",
    "StatusMessage": "Ожидание отчета",
    "SetStatusMessage": "Y",
    "Title": "Отчет о полученных наличных"
  }
}
```

### `Task2Activity`

Create a Bitrix task.

```json
{
  "Type": "Task2Activity",
  "Properties": {
    "Fields": {
      "TITLE": "До продажа по сделке: {=Document:TITLE}",
      "CREATED_BY": "user_1",
      "RESPONSIBLE_ID": ["Document", "ASSIGNED_BY_ID"],
      "DESCRIPTION": "Произвести до продажу..."
    },
    "HoldToClose": "0",
    "AUTO_LINK_TO_CRM_ENTITY": "N",
    "Title": "До продажа"
  }
}
```

---

## 10. Delays

### `DelayActivity`

**By absolute time:**

```json
{
  "Type": "DelayActivity",
  "Properties": {
    "TimeoutTime": "=dateadd({=Document:PROPERTY_FROM}, \"-5d\")",
    "WriteToLog": "Y",
    "Title": "Пауза до даты"
  }
}
```

**By duration:**

```json
{
  "Type": "DelayActivity",
  "Properties": {
    "TimeoutDuration": "12",
    "TimeoutDurationType": "h",
    "WriteToLog": "Y",
    "Title": "Пауза 12 часов"
  }
}
```

`TimeoutDurationType`: `"s"` seconds, `"m"` minutes, `"h"` hours, `"d"` days.

### `RobotDelayActivity`

Same as DelayActivity but in robot context. Has `WaitWorkDayUser`.

```json
{
  "Type": "RobotDelayActivity",
  "Properties": {
    "TimeoutTime": "{=Variable:Variable1}",
    "TimeoutTimeIsLocal": "N",
    "WriteToLog": "Y",
    "WaitWorkDayUser": [],
    "Title": "Пауза робота"
  }
}
```

---

## 11. Orchestration

### `StartWorkflowActivity`

Launch a child BP by template ID.

```json
{
  "Type": "StartWorkflowActivity",
  "Properties": {
    "DocumentId": "{=SomeActivity:ID}",
    "TemplateId": "95",
    "UseSubscription": "Y",
    "TemplateParameters": [],
    "Title": "Запуск дочернего БП"
  }
}
```

`UseSubscription`: `"Y"` = wait for child to finish before continuing.

### `TerminateActivity`

Forcefully stop the BP.

```json
{
  "Type": "TerminateActivity",
  "Properties": {
    "Title": "Прерывание процесса"
  }
}
```

---

## 12. Custom PHP code

### `CodeActivity`

```json
{
  "Type": "CodeActivity",
  "Properties": {
    "ExecuteCode": "$rootActivity = $this->GetRootActivity();\n$rootActivity->SetVariable('task_start', GetTime(time() + 60*60*24*30*12, 'SHORT'));",
    "Title": "PHP код"
  }
}
```

See skill `bitrix-bizproc-php-blocks` for detailed PHP block rules.

---

## 13. Other / rare activities

### `EmptyBlockActivity`

Visual container for grouping, no logic.

```json
{
  "Type": "EmptyBlockActivity",
  "Properties": { "Title": "НОЧНЫЕ v.3" }
}
```

### `SequenceActivity`

Sequential container inside other blocks (required by `ForEach`, `While`, `Parallel`, `EmptyBlock`).

```json
{
  "Type": "SequenceActivity",
  "Properties": { "Title": "Последовательность действий" }
}
```

### `PublishDocumentActivity`

Publish a document (lists).

```json
{
  "Type": "PublishDocumentActivity",
  "Properties": { "Title": "Опубликовать документ" }
}
```

### `SocNetMessageActivity`

Post to social network / live feed.

### `AbsenceActivity` / `Calendar2Activity`

HR/calendar events — rarely used.

### `rest_<hash>` activities

Custom activities registered by REST apps. Properties match the app's schema. On this portal:

- `rest_6515462f0a6260c5f68e82ba60f8c4f5` — in 15 templates (WhatsApp/messaging)
- `rest_6e9049d7fbafe1eeb17cc7a385cc8469` — in 3 templates (Telegram)
- `rest_4a64fafd0c72964fd1574767c63c8b12` — in 2 templates

---

## 14. Template-level: Variables, Constants, Parameters

### Variable definition

```json
{
  "Counter": {
    "Name": "Счетчик",
    "Description": "",
    "Type": "int",
    "Required": "0",
    "Multiple": "0",
    "Options": "",
    "Default": "90"
  }
}
```

Common types: `string`, `int`, `double`, `bool`, `datetime`, `date`, `user`, `file`, `text`, `select`, `UF:crm`, `UF:iblock_element`, `UF:iblock_section`, `UF:date`, `UF:crm_status`, `E:EList`, `S:employee`.

### Constant definition

```json
{
  "Manager": {
    "Name": "Кто утверждает",
    "Description": "директор или заместитель",
    "Type": "user",
    "Required": "1",
    "Multiple": "1",
    "Default": ""
  }
}
```

### Parameter definition (passed when BP is launched externally)

```json
{
  "Lead_Name": {
    "Name": "Название Лида",
    "Description": "",
    "Type": "string",
    "Required": "0",
    "Multiple": "0",
    "Options": "",
    "Default": ""
  }
}
```

### Template expressions

In any text/value field, use:

| Expression | Meaning |
|---|---|
| `{=Document:FIELD}` | Current document field |
| `{=Variable:Name}` | BP variable |
| `{=Constant:Name}` | BP constant |
| `{=GlobalVar:Name}` | Global variable |
| `{=ActivityName:Field}` | Output of another activity |
| `{=Variable:Name > printable}` | Printable (human-readable) value |
| `{=Document:CREATED_BY_PRINTABLE}` | Printable version of system field |
| `{=Workflow:id}` | Current workflow instance ID |

---

## 15. Portal statistics (bitrix.dreamteamcompany.ru, 2026-04-01)

| Metric | Value |
|---|---|
| Total templates | 196 |
| CRM / Lists | 154 / 42 |
| Sequential / State machine | 171 / 25 |
| With PHP (`CodeActivity`) | 26 |
| With child BP launch | 29 |
| With webhook activity | 18 |
| With `WhileActivity` | 21 |
| With `ForEachActivity` | 15 |
| With `ParallelActivity` | 6 |
| Unique activity types | 63 |
| Leads templates | 84 |
| Deal templates | 27 |
| Smart-process templates | 40 (16 types) |
| Main author (user_id=9) | 147 templates |
