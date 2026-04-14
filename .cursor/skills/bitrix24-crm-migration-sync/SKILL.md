---
name: bitrix24-crm-migration-sync
description: Migrate/synchronize Bitrix24 CRM between portals (cloud↔box or portal↔portal): create/update leads from deals, copy activities/comments/calls, store ID mappings in iblocks/lists, field/stage/responsible mappings, retries/locks, type normalization, and timeline insertion. Use when the user mentions migration, sync, копирование активностей, сделки→лиды, маппинг инфоблоков, webhook_handler.php, or CRest/webhook URLs.
---

# Bitrix24 CRM migration & sync (portal↔portal / cloud↔box)

## Typical use cases (from projects)

- **Create/update lead from deal** (source portal → target portal).
- **Copy history** when moving data:
  - activities (tasks, calls, emails, SMS)
  - comments / timeline notes
  - call recordings (download → upload → link)
- **Prevent duplicates**:
  - lock files / idempotency keys
  - check existing mappings before creating entities

## Architecture pattern

- **Webhook receiver** (`webhook_handler.php`):
  - receives events like deal add/update
  - extracts `deal_id`
  - triggers main sync pipeline (sometimes via `index.php`)

- **Sync engine** (`index.php` + helpers):
  - fetch data from source via REST (webhook/CRest)
  - map fields/stages/responsibles
  - create/update lead in target via REST or internal API (for box)
  - optionally copy activities
  - log everything

## Mapping storage inside Bitrix (box side)

### IBlocks as mapping tables

Common approach: store “old_id → new_id” in a dedicated iblock.

- **Activities ID mapping**:
  - iblock (example): `25`
  - properties:
    - orig id (e.g. `PROPERTY_93`)
    - new id (e.g. `PROPERTY_100`)
    - type (`activity`/`comment`) (e.g. `PROPERTY_113`)

- **Deal↔Lead mapping**:
  - iblock (example): `16`
  - properties:
    - deal id
    - lead id

- **Field mapping / dictionaries**:
  - iblock (example): `23` for dynamic mapping and type info
  - iblock (example): `24` for stage mapping (deal stage → lead status)
  - iblock (example): `22` for “city → fallback responsible”

### Existence checks (idempotency)

Before creating:
- check “orig id exists in mapping” → reuse “new id”
- otherwise create → save mapping

## Field/type normalization

Common types handled:
- **DATETIME**: convert to Bitrix format
- **NUMBER**: normalize to int/float
- **PHONE**: normalize to international format; beware CRM phone storage (`FM`)
- **LIST**: map values via dictionary iblocks/lists
- **TEXT**: copy as-is (sanitize when needed)

## Activities copy (deal → lead)

REST read:
- `crm.activity.list` filter by `OWNER_TYPE_ID/OWNER_ID`

Create (internal API in box):
- `\CCrmActivity::Add($fields, ...)`

Special cases:
- **Calls**: provider `VOXIMPLANT_CALL`
  - handle call recording: download, upload into a known folder, add links into description
- **Tasks / “doings”**: often force `COMPLETED = 'Y'`

## Timeline/comments

If migrating duplicates/status info, add a comment entry to lead timeline:
- D7: `\Bitrix\Crm\Timeline\CommentEntry::create([...])`
- optionally fix binding via `TimelineTable/TimelineBindingTable`

## Reliability/ops

- **Locks**: file-based locks to prevent parallel runs.
- **Retries**: retry REST calls (e.g., up to 3 attempts) with backoff.
- **Logging**:
  - separate logs for activities/calls/comments
  - rotate/trim logs (e.g., max 2MB)
- **Server diagnostics**: a `checkserver.php`-style script to validate:
  - cURL availability
  - file permissions
  - settings correctness
