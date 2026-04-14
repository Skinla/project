#!/usr/bin/env python3
import json
import os
import re
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlparse
from urllib.request import Request, urlopen


BASE_DIR = Path(__file__).resolve().parent
STATIC_DIR = BASE_DIR / "static"
INDEX_FILE = STATIC_DIR / "index.html"
DATA_DIR = BASE_DIR / "data"
BOX_DATA_DIR = DATA_DIR / "box"
BOX_ENTITIES_DIR = BOX_DATA_DIR / "entities"
VIBECODE_BASE_URL = "https://vibecode.bitrix24.tech"
BITRIX_MCP_URL = "https://mcp.bitrix24.com/mcp/"
DEFAULT_PORTAL_NAME = os.environ.get("VIBECODE_PORTAL", "Bitrix24")
SOURCE_TITLES = {
    "cloud": "Облако",
    "box": "Коробка",
}
CRM_DOCUMENT_LABELS = {
    "LEAD": "Лид",
    "DEAL": "Сделка",
    "CONTACT": "Контакт",
    "COMPANY": "Компания",
    "QUOTE": "Предложение",
    "SMART_INVOICE": "Счет",
    "INVOICE": "Счет",
}

BASE_ENTITY_CONFIGS = {
    "leads": {
        "entity": "leads",
        "title": "Лиды",
        "subtitle": "Все поля лидов и стадии лидов, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Стадии лидов",
        "stageSubtitle": "Все стадии из CRM-статусов типа STATUS.",
        "stageFilter": {"mode": "exact", "values": ["STATUS"]},
    },
    "deals": {
        "entity": "deals",
        "title": "Сделки",
        "subtitle": "Все поля сделок и все стадии по воронкам сделок, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Стадии сделок",
        "stageSubtitle": "Все стадии из CRM-статусов, относящихся к воронкам сделок.",
        "stageFilter": {"mode": "prefix", "value": "DEAL_STAGE"},
    },
    "invoices": {
        "entity": "invoices",
        "title": "Счета",
        "subtitle": "Все поля счетов, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Статусы счетов",
        "stageSubtitle": "Для этой сущности стадии не найдены через Entity API на текущем портале.",
        "stageFilter": None,
    },
    "contacts": {
        "entity": "contacts",
        "title": "Контакты",
        "subtitle": "Все поля контактов, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Стадии контактов",
        "stageSubtitle": "Контакты не используют стадии.",
        "stageFilter": None,
    },
    "companies": {
        "entity": "companies",
        "title": "Компании",
        "subtitle": "Все поля компаний, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Стадии компаний",
        "stageSubtitle": "Компании не используют стадии.",
        "stageFilter": None,
    },
    "quotes": {
        "entity": "quotes",
        "title": "Предложения",
        "subtitle": "Все поля коммерческих предложений и их стадии, включая пользовательские поля UF_CRM_*.",
        "stageTitle": "Стадии предложений",
        "stageSubtitle": "Все стадии из CRM-статусов типа QUOTE_STATUS.",
        "stageFilter": {"mode": "exact", "values": ["QUOTE_STATUS"]},
    },
}


def parse_source(value):
    source = value or "cloud"
    if source not in SOURCE_TITLES:
        raise ValueError(f"Unknown source '{source}'.")
    return source


def build_entities_list(catalog):
    return [
        {
            "slug": entity_slug,
            "title": entity_config["title"],
            "kind": entity_config["kind"],
        }
        for entity_slug, entity_config in catalog.items()
    ]


def get_default_slug(entities):
    for entity in entities:
        if entity.get("kind") != "bizproc_registry":
            return entity.get("slug")
    return entities[0]["slug"] if entities else "leads"


def read_json_file(path, not_found_message):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as error:
        raise RuntimeError(not_found_message) from error
    except json.JSONDecodeError as error:
        raise RuntimeError(f"Failed to parse JSON file '{path.name}'.") from error


def load_box_catalog_payload():
    payload = read_json_file(
        BOX_DATA_DIR / "catalog.json",
        "Box snapshot not found. Run scripts/export_box_metadata.py first.",
    )
    payload["source"] = "box"
    payload["sourceTitle"] = SOURCE_TITLES["box"]
    payload.setdefault("portal", "Коробка")
    payload.setdefault("entities", [])
    payload.setdefault("defaultSlug", get_default_slug(payload["entities"]))
    return payload


def load_box_entity_payload(slug):
    payload = read_json_file(
        BOX_ENTITIES_DIR / f"{slug}.json",
        f"Box snapshot for entity '{slug}' not found.",
    )
    payload["source"] = "box"
    payload["sourceTitle"] = SOURCE_TITLES["box"]
    fields = payload.get("fields")
    if isinstance(fields, list):
        payload["fields"] = apply_box_field_title_hints(slug, fields)
    return payload


def normalize_label(value, fallback):
    if isinstance(value, str) and value.strip():
        return value
    if isinstance(value, dict):
        nested_title = value.get("title")
        if isinstance(nested_title, str) and nested_title.strip():
            return nested_title
    return fallback


def normalize_option(option_code, option_name):
    return {
        "code": str(option_code),
        "name": option_name or str(option_code),
    }


def extract_field_options(meta, status_options=None):
    raw_items = meta.get("items")
    if isinstance(raw_items, list) and raw_items:
        options = []
        for item in raw_items:
            if not isinstance(item, dict):
                continue
            option_code = item.get("ID", item.get("id", item.get("VALUE")))
            option_name = item.get("VALUE", item.get("value", option_code))
            options.append(normalize_option(option_code, option_name))
        return options

    status_type = meta.get("statusType")
    if status_type and status_options and status_type in status_options:
        return status_options[status_type]

    return []


