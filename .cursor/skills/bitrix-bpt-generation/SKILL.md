---
name: bitrix-bpt-generation
description: Generate Bitrix BPT (business process template) files from Python. Covers PHP serialization format, zlib compression, activity types, DOCUMENT_FIELDS, Permission format, common pitfalls. Use when the user mentions BPT, .bpt, генерация шаблона БП, generate_bpt, импорт шаблона, or export/import бизнес-процесса.
---

# Bitrix BPT file generation (Python)

## File format

A `.bpt` file is `zlib.compress(php_serialized_data, 9)`.

Decompressed content is a PHP serialized array with structure:
```
a:6:{
  s:7:"VERSION";i:2;
  s:8:"TEMPLATE";a:1:{i:0; <root_activity> }
  s:10:"PARAMETERS";a:0:{}
  s:9:"VARIABLES";a:N:{ ... }
  s:9:"CONSTANTS";a:0:{}
  s:15:"DOCUMENT_FIELDS";a:N:{ ... }
}
```

## Compression

- **Must use `zlib.compress(data, 9)`** — compression level 9 (header `78da`).
- Level 6 (header `789c`) may cause import errors on some Bitrix versions.

## PHP serialization helpers (Python)

```python
def php_s(value: str) -> str:
    b = value.encode('utf-8')
    return f's:{len(b)}:"{value}";'

def php_int(value: int) -> str:
    return f'i:{value};'

def php_null() -> str:
    return 'N;'

def php_array(items: list[tuple[str, str]]) -> str:
    parts = [f'a:{len(items)}:{{']
    for key, val in items:
        parts.append(key)
        parts.append(val)
    parts.append('}')
    return ''.join(parts)

def php_array_indexed(items: list[str]) -> str:
    pairs = [(f'i:{i};', val) for i, val in enumerate(items)]
    return php_array(pairs)
```

**Critical**: `php_s()` must count UTF-8 **bytes**, not characters. "Новый" is 10 bytes, not 5 chars.

## Activity structure

Every activity is `a:6:{Type, Name, Activated, Node, Properties, Children}`:

```python
def make_activity(act_type, name, activated, properties, children=None):
    parts = [
        (php_s('Type'), php_s(act_type)),
        (php_s('Name'), php_s(name)),
        (php_s('Activated'), php_s(activated)),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array(properties)),
        (php_s('Children'), php_array_indexed(children or [])),
    ]
    return php_array(parts)
```

Names must be unique: `A{random}_{random}_{random}_{random}` pattern.

## DOCUMENT_FIELDS

**Must match the target entity type.** Extract from an existing working BPT for the same entity:

```python
def extract_document_fields(bpt_path: str) -> str:
    with open(bpt_path, 'rb') as f:
        text = zlib.decompress(f.read()).decode('utf-8', errors='replace')
    marker = '"DOCUMENT_FIELDS"'
    df_idx = text.find(marker)
    val_start = df_idx + len(marker) + 1  # skip ;
    depth = 0; started = False
    for i in range(val_start, len(text)):
        if text[i] == '{': depth += 1; started = True
        elif text[i] == '}':
            depth -= 1
            if started and depth == 0:
                return text[val_start:i + 1]
```

**Fatal mistake**: using DOCUMENT_FIELDS from a Lists BPT for a CRM Leads BPT (different field sets = import error).

## Permission format: CRM vs Lists

### CRM entities (Leads, Deals, etc.)

Root and State `Permission` must be **empty**: `a:0:{}`

```python
(php_s('Permission'), php_array([]))       # a:0:{}
(php_s('PermissionMode'), php_s(''))       # empty string
(php_s('PermissionScope'), php_s(''))      # empty string
```

### Lists (IBlock)

Root and State `Permission` has 8 keys (D,E,R,S,T,U,W,X):

```python
(php_s('Permission'), perm8)               # a:8:{...}
(php_s('PermissionMode'), php_s('3'))
(php_s('PermissionScope'), php_s('1'))
```

**Using Lists-style Permission in a CRM BPT causes "Network error" on import.**

## StateInitializationActivity

Properties must have only `Title` (1 key, `a:1:{}`), no `EditorComment`:

```python
make_activity('StateInitializationActivity', name, 'Y',
    [(php_s('Title'), php_s('Вход в статус'))],
    children)
```

