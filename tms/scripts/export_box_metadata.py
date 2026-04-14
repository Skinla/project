#!/usr/bin/env python3
import argparse
import json
import shutil
import subprocess
import sys
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parents[1]
if str(BASE_DIR) not in sys.path:
    sys.path.insert(0, str(BASE_DIR))

from server import (
    BASE_ENTITY_CONFIGS,
    apply_box_field_title_hints,
    build_category_options,
    build_stage_field_options,
    build_stage_groups,
    build_status_options,
    decode_auto_execute,
    normalize_field,
    normalize_stage,
    resolve_document_target,
)

DEFAULT_OUTPUT_DIR = BASE_DIR / "data" / "box"
DEFAULT_DOCROOT = "/home/bitrix/www"
DEFAULT_HOST = "bitrix.tms.net.ru"
DEFAULT_USER = "root"
SMART_INVOICE_ENTITY_TYPE_ID = 31

REMOTE_EXPORT_SCRIPT = r"""<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);
define("BX_NO_ACCELERATOR_RESET", true);
define("CHK_EVENT", true);
$_SERVER["DOCUMENT_ROOT"] = __DOCROOT__;
require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";

if (!CModule::IncludeModule("crm"))
{
    fwrite(STDERR, "Failed to include crm module\n");
    exit(1);
}
if (!CModule::IncludeModule("bizproc"))
{
    fwrite(STDERR, "Failed to include bizproc module\n");
    exit(1);
}

function isTruthy($value)
{
    return in_array($value, ["Y", "1", 1, true], true);
}

function getFactoryOrFail($entityTypeId)
{
    $factory = \Bitrix\Crm\Service\Container::getInstance()->getFactory($entityTypeId);
    if (!$factory)
    {
        throw new \RuntimeException("Factory not found for entity type " . $entityTypeId);
    }

    return $factory;
}

function crmStandardFieldCaptionFromLang($ownerTypeId, $fieldCode)
{
    if (!class_exists("CCrmOwnerType"))
    {
        return "";
    }
    $ownerTypeId = (int)$ownerTypeId;
    $fieldCode = (string)$fieldCode;
    if ($fieldCode === "")
    {
        return "";
    }
    $prefixByType = [
        CCrmOwnerType::Lead => "CRM_LEAD_FIELD_",
        CCrmOwnerType::Deal => "CRM_DEAL_FIELD_",
        CCrmOwnerType::Contact => "CRM_CONTACT_FIELD_",
        CCrmOwnerType::Company => "CRM_COMPANY_FIELD_",
        CCrmOwnerType::Quote => "CRM_QUOTE_FIELD_",
    ];
    $prefix = $prefixByType[$ownerTypeId] ?? "";
    if ($prefix === "")
    {
        return "";
    }
    $msg = \Bitrix\Main\Localization\Loc::getMessage($prefix . $fieldCode);
    if (!is_string($msg))
    {
        return "";
    }
    $msg = trim($msg);

    return $msg !== "" ? $msg : "";
}

function enrichFieldsWithCaptions($fields, $factory, $ownerTypeId = null)
{
    foreach ($fields as $code => &$field)
    {
        if (!is_array($field))
        {
            continue;
        }

        $caption = "";
        if ($factory && method_exists($factory, "getFieldCaption"))
        {
            $caption = trim((string)$factory->getFieldCaption($code));
        }
        if ($caption === "" && $ownerTypeId !== null)
        {
            $caption = crmStandardFieldCaptionFromLang($ownerTypeId, (string)$code);
        }

        $title = trim((string)($field["TITLE"] ?? ""));

        if ($caption !== "")
        {
            $field["TITLE"] = $caption;
        }
        elseif ($title === "")
        {
            $field["TITLE"] = (string)$code;
        }
    }
    unset($field);

    return $fields;
}

function collectUserFields($factory)
{
    $rows = [];
    $infoByCode = $factory->getUserFieldsInfo();
    $userFieldEntityId = $factory->getUserFieldEntityId();
    $result = CUserTypeEntity::GetList([], ["ENTITY_ID" => $userFieldEntityId]);

    while ($row = $result->Fetch())
    {
        $code = $row["FIELD_NAME"];
        $info = $infoByCode[$code] ?? [];
        $row["TITLE"] = $info["TITLE"] ?? $code;
        $row["LABELS"] = $info["LABELS"] ?? [];
        $row["USER_TYPE"] = $info["USER_TYPE"] ?? [];
        $row["TYPE"] = $info["TYPE"] ?? $row["USER_TYPE_ID"];
        $row["ENUM_ITEMS"] = [];

        if (($row["USER_TYPE_ID"] ?? "") === "enumeration")
        {
            $enum = new CUserFieldEnum();
            $enumResult = $enum->GetList(["SORT" => "ASC", "ID" => "ASC"], ["USER_FIELD_ID" => $row["ID"]]);
            while ($item = $enumResult->Fetch())
            {
                $row["ENUM_ITEMS"][] = [
                    "ID" => $item["ID"],
                    "VALUE" => $item["VALUE"],
                    "SORT" => $item["SORT"],
                    "DEF" => $item["DEF"],
                ];
            }
        }

        $rows[] = $row;
    }

    return $rows;
}

function collectStatuses()
{
    $items = [];
    $result = CCrmStatus::GetList(["SORT" => "ASC", "STATUS_ID" => "ASC"], ["CHECK_PERMISSIONS" => "N"]);
    while ($row = $result->Fetch())
    {
        $items[] = [
            "id" => $row["ID"] ?? null,
            "entityId" => $row["ENTITY_ID"] ?? null,
            "statusId" => $row["STATUS_ID"] ?? null,
            "name" => $row["NAME"] ?? null,
            "nameInit" => $row["NAME_INIT"] ?? null,
            "sort" => (int)($row["SORT"] ?? 0),
            "color" => $row["COLOR"] ?? null,
            "semantics" => $row["SEMANTICS"] ?? null,
            "SYSTEM" => $row["SYSTEM"] ?? null,
            "CATEGORY_ID" => $row["CATEGORY_ID"] ?? null,
        ];
    }

    return $items;
}

function collectDealCategories()
{
    $items = [];
    foreach (\Bitrix\Crm\Category\DealCategory::getAll(true) as $row)
    {
        $items[] = [
            "id" => (string)($row["ID"] ?? 0),
            "name" => $row["NAME"] ?? ("Воронка " . ($row["ID"] ?? 0)),
        ];
    }

    return $items;
}

function collectBaseEntities()
{
    $baseEntities = [];
    $definitions = [
        "leads" => [
            "entityTypeId" => CCrmOwnerType::Lead,
            "fields" => CCrmLead::GetFieldsInfo(),
        ],
        "deals" => [
            "entityTypeId" => CCrmOwnerType::Deal,
            "fields" => CCrmDeal::GetFieldsInfo(),
        ],
        "contacts" => [
            "entityTypeId" => CCrmOwnerType::Contact,
            "fields" => CCrmContact::GetFieldsInfo(),
        ],
        "companies" => [
            "entityTypeId" => CCrmOwnerType::Company,
            "fields" => CCrmCompany::GetFieldsInfo(),
        ],
        "quotes" => [
            "entityTypeId" => CCrmOwnerType::Quote,
            "fields" => CCrmQuote::GetFieldsInfo(),
        ],
        "invoices" => [
            "entityTypeId" => __SMART_INVOICE_ENTITY_TYPE_ID__,
            "fields" => getFactoryOrFail(__SMART_INVOICE_ENTITY_TYPE_ID__)->getFieldsInfo(),
        ],
    ];

    foreach ($definitions as $slug => $definition)
    {
        $factory = getFactoryOrFail($definition["entityTypeId"]);
        $baseEntities[$slug] = [
            "entityTypeId" => $definition["entityTypeId"],
            "fields" => enrichFieldsWithCaptions($definition["fields"], $factory, $definition["entityTypeId"]),
            "userFields" => collectUserFields($factory),
        ];
    }

    return $baseEntities;
}

function collectSmartProcesses()
{
    $items = [];
    $result = \Bitrix\Crm\Model\Dynamic\TypeTable::getList([
        "select" => [
            "ID",
            "ENTITY_TYPE_ID",
            "TITLE",
            "NAME",
            "IS_CATEGORIES_ENABLED",
            "IS_STAGES_ENABLED",
        ],
        "order" => [
            "TITLE" => "ASC",
            "ENTITY_TYPE_ID" => "ASC",
        ],
    ]);

    while ($row = $result->fetch())
    {
        $entityTypeId = (int)($row["ENTITY_TYPE_ID"] ?? 0);
        if ($entityTypeId === __SMART_INVOICE_ENTITY_TYPE_ID__)
        {
            continue;
        }

        $factory = getFactoryOrFail($entityTypeId);
        $categories = [];
        foreach ($factory->getCategories() as $category)
        {
            $categories[] = [
                "id" => (string)$category->getId(),
                "name" => $category->getName(),
            ];
        }

        $items[] = [
            "id" => (int)($row["ID"] ?? 0),
            "entityTypeId" => $entityTypeId,
            "title" => $row["TITLE"] ?: ("Смарт-процесс " . $entityTypeId),
            "name" => $row["NAME"] ?? null,
            "isCategoriesEnabled" => isTruthy($row["IS_CATEGORIES_ENABLED"] ?? false),
            "isStagesEnabled" => isTruthy($row["IS_STAGES_ENABLED"] ?? false),
            "categories" => $categories,
            "fields" => enrichFieldsWithCaptions($factory->getFieldsInfo(), $factory, $entityTypeId),
            "userFields" => collectUserFields($factory),
        ];
    }

    return $items;
}

function collectBizprocTemplates()
{
    $items = [];
    $result = CBPWorkflowTemplateLoader::GetList(
        ["ID" => "ASC"],
        [],
        false,
        false,
        ["ID", "NAME", "MODULE_ID", "ENTITY", "DOCUMENT_TYPE", "AUTO_EXECUTE", "ACTIVE", "SYSTEM_CODE"]
    );

    while ($row = $result->Fetch())
    {
        $items[] = [
            "id" => $row["ID"] ?? null,
            "name" => $row["NAME"] ?? null,
            "moduleId" => $row["MODULE_ID"] ?? null,
            "entity" => $row["ENTITY"] ?? null,
            "documentType" => $row["DOCUMENT_TYPE"] ?? [],
            "autoExecute" => $row["AUTO_EXECUTE"] ?? 0,
            "active" => $row["ACTIVE"] ?? null,
            "systemCode" => $row["SYSTEM_CODE"] ?? null,
        ];
    }

    return $items;
}

$payload = [
    "portal" => php_uname("n"),
    "generatedAt" => gmdate(DATE_ATOM),
    "statuses" => collectStatuses(),
    "dealCategories" => collectDealCategories(),
    "baseEntities" => collectBaseEntities(),
    "smartProcesses" => collectSmartProcesses(),
    "bizprocTemplates" => collectBizprocTemplates(),
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
"""