def is_custom_field(code, meta):
    normalized = str(code).lower()
    return (
        code.startswith("UF_CRM_")
        or normalized.startswith("ufcrm")
        or bool(meta.get("isDynamic"))
    )


def normalize_field(code, meta, status_options=None, extra_options=None):
    title = normalize_label(
        meta.get("formLabel"),
        normalize_label(
            meta.get("listLabel"),
            normalize_label(meta.get("title"), normalize_label(meta.get("label"), code)),
        ),
    )
    options = extract_field_options(meta, status_options=status_options)
    if not options and extra_options and code in extra_options:
        options = extra_options[code]
    return {
        "code": code,
        "title": title,
        "type": meta.get("type", "unknown"),
        "isCustom": is_custom_field(code, meta),
        "isMultiple": bool(meta.get("isMultiple")),
        "isRequired": bool(meta.get("isRequired")),
        "isReadOnly": bool(meta.get("isReadOnly", meta.get("readonly"))),
        "statusType": meta.get("statusType"),
        "options": options,
        "hasOptions": bool(options),
        "raw": meta,
    }


def normalize_stage(item):
    return {
        "id": item.get("id"),
        "entityId": item.get("entityId"),
        "code": item.get("statusId"),
        "name": item.get("name") or item.get("nameInit") or item.get("statusId"),
        "sort": item.get("sort", 0),
        "color": item.get("color") or item.get("EXTRA", {}).get("COLOR"),
        "semantic": item.get("EXTRA", {}).get("SEMANTICS") or item.get("semantics"),
        "isSystem": item.get("SYSTEM") == "Y",
        "raw": item,
    }


def extract_fields(raw_fields):
    nested_fields = raw_fields.get("fields")
    if isinstance(raw_fields, dict) and isinstance(nested_fields, dict):
        return nested_fields
    if isinstance(raw_fields, dict) and isinstance(raw_fields.get("data"), dict):
        return raw_fields["data"]
    return raw_fields


def fetch_json(url, api_key):
    request = Request(url, headers={"X-Api-Key": api_key})
    try:
        with urlopen(request, timeout=30) as response:
            return json.load(response)
    except HTTPError as error:
        details = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"VibeCode HTTP {error.code}: {details}") from error
    except URLError as error:
        raise RuntimeError(f"VibeCode connection error: {error.reason}") from error


def fetch_batch(api_key, calls):
    request = Request(
        f"{VIBECODE_BASE_URL}/v1/batch",
        data=json.dumps({"calls": calls}).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "X-Api-Key": api_key,
        },
        method="POST",
    )

    try:
        with urlopen(request, timeout=30) as response:
            payload = json.load(response)
    except HTTPError as error:
        details = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"VibeCode HTTP {error.code}: {details}") from error
    except URLError as error:
        raise RuntimeError(f"VibeCode connection error: {error.reason}") from error

    return payload.get("data", {}).get("results", {})


def parse_mcp_sse(body):
    for line in body.splitlines():
        if line.startswith("data: "):
            return json.loads(line[6:])
    raise RuntimeError("Bitrix MCP returned an unexpected response format.")


def call_bitrix_mcp_tool(token, tool_name, arguments):
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json, text/event-stream",
        "Authorization": f"Bearer {token}",
    }

    def post(payload, session_id=None):
        request = Request(
            BITRIX_MCP_URL,
            data=json.dumps(payload).encode("utf-8"),
            headers=headers,
            method="POST",
        )
        if session_id:
            request.add_header("mcp-session-id", session_id)

        with urlopen(request, timeout=30) as response:
            return dict(response.headers), response.read().decode("utf-8", "replace")

    try:
        init_headers, _ = post(
            {
                "jsonrpc": "2.0",
                "id": 1,
                "method": "initialize",
                "params": {
                    "protocolVersion": "2024-11-05",
                    "capabilities": {},
                    "clientInfo": {"name": "crm-metadata-app", "version": "1.0"},
                },
            }
        )
        session_id = init_headers.get("mcp-session-id")
        post(
            {
                "jsonrpc": "2.0",
                "method": "notifications/initialized",
                "params": {},
            },
            session_id,
        )
        _, body = post(
            {
                "jsonrpc": "2.0",
                "id": 2,
                "method": "tools/call",
                "params": {"name": tool_name, "arguments": arguments},
            },
            session_id,
        )
    except HTTPError as error:
        details = error.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Bitrix MCP HTTP {error.code}: {details}") from error
    except URLError as error:
        raise RuntimeError(f"Bitrix MCP connection error: {error.reason}") from error

    parsed = parse_mcp_sse(body)
    result = parsed.get("result", {})
    if result.get("isError"):
        raise RuntimeError(f"Bitrix MCP tool '{tool_name}' returned an error.")

    parts = result.get("content", [])
    text = "".join(part.get("text", "") for part in parts if part.get("type") == "text")
    return json.loads(text) if text else []


def fetch_deal_categories():
    token = os.environ.get("BITRIX_MCP_TOKEN")
    if not token:
        return {}

    categories = call_bitrix_mcp_tool(token, "deal_category_list", {})
    return {
        str(item.get("id")): {
            "id": str(item.get("id")),
            "name": item.get("name") or f"Воронка {item.get('id')}",
        }
        for item in categories
    }


def fetch_smart_processes(api_key):
    payload = fetch_json(
        f"{VIBECODE_BASE_URL}/v1/smart-processes?select=id,title,name,entityTypeId",
        api_key,
    )
    return payload.get("data", [])


