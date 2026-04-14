#!/usr/bin/env python3
"""
Минимальный BPT — точная копия структуры bp-733.
7 состояний, в каждом только SetStateActivity на следующий (последний пустой).
"""

import os
import random
import zlib

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
BPT_SOURCE = '/mnt/c/Users/Victor/Downloads/bp-600 (2).bpt'


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
    pairs = []
    for i, val in enumerate(items):
        pairs.append((php_int(i), val))
    return php_array(pairs)


def unique_name() -> str:
    return f'A{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}_{random.randint(10000,99999)}'


PERMISSION_8 = php_array([
    (php_s('D'), php_array([])),
    (php_s('E'), php_array([])),
    (php_s('R'), php_array([])),
    (php_s('S'), php_array([])),
    (php_s('T'), php_array([])),
    (php_s('U'), php_array([])),
    (php_s('W'), php_array([])),
    (php_s('X'), php_array([])),
])


def make_set_state(name: str, target: str) -> str:
    parts = [
        (php_s('Type'), php_s('SetStateActivity')),
        (php_s('Name'), php_s(name)),
        (php_s('Activated'), php_s('Y')),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array([
            (php_s('TargetStateName'), php_s(target)),
            (php_s('CancelCurrentState'), php_s('N')),
            (php_s('Title'), php_s('Установить статус')),
            (php_s('EditorComment'), php_s('')),
        ])),
        (php_s('Children'), php_array([])),
    ]
    return php_array(parts)


def make_state(name: str, title: str, init_children: list[str]) -> str:
    state_init_parts = [
        (php_s('Type'), php_s('StateInitializationActivity')),
        (php_s('Name'), php_s(unique_name())),
        (php_s('Activated'), php_s('Y')),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array([
            (php_s('Title'), php_s('Вход в статус')),
        ])),
        (php_s('Children'), php_array_indexed(init_children)),
    ]
    state_init = php_array(state_init_parts)

    parts = [
        (php_s('Type'), php_s('StateActivity')),
        (php_s('Name'), php_s(name)),
        (php_s('Activated'), php_s('Y')),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array([
            (php_s('Permission'), PERMISSION_8),
            (php_s('PermissionMode'), php_s('3')),
            (php_s('PermissionScope'), php_s('1')),
            (php_s('Title'), php_s(title)),
            (php_s('EditorComment'), php_s('')),
        ])),
        (php_s('Children'), php_array_indexed([state_init])),
    ]
    return php_array(parts)


def extract_document_fields(bpt_path: str) -> str:
    with open(bpt_path, 'rb') as f:
        data = f.read()
    text = zlib.decompress(data).decode('utf-8', errors='replace')
    marker = '"DOCUMENT_FIELDS"'
    df_idx = text.find(marker)
    if df_idx < 0:
        raise ValueError('DOCUMENT_FIELDS not found')
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
    raise ValueError('Could not parse DOCUMENT_FIELDS')


def main():
    random.seed(42)

    names = [unique_name() for _ in range(7)]
    titles = [
        'Проверка лида',
        'Загрузка данных',
        'Выбор оператора',
        'Запуск звонка',
        'Ожидание результата',
        'Пауза retry',
        'Завершено',
    ]

    states = []
    for i in range(7):
        if i < 6:
            children = [make_set_state(unique_name(), names[i + 1])]
        else:
            children = []
        states.append(make_state(names[i], titles[i], children))

    template_parts = [
        (php_s('Type'), php_s('StateMachineWorkflowActivity')),
        (php_s('Name'), php_s('Template')),
        (php_s('Activated'), php_s('Y')),
        (php_s('Node'), php_null()),
        (php_s('Properties'), php_array([
            (php_s('Title'), php_s('Обратный звонок по лиду')),
            (php_s('Permission'), PERMISSION_8),
            (php_s('InitialStateName'), php_s(names[0])),
        ])),
        (php_s('Children'), php_array_indexed(states)),
    ]
    template = php_array(template_parts)

    doc_fields_raw = extract_document_fields(BPT_SOURCE)

    top = php_array([
        (php_s('VERSION'), php_int(2)),
        (php_s('TEMPLATE'), php_array_indexed([template])),
        (php_s('PARAMETERS'), php_array([])),
        (php_s('VARIABLES'), php_array([])),
        (php_s('CONSTANTS'), php_array([])),
        (php_s('DOCUMENT_FIELDS'), doc_fields_raw),
    ])

    serialized = top.encode('utf-8')
    compressed = zlib.compress(serialized, 9)

    out = os.path.join(SCRIPT_DIR, 'lead_callback_test.bpt')
    with open(out, 'wb') as f:
        f.write(compressed)

    print(f'Generated: {out}')
    print(f'  Compressed: {len(compressed)} bytes')
    print(f'  Decompressed: {len(serialized)} bytes')
    print(f'  zlib header: {compressed[:2].hex()}')

    text = serialized.decode('utf-8')
    print(f'  StateActivity: {text.count("StateActivity")}')
    print(f'  SetStateActivity: {text.count("SetStateActivity")}')


if __name__ == '__main__':
    main()
