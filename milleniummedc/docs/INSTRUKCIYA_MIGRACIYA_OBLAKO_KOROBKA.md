# Инструкция: миграция и синхронизация сущностей Bitrix24 (облако → коробка)

Документ описывает устройство репозитория **milleniummedc**, порядок переноса на **другую пару порталов** (источник в облаке и целевая коробка) и рекомендации по развитию решения.

---

## 1. Назначение и границы

**Цель проекта:** перенос и последующая синхронизация CRM-сущностей с облачного Bitrix24 на коробочный портал: лиды, сделки, контакты, активности, комментарии таймлайна, товарные строки, часть сценариев с задачами и вложениями (записи звонков и файлы через URL).

**Не является полным «клонированием портала»:** не мигрируются произвольно все модули, диск целиком, почта, все бизнес-процессы и смарт-процессы без отдельной проработки. Сканер облака фиксирует наличие смарт-процессов и смежных сущностей — их перенос требует отдельного ТЗ.

---

## 2. Архитектура (два контура)

### 2.1. Рабочая машина (репозиторий)

- **Входящий REST к облаку** — файл `config/webhook.php` (URL входящего вебхука приложения в облаке с нужными правами `crm.*`, `tasks.*`, `disk.*` и т.д. по фактическому сценарию).
- **REST к коробке (опционально для скриптов)** — `config/webhook_target.php` (если что-то делается через REST с машины разработчика, например `migrate_stages.php`).
- **Пара порталов (метаданные)** — `config/portal.example.php` → копия `config/portal.php` (не коммитить): `portal_pair_id`, `source_base_url`, `box_base_url`, `box_document_root` — используется вспомогательными сценариями и при документировании маппингов.
- **SSH к серверу коробки** — `config/ssh.example.php` → `config/ssh.php` для сценариев, которые по SSH вызывают PHP в document root коробки и забирают JSON (пользователи, поля, создание контактов и т.п.).

**Артефакты в `data/` (корень репозитория):**

| Файл / группа | Назначение |
|---------------|------------|
| `scan_result.json` | Структура CRM облака: воронки, стадии лидов, используемые сущности, ошибки REST |
| `source_fields.json`, `target_fields.json` | Описание полей лидов/сделок (источник / коробка) |
| `field_mapping.json` | Соответствие кодов полей облако → коробка |
| `stage_mapping.json` | Соответствие стадий/статусов |
| `user_mapping.json` | `cloud_user_id` → `box_user_id` |
| `contact_mapping.json` | `cloud_contact_id` → `box_contact_id` |
| `source_mapping.json`, `honorific_mapping.json` | Источники, обращения и др. справочники |
| `booking_resource_mapping.json` и скрипты в `scripts/` | Отдельная линия под ресурсы бронирования (если используется) |

Скрипты **сборки** маппингов: `build_field_mapping.php`, `build_stage_mapping.php`, `build_user_mapping.php`, `build_contact_mapping.php`, `build_source_mapping.php`, `build_honorific_mapping.php` и др. — читают данные из `data/` и перезаписывают соответствующие JSON.

**Скрипты **сканирования** облака:** `scan_source.php`, `scan_source_fields.php`.

**Библиотеки:** `lib/BitrixRestClient.php`, `lib/LeadSync.php`, `lib/DealSync.php`, `lib/ContactBoxSync.php` — формирование payload для переноса и работа с контактами.

### 2.2. Сервер коробки

Разворачиваются каталоги:

- `local/handlers/leadsync/` — синхронизация **лидов** (вебхук → JSON → `migrate_leads_from_json.php`).
- `local/handlers/dealsync/` — синхронизация **сделок** (аналогично, `migrate_deals_from_json.php`).

У каждого обработчика свой `config/webhook.php` (URL вебхука **облака**, с которого читаются данные). У dealsync в примере конфига поддерживается **секрет** запроса (`secret` / заголовок `X-Sync-Secret`) — см. `local/handlers/dealsync/config/webhook.example.php`.

**Маппинги на коробке** должны быть согласованы с репозиторием: копирование JSON из `data/` проекта в `local/handlers/leadsync/data/` и `local/handlers/dealsync/data/` (и при необходимости обратно после правок на сервере). **Общий** файл контактов: `local/handlers/leadsync/data/contact_mapping.json` используется и сделками (см. комментарии в `webhook_deal_handler.php`).

