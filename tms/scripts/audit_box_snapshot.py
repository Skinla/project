#!/usr/bin/env python3
"""
Проверка целостности локального snapshot коробки (data/box).

Аналог идеи verify-скриптов из milleniummedc: быстрый отчёт перед выкладкой
или после export_box_metadata.py.
"""
from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path


def parse_args():
    p = argparse.ArgumentParser(description="Проверка snapshot коробки в data/box.")
    p.add_argument(
        "--data-dir",
        type=Path,
        default=None,
        help="Каталог с catalog.json и entities/ (по умолчанию <корень проекта>/data/box).",
    )
    p.add_argument(
        "--max-age-hours",
        type=float,
        default=None,
        help="Предупреждение, если generatedAt в catalog.json старше указанного числа часов.",
    )
    p.add_argument(
        "--check-orphans",
        action="store_true",
        help="Сообщить о JSON в entities/, которых нет в catalog.json.",
    )
    p.add_argument(
        "--strict",
        action="store_true",
        help="Код выхода 1 при любой ошибке или предупреждении (--max-age-hours, orphans).",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()
    base = Path(__file__).resolve().parents[1]
    data_dir = args.data_dir or (base / "data" / "box")
    catalog_path = data_dir / "catalog.json"
    entities_dir = data_dir / "entities"

    errors: list[str] = []
    warnings: list[str] = []

    if not catalog_path.is_file():
        errors.append(f"Нет файла каталога: {catalog_path}")
        print("\n".join(errors), file=sys.stderr)
        return 1

    try:
        catalog = json.loads(catalog_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        errors.append(f"Невалидный JSON в {catalog_path}: {e}")
        print("\n".join(errors), file=sys.stderr)
        return 1

    entities = catalog.get("entities")
    if not isinstance(entities, list) or not entities:
        errors.append("В catalog.json отсутствует непустой список entities.")

    generated_at = catalog.get("generatedAt")
    if args.max_age_hours is not None and isinstance(generated_at, str):
        try:
            raw = generated_at.replace("Z", "+00:00")
            ts = datetime.fromisoformat(raw)
            if ts.tzinfo is None:
                ts = ts.replace(tzinfo=timezone.utc)
            age_h = (datetime.now(timezone.utc) - ts).total_seconds() / 3600.0
            if age_h > args.max_age_hours:
                warnings.append(
                    f"Snapshot старше порога: ~{age_h:.1f} ч (лимит {args.max_age_hours} ч), generatedAt={generated_at!r}."
                )
        except ValueError:
            warnings.append(f"Не удалось разобрать generatedAt={generated_at!r} для проверки возраста.")

    catalog_slugs: list[str] = []
    if isinstance(entities, list):
        for i, ent in enumerate(entities):
            if not isinstance(ent, dict):
                errors.append(f"entities[{i}] не объект.")
                continue
            slug = ent.get("slug")
            if not slug:
                errors.append(f"entities[{i}] без slug: {ent!r}")
                continue
            catalog_slugs.append(str(slug))
            path = entities_dir / f"{slug}.json"
            if not path.is_file():
                errors.append(f"Нет файла сущности: {path} (slug из каталога: {slug!r})")
                continue
            try:
                payload = json.loads(path.read_text(encoding="utf-8"))
            except json.JSONDecodeError as e:
                errors.append(f"Невалидный JSON: {path}: {e}")
                continue
            if payload.get("slug") != slug:
                warnings.append(f"{path}: поле slug={payload.get('slug')!r} ожидалось {slug!r}")

    if args.check_orphans:
        if entities_dir.is_dir():
            on_disk = {p.stem for p in entities_dir.glob("*.json")}
            in_catalog = set(catalog_slugs)
            for stem in sorted(on_disk - in_catalog):
                warnings.append(f"Файл без записи в каталоге: {entities_dir / (stem + '.json')}")
        else:
            warnings.append(f"Каталог entities не найден: {entities_dir}")

    for line in warnings:
        print(f"WARNING: {line}", file=sys.stderr)
    for line in errors:
        print(f"ERROR: {line}", file=sys.stderr)

    if not errors:
        print(
            f"OK: каталог {catalog_path}, сущностей в каталоге: {len(catalog_slugs)}, "
            f"portal={catalog.get('portal', '?')!r}, generatedAt={generated_at!r}."
        )

    exit_strict = args.strict and (warnings or errors)
    if errors or exit_strict:
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