def build_entity_catalog(api_key):
    catalog = {}
    for slug, config in BASE_ENTITY_CONFIGS.items():
        catalog[slug] = {
            "slug": slug,
            "kind": "base",
            **config,
        }

    smart_processes = sorted(
        fetch_smart_processes(api_key),
        key=lambda item: (
            str(item.get("title", "")).lower(),
            int(item.get("entityTypeId", 0)),
        ),
    )
    for item in smart_processes:
        entity_type_id = item.get("entityTypeId")
        if not entity_type_id:
            continue
        slug = f"sp-{entity_type_id}"
        title = item.get("title") or f"Смарт-процесс {entity_type_id}"
        catalog[slug] = {
            "slug": slug,
            "kind": "smart_process",
            "entity": "items",
            "entityTypeId": int(entity_type_id),
            "smartProcessId": item.get("id"),
            "title": title,
            "subtitle": f'Смарт-процесс "{title}": воронки, стадии, поля и значения списочных полей.',
            "stageTitle": "Воронки и стадии",
            "stageSubtitle": "Если у смарт-процесса включены стадии, они показаны по воронкам.",
            "stageFilter": {
                "mode": "prefix",
                "value": f"DYNAMIC_{int(entity_type_id)}_STAGE",
            },
        }

    catalog["bp-templates"] = {
        "slug": "bp-templates",
        "kind": "bizproc_registry",
        "entity": "bizproc-templates",
        "title": "Шаблоны БП",
        "subtitle": "Реестр шаблонов бизнес-процессов портала и сущностей, где они запускаются.",
        "stageTitle": "",
        "stageSubtitle": "",
        "stageFilter": None,
    }

    return catalog


def filter_stages(raw_statuses, stage_filter):
    if not stage_filter:
        return []

    mode = stage_filter.get("mode")
    if mode == "exact":
        values = set(stage_filter.get("values", []))
        statuses = [item for item in raw_statuses if item.get("entityId") in values]
    elif mode == "prefix":
        prefix = stage_filter.get("value", "")
        statuses = [
            item
            for item in raw_statuses
            if str(item.get("entityId", "")).startswith(prefix)
        ]
    else:
        statuses = []

    stages = [normalize_stage(item) for item in statuses]
    stages.sort(
        key=lambda item: (
            item["entityId"] or "",
            item["sort"],
            item["name"].lower(),
            item["code"] or "",
        )
    )
    return stages


def build_status_options(raw_statuses):
    grouped = {}
    for item in raw_statuses:
        status_type = item.get("entityId")
        if not status_type:
            continue
        grouped.setdefault(status_type, []).append(
            normalize_option(
                item.get("statusId", item.get("id")),
                item.get("name") or item.get("nameInit") or item.get("statusId"),
            )
        )

    for values in grouped.values():
        values.sort(key=lambda item: (item["name"].lower(), item["code"]))

    return grouped


def build_category_options(stage_groups):
    return [
        normalize_option(group.get("code"), group.get("title"))
        for group in stage_groups
        if group.get("code") is not None
    ]


def build_stage_field_options(stages):
    return [
        normalize_option(stage.get("code"), stage.get("name"))
        for stage in stages
        if stage.get("code") is not None
    ]


def build_stage_groups(slug, stages, extra_context=None):
    if not stages:
        return []

    if slug == "deals" or (extra_context or {}).get("groupByCategory"):
        categories = (extra_context or {}).get("dealCategories", {})
        groups = {}
        for stage in stages:
            category_id = str(stage["raw"].get("CATEGORY_ID") or "0")
            entity_id = stage.get("entityId")
            category = categories.get(category_id, {})
            if category.get("name"):
                title = category["name"]
            elif category_id == "0":
                title = "Основная воронка"
            else:
                title = f"Воронка {category_id}"

            group = groups.setdefault(
                category_id,
                {
                    "id": category_id,
                    "title": title,
                    "code": category_id,
                    "entityId": entity_id,
                    "stageCount": 0,
                    "stages": [],
                },
            )
            group["stages"].append(stage)
            group["stageCount"] += 1

        return [
            groups[key]
            for key in sorted(groups, key=lambda value: (int(value), value))
        ]

    return [
        {
            "id": slug,
            "title": "Стадии",
            "code": stages[0].get("entityId"),
            "entityId": stages[0].get("entityId"),
            "stageCount": len(stages),
            "stages": stages,
        }
    ]


def decode_auto_execute(value):
    try:
        numeric = int(value or 0)
    except (TypeError, ValueError):
        numeric = 0

    labels = []
    if numeric & 1:
        labels.append("При создании")
    if numeric & 2:
        labels.append("При изменении")
    if numeric & 4:
        labels.append("При удалении")
    if not labels:
        labels.append("Вручную")

    return {"value": numeric, "labels": labels}


def resolve_document_target(document_type, catalog):
    module_id, entity_name, document_code = (list(document_type or []) + [None, None, None])[:3]
    code = str(document_code or "")
    name = code or "-"

    if code in CRM_DOCUMENT_LABELS:
        name = CRM_DOCUMENT_LABELS[code]
    elif code.startswith("DYNAMIC_"):
        entity_type_id = code.split("_", 1)[1]
        smart_process = catalog.get(f"sp-{entity_type_id}")
        if smart_process:
            name = smart_process["title"]
        else:
            name = f"Смарт-процесс {entity_type_id}"
    elif code.startswith("iblock_"):
        name = f"Список {code}"

    return {
        "moduleId": module_id or "-",
        "entity": entity_name or "-",
        "documentCode": code or "-",
        "name": name,
    }


def fetch_bizproc_templates(api_key):
    payload = fetch_json(
        (
            f"{VIBECODE_BASE_URL}/v1/bizproc-templates"
            "?select=id,name,documentType,moduleId,entity,systemCode,active,autoExecute&limit=500"
        ),
        api_key,
    )
    return payload.get("data", [])