**Логи и идемпотентность:** плоские JSON-логи вроде `lead_sync_log.json`, `deal_sync_log.json`, `activity_sync_log.json` — соответствие старых ID облака новым ID на коробке и учёт уже перенесённых активностей.

**Точки входа PHP на коробке (ядро Bitrix):** `migrate_leads_from_json.php`, `migrate_deals_from_json.php` подключают `prolog_before.php`, работают через `CCrmLead`, `CCrmDeal`, активности и таймлайн. Пути к логам зашиты относительно `DOCUMENT_ROOT` (часто `/home/bitrix/www`).

Подробнее по лидам: `local/handlers/leadsync/README.md`.

---

## 3. Масштабирование на другую пару порталов — пошаговый чеклист

### Этап A. Доступы и безопасность

1. Создать **входящий вебхук** (или приложение) в **облаке-источнике** с минимально достаточными правами для: чтения лидов/сделок/контактов, полей, статусов, активностей, таймлайна, при необходимости задач и диска.
2. Записать URL в `config/webhook.php` в клоне репозитория для этого клиента (отдельная ветка или отдельная копия проекта — см. раздел 5).
3. На **коробке** создать аналогичный вебхук к облаку и положить URL в:
   - `local/handlers/leadsync/config/webhook.php`
   - `local/handlers/dealsync/config/webhook.php`
4. Рекомендуется включить **секрет** для HTTP-обработчиков на коробке (хотя бы для dealsync) и ограничить доступ по сети (firewall, Basic auth на уровне веб-сервера) — вебхуки вызываются из облака (роботы/БП) и не должны быть открыты без проверки.

### Этап B. Метаданные порталов

1. Скопировать `config/portal.example.php` → `config/portal.php`, указать реальные `source_base_url`, `box_base_url`, `box_document_root`, осмысленный `portal_pair_id`.
2. Скопировать `config/ssh.example.php` → `config/ssh.php`, если используется автоматизация через SSH.

### Этап C. Сканирование и справочники

1. Выполнить с машины разработчика: `php scan_source.php` → проверить `data/scan_result.json` (ошибки REST, список сущностей, воронки).
2. При необходимости перенести **стадии** на коробку:
   - с REST цели: `php migrate_stages.php` (нужен `config/webhook_target.php`), или
   - локально на сервере коробки: `migrate_stages_local.php` с тем же `scan_result.json`.
3. `php scan_source_fields.php` → `data/source_fields.json`.
4. На коробке получить поля цели (через SSH): запуск `get_target_fields.php` с document root Bitrix → сохранить вывод в `data/target_fields.json`.
5. `php build_field_mapping.php` → проверить `null` в `field_mapping.json` (нерешённые поля — донастроить UF на коробке или скорректировать вручную).
6. Выгрузить пользователей облака и коробки, собрать `php build_user_mapping.php` (см. подсказки в скрипте: `get_box_users.php` на коробке, облако через REST).
7. Построить остальные маппинги (`build_stage_mapping.php`, `build_source_mapping.php`, `build_honorific_mapping.php`, контакты — по мере необходимости).

### Этап D. Деплой кода и данных на коробку

1. Скопировать каталоги `local/handlers/leadsync/` и `local/handlers/dealsync/` в document root коробки (или задеплоить через CI).
2. Скопировать актуальные JSON маппингов из `data/` проекта в `local/handlers/*/data/` на коробке (включая **`booking_resource_mapping.json`** в `dealsync/data/`, чтобы совпадало с корневым `data/`).
3. Права: каталоги `data/` у обработчиков должны быть **writable** для пользователя PHP (`chown bitrix:bitrix`, см. README dealsync).
4. Заполнить `config/webhook.php` внутри каждого handler на коробке.

### Этап E. Регистрация событий в облаке

- Для потоковой синхронизации **лидов** при классическом сценарии: на коробке в каталоге leadsync выполнить `register_lead_webhook.php` (привязка `ONCRMLEADADD`) — либо настроить исходящий вебхук/робот с телом JSON, как в `local/handlers/leadsync/README.md`.
- Для **сделок:** настроить вызов URL `webhook_deal_handler.php` (исходящий вебхук, робот, БП) с передачей ID сделки в облаке.

### Этап F. Проверка

1. Один тестовый лид/сделка: вызов обработчика через `curl` (примеры в README leadsync; для сделок — аналогично GET/POST с `cloud_deal_id`).
2. Убедиться, что записи появились в CRM коробки и обновились логи `*_sync_log.json`.
3. При наличии — прогнать специализированные скрипты из `scripts/` (например `verify_deal_export_booking.php`), если сценарий с бронированием актуален.

