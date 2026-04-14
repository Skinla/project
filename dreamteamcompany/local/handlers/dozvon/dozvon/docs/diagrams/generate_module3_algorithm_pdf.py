#!/usr/bin/env python3
"""
Строит module3_algorithm.pdf — схемы алгоритма модуля 3 как векторные «рисунки» (matplotlib → PDF).

Запуск:
  cd docs/diagrams && python3 generate_module3_algorithm_pdf.py

Для растрового PNG можно открыть PDF и экспортировать страницу, либо использовать Kroki/mermaid-cli по .mmd файлам.
"""

from __future__ import annotations

from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib import patches
from matplotlib.backends.backend_pdf import PdfPages

plt.rcParams["font.family"] = "DejaVu Sans"

HERE = Path(__file__).resolve().parent
OUT_PDF = HERE / "module3_algorithm.pdf"


def _box(ax, xy, w, h, text, fc="#e8f4fc", ec="#2563eb", fs=8):
    x, y = xy
    r = patches.FancyBboxPatch(
        (x, y),
        w,
        h,
        boxstyle="round,pad=0.015,rounding_size=0.02",
        linewidth=1.1,
        edgecolor=ec,
        facecolor=fc,
    )
    ax.add_patch(r)
    ax.text(x + w / 2, y + h / 2, text, ha="center", va="center", fontsize=fs)


def _arrow(ax, p0, p1, label=None, off=(0, 0)):
    p0 = (p0[0] + off[0], p0[1] + off[1])
    p1 = (p1[0] + off[0], p1[1] + off[1])
    ax.annotate(
        "",
        xy=p1,
        xytext=p0,
        arrowprops=dict(arrowstyle="-|>", color="#334155", lw=1.1, shrinkA=3, shrinkB=3),
    )
    if label:
        ax.text((p0[0] + p1[0]) / 2, (p0[1] + p1[1]) / 2 + 0.025, label, ha="center", fontsize=6, color="#475569")


def page_overview(ax):
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    ax.set_title("Модуль 3 — обзор одного тика БП", fontsize=12, fontweight="bold", pad=14)

    # Top row: decision
    _box(ax, (0.08, 0.58), 0.24, 0.18, "Есть eligible\nready?\n(131 + мастер 130)", fs=8)
    # Left branch: no
    _box(ax, (0.38, 0.72), 0.22, 0.14, "ТЗ п.1:\nplanned → ready", fs=8)
    _box(ax, (0.66, 0.72), 0.28, 0.14, "Стоп: activated /\nno_attempts", fc="#fef3c7", ec="#d97706", fs=8)
    # Right branch: yes — horizontal chain lower
    _box(ax, (0.38, 0.38), 0.14, 0.12, "Город\n22", fs=7)
    _box(ax, (0.54, 0.38), 0.14, 0.12, "Оператор\n128", fs=7)
    _box(ax, (0.70, 0.38), 0.12, 0.12, "Vox", fs=7)
    _box(ax, (0.84, 0.36), 0.14, 0.16, "CRM +\n128", fs=7)

    # Arrows
    _arrow(ax, (0.32, 0.67), (0.38, 0.79), "нет")
    _arrow(ax, (0.60, 0.79), (0.66, 0.79))
    _arrow(ax, (0.20, 0.58), (0.45, 0.50), "да")
    _arrow(ax, (0.52, 0.44), (0.54, 0.44))
    _arrow(ax, (0.68, 0.44), (0.70, 0.44))
    _arrow(ax, (0.82, 0.44), (0.84, 0.44))


def page_queue(ax):
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    ax.set_title("Часть 1 — очередь (список 131, мастер 130)", fontsize=12, fontweight="bold", pad=12)
    body = """Инициализация: модули iblock, voximplant, im; enum статусов 131 и контроля/статуса 130.

A. Выборка STATUS=ready (до 100 записей). Для каждой попытки:
   • SCHEDULED_AT не в будущем
   • есть MASTER_ELEMENT_ID и элемент мастера 130
   • CALLING_CONTROL = active; мастер не completed/cancelled

B. Если подходящих нет:
   • выборка planned по SCHEDULED_AT ASC; те же проверки мастера
   • перевод в ready, UPDATED_AT
   • выход: MODULE3_RESULT = activated или no_attempts (звонка нет)

C. Если список из A не пуст:
   • сортировка: RANZHIROVANIE ↑, CYCLE_DAY ↑, дата создания мастера ↓, SCHEDULED_AT ↑
   • первая попытка; при пустом PHONE → MODULE3_ERROR phone_empty
"""
    ax.text(0.05, 0.92, body, fontsize=9, va="top", linespacing=1.45)