def load_bizproc_registry(config, catalog, api_key):
    templates = []
    targets = set()

    for item in fetch_bizproc_templates(api_key):
        target = resolve_document_target(item.get("documentType"), catalog)
        auto_execute = decode_auto_execute(item.get("autoExecute"))
        targets.add(target["documentCode"])
        templates.append(
            {
                "id": item.get("id"),
                "name": item.get("name") or f'Шаблон #{item.get("id", "?")}',
                "moduleId": item.get("moduleId") or target["moduleId"],
                "entity": item.get("entity") or target["entity"],
                "systemCode": item.get("systemCode"),
                "active": item.get("active"),
                "autoExecute": auto_execute,
                "documentType": item.get("documentType") or [],
                "target": target,
            }
        )

    templates.sort(key=lambda item: (item["target"]["name"].lower(), item["name"].lower(), item["id"] or 0))

    return {
        "slug": config["slug"],
        "kind": config["kind"],
        "entity": config["entity"],
        "title": config["title"],
        "subtitle": config["subtitle"],
        "portal": DEFAULT_PORTAL_NAME,
        "entities": build_entities_list(catalog),
        "source": "cloud",
        "sourceTitle": SOURCE_TITLES["cloud"],
        "templates": templates,
        "summary": {
            "templateCount": len(templates),
            "targetCount": len(targets),
            "autoStartCount": sum(item["autoExecute"]["value"] > 0 for item in templates),
            "manualOnlyCount": sum(item["autoExecute"]["value"] == 0 for item in templates),
        },
    }


def load_cloud_entity_metadata(slug):
    api_key = os.environ.get("VIBECODE_API_KEY")
    if not api_key:
        raise RuntimeError("Environment variable VIBECODE_API_KEY is required.")

    catalog = build_entity_catalog(api_key)
    config = catalog.get(slug)
    if not config:
        raise KeyError(slug)
    if config["kind"] == "bizproc_registry":
        return load_bizproc_registry(config, catalog, api_key)

    statuses_result = fetch_batch(
        api_key,
        [
            {
                "id": "statuses",
                "entity": "statuses",
                "action": "list",
                "params": {"limit": 500, "sort": {"sort": "asc"}},
            }
        ],
    )
    raw_statuses = statuses_result.get("statuses", [])
    status_options = build_status_options(raw_statuses)

    if config["kind"] == "smart_process":
        entity_type_id = config["entityTypeId"]
        fields_payload = fetch_json(
            f"{VIBECODE_BASE_URL}/v1/items/{entity_type_id}/fields",
            api_key,
        )
        detail_payload = fetch_json(
            f"{VIBECODE_BASE_URL}/v1/smart-processes/{entity_type_id}",
            api_key,
        )
        smart_detail = detail_payload.get("data", {})
        config["subtitle"] = (
            f'Смарт-процесс "{config["title"]}": '
            "воронки, стадии, поля и значения списочных полей."
        )
        if not smart_detail.get("isStagesEnabled"):
            config["stageFilter"] = None
            config["stageSubtitle"] = "У этого смарт-процесса стадии отключены."
        elif not smart_detail.get("isCategoriesEnabled"):
            config["stageSubtitle"] = "Стадии смарт-процесса без разделения по воронкам."
        raw_fields = extract_fields(fields_payload.get("data", {}))
    else:
        fields_result = fetch_batch(
            api_key,
            [{"id": "fields", "entity": config["entity"], "action": "fields"}],
        )
        raw_fields = extract_fields(fields_result.get("fields", {}))
        smart_detail = {}

    stages = filter_stages(raw_statuses, config.get("stageFilter"))
    extra_context = {}
    if slug == "deals":
        try:
            extra_context["dealCategories"] = fetch_deal_categories()
        except RuntimeError:
            extra_context["dealCategories"] = {}
    if config["kind"] == "smart_process" and smart_detail.get("isCategoriesEnabled"):
        extra_context["groupByCategory"] = True
    stage_groups = build_stage_groups(slug, stages, extra_context=extra_context)
    extra_options = {}
    if config["kind"] == "smart_process":
        if stage_groups:
            extra_options["categoryId"] = build_category_options(stage_groups)
        if stages:
            stage_options = build_stage_field_options(stages)
            extra_options["stageId"] = stage_options
            extra_options["previousStageId"] = stage_options

    fields = [
        normalize_field(
            code,
            meta,
            status_options=status_options,
            extra_options=extra_options,
        )
        for code, meta in raw_fields.items()
    ]
    fields.sort(key=lambda item: (item["isCustom"], item["title"].lower(), item["code"]))

    custom_fields = [field for field in fields if field["isCustom"]]
    option_fields = [field for field in fields if field["hasOptions"]]

    return {
        "slug": slug,
        "kind": config["kind"],
        "entity": config["entity"],
        "title": config["title"],
        "subtitle": config["subtitle"],
        "stageTitle": config["stageTitle"],
        "stageSubtitle": config["stageSubtitle"],
        "portal": DEFAULT_PORTAL_NAME,
        "entities": build_entities_list(catalog),
        "source": "cloud",
        "sourceTitle": SOURCE_TITLES["cloud"],
        "stageGroups": stage_groups,
        "stages": stages,
        "fields": fields,
        "summary": {
            "stageCount": len(stages),
            "pipelineCount": len(stage_groups),
            "fieldCount": len(fields),
            "customFieldCount": len(custom_fields),
            "optionFieldCount": len(option_fields),
        },
    }


