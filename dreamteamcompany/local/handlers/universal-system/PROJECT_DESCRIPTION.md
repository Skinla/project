# Описание проекта: Universal System — обработчик лидов

## Общая архитектура

Система принимает входящие заявки (лиды) из разных источников (формы, вебхуки, коллтрекинг и т.п.), нормализует их и создаёт лиды в Bitrix24 CRM портала dreamteamcompany.

---

## Структура проекта

```
/project/dreamteamcompany/local/handlers/
├── webhook.php                    ← Точка входа (лежит отдельно от universal-system)
└── universal-system/              ← Основной модуль
    ├── config.php                 ← Конфигурация
    ├── queue_manager.php          ← Менеджер очередей
    ├── data_type_detector.php     ← Определение типа данных
    ├── lead_processor.php         ← Создание лидов
    ├── logger_and_queue.php       ← Логирование и работа с очередью
    ├── duplicate_checker.php      ← Проверка дублей
    ├── request_tracker.php        ← Отслеживание запросов
    ├── error_handler.php         ← Обработка ошибок
    ├── chat_notifications.php     ← Уведомления в Bitrix24
    ├── normalizers/               ← Нормализаторы данных
    │   ├── base_normalizer.php
    │   ├── generic_normalizer.php
    │   ├── tilda_normalizer.php
    │   ├── calltouch_normalizer.php
    │   ├── koltaсh_normalizer.php
    │   ├── bitrix24_webhook_normalizer.php
    │   └── normalizer_factory.php
    ├── queue/                     ← Файловая очередь
    │   ├── raw/                   ← Сырые данные
    │   ├── raw/raw_errors/        ← Ошибки на этапе raw
    │   ├── detected/              ← Данные с определённым типом
    │   ├── normalized/            ← Нормализованные данные
    │   ├── processed/             ← Успешно обработанные
    │   ├── duplicates/            ← Дубликаты
    │   ├── failed/                ← Ошибки обработки
    │   └── queue_errors/          ← Общие ошибки
    └── logs/                      ← Логи
```

---

## Точка входа: `webhook.php`

Файл лежит в `/project/dreamteamcompany/local/handlers/webhook.php` и является единственной точкой входа для входящих запросов.

### Основные функции

1. **Приём запросов** — POST (JSON, form-urlencoded, multipart) и GET.
2. **Определение домена** — по HTTP_REFERER, HTTP_ORIGIN, extra.href, ASSIGNED_BY_ID, source_domain, __submission.source_url, extra.referrer, CallTouch (subPoolName, url).
3. **Парсинг данных** — JSON, form-urlencoded, $_POST.
4. **Унификация телефона** — поиск полей phone/Phone и приведение к единому виду.
5. **Валидация** — блокировка пустых телефонов (кроме Bitrix24 с телефоном в QUERY_STRING).
6. **Дедупликация по request_id** — хеш от сырых данных, проверка статуса (processing/success/failed).
7. **Сохранение в очередь** — сырые данные в `queue/raw/`.
8. **Уведомления** — при накоплении ≥10 файлов в raw отправляется сообщение в чат «Ошибки по рекламе».
9. **Синхронная обработка** — через `flock()` вызывается `processAllQueues()`.

### Тестовый запрос

Для проверки вебхука (например, Тильдой) используется `test=test` в POST или JSON — ответ `{"status":"ok","message":"webhook_available"}`.

---

## Конвейер обработки (3 этапа)

### Этап 1: Определение типа (`data_type_detector.php`)

- Обрабатывает файлы из `queue/raw/`.
- Выбор нормализатора:
  - **Bitrix24 webhook** — по SOURCE_DESCRIPTION или ASSIGNED_BY_ID в инфоблоке 54.
  - **Остальные** — по домену (NAME элемента) в инфоблоке 54.
- Если домен не найден — файл в `raw_errors`, уведомление в чат.
- Результат сохраняется в `queue/detected/`.