### Онлайн-запись (CRM_BOOKING) — чеклист

Перенос записей из модуля «Онлайн-запись» идёт через активности с `PROVIDER_ID=CRM_BOOKING`: в JSON добавляется `migration_booking`, на коробке вызывается `AddBookingCommand`, в активность подставляется локальный `SETTINGS.FIELDS.id`.

1. **Входящий webhook облака** (тот же URL, что в `config/webhook.php` у обработчика и в корне проекта) должен разрешать **`booking.v1.booking.get`**, **`booking.v1.resource.get`**, **`booking.v1.resource.list`** — иначе `migration_booking` не соберётся. Проверка с машины разработчика: `php scripts/verify_cloud_webhook_booking.php` (использует `config/webhook.php`).
2. **Ресурсы и маппинг:** выгрузить ресурсы из облака (`scripts/pull_cloud_booking_resources.php`), создать на коробке (`scripts/push_box_booking_resources.php`), заполнить **`booking_resource_mapping.json`**. Файл должен лежать в **`local/handlers/dealsync/data/booking_resource_mapping.json`** на коробке и быть **синхронизирован** с `data/booking_resource_mapping.json` в репозитории (CLI `migrate_deals_from_id.php` читает корневой `data/`; обработчик сначала смотрит `dealsync/data/`). При необходимости задать путь явно: переменная окружения **`MILLENNIUM_BOOKING_RESOURCE_MAP`** (полный путь к JSON).
3. **Логи на коробке:** успехи — `local/handlers/dealsync/data/deal_booking_sync_log.json`, ошибки создания брони — **`deal_booking_sync_failures.json`**. Просмотр без SSH-сложностей: скопировать каталог `data` или выполнить на сервере `php scripts/cat_booking_sync_data.php /home/bitrix/www/local/handlers/dealsync/data` (скрипт только читает файлы).
4. **Проверка JSON до импорта:** `php scripts/verify_deal_export_booking.php путь/к/батчу.json` (в репозитории есть пример: `data/fixtures/sample_crm_booking_export.json`).
5. **Строгий режим вебхука:** в запрос к `webhook_deal_handler.php` можно передать **`strict_booking=1`** (query или поле JSON). Тогда при любой ошибке привязки CRM_BOOKING ответ будет **`ok: false`**, в теле — блок **`booking`** с полями `expected`, `linked`, `failures`. Для `migrate_deals_from_json.php` то же самое: в корневом JSON добавить `"strict_booking": true`.
6. **Ошибка «Не заполнено обязательное поле CLIENT_ID»** на коробке: в сущности клиента брони Bitrix ожидается **`id` на верхнем уровне** элемента `clients[]` (не только внутри `data`). В `DealSync` это заполняется автоматически; при пустых клиентах в активности подставляется контакт сделки. Старые JSON при импорте подправляет `migrate_deals_from_json.php` (копирует `data.id` → `id`).

### Этап G. Массовая миграция (пакеты)

- Скрипты вида `migrate_leads_from_json.php`, `migrate_deals_from_json.php`, `migrate_leads_from_id.php`, `migrate_deals_from_id.php` и генерация JSON через `LeadSync` / `DealSync` позволяют грузить **пакетами** с рабочей машины, передавая JSON на stdin на сервере коробки по SSH.
- Для больших объёмов планировать батчи, окна обслуживания и мониторинг ошибок в ответах скриптов.

---

## 4. Жёстко зашитые значения (обязательно править при новом клиенте)

В коде встречаются URL конкретного проекта (пример: `milleniummed.bitrix24.ru`, `bitrix.milleniummedc.ru`) в:

- `scan_source.php`, `scan_source_fields.php` — поле `source_url` в JSON;
- `build_field_mapping.php`, `build_user_mapping.php` и ряде других `build_*` — метаданные `source_url` / `target_url`;
- `get_target_fields.php` — `target_url` в выходном JSON.

Для новой пары порталов нужно **либо** править эти строки **либо** (предпочтительно в перспективе) централизовать чтение из `config/portal.php` — см. раздел 6.

---

## 5. Рекомендуемая организация нескольких миграций

Чтобы не смешивать маппинги и логи разных заказчиков:

