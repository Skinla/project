#!/usr/bin/env python3
"""
Проверка: для коробки после apply_box_field_title_hints у штатных полей
не остаётся title, совпадающего с кодом (кроме явных исключений).

Запуск из корня репозитория: python3 scripts/verify_box_field_title_hints.py
Код выхода 0 при прохождении порога, 1 при провале.
"""
from __future__ import annotations

import argparse
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[1]
if str(BASE_DIR) not in sys.path:
    sys.path.insert(0, str(BASE_DIR))

from server import (  # noqa: E402
    apply_box_field_title_hints,
    field_title_is_technical_only,
    load_box_entity_payload,
)


def parse_args():
    p = argparse.ArgumentParser()
    p.add_argument(
        "--slug",
        default="companies",
        help="Сущность snapshot коробки (по умолчанию companies).",
    )
    p.add_argument(
        "--max-bad",
        type=int,
        default=5,
        help="Максимум штатных полей, у которых title всё ещё совпадает с кодом.",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()
    payload = load_box_entity_payload(args.slug)
    fields = payload.get("fields") or []
    bad = []
    for f in fields:
        if f.get("isCustom"):
            continue
        code = str(f.get("code") or "")
        title = f.get("title")
        if field_title_is_technical_only(title, code):
            bad.append(code)
    if len(bad) > args.max_bad:
        print(f"FAIL: {len(bad)} штатных полей с title=код (лимит {args.max_bad}):", ", ".join(bad[:40]))
        return 1
    print(f"OK: slug={args.slug!r}, штатных полей с title=код: {len(bad)} (лимит {args.max_bad}).")
    if bad:
        print("Оставшиеся:", ", ".join(bad))
    sample = next((x for x in fields if x.get("code") == "ADDRESS_2"), None)
    if sample:
        print(f"Пример ADDRESS_2 title={sample.get('title')!r}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
