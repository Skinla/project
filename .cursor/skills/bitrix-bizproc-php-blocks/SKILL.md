---
name: bitrix-bizproc-php-blocks
description: Bitrix BizProc (business process) custom PHP code block cheat-sheet: GetVariable/SetVariable, type casting, module loading, tracking logs, common intranet/iblock patterns, workflow initiator (STARTED_BY), and smart-process (dynamic) D7 access from BP. Use when the user mentions БП, BizProc, "Выполнение произвольного PHP кода", GetVariable/SetVariable, workflowInstanceId, STARTED_BY, or tracking logs.
---

# Bitrix BizProc PHP blocks (БП: “Произвольный PHP код”)

## Hard rules

- **Do not** add `<?php` in the BP PHP block.
- Always read/write BP variables via:
  - `$this->GetVariable('NAME')`
  - `$this->SetVariable('NAME', $value)`
- **Cast types explicitly**:
  - numeric from strings: `(int) preg_replace('/\D+/', '', $raw)`
  - if BP variable type is “string”, write `(string)$value`
- **Fail fast** on missing modules / invalid inputs.

## Logging / debugging

- Preferred: `$this->WriteToTrackingService('msg ' . print_r($data, true));`

## Module loading (common)

```php
use Bitrix\Main\Loader;

if (!Loader::includeModule('iblock')) {
    $this->WriteToTrackingService('iblock module not available');
    return;
}
```

### Intranet: company structure iblock id

```php
$structureIblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
```

## Example: department head “climb up” (intranet + iblock sections)

- Iterate `CIBlockSection` by `ID`, climb to `IBLOCK_SECTION_ID` until `UF_HEAD` found.
- Save to BP variable as string (or empty string).

## Workflow initiator (STARTED_BY) — reliable pattern

Observed in practice:
- `GetWorkflowInstanceId()` returns an instance id like `699713f0d635d6.69564704`.
- `WorkflowInstanceTable::getById(...)` may return `false`.
- `WorkflowInstanceTable::getList()` with wrong fields may throw `Unknown field definition ...`.
- **Most reliable** for `STARTED_BY`: `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

Safe version:

```php
$workflowInstanceId = (string)$this->GetWorkflowInstanceId();
$row = \Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)->fetch();
$startedBy = is_array($row) ? (int)($row['STARTED_BY'] ?? 0) : 0;
$this->SetVariable('BpInitiatorId', $startedBy > 0 ? ('user_' . $startedBy) : '');
```

Notes:
- If BP variable type is “user”, it often expects `user_<id>` (not just `123`).

## Smart-process (dynamic) access from BP (D7)

- Load module: `Loader::includeModule('crm')`
- Get factory: `\Bitrix\Crm\Service\Container::getInstance()->getFactory(<entityTypeId>)`
- For “completed in current month” checks:
  - use `MOVED_TIME` (not `STAGE_CHANGED_TIME`)
  - stage ids often contain `...:SUCCESS` (use substring check)

## Common mistakes (seen)

- Using `\CBPHelper::WriteToTrackingService()` (doesn’t exist).
- Calling `WriteToTrackingService()` without `$this->`.
- Not matching BP variable type on output.
- Not cleaning/casting inputs.


---

## Дополнение (источник: `dreamteamcompany/local/handlers/dozvon/.cursor/skills/bitrix-bizproc-php-blocks/SKILL.md`)

---
name: bitrix-bizproc-php-blocks
description: Bitrix BizProc (business process) custom PHP code block cheat-sheet: GetVariable/SetVariable, type casting, module loading, tracking logs, eval() environment pitfalls, closures, common intranet/iblock patterns, workflow initiator (STARTED_BY), and smart-process (dynamic) D7 access from BP. Use when the user mentions БП, BizProc, "Выполнение произвольного PHP кода", GetVariable/SetVariable, workflowInstanceId, STARTED_BY, tracking logs, or CodeActivity.
---

# Bitrix BizProc PHP blocks (БП: "Произвольный PHP код")

## Hard rules

- **Do not** add `<?php` in the BP PHP block.
- **Do not** use `declare(strict_types=1)` — BP executes code via `eval()`, strict types cause fatal errors.
- **Do not** use external PHP files (`require`, `include`) — BP blocks must be self-contained. All logic lives in the block itself.
- Always read/write BP variables via:
  - `$this->GetVariable('NAME')`
  - `$this->SetVariable('NAME', $value)`
- **Cast types explicitly**:
  - numeric from strings: `(int) preg_replace('/\D+/', '', $raw)`
  - if BP variable type is "string", write `(string)$value`
- **Fail fast** on missing modules / invalid inputs.
- Prefer **standard BP blocks** (Условие, Изменение документа, Переменная, etc.) over PHP. Use PHP only for what standard blocks cannot do: `CCrmFieldMulti`, `CIBlockElement::GetList`, `CTimeManUser`, `StatisticTable`, `curl`, date/time calculations.

## Closures in eval() context

BP PHP blocks run inside `eval()`. Access `$this` and root activity via closures:

```php
$rootActivity = $this->GetRootActivity();
$setVar = function ($name, $value) use ($rootActivity) {
    if (method_exists($rootActivity, 'SetVariable')) {
        $rootActivity->SetVariable($name, (string)$value);
    }
};
$getVar = function ($name) use ($rootActivity) {
    if (method_exists($rootActivity, 'GetVariable')) {
        return $rootActivity->GetVariable($name);
    }
    return null;
};
$log = function ($msg) {
    $this->WriteToTrackingService((string)$msg);
};
```

**Do not** add type hints to closure parameters — some Bitrix versions fail on typed closures in `eval()`.

## Logging / debugging

- Preferred: `$this->WriteToTrackingService('msg ' . print_r($data, true));`
- Use step markers like `[1/4]`, `[2/4]` for multi-step blocks.
- Wrap entire block in `try/catch (\Throwable $e)` — unhandled exceptions silently freeze the BP.

## Module loading (common)

```php
use Bitrix\Main\Loader;