def parse_args():
    parser = argparse.ArgumentParser(
        description="Export Bitrix box metadata snapshot over SSH.",
    )
    parser.add_argument("--host", default=DEFAULT_HOST)
    parser.add_argument("--user", default=DEFAULT_USER)
    parser.add_argument("--password", default=None)
    parser.add_argument("--port", type=int, default=22)
    parser.add_argument("--docroot", default=DEFAULT_DOCROOT)
    parser.add_argument("--output-dir", default=str(DEFAULT_OUTPUT_DIR))
    return parser.parse_args()


def build_remote_script(docroot):
    return (
        REMOTE_EXPORT_SCRIPT.replace("__DOCROOT__", json.dumps(docroot))
        .replace("__SMART_INVOICE_ENTITY_TYPE_ID__", str(SMART_INVOICE_ENTITY_TYPE_ID))
    )


def build_ssh_command(args):
    password = args.password or ""
    command = []
    if password:
        if shutil.which("sshpass") is None:
            raise RuntimeError("sshpass is required when using password authentication.")
        command.extend(["sshpass", "-p", password])

    command.extend(
        [
            "ssh",
            "-T",
            "-p",
            str(args.port),
            "-o",
            "StrictHostKeyChecking=accept-new",
            "-o",
            "LogLevel=ERROR",
            f"{args.user}@{args.host}",
            "php",
        ]
    )
    return command