## Activity types — real names

| What you want | Correct type name | WRONG name (does not exist) |
|---|---|---|
| Изменение документа | `SetFieldActivity` | ~~ModifyDocumentActivity~~ |
| Условие | `IfElseActivity` + `IfElseBranchActivity` | — |
| PHP код | `CodeActivity` | — |
| Установить статус | `SetStateActivity` | — |
| Переменная | `SetVariableActivity` | — |
| Запись в отчёт | `LogActivity` | — |
| Получить инфо элемента списка | `GetListsDocumentActivity` | — |
| Математика | Нет отдельного Activity. Использовать выражения калькулятора в `SetVariableActivity` | ~~MathOperationActivity~~ |

## SetFieldActivity (Изменение документа)

```python
def make_set_field(name, title, field_values):
    items = [(php_s(k), php_s(v)) for k, v in field_values.items()]
    return make_activity('SetFieldActivity', name, 'Y', [
        (php_s('FieldValue'), php_array(items)),
        (php_s('ModifiedBy'), php_array_indexed([php_s('user_1')])),
        (php_s('MergeMultipleFields'), php_s('N')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])
```

Properties: `FieldValue` (not `Fields`), `ModifiedBy`, `MergeMultipleFields`.

## IfElseBranchActivity conditions

Document field condition:
```python
(php_s('fieldcondition'), php_array([
    (php_int(0), php_array_indexed([
        php_s('STATUS_ID'), php_s('='), php_s('NEW'), php_s('0')
    ]))
]))
```

Variable condition:
```python
(php_s('propertyvariablecondition'), php_array([
    (php_int(0), php_array_indexed([
        php_s('CB_RESULT'), php_s('='), php_s('passed'), php_s('0')
    ]))
]))
```

Else branch (always true):
```python
(php_s('truecondition'), php_s('1'))
```

## Validation checklist (before import)

1. Braces balanced: `text.count('{') == text.count('}')`
2. All `s:N:"..."` byte lengths correct
3. No `ModifyDocumentActivity` or `MathOperationActivity`
4. `DOCUMENT_FIELDS` matches target entity type
5. `Permission` format matches entity type (CRM = `a:0:{}`, Lists = `a:8:{}`)
6. zlib header is `78da` (level 9)
7. Round-trip: `zlib.decompress(zlib.compress(data, 9)) == data`

## GetListsDocumentActivity (Получить информацию об элементе списка)

Reads properties from a list (IBlock) element. Result fields accessible as `{=ActivityName:PROPERTY_CODE}`.

```python
def make_get_list_element(name, title, iblock_id, element_id_expr, fields_map):
    """
    fields_map: dict of {field_code: {Name, Type, ...}} 
    e.g. {'PROPERTY_PROPERTY_613': {'Name': 'sip обратный звонок', 'Type': 'string', ...}}
    """
    fields = [(php_int(i), php_s(code)) for i, code in enumerate(fields_map.keys())]
    fmap = []
    for code, meta in fields_map.items():
        props = [
            (php_s('Id'), php_s('')),
            (php_s('Type'), php_s(meta.get('Type', 'string'))),
            (php_s('Name'), php_s(meta['Name'])),
            (php_s('Description'), php_s('')),
            (php_s('Multiple'), php_s(meta.get('Multiple', '0'))),
            (php_s('Required'), php_s('0')),
            (php_s('Options'), php_s('')),
            (php_s('Settings'), php_array([])),
            (php_s('Default'), php_s('')),
        ]
        fmap.append((php_s(code), php_array(props)))
    
    return make_activity('GetListsDocumentActivity', name, 'Y', [
        (php_s('DocumentType'), php_array_indexed([
            php_s('lists'),
            php_s('Bitrix\\Lists\\BizprocDocumentLists'),
            php_s(f'iblock_{iblock_id}'),
        ])),
        (php_s('ElementId'), php_s(element_id_expr)),
        (php_s('Fields'), php_array(fields)),
        (php_s('FieldsMap'), php_array(fmap)),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])
```

DocumentType for lists: `['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_<ID>']`.

## Calculator expressions in BP parameters