def build_catalog_payload(source):
    if source == "cloud":
        api_key = os.environ.get("VIBECODE_API_KEY")
        if not api_key:
            raise RuntimeError("Environment variable VIBECODE_API_KEY is required.")
        catalog = build_entity_catalog(api_key)
        entities = build_entities_list(catalog)
        return {
            "source": "cloud",
            "sourceTitle": SOURCE_TITLES["cloud"],
            "portal": DEFAULT_PORTAL_NAME,
            "entities": entities,
            "defaultSlug": get_default_slug(entities),
        }

    return load_box_catalog_payload()


def load_entity_metadata(slug, source="cloud"):
    if source == "cloud":
        return load_cloud_entity_metadata(slug)

    catalog_payload = load_box_catalog_payload()
    known_slugs = {entity.get("slug") for entity in catalog_payload["entities"]}
    if slug not in known_slugs:
        raise KeyError(slug)

    payload = load_box_entity_payload(slug)
    payload.setdefault("portal", catalog_payload["portal"])
    payload.setdefault("entities", catalog_payload["entities"])
    return payload


def normalize_mapping_name(value):
    text = str(value or "").strip().lower().replace("ё", "е")
    text = re.sub(r"^\++\s*", "", text)
    text = re.sub(r"\([^)]*\)", " ", text)
    text = re.sub(r"[^0-9a-zа-я]+", " ", text)
    tokens = []
    for token in text.split():
        normalized = normalize_mapping_token(token)
        if normalized:
            tokens.append(normalized)
    return " ".join(tokens)


def normalize_mapping_token(token):
    if len(token) <= 4:
        return token

    suffixes = (
        "иями",
        "ями",
        "ами",
        "иях",
        "ах",
        "ях",
        "ого",
        "ему",
        "ому",
        "ыми",
        "ими",
        "ов",
        "ев",
        "ей",
        "ам",
        "ям",
        "ом",
        "ем",
        "ую",
        "ая",
        "яя",
        "ое",
        "ее",
        "ы",
        "и",
        "а",
        "я",
    )
    for suffix in suffixes:
        if token.endswith(suffix) and len(token) - len(suffix) >= 4:
            return token[: -len(suffix)]
    return token


def normalize_mapping_code(value):
    text = str(value or "").strip()
    if not text:
        return ""
    text = re.sub(r"([a-z0-9])([A-Z])", r"\1 \2", text)
    text = re.sub(r"[_\-]+", " ", text)
    return normalize_mapping_name(text)


def canonical_bitrix_field_code(value):
    """
    Единый ключ для сопоставления кода поля облако ↔ коробка.

    VibeCode отдаёт поля в camelCase без подчёркивания перед цифрами (address2),
    коробка — классический Bitrix UPPER_SNAKE (ADDRESS_2). Только normalize_mapping_code
    даёт 'address2' и 'address 2' без пересечения — пары не находятся.

    Аналог первого шага в milleniummedc build_field_mapping.php: совпадение по коду поля.
    """
    text = str(value or "").strip()
    if not text:
        return ""
    s = text.replace("-", "_")
    s = re.sub(r"([a-z0-9])([A-Z])", r"\1_\2", s)
    s = re.sub(r"([a-zA-Z])(\d)", r"\1_\2", s)
    s = re.sub(r"(\d)([a-zA-Z])", r"\1_\2", s)
    s = s.upper()
    s = re.sub(r"[^A-Z0-9]+", "_", s)
    return re.sub(r"_+", "_", s).strip("_")


_field_title_hints_ru_payload = None


def load_field_title_hints_ru():
    """
    Локальные подписи для штатных полей коробки, если в snapshot title = коду
    (нет SSH к коробке / старый выгруз). Файл data/field_title_hints_ru.json опционален.
    """
    global _field_title_hints_ru_payload
    if _field_title_hints_ru_payload is not None:
        return _field_title_hints_ru_payload
    path = DATA_DIR / "field_title_hints_ru.json"
    try:
        _field_title_hints_ru_payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        _field_title_hints_ru_payload = {}
    return _field_title_hints_ru_payload


def field_title_is_technical_only(title, code):
    if not str(code or "").strip():
        return False
    t = str(title or "").strip()
    if not t:
        return True
    # Подпись с кириллицией не считаем «техническим дублем кода» (иначе
    # canonical обрезает латиницу из скобок и совпадает с UTM_* и т.п.).
    if re.search(r"[\u0400-\u04FF]", t):
        return False
    return canonical_bitrix_field_code(t) == canonical_bitrix_field_code(code)


def apply_box_field_title_hints(slug, fields):
    hints = load_field_title_hints_ru().get(slug)
    if not hints or not fields:
        return fields
    out = []
    for item in fields:
        item = dict(item)
        code = str(item.get("code") or "")
        hint = hints.get(code)
        if hint and field_title_is_technical_only(item.get("title"), code):
            item["title"] = hint
        out.append(item)
    return out


def join_mapping_codes(items, key):
    values = []
    for item in items:
        value = item.get(key)
        if value is None:
            continue
        text = str(value)
        if text and text not in values:
            values.append(text)
    return ", ".join(values)


def _mapping_label_equals_code(label, code):
    if not label or not code:
        return False
    return canonical_bitrix_field_code(label) == canonical_bitrix_field_code(code)