def run_remote_export(args):
    command = build_ssh_command(args)
    script = build_remote_script(args.docroot)
    result = subprocess.run(
        command,
        input=script,
        text=True,
        capture_output=True,
        check=False,
    )
    if result.returncode != 0:
        stderr = result.stderr.strip() or result.stdout.strip() or "Unknown SSH error."
        raise RuntimeError(stderr)

    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError("Remote export did not return valid JSON.") from error


def normalize_standard_field_meta(meta):
    attributes = set(meta.get("ATTRIBUTES") or [])
    return {
        "title": meta.get("TITLE"),
        "type": str(meta.get("TYPE") or "unknown").lower(),
        "isMultiple": "MUL" in attributes,
        "isRequired": ("REQ" in attributes) or ("CAN_NOT_BE_EMPTIED" in attributes),
        "isReadOnly": ("R-O" in attributes) or ("READ_ONLY" in attributes),
        "statusType": meta.get("CRM_STATUS_TYPE"),
        "items": meta.get("ITEMS") or [],
        "raw": meta,
    }


def build_boolean_items(row):
    settings = row.get("SETTINGS") or {}
    labels = settings.get("LABEL") or []
    no_label = labels[0] if len(labels) > 0 and labels[0] else "Нет"
    yes_label = labels[1] if len(labels) > 1 and labels[1] else "Да"
    return [
        {"ID": "N", "VALUE": no_label},
        {"ID": "Y", "VALUE": yes_label},
    ]


