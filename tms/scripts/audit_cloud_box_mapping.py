#!/usr/bin/env python3
"""
Сводка сопоставления облако ↔ коробка (та же логика, что у /api/entity-mapping).

Нужен VIBECODE_API_KEY и актуальный snapshot в data/box. Удобно после смены портала
или обновления выгрузки коробки (ср. audit/verify-скрипты в milleniummedc).
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[1]
if str(BASE_DIR) not in sys.path:
    sys.path.insert(0, str(BASE_DIR))

from server import (  # noqa: E402
    build_catalog_payload,
    build_field_mapping_rows,
    build_mapping_summary,
    build_option_mapping_rows,
    load_entity_metadata,
    match_mapping_rows,
)


def parse_args():
    p = argparse.ArgumentParser(
        description="CLI-аудит сопоставления метаданных облако / коробка.",
    )
    p.add_argument(
        "--scope",
        choices=("entities", "fields", "all"),
        default="entities",
        help="entities: только сущности; fields: поля по всем СОВПАВШИМ по названию сущностям; "
        "all: поля и списочные значения (тяжёлый режим, много запросов к VibeCode).",
    )
    p.add_argument(
        "--fail-on-unmatched",
        action="store_true",
        help="Код выхода 1, если есть несопоставленные сущности / поля / значения (см. --scope).",
    )
    p.add_argument(
        "--json",
        action="store_true",
        help="Печатать один JSON-объект в stdout вместо текста.",
    )
    return p.parse_args()


def build_entity_rows():
    cloud_catalog = build_catalog_payload("cloud")
    box_catalog = build_catalog_payload("box")
    rows = match_mapping_rows(
        cloud_catalog.get("entities", []),
        box_catalog.get("entities", []),
        label_key="title",
        cloud_code_key="slug",
        box_code_key="slug",
        extra_builder=lambda cloud_items, box_items: {
            "cloudItems": cloud_items,
            "boxItems": box_items,
        },
    )
    return cloud_catalog, box_catalog, rows


def collect_slugs_for_payloads(entity_rows):
    cloud_slugs: set[str] = set()
    box_slugs: set[str] = set()
    for row in entity_rows:
        if not row.get("isMatched"):
            continue
        for item in row.get("cloudItems") or []:
            s = item.get("slug")
            if s:
                cloud_slugs.add(str(s))
        for item in row.get("boxItems") or []:
            s = item.get("slug")
            if s:
                box_slugs.add(str(s))
    return cloud_slugs, box_slugs


def main() -> int:
    args = parse_args()
    if not os.environ.get("VIBECODE_API_KEY"):
        print("Требуется переменная окружения VIBECODE_API_KEY.", file=sys.stderr)
        return 2

    try:
        cloud_catalog, box_catalog, entity_rows = build_entity_rows()
    except Exception as e:
        print(f"Ошибка при загрузке каталогов: {e}", file=sys.stderr)
        return 1

    out: dict = {
        "cloudPortal": cloud_catalog.get("portal"),
        "boxPortal": box_catalog.get("portal"),
        "entity": build_mapping_summary(entity_rows, [], []),
        "entityRowsUnmatched": [
            {
                "name": r["name"],
                "cloudCode": r.get("cloudCode"),
                "boxCode": r.get("boxCode"),
            }
            for r in entity_rows
            if not r.get("isMatched")
        ],
    }

    field_rows: list = []
    option_rows: list = []

    if args.scope in ("fields", "all"):
        matched_rows = [r for r in entity_rows if r.get("isMatched")]
        cloud_slugs, box_slugs = collect_slugs_for_payloads(matched_rows)
        cloud_payloads = {}
        box_payloads = {}
        for slug in sorted(cloud_slugs):
            try:
                cloud_payloads[slug] = load_entity_metadata(slug, "cloud")
            except Exception as e:
                print(f"WARNING: cloud {slug!r}: {e}", file=sys.stderr)
        for slug in sorted(box_slugs):
            try:
                box_payloads[slug] = load_entity_metadata(slug, "box")
            except Exception as e:
                print(f"WARNING: box {slug!r}: {e}", file=sys.stderr)

        field_rows = build_field_mapping_rows(matched_rows, cloud_payloads, box_payloads)
        if args.scope == "all":
            option_rows = build_option_mapping_rows(field_rows)
        summary = build_mapping_summary(entity_rows, field_rows, option_rows)
        out["field"] = {
            "matchedEntityPairs": len(matched_rows),
            "summary": summary,
        }
        out["fieldsUnmatched"] = [
            {
                "entity": r.get("entityName"),
                "name": r.get("name"),
                "cloudCode": r.get("cloudCode"),
                "boxCode": r.get("boxCode"),
            }
            for r in field_rows
            if not r.get("isMatched")
        ]
        if args.scope == "all":
            out["optionsUnmatched"] = [
                {
                    "entity": r.get("entityName"),
                    "field": r.get("fieldName"),
                    "name": r.get("name"),
                    "cloudCode": r.get("cloudCode"),
                    "boxCode": r.get("boxCode"),
                }
                for r in option_rows
                if not r.get("isMatched")
            ]

    if args.json:
        print(json.dumps(out, ensure_ascii=False, indent=2))
    else:
        es = out["entity"]
        print(
            f"Порталы: облако={out['cloudPortal']!r}, коробка={out['boxPortal']!r}\n"
            f"Сущности: всего строк сопоставления {es['entityCount']}, "
            f"без пары: {es['unmatchedEntityCount']}."
        )
        if out["entityRowsUnmatched"] and es["unmatchedEntityCount"] <= 30:
            for line in out["entityRowsUnmatched"]:
                print(f"  — {line['name']!r}: cloud={line['cloudCode']!r} box={line['boxCode']!r}")
        elif out["entityRowsUnmatched"]:
            print(f"  (скрыто: больше 30 строк; используйте --json)")

        if args.scope in ("fields", "all"):
            s = out["field"]["summary"]
            print(
                f"Поля (по совпавшим сущностям): всего {s['fieldCount']}, без пары: {s['unmatchedFieldCount']}."
            )
            if args.scope == "all":
                print(
                    f"Списочные значения: всего {s['optionCount']}, без пары: {s['unmatchedOptionCount']}."
                )

    fail = False
    if args.fail_on_unmatched:
        es = out["entity"]
        if es["unmatchedEntityCount"]:
            fail = True
        if args.scope in ("fields", "all"):
            s = out["field"]["summary"]
            if s["unmatchedFieldCount"]:
                fail = True
            if args.scope == "all" and s["unmatchedOptionCount"]:
                fail = True

    return 1 if fail else 0


if __name__ == "__main__":
    raise SystemExit(main())