Bitrix BP supports inline expressions in any parameter via `{{=expression}}` syntax.
No separate "MathActivity" — use expressions inside `SetVariableActivity` values.

Increment example:
```python
make_set_variable(name, 'Инкремент попытки', {
    'CB_ATTEMPT': '{{=intval({=Variable:CB_ATTEMPT})+1}}',
})
```

Available functions: `intval`, `floatval`, `abs`, `round`, `ceil`, `floor`, `min`, `max`,
`rand`, `substr`, `strlen`, `strpos`, `if`, `dateadd`, `datediff`, `date`, `trim`,
`strtolower`, `strtoupper`, `implode`, `explode`, `merge`, `firstvalue`, `shuffle`, `swirl`.

Operators: `+`, `-`, `*`, `/`, `=`, `<>`, `<`, `>`, `<=`, `>=`, `&` (concat), `^` (power), `%` (percent),
`and`, `or`, `not`, `true`, `false`.

## Debugging import errors

| Error | Cause |
|---|---|
| "Действие не найдено (X)" | Activity type `X` does not exist in Bitrix |
| "Network error" | Wrong Permission format, wrong DOCUMENT_FIELDS, or corrupted serialization |
| Silent import failure | String length mismatch in PHP serialization (UTF-8 byte vs char count) |


---

## Дополнение (источник: `dreamteamcompany/local/handlers/universal-system/.cursor/skills/bitrix-bpt-generation/SKILL.md`)

---
name: bitrix-bpt-generation
description: Generate Bitrix BPT (business process template) files from Python. Covers PHP serialization format, zlib compression, activity types, DOCUMENT_FIELDS, Permission format, common pitfalls. Use when the user mentions BPT, .bpt, генерация шаблона БП, generate_bpt, импорт шаблона, or export/import бизнес-процесса.
---

# Bitrix BPT file generation (Python)

## File format

A `.bpt` file is `zlib.compress(php_serialized_data, 9)`.

Decompressed content is a PHP serialized array with structure:
```
a:6:{
  s:7:"VERSION";i:2;
  s:8:"TEMPLATE";a:1:{i:0; <root_activity> }
  s:10:"PARAMETERS";a:0:{}
  s:9:"VARIABLES";a:N:{ ... }
  s:9:"CONSTANTS";a:0:{}
  s:15:"DOCUMENT_FIELDS";a:N:{ ... }
}
```

## Compression

- **Must use `zlib.compress(data, 9)`** — compression level 9 (header `78da`).
- Level 6 (header `789c`) may cause import errors on some Bitrix versions.

## PHP serialization helpers (Python)

```python
def php_s(value: str) -> str:
    b = value.encode('utf-8')
    return f's:{len(b)}:"{value}";'

def php_int(value: int) -> str:
    return f'i:{value};'

def php_null() -> str:
    return 'N;'

def php_array(items: list[tuple[str, str]]) -> str:
    parts = [f'a:{len(items)}:{{']
    for key, val in items:
        parts.append(key)
        parts.append(val)
    parts.append('}')
    return ''.join(parts)

def php_array_indexed(items: list[str]) -> str:
    pairs = [(f'i:{i};', val) for i, val in enumerate(items)]
    return php_array(pairs)
```

**Critical**: `php_s()` must count UTF-8 **bytes**, not characters. "Новый" is 10 bytes, not 5 chars.

## Activity structure

Every activity is `a:6:{Type, Name, Activated, Node, Properties, Children}`:

```python
def make_activity(act_type, name, activated, properties, children=None):
    parts = [
        (php_s('Type'), php_s(act_type)),
        (php_s('Name'), php_s(name)),
        (php_s('Activated'), php_s(activated)),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array(properties)),
        (php_s('Children'), php_array_indexed(children or [])),
    ]
    return php_array(parts)
```

Names must be unique: `A{random}_{random}_{random}_{random}` pattern.

## DOCUMENT_FIELDS

**Must match the target entity type.** Extract from an existing working BPT for the same entity:

```python
def extract_document_fields(bpt_path: str) -> str:
    with open(bpt_path, 'rb') as f:
        text = zlib.decompress(f.read()).decode('utf-8', errors='replace')
    marker = '"DOCUMENT_FIELDS"'
    df_idx = text.find(marker)
    val_start = df_idx + len(marker) + 1  # skip ;
    depth = 0; started = False
    for i in range(val_start, len(text)):
        if text[i] == '{': depth += 1; started = True
        elif text[i] == '}':
            depth -= 1
            if started and depth == 0:
                return text[val_start:i + 1]
```