def mapping_item_display_label(item, label_key, code_key):
    """
    Подпись для колонки «Название» в сопоставлении: не дублировать сырой код поля,
    если в коробке/облаке есть человекочитаемая подпись (в т.ч. в raw / LABELS).
    """
    code = str(item.get(code_key) or "").strip()
    label = str(item.get(label_key) or "").strip()
    if label and code and _mapping_label_equals_code(label, code):
        label = ""
    raw = item.get("raw")
    if isinstance(raw, dict):
        for k in (
            "EDIT_FORM_LABEL",
            "LIST_COLUMN_TITLE",
            "editFormLabel",
            "listColumnTitle",
            "formLabel",
            "listLabel",
        ):
            v = raw.get(k)
            if isinstance(v, str) and v.strip():
                t = v.strip()
                if not code or not _mapping_label_equals_code(t, code):
                    return t
        labels = raw.get("LABELS") or raw.get("labels")
        if isinstance(labels, dict):
            for pref in ("ru", "RU", "en", "EN", "de", "DE", "default", "DEFAULT"):
                block = labels.get(pref)
                if isinstance(block, dict):
                    for k in ("LIST_COLUMN_TITLE", "EDIT_FORM_LABEL"):
                        v = block.get(k)
                        if isinstance(v, str) and v.strip():
                            t = v.strip()
                            if not code or not _mapping_label_equals_code(t, code):
                                return t
            for block in labels.values():
                if isinstance(block, dict):
                    for k in ("LIST_COLUMN_TITLE", "EDIT_FORM_LABEL"):
                        v = block.get(k)
                        if isinstance(v, str) and v.strip():
                            t = v.strip()
                            if not code or not _mapping_label_equals_code(t, code):
                                return t
    if label:
        return label
    return code or ""


def mapping_row_display_name(cloud_items, box_items, fallback, label_key, cloud_code_key, box_code_key):
    for item in cloud_items or []:
        t = mapping_item_display_label(item, label_key, cloud_code_key)
        if t:
            return t
    for item in box_items or []:
        t = mapping_item_display_label(item, label_key, box_code_key)
        if t:
            return t
    return fallback or ""


def build_mapping_aliases(item, label_key, code_key):
    aliases = set()
    label_alias = normalize_mapping_name(item.get(label_key))
    code_alias = normalize_mapping_code(item.get(code_key))
    if label_alias:
        aliases.add(label_alias)
    if code_alias:
        aliases.add(code_alias)
    code_canon = canonical_bitrix_field_code(item.get(code_key))
    if code_canon:
        aliases.add(code_canon)
    return aliases


def build_mapping_index(items, label_key, code_key):
    groups = {}
    for item in items:
        label = str(item.get(label_key) or "").strip()
        normalized_label = normalize_mapping_name(label)
        normalized_code = normalize_mapping_code(item.get(code_key))
        primary_key = normalized_label or normalized_code
        if not primary_key:
            continue
        groups.setdefault(
            primary_key,
            {"label": label, "items": [], "aliases": set()},
        )
        groups[primary_key]["items"].append(item)
        groups[primary_key]["aliases"].update(build_mapping_aliases(item, label_key, code_key))
    return groups


def match_mapping_rows(cloud_items, box_items, label_key, cloud_code_key, box_code_key, extra_builder=None):
    cloud_index = build_mapping_index(cloud_items, label_key, cloud_code_key)
    box_index = build_mapping_index(box_items, label_key, box_code_key)
    rows = []
    unmatched_box_keys = set(box_index.keys())

    for cloud_key in sorted(cloud_index):
        cloud_group = cloud_index[cloud_key]
        matched_box_key = next(
            (
                box_key
                for box_key in unmatched_box_keys
                if cloud_group["aliases"] & box_index[box_key]["aliases"]
            ),
            None,
        )
        box_group = (
            box_index[matched_box_key]
            if matched_box_key is not None
            else {"label": "", "items": [], "aliases": set()}
        )
        if matched_box_key is not None:
            unmatched_box_keys.remove(matched_box_key)
        row = {
            "name": mapping_row_display_name(
                cloud_group["items"],
                box_group["items"],
                cloud_group["label"] or box_group["label"],
                label_key,
                cloud_code_key,
                box_code_key,
            ),
            "cloudCode": join_mapping_codes(cloud_group["items"], cloud_code_key),
            "boxCode": join_mapping_codes(box_group["items"], box_code_key),
            "isMatched": bool(cloud_group["items"]) and bool(box_group["items"]),
        }
        if extra_builder:
            row.update(extra_builder(cloud_group["items"], box_group["items"]))
        rows.append(row)

    for box_key in sorted(unmatched_box_keys):
        box_group = box_index[box_key]
        row = {
            "name": mapping_row_display_name(
                [],
                box_group["items"],
                box_group["label"],
                label_key,
                cloud_code_key,
                box_code_key,
            ),
            "cloudCode": "",
            "boxCode": join_mapping_codes(box_group["items"], box_code_key),
            "isMatched": False,
        }
        if extra_builder:
            row.update(extra_builder([], box_group["items"]))
        rows.append(row)

    rows.sort(
        key=lambda item: (
            item["isMatched"],
            normalize_mapping_name(item["name"]),
            item["cloudCode"],
            item["boxCode"],
        )
    )
    return rows


def build_entity_mapping_rows(cloud_catalog, box_catalog):
    return match_mapping_rows(
        cloud_catalog.get("entities", []),
        box_catalog.get("entities", []),
        label_key="title",
        cloud_code_key="slug",
        box_code_key="slug",
    )


