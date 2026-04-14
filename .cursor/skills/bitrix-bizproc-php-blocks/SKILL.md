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

