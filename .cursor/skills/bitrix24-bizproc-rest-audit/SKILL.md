---
name: bitrix24-bizproc-rest-audit
description: Export and audit all Bitrix24 BizProc templates over REST webhook via `bizproc.workflow.template.list`, including full `TEMPLATE` payload, pagination, activity analysis, and portal-level summary patterns. Use when the user asks to выгрузить все БП, получить все шаблоны БП по вебхуку, проанализировать BizProc через REST, or summarize BP architecture from a portal.
---

# Bitrix24 BizProc REST audit

## When to use

- Нужно **выгрузить все шаблоны БП** с портала через входящий вебхук.
- Нужно **проанализировать архитектуру БП**: где CRM, где списки, сколько PHP-блоков, какие activity реально используются.
- Нужно сделать **инвентаризацию/аудит** перед миграцией, чисткой дублей, переносом логики в код или генерацией `.bpt`.

## Core REST fact

- Для шаблонов БП используется **`bizproc.workflow.template.list`**.
- Отдельного `get` для шаблона в REST нет.
- Полный шаблон нужно забирать именно через `list` + `select`.
- Метод требует scope `bizproc` и, по документации, права администратора.

## Required fields for full export

Запрашивай минимум такой набор:

```json
[
  "ID",
  "MODULE_ID",
  "ENTITY",
  "DOCUMENT_TYPE",
  "AUTO_EXECUTE",
  "NAME",
  "DESCRIPTION",
  "TEMPLATE",
  "PARAMETERS",
  "VARIABLES",
  "CONSTANTS",
  "MODIFIED",
  "IS_MODIFIED",
  "USER_ID",
  "SYSTEM_CODE"
]
```

Если `select` не передать, Bitrix вернёт только `ID`.

## Pagination

- Размер страницы фиксированный: **50**.
- Нужно прокручивать `start=0, 50, 100, ...` либо брать `next` из ответа.
- Для полного аудита безопаснее идти последовательно, а не через агрессивный `batch`.

## Working webhook example

```bash
curl -X POST \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "select": [
      "ID","MODULE_ID","ENTITY","DOCUMENT_TYPE","AUTO_EXECUTE",
      "NAME","DESCRIPTION","TEMPLATE","PARAMETERS","VARIABLES",
      "CONSTANTS","MODIFIED","IS_MODIFIED","USER_ID","SYSTEM_CODE"
    ],
    "order": {"ID":"ASC"},
    "start": 0
  }' \
  "https://<portal>/rest/<user>/<webhook>/bizproc.workflow.template.list.json"
```

## Recommended audit procedure

1. Сначала вызови `scope.json` и убедись, что есть `bizproc`.
2. Пройди все страницы `bizproc.workflow.template.list`.
3. Сохрани:
   - полный raw JSON экспорт
   - machine-readable summary JSON
   - короткий markdown summary для человека
4. Анализируй не только поля шаблона, но и дерево `TEMPLATE`.

## How to count activities correctly

- В `TEMPLATE` есть не только activity-узлы, но и вложенные структуры параметров/типов.
- Не считай каждый `Type` подряд: так в статистику попадут `string`, `select` и другие не-activity значения.
- Activity лучше определять по признакам:
  - есть `Type`
  - есть `Name`
  - есть `Children` и/или `Properties` и/или `Activated`

Практическое правило:

```python
is_activity = (
    isinstance(node, dict)
    and 'Type' in node
    and 'Name' in node
    and ('Children' in node or 'Properties' in node or 'Activated' in node)
)
```

## What to summarize

Минимально полезные срезы:

- количество шаблонов всего
- разрез по `MODULE_ID`
- разрез по `ENTITY`
- разрез по `DOCUMENT_TYPE`
- разрез по `AUTO_EXECUTE`
- кто создавал/последним менял (`USER_ID`)
- сколько `SequentialWorkflowActivity` vs `StateMachineWorkflowActivity`
- сколько шаблонов с `CodeActivity`
- сколько шаблонов с `StartWorkflowActivity`
- сколько шаблонов с `WebHookActivity`
- какие custom REST activity встречаются (`rest_<hash>`)
- следы зоопарка версий: `(old)`, `v.2`, `v.3`, `actual`, `test`

## Important interpretation rules

- `CodeActivity` не значит "весь БП кастомный", а только наличие PHP-блока.
- `rest_<hash>` в `Type` обычно означает кастомную activity из REST/приложения.
- `DOCUMENT_TYPE` удобно нормализовать в строку вида:
  - `crm / CCrmDocumentLead / LEAD`
  - `lists / Bitrix\Lists\BizprocDocumentLists / iblock_131`
