#!/usr/bin/env bash
set -eu

BASE_URL="${1:-}"
OUT_DIR="${2:-./webhook_check}"

if [[ -z "${BASE_URL}" ]]; then
  echo "Usage: $0 <WEBHOOK_BASE_URL> [OUT_DIR]"
  echo "Example: $0 \"https://example.bitrix24.ru/rest/1/yourkey/\""
  exit 1
fi

mkdir -p "${OUT_DIR}"

req() {
  local method="$1"
  local data="$2"
  local out="$3"
  curl -sS -m 40 \
    -H "Content-Type: application/json" \
    -X POST \
    -d "${data}" \
    "${BASE_URL}${method}" > "${out}"
}

echo "Checking scope..."
curl -sS -m 40 "${BASE_URL}scope" > "${OUT_DIR}/scope.json" || true

echo "Checking user.current..."
curl -sS -m 40 "${BASE_URL}user.current" > "${OUT_DIR}/user.current.json" || true

echo "Checking catalog.product.list (iblockId=14)..."
req "catalog.product.list" \
  '{"select":["id","iblockId","name","active","code","previewText","detailText","previewPicture","detailPicture","iblockSectionId","property*"],"filter":{"iblockId":14},"order":{"id":"desc"}}' \
  "${OUT_DIR}/catalog.product.list.json"

echo "Checking catalog.product.get (id=7896)..."
req "catalog.product.get" \
  '{"id":7896}' \
  "${OUT_DIR}/catalog.product.get_7896.json"

echo "Checking catalog.productProperty.list (iblockId=14)..."
req "catalog.productProperty.list" \
  '{"filter":{"iblockId":14},"select":["id","name","code","propertyType","userType","multiple"]}' \
  "${OUT_DIR}/catalog.productProperty.list.json"

echo "Checking catalog.section.list (iblockId=14)..."
req "catalog.section.list" \
  '{"filter":{"iblockId":14},"select":["id","name","sort","sectionId","xmlId","code"]}' \
  "${OUT_DIR}/catalog.section.list.json"

echo "Checking catalog.price.list (productId=7896)..."
req "catalog.price.list" \
  '{"select":["id","productId","catalogGroupId","price","currency"],"filter":{"productId":7896}}' \
  "${OUT_DIR}/catalog.price.list_7896.json"

echo "Done. Outputs in: ${OUT_DIR}"