def normalize_user_field_meta(row):
    field_type = row.get("TYPE") or row.get("USER_TYPE_ID") or "unknown"
    items = row.get("ENUM_ITEMS") or []
    if row.get("USER_TYPE_ID") == "boolean":
        items = build_boolean_items(row)

    return {
        "title": row.get("TITLE") or row.get("FIELD_NAME"),
        "type": field_type,
        "isMultiple": row.get("MULTIPLE") == "Y",
        "isRequired": row.get("MANDATORY") == "Y",
        "isReadOnly": row.get("EDIT_IN_LIST") == "N" and row.get("SHOW_IN_LIST") == "N",
        "items": items,
        "raw": row,
    }


def combine_field_map(standard_fields, user_fields):
    combined = {}
    for code, meta in (standard_fields or {}).items():
        combined[code] = normalize_standard_field_meta(meta)
    for row in user_fields or []:
        code = row.get("FIELD_NAME")
        if code:
            combined[code] = normalize_user_field_meta(row)
    return combined


def build_category_map(items):
    return {
        str(item.get("id")): {
            "id": str(item.get("id")),
            "name": item.get("name") or f"Воронка {item.get('id')}",
        }
        for item in (items or [])
        if item.get("id") is not None
    }


def apply_extra_options(raw_fields, stage_groups, stages):
    options = {}
    lowered = {code.lower(): code for code in raw_fields}

    if stage_groups:
        category_options = build_category_options(stage_groups)
        for alias in ("categoryid", "category_id"):
            if alias in lowered:
                options[lowered[alias]] = category_options

    if stages:
        stage_options = build_stage_field_options(stages)
        for alias in ("stageid", "stage_id"):
            if alias in lowered:
                options[lowered[alias]] = stage_options
        for alias in ("previousstageid", "previous_stage_id"):
            if alias in lowered:
                options[lowered[alias]] = stage_options

    return options


