#!/usr/bin/env python3
"""
Генератор lead_callback.bpt — шаблон БП «Обратный звонок по лиду».

Архитектура: максимум штатных блоков, PHP только для недоступного штатным.

Штатные блоки вместо PHP:
  - Блок 1 (телефон): SetVariable + IfElse — firstvalue, substr, конкатенация
  - Блок 2 (город):   TODO GetListsDocumentActivity + SetVariable (SIP, инкремент)
  - Блок 3 (рабочее время): SetVariable (dateadd, substr, intval) + IfElse

PHP-блоки (только то, что нельзя штатно):
  - bp_pick_operator.txt — CIBlockElement::GetList(128) + CTimeManUser + CVoxImplantIncoming
  - bp_start_call.txt    — curl Vox StartScenarios
  - bp_fix_result.txt    — curl Vox GetCallHistory + StatisticTable
  - bp_sleep.txt         — sleep()

Состояния:
  1. Проверка лида — ШТАТНЫЕ (IfElse по STATUS_ID, SOURCE_ID, UF-полям)
  2. Загрузка данных — ШТАТНЫЕ (firstvalue, substr, dateadd, intval)
  3. Выбор оператора — PHP(список128 + timeman + busy) + IfElse
  4. Запуск звонка — PHP (curl Vox API)
  5. Ожидание результата — PHP (sleep + fix_result)
  6. Пауза retry — PHP (sleep)
  7. Завершено
"""

import os
import random
import zlib

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BPT_SOURCE = '/mnt/c/Users/Victor/Downloads/bp-774.bpt'


def php_s(value: str) -> str:
    b = value.encode('utf-8')
    return f's:{len(b)}:"{value}";'


def php_int(value: int) -> str:
    return f'i:{value};'


def php_null() -> str:
    return 'N;'


def php_bool(value: bool) -> str:
    return f'b:{1 if value else 0};'


def php_array(items: list[tuple[str, str]]) -> str:
    parts = [f'a:{len(items)}:{{']
    for key, val in items:
        parts.append(key)
        parts.append(val)
    parts.append('}')
    return ''.join(parts)


def php_array_indexed(items: list[str]) -> str:
    pairs = []
    for i, val in enumerate(items):
        pairs.append((php_int(i), val))
    return php_array(pairs)


def unique_name() -> str:
    return f'A{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}'


def make_variable(name: str, label: str, var_type: str = 'string',
                  default: str = '', multiple: bool = False) -> tuple[str, str]:
    return (
        php_s(name),
        php_array([
            (php_s('Name'), php_s(label)),
            (php_s('Description'), php_s('')),
            (php_s('Type'), php_s(var_type)),
            (php_s('Required'), php_s('0')),
            (php_s('Multiple'), php_s('1' if multiple else '0')),
            (php_s('Options'), php_s('')),
            (php_s('Default'), php_s(default)),
        ])
    )


def make_activity(act_type: str, name: str, activated: str,
                  properties: list[tuple[str, str]],
                  children: list[str] | None = None) -> str:
    parts = [
        (php_s('Type'), php_s(act_type)),
        (php_s('Name'), php_s(name)),
        (php_s('Activated'), php_s(activated)),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array(properties)),
        (php_s('Children'), php_array_indexed(children or [])),
    ]
    return php_array(parts)


def make_code_activity(name: str, code: str, title: str) -> str:
    return make_activity('CodeActivity', name, 'Y', [
        (php_s('ExecuteCode'), php_s(code)),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])