**Fatal mistake**: using DOCUMENT_FIELDS from a Lists BPT for a CRM Leads BPT (different field sets = import error).

## Permission format: CRM vs Lists

### CRM entities (Leads, Deals, etc.)

Root and State `Permission` must be **empty**: `a:0:{}`

```python
(php_s('Permission'), php_array([]))       # a:0:{}
(php_s('PermissionMode'), php_s(''))       # empty string
(php_s('PermissionScope'), php_s(''))      # empty string
```

### Lists (IBlock)

Root and State `Permission` has 8 keys (D,E,R,S,T,U,W,X):

```python
(php_s('Permission'), perm8)               # a:8:{...}
(php_s('PermissionMode'), php_s('3'))
(php_s('PermissionScope'), php_s('1'))
```

**Using Lists-style Permission in a CRM BPT causes "Network error" on import.**

## StateInitializationActivity

Properties must have only `Title` (1 key, `a:1:{}`), no `EditorComment`:

```python
make_activity('StateInitializationActivity', name, 'Y',
    [(php_s('Title'), php_s('Вход в статус'))],
    children)
```

## Activity types — real names

| What you want | Correct type name | WRONG name (does not exist) |
|---|---|---|
| Изменение документа | `SetFieldActivity` | ~~ModifyDocumentActivity~~ |
| Условие | `IfElseActivity` + `IfElseBranchActivity` | — |
| PHP код | `CodeActivity` | — |
| Установить статус | `SetStateActivity` | — |
| Переменная | `SetVariableActivity` | — |
| Запись в отчёт | `LogActivity` | — |
| Получить инфо элемента списка | `GetListsDocumentActivity` | — |
| Математика | Нет отдельного Activity. Использовать выражения калькулятора в `SetVariableActivity` | ~~MathOperationActivity~~ |

## SetFieldActivity (Изменение документа)

```python
def make_set_field(name, title, field_values):
    items = [(php_s(k), php_s(v)) for k, v in field_values.items()]
    return make_activity('SetFieldActivity', name, 'Y', [
        (php_s('FieldValue'), php_array(items)),
        (php_s('ModifiedBy'), php_array_indexed([php_s('user_1')])),
        (php_s('MergeMultipleFields'), php_s('N')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])
```

Properties: `FieldValue` (not `Fields`), `ModifiedBy`, `MergeMultipleFields`.

## IfElseBranchActivity conditions

Document field condition:
```python
(php_s('fieldcondition'), php_array([
    (php_int(0), php_array_indexed([
        php_s('STATUS_ID'), php_s('='), php_s('NEW'), php_s('0')
    ]))
]))
```

Variable condition:
```python
(php_s('propertyvariablecondition'), php_array([
    (php_int(0), php_array_indexed([
        php_s('CB_RESULT'), php_s('='), php_s('passed'), php_s('0')
    ]))
]))
```

Else branch (always true):
```python
(php_s('truecondition'), php_s('1'))
```

## Validation checklist (before import)

1. Braces balanced: `text.count('{') == text.count('}')`
2. All `s:N:"..."` byte lengths correct
3. No `ModifyDocumentActivity` or `MathOperationActivity`
4. `DOCUMENT_FIELDS` matches target entity type
5. `Permission` format matches entity type (CRM = `a:0:{}`, Lists = `a:8:{}`)
6. zlib header is `78da` (level 9)
7. Round-trip: `zlib.decompress(zlib.compress(data, 9)) == data`

## GetListsDocumentActivity (Получить информацию об элементе списка)

Reads properties from a list (IBlock) element. Result fields accessible as `{=ActivityName:PROPERTY_CODE}`.