def build_entity_payload(config, catalog, portal, raw_statuses, standard_fields, user_fields, extra_context=None):
    statuses = raw_statuses
    status_options = build_status_options(statuses)
    stages = build_stages(config, statuses)
    stage_groups = build_stage_groups(config["slug"], stages, extra_context=extra_context or {})
    raw_fields = combine_field_map(standard_fields, user_fields)
    extra_options = apply_extra_options(raw_fields, stage_groups, stages)

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

    fields = apply_box_field_title_hints(config["slug"], fields)

    custom_fields = [field for field in fields if field["isCustom"]]
    option_fields = [field for field in fields if field["hasOptions"]]

    return {
        "slug": config["slug"],
        "kind": config["kind"],
        "entity": config["entity"],
        "title": config["title"],
        "subtitle": config["subtitle"],
        "stageTitle": config["stageTitle"],
        "stageSubtitle": config["stageSubtitle"],
        "portal": portal,
        "source": "box",
        "sourceTitle": "Коробка",
        "entities": build_entities(catalog),
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


def build_stages(config, raw_statuses):
    stage_filter = config.get("stageFilter")
    if not stage_filter:
        return []

    mode = stage_filter.get("mode")
    if mode == "exact":
        values = set(stage_filter.get("values", []))
        filtered = [item for item in raw_statuses if item.get("entityId") in values]
    elif mode == "prefix":
        prefix = stage_filter.get("value", "")
        filtered = [
            item
            for item in raw_statuses
            if str(item.get("entityId", "")).startswith(prefix)
        ]
    else:
        filtered = []

    stages = [normalize_stage(item) for item in filtered]
    stages.sort(
        key=lambda item: (
            item["entityId"] or "",
            item["sort"],
            item["name"].lower(),
            item["code"] or "",
        )
    )
    return stages


def build_entities(catalog):
    return [
        {
            "slug": slug,
            "title": config["title"],
            "kind": config["kind"],
        }
        for slug, config in catalog.items()
    ]


def build_box_catalog(raw_snapshot):
    catalog = {
        slug: {
            "slug": slug,
            "kind": "base",
            **config,
        }
        for slug, config in BASE_ENTITY_CONFIGS.items()
    }

    for item in raw_snapshot.get("smartProcesses", []):
        entity_type_id = int(item["entityTypeId"])
        title = item.get("title") or f"Смарт-процесс {entity_type_id}"
        config = {
            "slug": f"sp-{entity_type_id}",
            "kind": "smart_process",
            "entity": "items",
            "entityTypeId": entity_type_id,
            "smartProcessId": item.get("id"),
            "title": title,
            "subtitle": f'Смарт-процесс "{title}": воронки, стадии, поля и значения списочных полей.',
            "stageTitle": "Воронки и стадии",
            "stageSubtitle": "Если у смарт-процесса включены стадии, они показаны по воронкам.",
            "stageFilter": {
                "mode": "prefix",
                "value": f"DYNAMIC_{entity_type_id}_STAGE",
            },
        }
        if not item.get("isStagesEnabled"):
            config["stageFilter"] = None
            config["stageSubtitle"] = "У этого смарт-процесса стадии отключены."
        elif not item.get("isCategoriesEnabled"):
            config["stageSubtitle"] = "Стадии смарт-процесса без разделения по воронкам."
        catalog[config["slug"]] = config

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


def build_bizproc_payload(raw_snapshot, catalog, portal):
    templates = []
    targets = set()
    for item in raw_snapshot.get("bizprocTemplates", []):
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

    templates.sort(key=lambda item: (item["target"]["name"].lower(), item["name"].lower(), str(item["id"] or "")))

    return {
        "slug": "bp-templates",
        "kind": "bizproc_registry",
        "entity": "bizproc-templates",
        "title": "Шаблоны БП",
        "subtitle": "Реестр шаблонов бизнес-процессов портала и сущностей, где они запускаются.",
        "portal": portal,
        "source": "box",
        "sourceTitle": "Коробка",
        "entities": build_entities(catalog),
        "templates": templates,
        "summary": {
            "templateCount": len(templates),
            "targetCount": len(targets),
            "autoStartCount": sum(item["autoExecute"]["value"] > 0 for item in templates),
            "manualOnlyCount": sum(item["autoExecute"]["value"] == 0 for item in templates),
        },
    }


def build_snapshot(raw_snapshot):
    portal = raw_snapshot.get("portal") or DEFAULT_HOST
    catalog = build_box_catalog(raw_snapshot)
    deal_categories = build_category_map(raw_snapshot.get("dealCategories"))
    payloads = {}

    for slug, config in BASE_ENTITY_CONFIGS.items():
        entity_snapshot = raw_snapshot["baseEntities"][slug]
        extra_context = {"dealCategories": deal_categories} if slug == "deals" else {}
        payloads[slug] = build_entity_payload(
            {
                "slug": slug,
                "kind": "base",
                **config,
            },
            catalog,
            portal,
            raw_snapshot["statuses"],
            entity_snapshot.get("fields", {}),
            entity_snapshot.get("userFields", []),
            extra_context=extra_context,
        )

    for item in raw_snapshot.get("smartProcesses", []):
        config = catalog[f"sp-{int(item['entityTypeId'])}"]
        extra_context = {}
        if item.get("isCategoriesEnabled"):
            extra_context["groupByCategory"] = True
            extra_context["dealCategories"] = build_category_map(item.get("categories"))

        payloads[config["slug"]] = build_entity_payload(
            config,
            catalog,
            portal,
            raw_snapshot["statuses"],
            item.get("fields", {}),
            item.get("userFields", []),
            extra_context=extra_context,
        )

    payloads["bp-templates"] = build_bizproc_payload(raw_snapshot, catalog, portal)

    entities = build_entities(catalog)
    return {
        "catalog": {
            "source": "box",
            "sourceTitle": "Коробка",
            "portal": portal,
            "generatedAt": raw_snapshot.get("generatedAt"),
            "entities": entities,
            "defaultSlug": next(
                (entity["slug"] for entity in entities if entity["kind"] != "bizproc_registry"),
                "leads",
            ),
        },
        "payloads": payloads,
    }


def write_snapshot(snapshot, output_dir):
    output_dir.mkdir(parents=True, exist_ok=True)
    entities_dir = output_dir / "entities"
    entities_dir.mkdir(parents=True, exist_ok=True)

    for path in entities_dir.glob("*.json"):
        path.unlink()

    (output_dir / "catalog.json").write_text(
        json.dumps(snapshot["catalog"], ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )

    for slug, payload in snapshot["payloads"].items():
        (entities_dir / f"{slug}.json").write_text(
            json.dumps(payload, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )


def main():
    args = parse_args()
    output_dir = Path(args.output_dir).resolve()
    raw_snapshot = run_remote_export(args)
    snapshot = build_snapshot(raw_snapshot)
    write_snapshot(snapshot, output_dir)
    print(f"Box snapshot exported to {output_dir}")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:  # noqa: BLE001
        print(f"Error: {error}", file=sys.stderr)
        sys.exit(1)