### Этап 2: Нормализация (`queue_manager.php` + нормализаторы)

- Обрабатывает файлы из `queue/detected/`.
- `NormalizerFactory` создаёт нормализатор по имени из ИБ54.
- Нормализатор приводит данные к единому формату: `phone`, `name`, `comment`, UTM, `source_domain`.
- Результат сохраняется в `queue/normalized/`.

### Этап 3: Создание лидов (`lead_processor.php`)

- Обрабатывает файлы из `queue/normalized/`.
- Проверка дублей по телефону + домену (`DuplicateChecker`).
- Получение настроек из инфоблока 54 (ответственный, источник, наблюдатели и т.д.).
- Создание лида через Bitrix24 REST API (`crm.lead.add`).
- Успешные — в `queue/processed/`, дубли — в `queue/duplicates/`, ошибки — в `queue_errors`.

---

## Инфоблок 54 (Bitrix)

Инфоблок 54 — справочник источников и настроек:

| Свойство | Назначение |
|----------|------------|
| NAME | Домен или идентификатор источника |
| PROPERTY_388 | Нормализатор (например, `generic_normalizer.php`) |
| PROPERTY_191 | Город (связь с ответственным) |
| PROPERTY_192 | Источник (SOURCE_ID) |
| PROPERTY_193 | Исполнитель |
| PROPERTY_194 | Инфоповод |
| PROPERTY_195 | Наблюдатели (OBSERVER_IDS) |
| PROPERTY_199 | siteId (для CallTouch) |

---

## Нормализаторы

| Нормализатор | Источник |
|--------------|----------|
| `generic_normalizer.php` | Универсальный |
| `tilda_normalizer.php` | Тильда |
| `calltouch_normalizer.php` | CallTouch |
| `koltaсh_normalizer.php` | Колтач |
| `bitrix24_webhook_normalizer.php` | Bitrix24 webhook |

Все наследуют `BaseNormalizer` и реализуют `normalize($rawData)`.

---

## Защита от дублей

1. **RequestTracker** — хеш от сырых данных, статусы processing/success/failed.
2. **DuplicateChecker** — телефон + домен, лог в `processed_phones.log` и `processing_phones.log` (окно 30 минут).

---

## Обработка ошибок

- **ErrorHandler** — логирование, уведомления в чат, копирование в `queue_errors`.
- Сообщения содержат ссылки на:
  - добавление в ИБ54;
  - добавление в исключения;
  - повторную обработку.

---

## Запуск обработки

1. **Синхронно** — из `webhook.php` при каждом входящем запросе (если получена блокировка).
2. **CLI** — `php queue_manager.php run`.
3. **Cron** — `*/2 * * * * php queue_manager.php run`.
4. **Веб** — `retry-raw-errors.php` для повторной обработки файлов из `raw_errors`.

---

## Веб-интерфейсы

| Файл | Назначение |
|------|------------|
| `retry-raw-errors.php` | Повторная обработка raw_errors, просмотр очередей |
| `add-to-iblock.php` | Добавление элемента в ИБ54 |
| `add-to-exceptions.php` | Добавление домена в исключения |
| `check_status_web.php` | Статус системы и очередей |
| `start_auto_sync_web.php` | Запуск фоновой синхронизации |
| `clear_flags_web.php` | Очистка флагов |

---

## Конфигурация (`config.php`)

- `queue_dir`, `logs_dir` — пути к очередям и логам.
- `portal_webhooks['dreamteamcompany']` — REST API Bitrix24.
- `error_chat_id` — чат «Ошибки по рекламе».
- `max_files_per_run` — до 50 файлов за запуск.
- `max_execution_time` — 25 секунд.
- `lock_timeout` — 300 секунд.
- `raw_files_notification_threshold` — 10 файлов для уведомления.

---

## Поток данных