```python
def make_get_list_element(name, title, iblock_id, element_id_expr, fields_map):
    """
    fields_map: dict of {field_code: {Name, Type, ...}} 
    e.g. {'PROPERTY_PROPERTY_613': {'Name': 'sip обратный звонок', 'Type': 'string', ...}}
    """
    fields = [(php_int(i), php_s(code)) for i, code in enumerate(fields_map.keys())]
    fmap = []
    for code, meta in fields_map.items():
        props = [
            (php_s('Id'), php_s('')),
            (php_s('Type'), php_s(meta.get('Type', 'string'))),
            (php_s('Name'), php_s(meta['Name'])),
            (php_s('Description'), php_s('')),
            (php_s('Multiple'), php_s(meta.get('Multiple', '0'))),
            (php_s('Required'), php_s('0')),
            (php_s('Options'), php_s('')),
            (php_s('Settings'), php_array([])),
            (php_s('Default'), php_s('')),
        ]
        fmap.append((php_s(code), php_array(props)))
    
    return make_activity('GetListsDocumentActivity', name, 'Y', [
        (php_s('DocumentType'), php_array_indexed([
            php_s('lists'),
            php_s('Bitrix\\Lists\\BizprocDocumentLists'),
            php_s(f'iblock_{iblock_id}'),
        ])),
        (php_s('ElementId'), php_s(element_id_expr)),
        (php_s('Fields'), php_array(fields)),
        (php_s('FieldsMap'), php_array(fmap)),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])
```

DocumentType for lists: `['lists', 'Bitrix\\Lists\\BizprocDocumentLists', 'iblock_<ID>']`.

### Portal pattern: linked list lookup chains

When a workflow needs values from linked Bitrix lists, prefer a chain of standard `GetListsDocumentActivity` blocks over PHP parsing.

Observed working pattern for lead buffer BP 776:

1. PHP block finds the matching element in list `54` and stores only `id_54`
2. `GetListsDocumentActivity` reads fields from `iblock_54`
3. If `PROPERTY_PROPERTY_192` from list `54` points to list `19`, a second `GetListsDocumentActivity` reads `PROPERTY_PROPERTY_73` from `iblock_19`
4. If city from list `54` points to list `22`, use either the raw linked element id directly for list field writes, or a separate `GetListsDocumentActivity` against `iblock_22` when a derived field is needed

Critical distinction:

- A list field like `PROPERTY_ISTOCHNIK` may store the linked element id from list `54`/`19`
- CRM lead field `SOURCE_ID` may require a box code from another field such as list `19` -> `PROPERTY_73`
- These are **not** interchangeable and must not be written from the same variable blindly

Anti-pattern seen in practice:

- Parse incoming webhook
- Store `CB_SOURCE_ID` from raw payload only
- Write document `PROPERTY_SOURCE_ID` or similar directly from `CB_SOURCE_ID`

This can erase the source if the webhook payload has no `SOURCE_ID`.

Safer rule:

- Never overwrite a document field from a variable that may legitimately be empty unless the workflow explicitly wants to clear that field
- For list-backed fields, inspect a working exported BPT first and copy the real document aliases exactly (`PROPERTY_ISTOCHNIK`, `PROPERTY_GOROD`, etc.), do not guess from human names

## Calculator expressions in BP parameters

Bitrix BP supports inline expressions in any parameter via `{{=expression}}` syntax.
No separate "MathActivity" — use expressions inside `SetVariableActivity` values.

Increment example:
```python
make_set_variable(name, 'Инкремент попытки', {
    'CB_ATTEMPT': '{{=intval({=Variable:CB_ATTEMPT})+1}}',
})
```

Available functions: `intval`, `floatval`, `abs`, `round`, `ceil`, `floor`, `min`, `max`,
`rand`, `substr`, `strlen`, `strpos`, `if`, `dateadd`, `datediff`, `date`, `trim`,
`strtolower`, `strtoupper`, `implode`, `explode`, `merge`, `firstvalue`, `shuffle`, `swirl`.

Operators: `+`, `-`, `*`, `/`, `=`, `<>`, `<`, `>`, `<=`, `>=`, `&` (concat), `^` (power), `%` (percent),
`and`, `or`, `not`, `true`, `false`.

## Debugging import errors

| Error | Cause |
|---|---|
| "Действие не найдено (X)" | Activity type `X` does not exist in Bitrix |
| "Network error" | Wrong Permission format, wrong DOCUMENT_FIELDS, or corrupted serialization |
| Silent import failure | String length mismatch in PHP serialization (UTF-8 byte vs char count) |
