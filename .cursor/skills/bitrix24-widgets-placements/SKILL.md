---
name: bitrix24-widgets-placements
description: Build, register, and debug Bitrix24 widgets/placements (especially user profile). Covers handler patterns (iframe, inline HTML, JS-only loader), local app vs OAuth/REST placement.bind, PlacementTable in box, caching/visibility quirks, and practical diagnostics scripts. Use when the user says "виджет", "placement", "USER_PROFILE_MENU/TOOLBAR", "local app", "widget_handler.php", or when a widget doesn't appear.
---

# Bitrix24 widgets / placements

## What this covers (based on existing projects)

- **Placements**: `USER_PROFILE_MENU`, `USER_PROFILE_TOOLBAR`.
- **Registration**:
  - box: `\Bitrix\Rest\PlacementTable::add/delete/getList`
  - cloud/external: REST `placement.bind`, `placement.unbind`, `placement.get` (+ OAuth/access token)
- **3 handler styles**:
  1) **iframe-style handler** (opens a widget UI)
  2) **inline HTML handler** (returns HTML fragment for embedding into profile column)
  3) **JS-only handler** (returns only JS; JS injects widget into DOM and loads HTML via AJAX)
- **Diagnostics & ops**: scripts to validate registration/path/cache/CRM module.

## Recommended file layout (portal side)

- Typical (bonus widgets pattern):

```
/local/widgets/<widget>/
  widget_handler.php
  profile_widget_handler.php          # inline HTML fragment
  js_component/handler.php            # JS-only handler (optional)
  config.php
  diagnose_widget.php                 # optional but very useful
  check_placements.php                # optional helper
  install_widget.php / uninstall_widget.php  # remove after use
```

## Handler patterns

### 1) iframe-style handler (classic placement widget)

- Used when Bitrix opens your handler as a “widget view”.
- Typically:
  - read `PLACEMENT_OPTIONS` / `USER_ID`
  - load config
  - fetch data (D7 in box, REST if external)
  - render HTML/CSS/JS for the widget

**Security note**: after installation, remove `install_widget.php` / `uninstall_widget.php` if they are publicly accessible.

### 2) Inline HTML fragment (embed into profile column)

- Handler returns an HTML block (no iframe) that matches Bitrix DOM conventions (profile column blocks, etc.).
- Practical input sources:
  - `?USER_ID=...` query param
  - parse user id from URL `/company/personal/user/<id>/` as fallback
- Practical reliability:
  - always include `prolog_before.php`
  - always escape URLs/strings for HTML attributes
  - write lightweight debug logs into `/local/widgets/<widget>/*.log`

### 3) JS-only handler (DOM injection)

- Handler returns only JavaScript (Bitrix injects it for placement).
- JS responsibilities:
  - find profile container (e.g. `.user-profile-card` or similar)
  - call `BX.ajax` to load ready HTML from `profile_widget_handler.php?USER_ID=...`
  - insert HTML into DOM
- Why useful:
  - no iframe
  - can control UI/UX and placement precisely
  - can work in box and cloud (it’s still a placement handler)

## Registration options

### A) Box: PlacementTable (direct DB registration)

- Query:
  - `PlacementTable::getList(['filter' => ['PLACEMENT' => 'USER_PROFILE_MENU', 'PLACEMENT_HANDLER' => $handler]])`
- Add:
  - `PlacementTable::add(['APP_ID'=>0,'PLACEMENT'=>..., 'PLACEMENT_HANDLER'=>$handler, 'TITLE'=>..., 'COMMENT'=>...])`
- Delete old registrations before re-add (avoids duplicates).

### B) Cloud/external: REST placement.bind

- `placement.bind` params: `PLACEMENT`, `HANDLER`, `TITLE`, `DESCRIPTION`, `auth`.
- For management:
  - `placement.get` to list
  - `placement.unbind` to remove

## Debug / troubleshooting checklist (what реально проверяли)

- **1) Handler path mismatch**
  - Most common: registration handler path differs from actual file location.
  - Fix: re-register with correct handler, then clear cache.

- **2) Cache**
  - Required steps: Bitrix cache clear + hard refresh `Ctrl+F5`.

- **3) “Widget doesn’t show in my own profile”**
  - Real-world quirk: in some versions placements behave differently in own profile vs another user’s profile.
  - Verify in another user profile.

- **4) CRM module / rights**
  - For smart-process based widgets: `Loader::includeModule('crm')` must succeed.
  - Access rights for smart process items matter (read permissions).

- **5) Validate init-hook injection (for inline widgets)**
  - Projects used diagnostic checks for:
    - presence of widget code in `/local/php_interface/init.php`
    - existence of loader JS (`/local/templates/<template>/js/...`)
    - existence + accessibility of handler endpoints by URL

## Practical diagnostics scripts (patterns)

- **`diagnose_widget.php`** style:
  - check `init.php` for “signature strings”
  - check loader path(s)
  - check handler/profile-handler existence
  - check config format
  - check `crm` module availability
  - show actionable recommendations (console logs, Network tab, cache clear)

- **`check_placements.php`** style:
  - show current registrations for `USER_PROFILE_MENU` and `USER_PROFILE_TOOLBAR`
  - allow re-register in one click (delete old → add new)