def page_operator(ax):
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    ax.set_title("Часть 2 — операторы (список 128), ТЗ п.4", fontsize=12, fontweight="bold", pad=12)
    body = """После успешного чтения города (список 22: SIP, пароль, RULE_ID):

1. refreshList128Operators(cityId) — актуализация полей элементов 128.

2. Этап 1: все операторы города (PROPERTY_GOROD). Пусто → все элементы 128.

3. Этап 2: рабочий день TimeMan (OPENED или PAUSED) → WORKDAY_STARTED Y/N.
   Если после фильтра пусто и city задан → fallback на всех операторов 128 и повтор этапа 2.

4. Этап 3: среди оставшихся проверка линии Vox (BUSY) → LINE_FREE, STATUS free/busy.

5. Этап 4: выбор одного свободного по round-robin (LAST_ASSIGNED_AT ASC; все пусты → shuffle).

При отсутствии оператора: MODULE3_ERROR no_free_operator.
"""
    ax.text(0.05, 0.92, body, fontsize=9, va="top", linespacing=1.45)


def page_vox(ax):
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    ax.set_title("Часть 3 — Vox и финализация (ТЗ п.5–7)", fontsize=12, fontweight="bold", pad=12)
    body = """• Попытка 131: STATUS operator_calling, OPERATOR_ID, SIP_NUMBER, UPDATED_AT.

• POST StartScenarios: rule_id, script_custom_data (телефон, host, SIP user/pass).

• Ошибка сети / JSON / поля error в ответе:
  откат попытки в ready, MODULE3_ERROR (vox_api_failed, vox_invalid_response и т.д.).

• Успех: CALL_ID, STARTED_AT, VOX_SESSION_ID, URL записи в 131 при наличии.

• CCrmLead::Update: UF номера попытки (LEAD_ID с мастера 130).

• Элемент 128 выбранного оператора: busy, LAST_ASSIGNED_AT, счётчики.

• Опционально: GetCallHistory в журнал слежения.

• Итог: MODULE3_RESULT = call_started, MODULE3_CALL_ID.
"""
    ax.text(0.05, 0.92, body, fontsize=9, va="top", linespacing=1.45)


def page_compact(ax):
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    ax.set_title("Порядок выполнения в одном запуске (сопоставление с ТЗ в шапке bp_module3_process_queue.txt)", fontsize=11, fontweight="bold", pad=10)
    body = """Примечание: в комментарии-ТЗ пункт 1 (planned→ready) стоит первым, в коде он выполняется
только если нет ни одной eligible попытки в ready.

1. Eligible ready + фильтры мастера 130
2. Пусто → ТЗ п.1 planned→ready → стоп без звонка
3. Иначе → ТЗ п.2 сортировка и первая попытка → проверка PHONE
4. ТЗ п.3 список 22
5. ТЗ п.4 список 128 (этапы 1–4 одним логическим блоком)
6. ТЗ п.6А резерв попытки + ТЗ п.5 StartScenarios
7. ТЗ п.6Б–7 запись результатов и UF лида + обновление 128
"""
    ax.text(0.05, 0.90, body, fontsize=9, va="top", linespacing=1.5)


def main():
    figsize = (11.69, 8.27)  # A4 landscape inches

    pages = [
        ("Обзор", page_overview),
        ("Очередь", page_queue),
        ("Операторы", page_operator),
        ("Vox", page_vox),
        ("Порядок / ТЗ", page_compact),
    ]

    with PdfPages(OUT_PDF) as pdf:
        for _title, builder in pages:
            fig, ax = plt.subplots(figsize=figsize)
            builder(ax)
            plt.tight_layout()
            pdf.savefig(fig, dpi=150)
            plt.close(fig)

    print(f"Wrote {OUT_PDF}")


if __name__ == "__main__":
    main()
