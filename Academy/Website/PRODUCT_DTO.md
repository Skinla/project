## Product DTO (детальная карточка) — “разворачиваем от конца”

Идея: фиксируем контракт **детальной карточки товара** (DTO) и реализуем её как **встраиваемый блок**.

Список/поиск — **out of scope** на текущем этапе.

Каталог Bitrix24 **read-only** (см. `PROMPT.md`), поэтому DTO строится только из того, что уже есть в данных.

### DTO: `ProductDto`

```json
{
  "id": 18,
  "code": "zhestyanshchik_2_vtorogo_razryada",
  "name": "Жестянщик 2 (второго) разряда",
  "sectionId": 40,
  "serviceType": "Рабочие профессии, должности служащих",
  "registry": "ФИС ФРДО",
  "periodicity": "Бессрочно",
  "hours": 360,
  "durationWorkDays": 45,
  "format": "Очно/Очно-заочно, ...",
  "educationLevel": "Среднее общее (школа)",
  "requirements": [
    "Паспорт",
    "СНИЛС",
    "Документ об образовании",
    "Фотография 3x4",
    "Эл. почта"
  ],
  "priceRetail": { "amount": 5000, "currency": "RUB" },
  "priceDisplay": "от 5000 ₽",
  "ui": {
    "ctaLabel": "Оставить заявку",
    "ctaAnchor": "#request"
  }
}
```

### Маппинг из Bitrix (ключи)

- `id` ← `product.id`
- `code` ← `product.code`
- `name` ← `product.name`
- `sectionId` ← `product.iblockSectionId` (nullable)
- `serviceType` ← `product.property106.valueEnum`
- `registry` ← `product.property122.value` (nullable). Значение может быть `ФИС ФРДО`, `Минтруд`, `Ростехнадзор`, … (см. `REGISTRY_RULES.md`).
- `periodicity` ← `product.property114.value` (nullable)
- `hours` ← `product.property112.value` (nullable, number)
- `durationWorkDays` ← `product.property116.value` (nullable, number)
- `format` ← `product.property124.value` (nullable)
- `educationLevel` ← `product.property128.value` (nullable)
- `requirements` ← `product.property126.value` (nullable) → split по `\\n`/`;`/`,` + trim + de-dup.
- `priceRetail` ← `product.property152.value` (Money `"N|RUB"`)

### Правила нормализации (строго)
- `null`/пусто/`"-"` → `null` (или `[]` для `requirements`).
- Money `"N|RUB"`:
  - `amount` = int(N)
  - `currency` = `"RUB"`
  - `priceDisplay` = `от {amount} ₽`

### UI‑поля (CTA) — только из настроек модуля
Сейчас “Оставить заявку” — это **якорь** на форму‑блок на этой же странице:
- `MODULE_SETTINGS.example.json` → `ui.cta.anchor`

Алгоритм:
- `ctaLabel` = `ui.cta.label`
- если `ui.cta.mode == "anchor"` → `ctaAnchor` = `ui.cta.anchor`