```
Внешний запрос (Тильда, CallTouch, Bitrix24, формы и т.д.)
    ↓
webhook.php (парсинг, домен, request_id, сохранение в raw)
    ↓
processAllQueues()
    ↓
1. processRawFiles() → raw → detected (выбор нормализатора по ИБ54)
    ↓
2. processDetectedFiles() → detected → normalized (нормализация)
    ↓
3. processNormalizedFiles() → normalized → processed (создание лида в Bitrix24)
    ↓
Лид в CRM Bitrix24
```

---

## Интеграции

- **Bitrix24 REST API** — создание лидов, отправка сообщений в чат.
- **База Bitrix** — чтение инфоблока 54, списков 19 и 22.
- **Тильда** — тестовый запрос `test=test`.
- **CallTouch** — поля `subPoolName`, `siteId`, `callerphone`.
- **Колтач** — поля `answers`, `raw`.

---

## Новая архитектура: webhook_bp.php + БП 775 (v2)

Параллельно со старой схемой работает новая, основанная на бизнес-процессе Bitrix24.

### Точка входа: `webhook_bp.php`

Файл: `/project/dreamteamcompany/local/handlers/universal/webhook_bp.php`

Минимальный обработчик (~200 строк):
1. Приём POST/GET запроса, тест Тильды (`test=test`).
2. Парсинг тела (JSON, form-urlencoded, $_POST).
3. Определение домена (та же логика что в webhook.php).
4. Первичное извлечение телефона (валидация пустого).
5. Создание элемента в списке-буфере IBlock 49 через `lists.element.add`.
6. JSON-ответ `{"status":"ok", "element_id": ...}`.

Не содержит: файловую очередь, RequestTracker, DuplicateChecker, processAllQueues.

### Список-буфер IBlock 49

Список в группе 1 на портале `bitrix.dreamteamcompany.ru` (тип `lists_socnet`).

| Свойство | Код | Назначение |
|----------|-----|------------|
| NAME | штатное | "Заявка dd.mm.yyyy HH:MM:SS" |
| PROPERTY_619 | RAW_DATA | JSON сырых данных |
| PROPERTY_620 | STATUS | new / processing / success / error |
| PROPERTY_621 | SOURCE_DOMAIN | Домен источника |
| PROPERTY_622 | PHONE | Телефон |
| PROPERTY_623 | LEAD_ID | ID созданного лида |
| PROPERTY_624 | ERROR_MSG | Сообщение об ошибке |

### БП 775 (на списке 49)

Автоматический последовательный БП, запускается при создании элемента.

Блоки:
1. **SetFieldActivity** — статус = "processing"
2. **SetVariableActivity** — чтение PROPERTY_619 в переменную CB_RAW_JSON
3. **CodeActivity** — парсинг JSON: телефон, имя, домен, UTM, комментарий
4. **IfElseActivity** — проверка CB_PHONE (пустой → error + terminate)
5. **CodeActivity** — поиск в ИБ54 по домену, списки 19 (SOURCE_ID), 22 (ASSIGNED_BY_ID)
6. **CodeActivity** — создание лида через `crm.lead.add` (REST API)
7. **IfElseActivity** — результат (success → запись LEAD_ID / error → запись ERROR_MSG)

### Поток данных (v2)

```
Внешний запрос → webhook_bp.php → lists.element.add (IBlock 49)
                                        ↓
                                   БП 775 (автозапуск)
                                        ↓
                              Парсинг → ИБ54 → crm.lead.add
                                        ↓
                                  Лид в CRM Bitrix24
```

### Файлы v2

| Файл | Назначение |
|------|------------|
| `universal/webhook_bp.php` | Точка входа v2 |
| `universal-system/v2/generate_bp775.py` | Генератор BPT-файла |
| `universal-system/v2/bp775_lead_processor.bpt` | BPT для импорта на портал |
| `universal-system/v2/helpers/` | PHP-хелперы для локальной отладки BP |
| `universal-system/v2/snippets/` | Тела блоков `CodeActivity` |
| `universal-system/v2/docs/` | Документация и схемы BP-проекта |