def partition_matches_by_canonical_code(
    cloud_items, box_items, cloud_code_key, box_code_key, extra_builder, label_key="title"
):
    """
    Сначала пары с одинаковым canonical_bitrix_field_code (как в milleniummedc по коду поля),
    затем остаток отдаётся match_mapping_rows по названию/алиасам.

    Иначе жадный порядок cloud-ключей и общие токены в названиях («Адрес», ADDRESS_*)
    оставляют штатные поля без пары, хотя коды на самом деле те же.
    """
    cloud_list = list(cloud_items or [])
    box_list = list(box_items or [])
    cloud_used = [False] * len(cloud_list)
    box_used = [False] * len(box_list)
    pre_rows = []
    cloud_canon = [
        canonical_bitrix_field_code(cloud_list[i].get(cloud_code_key)) for i in range(len(cloud_list))
    ]
    box_canon = [canonical_bitrix_field_code(box_list[i].get(box_code_key)) for i in range(len(box_list))]

    for key in sorted(set(k for k in cloud_canon if k) & set(k for k in box_canon if k)):
        c_idxs = [i for i, c in enumerate(cloud_canon) if c == key and not cloud_used[i]]
        b_idxs = [i for i, c in enumerate(box_canon) if c == key and not box_used[i]]
        for j in range(min(len(c_idxs), len(b_idxs))):
            ci, bi = c_idxs[j], b_idxs[j]
            cloud_used[ci] = True
            box_used[bi] = True
            c_item = cloud_list[ci]
            b_item = box_list[bi]
            name = mapping_row_display_name(
                [c_item],
                [b_item],
                str(
                    c_item.get(label_key)
                    or b_item.get(label_key)
                    or c_item.get(cloud_code_key)
                    or b_item.get(box_code_key)
                    or ""
                ),
                label_key,
                cloud_code_key,
                box_code_key,
            )
            row = {
                "name": name,
                "cloudCode": join_mapping_codes([c_item], cloud_code_key),
                "boxCode": join_mapping_codes([b_item], box_code_key),
                "isMatched": True,
            }
            if extra_builder:
                row.update(extra_builder([c_item], [b_item]))
            pre_rows.append(row)

    rest_cloud = [cloud_list[i] for i in range(len(cloud_list)) if not cloud_used[i]]
    rest_box = [box_list[i] for i in range(len(box_list)) if not box_used[i]]
    return pre_rows, rest_cloud, rest_box


def build_field_mapping_rows(entity_rows, cloud_payloads, box_payloads):
    rows = []
    for entity_row in entity_rows:
        cloud_entities = entity_row.get("cloudItems", [])
        box_entities = entity_row.get("boxItems", [])
        cloud_fields = []
        box_fields = []

        for entity in cloud_entities:
            payload = cloud_payloads.get(entity.get("slug"))
            if payload:
                cloud_fields.extend(payload.get("fields", []))
        for entity in box_entities:
            payload = box_payloads.get(entity.get("slug"))
            if payload:
                box_fields.extend(payload.get("fields", []))

        extra = lambda cloud_items, box_items, entity_row=entity_row: {
            "entityName": entity_row["name"],
            "entityCloudCode": entity_row["cloudCode"],
            "entityBoxCode": entity_row["boxCode"],
            "cloudItems": cloud_items,
            "boxItems": box_items,
        }
        pre_rows, rest_cloud, rest_box = partition_matches_by_canonical_code(
            cloud_fields,
            box_fields,
            "code",
            "code",
            extra,
            label_key="title",
        )
        rest_rows = match_mapping_rows(
            rest_cloud,
            rest_box,
            label_key="title",
            cloud_code_key="code",
            box_code_key="code",
            extra_builder=extra,
        )
        field_rows = pre_rows + rest_rows
        field_rows.sort(
            key=lambda item: (
                item["isMatched"],
                normalize_mapping_name(item["name"]),
                item["cloudCode"],
                item["boxCode"],
            )
        )
        rows.extend(field_rows)

    return rows


def build_option_mapping_rows(field_rows):
    rows = []
    for field_row in field_rows:
        cloud_options = []
        box_options = []
        for field in field_row.get("cloudItems", []):
            cloud_options.extend(field.get("options", []))
        for field in field_row.get("boxItems", []):
            box_options.extend(field.get("options", []))

        opt_extra = lambda cloud_items, box_items, field_row=field_row: {
            "entityName": field_row["entityName"],
            "entityCloudCode": field_row["entityCloudCode"],
            "entityBoxCode": field_row["entityBoxCode"],
            "fieldName": field_row["name"],
            "fieldCloudCode": field_row["cloudCode"],
            "fieldBoxCode": field_row["boxCode"],
            "cloudItems": cloud_items,
            "boxItems": box_items,
        }
        pre_opt, rest_c_opt, rest_b_opt = partition_matches_by_canonical_code(
            cloud_options,
            box_options,
            "code",
            "code",
            opt_extra,
            label_key="name",
        )
        rest_opt = match_mapping_rows(
            rest_c_opt,
            rest_b_opt,
            label_key="name",
            cloud_code_key="code",
            box_code_key="code",
            extra_builder=opt_extra,
        )
        option_rows = pre_opt + rest_opt
        option_rows.sort(
            key=lambda item: (
                item["isMatched"],
                normalize_mapping_name(item["name"]),
                item["cloudCode"],
                item["boxCode"],
            )
        )
        rows.extend(option_rows)

    return rows


def build_mapping_summary(entity_rows, field_rows, option_rows):
    return {
        "entityCount": len(entity_rows),
        "unmatchedEntityCount": sum(not row["isMatched"] for row in entity_rows),
        "fieldCount": len(field_rows),
        "unmatchedFieldCount": sum(not row["isMatched"] for row in field_rows),
        "optionCount": len(option_rows),
        "unmatchedOptionCount": sum(not row["isMatched"] for row in option_rows),
    }


def build_mapping_navigation_entities(entity_rows):
    entities = []
    for row in entity_rows:
        source_items = row.get("cloudItems") or row.get("boxItems") or []
        if not source_items:
            continue
        sample = source_items[0]
        slug = sample.get("slug")
        kind = sample.get("kind", "base")
        if not slug:
            continue
        entities.append(
            {
                "slug": slug,
                "title": row["name"],
                "kind": kind,
            }
        )
    return entities