- Для смарт-процессов ищи `DYNAMIC_<id>`.
- Для списков ищи `iblock_<id>`.

## Loop and iterator patterns

При аудите БП отдельно смотри:

- `WhileActivity`
- `ForEachActivity`
- `DelayActivity`
- `StartWorkflowActivity`
- `SetVariableActivity`

Именно их сочетание обычно показывает, как на портале решены:

- циклы по счетчику
- перебор массивов/списков
- long-running orchestration
- polling/retry
- разбиение тяжелой логики на дочерние БП

### `WhileActivity` on this portal

Наблюдаемый паттерн: `WhileActivity` чаще используется не как "бесконечный бизнес-цикл", а как управляемый цикл по BP-переменной.

Типовые варианты:

- цикл согласования в стандартных list-шаблонах
- счетный цикл с явным лимитом: `Цикл от 1 до 800`, `Цикл 366 итераций (~ 6 месяцев)`, `Цикл (24 прогона)`
- таймерный цикл: `While + Delay + SetVariable`
- оркестрация дочерних БП: `While + StartWorkflowActivity + Delay`

Практический вывод:

- если в шаблоне есть `WhileActivity`, почти всегда нужно смотреть, где именно меняется счетчик/флаг выхода через `SetVariableActivity`
- без этого анализ цикла будет неполным

### `ForEachActivity` on this portal

Наблюдаемый паттерн: `ForEachActivity` используется как нормальный итератор по коллекции, чаще всего по списку ID, заранее положенному в BP-переменную.

Характерные свойства:

- `Object=Variable`
- `Variable=<имя переменной>`
- перед ним почти всегда есть `SetVariableActivity`, которая готовит массив для итерации

Типовые кейсы на портале:

- перебор городов
- перебор интеграций
- перебор ID лидов
- перебор отделов
- перебор элементов смарт-процесса

Практический вывод:

- `ForEachActivity` здесь это в основном "чистый iterator over prepared list"
- источник списка обычно надо искать не внутри самого `ForEach`, а в соседних `SetVariableActivity`, `GetListsDocumentActivity`, `CrmGet*Activity` или PHP-блоке выше по дереву

### `DelayActivity` on this portal

Задержки используются в двух основных формах:

- абсолютное время: `TimeoutTime`
- относительная длительность: `TimeoutDuration` + `TimeoutDurationType`

Реальные сценарии:

- пауза до конкретного часа (`до 9/10 утра`)
- пауза на часы/дни (`12 часов`, `30 дней`)
- короткая пауза внутри таймерного цикла (`5 секунд`)

Практический вывод:

- для "ожидания расписания" здесь часто применяют штатный `DelayActivity`
- для циклических таймеров delay обычно встроен внутрь `WhileActivity`

### `StartWorkflowActivity` on this portal

`StartWorkflowActivity` активно используется как способ декомпозировать сценарий на дочерние БП.

Типовые свойства:

- `TemplateId`
- `DocumentId`
- `TemplateParameters`
- `UseSubscription`

Практические паттерны:

- dispatcher BP запускает специализированные дочерние БП
- delay/timer BP запускается отдельно и возвращает управление через изменение документа
- один родительский БП может запускать несколько дочерних шаблонов в разных ветках

Критически важно:

- перед удалением или переименованием шаблона нужно проверить, не вызывается ли он из других БП через `StartWorkflowActivity`

### Verified structural patterns from bitrix.dreamteamcompany.ru

Проверенные на выгрузке портала структуры:

- `While + ForEach + Delay`:
  - шаблон `463` `Контроль интеграций`
  - схема: задержка -> цикл на 366 итераций -> внутри перебор интеграций -> пауза 12 часов -> декремент счетчика
- `While + CRM read/update`:
  - шаблон `662` `Проверка активности`
  - схема: диапазонный цикл `1..800`, затем второй `800..1600`, внутри `CrmGetDynamicInfoActivity` + `GetUserInfoActivity` + `CrmUpdateDynamicActivity`
- `While + StartWorkflow + Delay`:
  - шаблон `755` `таймер`
  - схема: цикл -> запуск дочернего БП `TemplateId=749` -> пауза 5 секунд
- `State machine + iterator + nested start`:
  - шаблон `289` `Распределение Лидов v.2`
  - схема: state machine, в одном статусе `ForEach` по городам, в другом статусе `StartWorkflowActivity` на шаблон задержки `232`
- `Pure PHP orchestration`:
  - шаблон `735` `Создание очереди`
  - схема: последовательный БП из нескольких `CodeActivity` подряд без штатных циклов