if (!Loader::includeModule('iblock')) {
    $this->WriteToTrackingService('iblock module not available');
    return;
}
```

### Intranet: company structure iblock id

```php
$structureIblockId = (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
```

## Block design: inputs/outputs via BP variables

Each PHP block should have clearly documented inputs/outputs:
- **Reads**: which BP variables the block expects.
- **Writes**: which BP variables the block sets as result.
- Result variables follow pattern: `CB_*_RESULT` (passed/skipped/error/found/not_found), `CB_*_MESSAGE` (human text).
- Standard BP blocks (IfElse) then branch on these result variables.

Example header:
```
Читает: CB_CITY_ID
Записывает: CB_SIP_LINE, CB_SIP_PASSWORD, CB_RULE_ID, CB_TIMEZONE, CB_WORKTIME,
            CB_CITY_RESULT (passed/error), CB_CITY_MESSAGE
```

## Example: department head "climb up" (intranet + iblock sections)

- Iterate `CIBlockSection` by `ID`, climb to `IBLOCK_SECTION_ID` until `UF_HEAD` found.
- Save to BP variable as string (or empty string).

## Workflow initiator (STARTED_BY) — reliable pattern

Observed in practice:
- `GetWorkflowInstanceId()` returns an instance id like `699713f0d635d6.69564704`.
- `WorkflowInstanceTable::getById(...)` may return `false`.
- `WorkflowInstanceTable::getList()` with wrong fields may throw `Unknown field definition ...`.
- **Most reliable** for `STARTED_BY`: `\Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)`.

Safe version:

```php
$workflowInstanceId = (string)$this->GetWorkflowInstanceId();
$row = \Bitrix\Bizproc\Workflow\Entity\WorkflowStateTable::getById($workflowInstanceId)->fetch();
$startedBy = is_array($row) ? (int)($row['STARTED_BY'] ?? 0) : 0;
$this->SetVariable('BpInitiatorId', $startedBy > 0 ? ('user_' . $startedBy) : '');
```

Notes:
- If BP variable type is "user", it often expects `user_<id>` (not just `123`).

## Smart-process (dynamic) access from BP (D7)

- Load module: `Loader::includeModule('crm')`
- Get factory: `\Bitrix\Crm\Service\Container::getInstance()->getFactory(<entityTypeId>)`
- For "completed in current month" checks:
  - use `MOVED_TIME` (not `STAGE_CHANGED_TIME`)
  - stage ids often contain `...:SUCCESS` (use substring check)

## Custom timer (sleep) in BP

Bitrix standard "Пауза" block is unreliable. Use a PHP block with `sleep()`:

```php
$sec = (int)preg_replace('/\D+/', '', (string)$this->GetVariable('CB_SLEEP_SEC'));
if ($sec < 1) $sec = 10;
if ($sec > 120) $sec = 120;
sleep($sec);
$this->SetVariable('CB_SLEEP_DONE', 'Y');
```

## Common mistakes (seen)

- Using `declare(strict_types=1)` — causes fatal in `eval()`.
- Using `\CBPHelper::WriteToTrackingService()` (doesn't exist).
- Calling `WriteToTrackingService()` without `$this->`.
- Not matching BP variable type on output.
- Not cleaning/casting inputs.
- Adding type hints to closures — fails in some Bitrix eval() environments.
- Referencing external PHP files with `require/include` — file paths break between environments.
- Not wrapping code in `try/catch` — unhandled exception freezes BP silently.
