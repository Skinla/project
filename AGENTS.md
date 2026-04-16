# AGENTS.md

## Cursor Cloud specific instructions

### Project overview

This repo is a mirror of a WSL `/project` directory containing **multiple independent Bitrix24 integration projects** — PHP handlers, Python utilities, and migration scripts for various client portals. There is no monorepo build system, no `package.json`, no `composer.json`, and no `requirements.txt`.

### Locally runnable service

The only locally runnable application is `tms/server.py` — a pure-stdlib Python 3 HTTP server (CRM Metadata viewer) that serves on port **8000** by default.

```bash
cd tms && python3 server.py
```

- **Box data** (`?source=box`) works out of the box using snapshot files in `tms/data/box/`.
- **Cloud data** (`?source=cloud`) requires `VIBECODE_API_KEY` and optionally `VIBECODE_PORTAL` env vars.
- **Mapping view** (`?view=mapping`) compares cloud vs box; requires cloud credentials.

### Linting

- **PHP**: `php -l <file>` — PHP 8.3 CLI is installed in the environment. All 373 PHP files pass syntax check except one pre-existing error in `dreamteamcompany/local/php_interface/handlers/wz_debug_departments.php`.
- **Python**: `python3 -m py_compile <file>` — all Python scripts in `tms/` compile cleanly.

### Testing

- **Box snapshot audit**: `cd tms && python3 scripts/audit_box_snapshot.py --check-orphans --strict`
- **Field title hints verification**: `cd tms && python3 scripts/verify_box_field_title_hints.py --slug companies --max-bad 0`
- **Cloud-box mapping audit** (needs `VIBECODE_API_KEY`): `cd tms && python3 scripts/audit_cloud_box_mapping.py`

### Other PHP projects

All other directories (`dreamteamcompany/`, `milleniummedc/`, `syrtaki/`, `Academy/`, `telephony/`) contain PHP files that run on live Bitrix24 servers (cloud or self-hosted). They cannot be executed locally — they depend on `$_SERVER['DOCUMENT_ROOT']` and the Bitrix CMS prolog. Lint them with `php -l` only.

### No external services needed locally

No Docker, databases, Redis, or message queues are needed. The PHP code runs on remote Bitrix24 servers. Only `tms/server.py` runs locally.
