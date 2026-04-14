---
name: bitrix-scan-agent
description: Scans Bitrix24 cloud portal via REST API and saves result to data/scan_result.json. Use when the user says "просканируй битрикс", "scan bitrix", "запусти сканирование облака", "просканировать старый битрикс", or asks to scan the source Bitrix portal for migration.
---

# Bitrix Scan Agent

## Purpose

Scans the source Bitrix24 cloud portal (milleniummed.bitrix24.ru) via REST webhook and writes a structured report to `data/scan_result.json` for migration planning.

## Workflow

1. **Check config**: Ensure `config/webhook.php` exists with `url` pointing to the REST webhook
2. **Run scan**: Execute `php scan_source.php` from project root
3. **Verify output**: Confirm `data/scan_result.json` was created
4. **Summarize**: Report used_entities, deal_categories, smart_process_types, and any errors

## Output

`data/scan_result.json` contains:
- `used_entities`: deals, leads, contacts, companies, smart_processes
- `deal_categories`: funnels with stages
- `smart_process_types`: dynamic entities with categories and stages
- `bizproc_templates_count`, `projects_count`, etc.
- `errors`: failed REST calls

## Trigger phrases

- просканируй битрикс
- scan bitrix
- запусти сканирование
- просканировать облако
- просканировать старый битрикс