### Heuristics for future analysis

Если пользователь просит "изучить, как организованы циклы/итераторы", делай так:

1. Отфильтруй шаблоны с `WhileActivity`, `ForEachActivity`, `StartWorkflowActivity`, `DelayActivity`.
2. Для каждого такого шаблона восстанови ближайший контекст по дереву:
   - что подготавливает входные переменные
   - где меняется счетчик/флаг выхода
   - есть ли дочерний запуск БП
   - есть ли штатная задержка
3. Раздели шаблоны по паттернам:
   - счетные циклы
   - итераторы по спискам
   - timer/polling loops
   - parent-child orchestration
   - heavy batch processing
4. Отдельно отметь шаблоны, где цикл сделан через `CodeActivity`, а не штатными блоками.

## Verified portal example: bitrix.dreamteamcompany.ru

Аудит через webhook `https://bitrix.dreamteamcompany.ru/rest/1/jsmaj7a6nb501gc3/` показал:

- всего шаблонов: **196**
- CRM: **154**
- Lists: **42**
- sequential root: **171**
- state machine root: **25**
- шаблонов с PHP-блоками (`CodeActivity`): **26**
- шаблонов с вложенным запуском БП (`StartWorkflowActivity`): **29**
- шаблонов с `WebHookActivity`: **18**
- лиды доминируют: **84** шаблона на `LEAD`
- смарт-процессы: **40** шаблонов по **16** `DYNAMIC_*`
- основные list iblock: `iblock_25` (**8**), `iblock_131` (**8**), `iblock_54` (**3**), `iblock_130` (**3**)
- основной автор/последний редактор: пользователь `9` (**147** шаблонов)
- legacy/version drift заметен: **5** шаблонов с `(old)` и ещё **16** с `v.2`/`v.3`

Часто встречающиеся activity по presence in template:

- `IfElseActivity`: 118
- `LogActivity`: 107
- `SetVariableActivity`: 94
- `SetFieldActivity`: 82
- `IMNotifyActivity`: 55
- `CodeActivity`: 26

Обнаруженные custom REST activity:

- `rest_6515462f0a6260c5f68e82ba60f8c4f5` — в 15 шаблонах
- `rest_6e9049d7fbafe1eeb17cc7a385cc8469` — в 3 шаблонах
- `rest_4a64fafd0c72964fd1574767c63c8b12` — в 2 шаблонах

Практический вывод по этому порталу:

- основная масса логики собрана штатными блоками конструктора
- PHP-блоки используются точечно, а не как основной слой orchestration
- есть выраженный пласт коммуникационных БП (WhatsApp/Telegram/SMS)
- есть признаки накопившихся версий и старых дублей, которые можно чистить после инвентаризации зависимостей

## Artifacts from the verified audit

Для `bitrix.dreamteamcompany.ru` сохранены:

- `docs/bizproc_rest_audit/bitrix_dreamteamcompany_ru_2026-04-01_templates_full.json` — raw export
- `docs/bizproc_rest_audit/README.md`

## Activity reference

Полный справочник activity с копируемыми примерами Properties:

- `.cursor/skills/bitrix24-bizproc-activity-reference/SKILL.md`

## Reusable Python outline

```python
import json
import urllib.request

base = 'https://<portal>/rest/<user>/<webhook>/bizproc.workflow.template.list.json'
select = [
    'ID', 'MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE', 'AUTO_EXECUTE',
    'NAME', 'DESCRIPTION', 'TEMPLATE', 'PARAMETERS', 'VARIABLES',
    'CONSTANTS', 'MODIFIED', 'IS_MODIFIED', 'USER_ID', 'SYSTEM_CODE',
]

items = []
start = 0

while True:
    payload = json.dumps({
        'select': select,
        'order': {'ID': 'ASC'},
        'start': start,
    }, ensure_ascii=False).encode('utf-8')

    req = urllib.request.Request(
        base,
        data=payload,
        headers={'Content-Type': 'application/json', 'Accept': 'application/json'},
    )

    with urllib.request.urlopen(req, timeout=60) as response:
        data = json.load(response)

    items.extend(data.get('result', []))

    if 'next' not in data:
        break

    start = data['next']
```

## Red flags during audit

- `ACCESS_DENIED` or `insufficient_scope`: вебхук неадминский или без `bizproc`
- выгружаются только `ID`: забыли `select`
- activity-статистика засорена `string/select/int`: неверно определяются узлы activity
- на портале много `(old)` / `v.2` / `v.3`: перед чисткой обязательно проверить, нет ли `StartWorkflowActivity` зависимостей между шаблонами