1. **Отдельный клон репозитория** на диске или отдельная git-ветка на пару порталов + игнорируемый `config/*.php` с секретами.
2. Либо ввести подкаталоги `data/<portal_pair_id>/` и параметризовать скрипты (сейчас не реализовано — см. улучшения).
3. На коробке для изоляции можно использовать разные префиксы URL или отдельные виртуальные хосты с **своими** копиями `local/handlers/*` и **своими** `data/`.

Поле `portal_pair_id` в `config/portal.example.php` задумано как идентификатор пары для логов и будущей параметризации.

---

## 6. Улучшения и дополнения (рекомендации)

### 6.1. Конфигурация и поддерживаемость

- **Единый источник правды для URL:** читать `source_base_url` и `box_base_url` из `config/portal.php` во всех скриптах сканирования и `build_*`, убрать дублирование доменов.
- **Параметр `--data-dir` или переменная окружения** для путей к JSON, чтобы одна кодовая база обслуживала несколько пар порталов без копирования репозитория.
- **Примеры конфигов** в корне: добавить `config/webhook.example.php` (симметрично dealsync), в README указать минимальный набор прав вебхука.

### 6.2. Надёжность и эксплуатация

- **Очередь для вебхуков:** длительная обработка (много активностей) упирается в таймауты PHP/nginx; вынести в очередь (агент, cron, Rabbit/Redis) с быстрым ACK HTTP.
- **Ротация и архивация логов** JSON (размер, бэкап перед массовым прогоном).
- **Метрики и алертинг:** счётчики ошибок REST, длительность синка, интеграция с мониторингом.
- **Повторяемые прогоны:** явная политика для `check_date_modify` и для конфликтов при ручном изменении записей на коробке.

### 6.3. Функциональные расширения

- **Компании и реквизиты:** при необходимости полноценного B2B — отдельный контур маппинга `company_mapping.json` (в dealsync уже есть заготовки данных) и согласование с контактами.
- **Смарт-процессы и кастомные типы:** опираться на `scan_result.json` → отдельные модули миграции через `crm.item.*` / D7 Factory.
- **Бизнес-процессы:** перенос шаблонов и связей не покрыт текущими скриптами — только оценка количества в скане.
- **Юнит-тесты** нормализации дат, телефонов, маппинга полей на фикстурах JSON.
- **CI:** `php -l` на всех CLI-скриптах, проверка схем JSON (опционально).

### 6.4. Безопасность

- Обязательный **секрет** и TLS для всех публичных handler URL.
- Не коммитить вебхуки и SSH-пароли; использовать секрет-хранилища и `chmod` на `config/*.php`.

---

## 7. Быстрая карта файлов

| Зона | Ключевые файлы |
|------|----------------|
| Конфиг (локально) | `config/webhook.php`, `config/webhook_target.php`, `config/portal.php`, `config/ssh.php` |
| Скан | `scan_source.php`, `scan_source_fields.php` |
| Маппинги | `build_*.php`, `data/*.json` |
| Синк облако→JSON | `lib/LeadSync.php`, `lib/DealSync.php`, `lib/ContactBoxSync.php` |
| Коробка: лиды | `local/handlers/leadsync/webhook_lead_handler.php`, `migrate_leads_from_json.php` |
| Коробка: сделки | `local/handlers/dealsync/webhook_deal_handler.php`, `migrate_deals_from_json.php` |
| Стадии | `migrate_stages.php`, `migrate_stages_local.php`, `migrate_stages_on_box.php` |
| Доп. скрипты | `scripts/*.php`, `migrate_contacts_from_leads.php`, `create_contacts_on_box.php`; для CRM_BOOKING: `verify_deal_export_booking.php`, `verify_cloud_webhook_booking.php`, `cat_booking_sync_data.php` |

---

## 8. Критерий успешного масштабирования

Для новой пары порталов считается готовность, когда:

1. Скан облака без критичных ошибок в `scan_result.json`.
2. Стадии и обязательные поля согласованы, `field_mapping.json` и `stage_mapping.json` покрывают боевые сценарии.
3. Пользователи сопоставлены или осознанно заменены fallback (например администратор коробки).
4. Тестовый лид и тестовая сделка проходят через вебхуки на коробке, логи обновляются, дубликаты не создаются при повторном вызове (где задумана идемпотентность).
5. Документированы URL обработчиков, способ вызова из облака и ответственные за поддержку маппингов.

---

*Файл подготовлен как описание текущей структуры проекта и руководство по переносу на новые порталы; при изменении кода в репозитории разделы 2 и 7 следует актуализировать.*