def make_log_activity(name: str, text: str, title: str = 'Запись в отчет') -> str:
    return make_activity('LogActivity', name, 'Y', [
        (php_s('Text'), php_s(text)),
        (php_s('SetVariable'), php_s('0')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])


def make_set_state(name: str, target_state_name: str, title: str) -> str:
    return make_activity('SetStateActivity', name, 'Y', [
        (php_s('TargetStateName'), php_s(target_state_name)),
        (php_s('CancelCurrentState'), php_s('N')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])


def make_if_else_branch(name: str, title: str, children: list[str],
                        var_condition: list | None = None,
                        doc_condition: list | None = None,
                        true_condition: bool = False) -> str:
    props: list[tuple[str, str]] = [
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ]
    if var_condition:
        cond_items = []
        for i, cond in enumerate(var_condition):
            cond_items.append((php_int(i), php_array_indexed([php_s(c) for c in cond])))
        props.append((php_s('propertyvariablecondition'), php_array(cond_items)))
    if doc_condition:
        cond_items = []
        for i, cond in enumerate(doc_condition):
            cond_items.append((php_int(i), php_array_indexed([php_s(c) for c in cond])))
        props.append((php_s('fieldcondition'), php_array(cond_items)))
    if true_condition:
        props.append((php_s('truecondition'), php_s('1')))
    return make_activity('IfElseBranchActivity', name, 'Y', props, children)


def make_if_else(name: str, title: str, branches: list[str]) -> str:
    return make_activity('IfElseActivity', name, 'Y', [
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ], branches)


def make_set_variable(name: str, title: str, var_values: dict[str, str]) -> str:
    items = [(php_s(k), php_s(v)) for k, v in var_values.items()]
    return make_activity('SetVariableActivity', name, 'Y', [
        (php_s('VariableValue'), php_array(items)),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])


def make_set_field(name: str, title: str, field_values: dict[str, str]) -> str:
    items = [(php_s(k), php_s(v)) for k, v in field_values.items()]
    return make_activity('SetFieldActivity', name, 'Y', [
        (php_s('FieldValue'), php_array(items)),
        (php_s('ModifiedBy'), php_array_indexed([php_s('user_1')])),
        (php_s('MergeMultipleFields'), php_s('N')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ])


def make_state(name: str, title: str, init_children: list[str]) -> str:
    state_init = make_activity('StateInitializationActivity', unique_name(), 'Y',
                               [(php_s('Title'), php_s('Вход в статус'))],
                               init_children)
    return make_activity('StateActivity', name, 'Y', [
        (php_s('Permission'), php_array([])),
        (php_s('PermissionMode'), php_s('')),
        (php_s('PermissionScope'), php_s('')),
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ], [state_init])


def make_while_activity(name: str, title: str, children: list[str],
                        var_condition: list | None = None) -> str:
    props: list[tuple[str, str]] = [
        (php_s('Title'), php_s(title)),
        (php_s('EditorComment'), php_s('')),
    ]
    if var_condition:
        cond_items = []
        for i, cond in enumerate(var_condition):
            cond_items.append((php_int(i), php_array_indexed([php_s(c) for c in cond])))
        props.append((php_s('propertyvariablecondition'), php_array(cond_items)))
    return make_activity('WhileActivity', name, 'Y', props, children)


def read_php_block(filename: str) -> str:
    path = os.path.join(SCRIPT_DIR, filename)
    with open(path, 'r', encoding='utf-8') as f:
        return f.read()


def extract_document_fields(bpt_path: str) -> str:
    with open(bpt_path, 'rb') as f:
        data = f.read()
    text = zlib.decompress(data).decode('utf-8', errors='replace')

    marker = '"DOCUMENT_FIELDS"'
    df_idx = text.find(marker)
    if df_idx < 0:
        raise ValueError('DOCUMENT_FIELDS not found in source BPT')

    val_start = text.find(';a:', df_idx) + 1
    depth = 0
    started = False
    for i in range(val_start, len(text)):
        if text[i] == '{':
            depth += 1
            started = True
        elif text[i] == '}':
            depth -= 1
            if started and depth == 0:
                return text[val_start:i + 1]
    raise ValueError('Could not parse DOCUMENT_FIELDS boundaries')


def build_bpt() -> bytes:
    random.seed(42)

    code_load_city  = read_php_block('bp_load_city.txt')
    code_pick_op    = read_php_block('bp_pick_operator.txt')
    code_start_call = read_php_block('bp_start_call.txt')
    code_fix_result = read_php_block('bp_fix_result.txt')
    code_sleep      = read_php_block('bp_sleep.txt')

    st_check    = unique_name()
    st_load     = unique_name()
    st_pick_op  = unique_name()
    st_call     = unique_name()
    st_wait     = unique_name()
    st_retry    = unique_name()
    st_done     = unique_name()

    # ===================================================================
    # State 1: Проверка лида — ВСЁ ШТАТНОЕ (0 PHP)
    # ===================================================================

    check_status = make_if_else(unique_name(), 'Статус = NEW?', [
        make_if_else_branch(unique_name(), 'NEW', [],
            doc_condition=[['STATUS_ID', '=', 'NEW', '0']]),
        make_if_else_branch(unique_name(), 'не NEW', [
            make_log_activity(unique_name(), 'Лид не в стадии Новая заявка: {=Document:STATUS_ID}'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    check_source = make_if_else(unique_name(), 'Источник не исключён?', [
        make_if_else_branch(unique_name(), 'SOURCE=62', [
            make_log_activity(unique_name(), 'Источник исключён: {=Document:SOURCE_ID}'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], doc_condition=[['SOURCE_ID', '=', '62', '0']]),
        make_if_else_branch(unique_name(), 'SOURCE=UC_YFLXKA', [
            make_log_activity(unique_name(), 'Источник исключён: {=Document:SOURCE_ID}'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], doc_condition=[['SOURCE_ID', '=', 'UC_YFLXKA', '0']]),
        make_if_else_branch(unique_name(), 'OK', [], true_condition=True),
    ])

    check_city = make_if_else(unique_name(), 'Город заполнен?', [
        make_if_else_branch(unique_name(), 'Город > 0', [],
            doc_condition=[['UF_CRM_1744362815', '>', '0', '0']]),
        make_if_else_branch(unique_name(), 'Город пуст', [
            make_log_activity(unique_name(), 'Город не заполнен'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    check_attempts = make_if_else(unique_name(), 'Попытки < 5?', [
        make_if_else_branch(unique_name(), '< 5', [],
            doc_condition=[['UF_CRM_1771439155', '<', '5', '0']]),
        make_if_else_branch(unique_name(), '>= 5', [
            make_set_field(unique_name(), 'Лид -> Недозвон', {
                'STATUS_ID': 'PROCESSED',
                'UF_CRM_1773155019732': 'Не удалось (оператор не ответил)',
            }),
            make_log_activity(unique_name(), 'Лимит попыток достигнут'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    set_vars_from_doc = make_set_variable(unique_name(), 'Переменные из документа', {
        'CB_CITY_ID': '{=Document:UF_CRM_1744362815}',
        'CB_LEAD_ID': '{=Document:ID}',
    })

    goto_load = make_set_state(unique_name(), st_load, 'Загрузка данных')

    state_check = make_state(st_check, 'Проверка лида', [
        check_status, check_source, check_city, check_attempts,
        set_vars_from_doc,
        make_log_activity(unique_name(), 'Проверки пройдены, leadId={=Document:ID} city={=Document:UF_CRM_1744362815}'),
        goto_load,
    ])

    # ===================================================================
    # State 2: Загрузка данных — ВСЁ ШТАТНОЕ (0 PHP)
    # ===================================================================

    # --- Блок 1: Телефон (штатный) ---
    set_phone_raw = make_set_variable(unique_name(), 'Телефон из лида', {
        'CB_PHONE_RAW': '{{=firstvalue({=Document:PHONE})}}',
    })

    check_phone_empty = make_if_else(unique_name(), 'Телефон есть?', [
        make_if_else_branch(unique_name(), 'есть', [],
            var_condition=[['CB_PHONE_RAW', '!empty', '', '0']]),
        make_if_else_branch(unique_name(), 'нет телефона', [
            make_log_activity(unique_name(), 'У лида нет телефона'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    normalize_phone = make_set_variable(unique_name(), 'Нормализация телефона', {
        'CB_PHONE': '{{="+7" & substr({=Variable:CB_PHONE_RAW}, strlen({=Variable:CB_PHONE_RAW})-9, 9)}}',
    })

    # --- Блок 2: Город из списка 22 (PHP) ---
    load_city_php = make_code_activity(unique_name(), code_load_city,
                                        'PHP: Загрузка города (список 22)')

    check_city_result = make_if_else(unique_name(), 'Город загружен?', [
        make_if_else_branch(unique_name(), 'passed', [],
            var_condition=[['CB_CITY_RESULT', '=', 'passed', '0']]),
        make_if_else_branch(unique_name(), 'error', [
            make_log_activity(unique_name(),
                'Ошибка загрузки города: {=Variable:CB_CITY_MESSAGE}'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    # --- Блок 3: Рабочее время (штатный) ---
    calc_worktime = make_set_variable(unique_name(), 'Расчёт рабочего времени', {
        'CB_NOW_MINUTES': '{{=intval(date("H", dateadd({=System:Now}, {=Variable:CB_TIMEZONE} & "h"))) * 60 + intval(date("i", dateadd({=System:Now}, {=Variable:CB_TIMEZONE} & "h")))}}',
        'CB_FROM_MINUTES': '{{=intval(substr({=Variable:CB_WORKTIME}, 0, 2)) * 60 + intval(substr({=Variable:CB_WORKTIME}, 3, 2))}}',
        'CB_TO_MINUTES': '{{=intval(substr({=Variable:CB_WORKTIME}, 6, 2)) * 60 + intval(substr({=Variable:CB_WORKTIME}, 9, 2))}}',
    })

    check_worktime_result = make_if_else(unique_name(), 'Рабочее время?', [
        make_if_else_branch(unique_name(), 'CB_WORKTIME пусто — разрешаем', [],
            var_condition=[['CB_WORKTIME', '=', '', '0']]),
        make_if_else_branch(unique_name(), 'в рабочее время', [],
            var_condition=[
                ['CB_NOW_MINUTES', '>=', '{=Variable:CB_FROM_MINUTES}', '0'],
                ['CB_NOW_MINUTES', '<=', '{=Variable:CB_TO_MINUTES}', '0'],
            ]),
        make_if_else_branch(unique_name(), 'вне рабочего времени', [
            make_log_activity(unique_name(), 'Вне рабочего времени КЦ'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], true_condition=True),
    ])

    update_lead_progress = make_set_field(unique_name(), 'Лид: попытка + статус', {
        'UF_CRM_1771439155': '{=Variable:CB_ATTEMPT}',
        'UF_CRM_1773155019732': 'В процессе callback',
        'UF_CRM_1772538740': '1',
    })

    goto_pick = make_set_state(unique_name(), st_pick_op, 'Выбор оператора')

    state_load = make_state(st_load, 'Загрузка данных', [
        set_phone_raw, check_phone_empty, normalize_phone,
        load_city_php, check_city_result,
        calc_worktime, check_worktime_result,
        update_lead_progress,
        make_log_activity(unique_name(),
            'Данные загружены, тел={=Variable:CB_PHONE} попытка={=Variable:CB_ATTEMPT} rule={=Variable:CB_RULE_ID} sip={=Variable:CB_SIP_LINE}'),
        goto_pick,
    ])

    # ===================================================================
    # State 3: Выбор оператора — PHP (список128 + timeman + busy) + IfElse
    # ===================================================================

    pick_op_php = make_code_activity(unique_name(), code_pick_op,
                                     'PHP: Выбор оператора')

    check_max_retries = make_if_else(unique_name(), 'Лимит повторов?', [
        make_if_else_branch(unique_name(), 'max_retries', [
            make_log_activity(unique_name(),
                'СТОП: лимит повторов выбора оператора (5). Завершаем.'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], var_condition=[['CB_OPERATOR_RESULT', '=', 'max_retries', '0']]),
        make_if_else_branch(unique_name(), 'продолжаем', [], true_condition=True),
    ])

    check_op_found = make_if_else(unique_name(), 'Оператор найден?', [
        make_if_else_branch(unique_name(), 'found', [
            make_log_activity(unique_name(),
                'Оператор: uid={=Variable:CB_OPERATOR_ID} ext={=Variable:CB_OPERATOR_EXTENSION}'),
            make_set_state(unique_name(), st_call, 'Запуск звонка'),
        ], var_condition=[['CB_OPERATOR_RESULT', '=', 'found', '0']]),
        make_if_else_branch(unique_name(), 'not_found/all_busy', [
            make_log_activity(unique_name(),
                'Оператор не найден: {=Variable:CB_OPERATOR_RESULT}'),
            make_set_state(unique_name(), st_retry, 'Повтор'),
        ], true_condition=True),
    ])

    state_pick = make_state(st_pick_op, 'Выбор оператора', [
        pick_op_php, check_max_retries, check_op_found,
    ])

    # ===================================================================
    # State 4: Запуск звонка — PHP + штатные
    # ===================================================================

    call_php = make_code_activity(unique_name(), code_start_call, 'PHP: Запуск звонка')
    call_log = make_log_activity(unique_name(),
        'Звонок: {=Variable:CB_CALL_RESULT} callId={=Variable:CB_CALL_ID}')

    call_if = make_if_else(unique_name(), 'Звонок запущен?', [
        make_if_else_branch(unique_name(), 'started', [
            make_set_state(unique_name(), st_wait, 'Ожидание результата'),
        ], var_condition=[['CB_CALL_RESULT', '=', 'started', '0']]),
        make_if_else_branch(unique_name(), 'error', [
            make_log_activity(unique_name(), 'Ошибка звонка: {=Variable:CB_CALL_MESSAGE}'),
            make_set_state(unique_name(), st_retry, 'Повтор'),
        ], true_condition=True),
    ])

    state_call = make_state(st_call, 'Запуск звонка', [call_php, call_log, call_if])

    # ===================================================================
    # State 5: Ожидание результата — PHP + штатные
    # ===================================================================

    wait_set_delay = make_set_variable(unique_name(), 'Задержка 25 сек', {'CB_SLEEP_SEC': '25'})
    wait_sleep = make_code_activity(unique_name(), code_sleep, 'PHP: Пауза 25 сек')
    wait_fix = make_code_activity(unique_name(), code_fix_result, 'PHP: Фиксация результата')
    wait_log = make_log_activity(unique_name(),
        'Результат: {=Variable:CB_FIX_STATUS} {=Variable:CB_FIX_RESULT}')

    wait_if = make_if_else(unique_name(), 'Результат звонка', [
        make_if_else_branch(unique_name(), 'connected', [
            make_set_field(unique_name(), 'Лид: Успешно', {
                'UF_CRM_1773155019732': '{=Variable:CB_FIX_LABEL}',
            }),
            make_log_activity(unique_name(), 'Клиент соединён с оператором'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], var_condition=[['CB_FIX_STATUS', '=', 'connected', '0']]),
        make_if_else_branch(unique_name(), 'final_failed', [
            make_set_field(unique_name(), 'Лид: Неудача', {
                'STATUS_ID': 'PROCESSED',
                'UF_CRM_1773155019732': '{=Variable:CB_FIX_LABEL}',
            }),
            make_log_activity(unique_name(), 'Финал: {=Variable:CB_FIX_MESSAGE}'),
            make_set_state(unique_name(), st_done, 'Завершено'),
        ], var_condition=[['CB_FIX_STATUS', '=', 'final_failed', '0']]),
        make_if_else_branch(unique_name(), 'retry_wait', [
            make_set_state(unique_name(), st_retry, 'Повтор'),
        ], true_condition=True),
    ])

    state_wait = make_state(st_wait, 'Ожидание результата',
                            [wait_set_delay, wait_sleep, wait_fix, wait_log, wait_if])

    # ===================================================================
    # State 6: Пауза retry
    # ===================================================================

    retry_set = make_set_variable(unique_name(), 'Задержка 10 сек', {'CB_SLEEP_SEC': '10'})
    retry_sleep = make_code_activity(unique_name(), code_sleep, 'PHP: Пауза 10 сек')
    retry_log = make_log_activity(unique_name(), 'Retry done, переход к выбору оператора')
    retry_goto = make_set_state(unique_name(), st_pick_op, 'Выбор оператора')

    state_retry = make_state(st_retry, 'Пауза retry', [retry_set, retry_sleep, retry_log, retry_goto])

    # ===================================================================
    # State 7: Завершено
    # ===================================================================

    done_log = make_log_activity(unique_name(),
        'CALLBACK ЗАВЕРШЁН: fix={=Variable:CB_FIX_RESULT} msg={=Variable:CB_FIX_MESSAGE}')
    state_done = make_state(st_done, 'Завершено', [done_log])

    # ===================================================================
    # Root template
    # ===================================================================

    template = make_activity('StateMachineWorkflowActivity', 'Template', 'Y', [
        (php_s('Title'), php_s('Обратный звонок по лиду')),
        (php_s('Permission'), php_array([])),
        (php_s('InitialStateName'), php_s(st_check)),
    ], [state_check, state_load, state_pick, state_call, state_wait, state_retry, state_done])

    # ===================================================================
    # Variables
    # ===================================================================

    variables = [
        make_variable('CB_LEAD_ID', 'ID лида'),
        make_variable('CB_PHONE_RAW', 'Телефон (сырой)'),
        make_variable('CB_PHONE', 'Телефон клиента'),
        make_variable('CB_CITY_ID', 'ID города'),
        make_variable('CB_SIP_LINE', 'SIP-линия'),
        make_variable('CB_SIP_PASSWORD', 'Пароль SIP'),
        make_variable('CB_RULE_ID', 'Rule ID Vox'),
        make_variable('CB_TIMEZONE', 'Часовой пояс города'),
        make_variable('CB_WORKTIME', 'Рабочее время города'),
        make_variable('CB_NOW_MINUTES', 'Текущие минуты (local)'),
        make_variable('CB_FROM_MINUTES', 'Начало рабочего дня (мин)'),
        make_variable('CB_TO_MINUTES', 'Конец рабочего дня (мин)'),
        make_variable('CB_ATTEMPT', 'Номер попытки', default='0'),
        make_variable('CB_OPERATORS_COUNT', 'Кол-во операторов'),
        make_variable('CB_OPERATOR_ID', 'USER_ID оператора (число)'),
        make_variable('CB_OPERATOR_EXTENSION', 'Внутренний номер оператора'),
        make_variable('CB_OPERATOR_BUSY', 'Оператор занят (Y/N)'),
        make_variable('CB_OPERATOR_RESULT', 'Результат выбора оператора'),
        make_variable('CB_OP_OFFSET', 'Смещение round-robin', default='0'),
        make_variable('CB_PICK_RETRIES', 'Счётчик повторов цикла', default='0'),
        make_variable('CB_CALL_ID', 'ID звонка'),
        make_variable('CB_VOX_SESSION_ID', 'Vox Session ID'),
        make_variable('CB_STARTED_AT', 'Время запуска звонка'),
        make_variable('CB_CALL_RESULT', 'Результат запуска звонка'),
        make_variable('CB_CALL_MESSAGE', 'Сообщение запуска звонка'),
        make_variable('CB_FIX_STATUS', 'Статус результата звонка'),
        make_variable('CB_FIX_RESULT', 'Результат фиксации'),
        make_variable('CB_FIX_MESSAGE', 'Сообщение фиксации'),
        make_variable('CB_FIX_LABEL', 'Текст статуса для лида'),
        make_variable('CB_CITY_RESULT', 'Результат загрузки города'),
        make_variable('CB_CITY_MESSAGE', 'Сообщение загрузки города'),
        make_variable('CB_SLEEP_SEC', 'Задержка в секундах', default='10'),
        make_variable('CB_SLEEP_DONE', 'Sleep завершён'),
    ]

    doc_fields_raw = extract_document_fields(BPT_SOURCE)

    top = php_array([
        (php_s('VERSION'), php_int(2)),
        (php_s('TEMPLATE'), php_array_indexed([template])),
        (php_s('PARAMETERS'), php_array([])),
        (php_s('VARIABLES'), php_array(variables)),
        (php_s('CONSTANTS'), php_array([])),
        (php_s('DOCUMENT_FIELDS'), doc_fields_raw),
    ])

    serialized = top.encode('utf-8')
    return zlib.compress(serialized, 9)


def main():
    output_path = os.path.join(SCRIPT_DIR, 'lead_callback.bpt')
    data = build_bpt()
    with open(output_path, 'wb') as f:
        f.write(data)
    print(f'Generated: {output_path}')
    print(f'  Compressed size: {len(data)} bytes')

    decompressed = zlib.decompress(data)
    print(f'  Decompressed size: {len(decompressed)} bytes')

    text = decompressed.decode('utf-8', errors='replace')
    if 'StateMachineWorkflowActivity' in text and 'CB_PHONE' in text:
        print('  Validation: OK — StateMachine + variables found')
    else:
        print('  Validation: WARNING — expected markers not found')

    import re
    states = re.findall(r'"StateActivity"', text)
    codes = re.findall(r'"CodeActivity"', text)
    conditions = re.findall(r'"IfElseActivity"', text)
    set_fields = re.findall(r'"SetFieldActivity"', text)
    set_vars = re.findall(r'"SetVariableActivity"', text)
    print(f'  States: {len(states)}, CodeActivities: {len(codes)}, '
          f'Conditions: {len(conditions)}, SetField: {len(set_fields)}, '
          f'SetVariable: {len(set_vars)}')


if __name__ == '__main__':
    main()