def find_selected_mapping_row(entity_rows, slug):
    if slug:
        for row in entity_rows:
            cloud_slugs = {item.get("slug") for item in row.get("cloudItems", [])}
            box_slugs = {item.get("slug") for item in row.get("boxItems", [])}
            if slug in cloud_slugs or slug in box_slugs:
                return row

    for row in entity_rows:
        if row.get("cloudItems") or row.get("boxItems"):
            return row
    return None


def get_mapping_row_kind(row):
    source_items = (row or {}).get("cloudItems") or (row or {}).get("boxItems") or []
    if not source_items:
        return "base"
    return source_items[0].get("kind", "base")


def build_entity_mapping_payload(slug=None):
    cloud_catalog = build_catalog_payload("cloud")
    box_catalog = build_catalog_payload("box")
    cloud_payloads = {
        entity["slug"]: load_entity_metadata(entity["slug"], source="cloud")
        for entity in cloud_catalog.get("entities", [])
    }
    box_payloads = {
        entity["slug"]: load_entity_metadata(entity["slug"], source="box")
        for entity in box_catalog.get("entities", [])
    }

    entity_rows = match_mapping_rows(
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
    navigation_entities = build_mapping_navigation_entities(entity_rows)
    selected_row = find_selected_mapping_row(entity_rows, slug)
    selected_entity_rows = [selected_row] if selected_row else []
    field_rows = build_field_mapping_rows(selected_entity_rows, cloud_payloads, box_payloads)
    option_rows = build_option_mapping_rows(field_rows)

    for row in selected_entity_rows:
        row.pop("cloudItems", None)
        row.pop("boxItems", None)
    for row in field_rows:
        row.pop("cloudItems", None)
        row.pop("boxItems", None)
    for row in option_rows:
        row.pop("cloudItems", None)
        row.pop("boxItems", None)

    return {
        "title": "Сопоставление",
        "subtitle": (
            f"Сопоставление по названию между порталами "
            f"{cloud_catalog.get('portal')} и {box_catalog.get('portal')}."
        ),
        "slug": (
            (selected_row.get("cloudCode") or selected_row.get("boxCode") or "").split(", ")[0]
            if selected_row
            else (navigation_entities[0]["slug"] if navigation_entities else "leads")
        ),
        "kind": get_mapping_row_kind(selected_row),
        "cloudPortal": cloud_catalog.get("portal"),
        "boxPortal": box_catalog.get("portal"),
        "entities": navigation_entities,
        "entityRows": selected_entity_rows,
        "fields": field_rows,
        "options": option_rows,
        "summary": build_mapping_summary(selected_entity_rows, field_rows, option_rows),
    }


class AppHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urlparse(self.path)

        if parsed.path == "/api/entity-mapping":
            self.serve_entity_mapping(parsed.query)
            return

        if parsed.path == "/api/entity-catalog":
            self.serve_entity_catalog(parsed.query)
            return

        if parsed.path == "/api/entity-meta":
            self.serve_entity_meta(parsed.query)
            return

        if parsed.path == "/" or (
            not parsed.path.startswith("/api/")
            and "." not in parsed.path.rsplit("/", 1)[-1]
        ):
            self.serve_index()
            return

        self.send_error(HTTPStatus.NOT_FOUND, "Not found")

    def serve_index(self):
        try:
            content = INDEX_FILE.read_bytes()
        except FileNotFoundError:
            self.send_error(HTTPStatus.NOT_FOUND, "index.html not found")
            return

        self.send_response(HTTPStatus.OK)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def serve_entity_meta(self, query):
        params = parse_qs(query)
        slug = params.get("entity", ["leads"])[0]

        try:
            source = parse_source(params.get("source", ["cloud"])[0])
            payload = load_entity_metadata(slug, source=source)
            self.send_json(HTTPStatus.OK, payload)
        except KeyError:
            self.send_json(
                HTTPStatus.BAD_REQUEST,
                {"error": f"Unknown entity '{slug}' for source '{source}'."},
            )
        except RuntimeError as error:
            self.send_json(
                HTTPStatus.BAD_GATEWAY,
                {"error": str(error)},
            )
        except ValueError as error:
            self.send_json(
                HTTPStatus.BAD_REQUEST,
                {"error": str(error)},
            )

    def serve_entity_catalog(self, query):
        params = parse_qs(query)
        try:
            source = parse_source(params.get("source", ["cloud"])[0])
            payload = build_catalog_payload(source)
            self.send_json(HTTPStatus.OK, payload)
        except RuntimeError as error:
            self.send_json(
                HTTPStatus.BAD_GATEWAY,
                {"error": str(error)},
            )
        except ValueError as error:
            self.send_json(
                HTTPStatus.BAD_REQUEST,
                {"error": str(error)},
            )

    def serve_entity_mapping(self, query=""):
        try:
            params = parse_qs(query)
            slug = params.get("entity", [None])[0]
            payload = build_entity_mapping_payload(slug=slug)
            self.send_json(HTTPStatus.OK, payload)
        except RuntimeError as error:
            self.send_json(
                HTTPStatus.BAD_GATEWAY,
                {"error": str(error)},
            )

    def send_json(self, status, payload):
        content = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(content)))
        self.end_headers()
        self.wfile.write(content)

    def log_message(self, format, *args):
        return


def main():
    port = int(os.environ.get("PORT", "8000"))
    server = ThreadingHTTPServer(("0.0.0.0", port), AppHandler)
    print(f"CRM metadata app is running on http://localhost:{port}")
    server.serve_forever()


if __name__ == "__main__":
    main()
