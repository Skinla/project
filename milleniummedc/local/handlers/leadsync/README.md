# LeadSync — синхронизация лидов облако → коробка

HTTP-обработчик принимает **исходящий вебхук облака** или классическое событие **ONCRMLEADADD**, подтягивает лид/контакт/активности по REST облака и создаёт или обновляет лид на коробке через `migrate_leads_from_json.php`.

## URL (коробка)

```
https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php
```

## Входящие данные

### Исходящий вебхук / бизнес-процесс / робот (рекомендуется)

- **ID лида в облаке** — одно из полей (тело JSON и/или query):
  - `cloud_lead_id`, `lead_id` или `ID`
  - Пример query: `?cloud_lead_id=123&check_date_modify=0`
- **`check_date_modify`** (опционально, по умолчанию **`true`**):
  - `false` / `0` / `N` — не отсекать обновления лида и активностей по датам (принудительное обновление).
  - `true` / `1` / `Y` — как в миграции: учитывать `DATE_MODIFY` / `LAST_UPDATED`.
  - Можно передать в JSON или query (`check_date_modify` или `checkDateModify`).

Пример тела для настройки в редакторе Битрикс24 (плейсхолдеры уточните в своём портале):

```json
{
  "cloud_lead_id": "{{ID}}",
  "check_date_modify": false
}
```

### Классический сценарий приложения

Как раньше: `event` = `ONCRMLEADADD`, в теле `data.FIELDS.ID` — ID созданного лида в облаке. Явные `cloud_lead_id` / query при этом не обязательны.

## Регистрация события (облако)

Для варианта только с ONCRMLEADADD:

```bash
cd /home/bitrix/www/local/handlers/leadsync
php register_lead_webhook.php
```

## Контакт лида

Если у лида в облаке заполнен **CONTACT_ID**, а пары нет в `data/contact_mapping.json`, обработчик:

1. Запрашивает `crm.contact.get` в облаке.
2. Подключает ядро коробки (`prolog_before.php`), создаёт контакт через CRM API и **дописывает** маппинг в `contact_mapping.json` (с блокировкой файла).

После этого `LeadSync::buildLeadPayload` подставит коробочный контакт в поля лида.

## Структура каталога

```
leadsync/
├── webhook_lead_handler.php   # Точка входа
├── migrate_leads_from_json.php # stdin: { "items": [...], "check_date_modify": bool }
├── register_lead_webhook.php   # event.bind ONCRMLEADADD
├── config/
│   └── webhook.php             # url облака (REST вебхук для запросов к облаку)
├── data/
│   ├── stage_mapping.json
│   ├── field_mapping.json
│   ├── user_mapping.json
│   ├── contact_mapping.json
│   ├── source_mapping.json
│   ├── honorific_mapping.json
│   ├── lead_sync_log.json
│   └── activity_sync_log.json
└── lib/
    ├── BitrixRestClient.php
    ├── LeadSync.php
    ├── LeadSyncLog.php
    └── EnsureCloudContact.php
```

## Маппинги

Актуальные JSON нужно **копировать из каталога `data/` корня этого проекта** в `local/handlers/leadsync/data/` на сервере коробки при изменении маппингов в проекте. Секреты в репозиторий не класть; для новой установки можно начать с минимальных файлов (пустые объекты/объекты по структуре существующих примеров).

## Логи

- **`data/lead_sync_log.json`** — пары `cloud_lead_id` → `box_lead_id` (плоский объект, как в `LeadSyncLog`).
- **`data/activity_sync_log.json`** — учёт синхронизированных активностей (используется миграцией).

## Проверка (curl)

```bash
curl -sS -X POST 'https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php' \
  -H 'Content-Type: application/json' \
  -d '{"cloud_lead_id":12345,"check_date_modify":false}'
```

Пример только через query (GET):

```text
https://bitrix.milleniummedc.ru/local/handlers/leadsync/webhook_lead_handler.php?cloud_lead_id=12345&check_date_modify=0
```

## Ограничения

- Долгий ответ при большом числе активностей — при необходимости увеличьте `max_execution_time` для PHP/nginx на этом URL или вынесите обработку в очередь.
