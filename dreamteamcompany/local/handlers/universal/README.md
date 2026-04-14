# Universal Webhook Handler

Универсальный обработчик входящих вебхуков для создания лидов в Bitrix24 CRM. Принимает запросы из различных источников (Tilda, CallTouch, Bitrix24, Koltaсh и др.), определяет тип данных, нормализует их и создаёт лиды в CRM.

## Структура проекта

```
universal/
├── webhook.php                    # Точка входа — принимает POST/GET запросы
└── universal-system/
    ├── config.php                 # Конфигурация
    ├── logger_and_queue.php       # Логирование и работа с очередью
    ├── request_tracker.php        # Отслеживание запросов (request_id)
    ├── queue_manager.php          # Менеджер очередей (raw → detected → normalized → processed)
    ├── data_type_detector.php     # Определение типа данных и выбор нормализатора
    ├── lead_processor.php         # Создание лидов в Bitrix24
    ├── duplicate_checker.php      # Проверка дубликатов (телефон + домен)
    ├── error_handler.php          # Обработка ошибок и уведомления в чат
    ├── chat_notifications.php     # Отправка сообщений в Bitrix24 чат
    ├── retry-raw-errors.php       # Веб-интерфейс управления очередями
    ├── setup_cron.php             # Настройка cron
    ├── auto_sync.php              # Скрипт автоматической обработки (для cron)
    ├── add-to-iblock.php          # Добавление элементов в ИБ54
    ├── add-to-exceptions.php      # Добавление в исключения
    ├── normalizers/               # Нормализаторы данных
    │   ├── base_normalizer.php    # Базовый абстрактный класс
    │   ├── normalizer_factory.php # Фабрика нормализаторов
    │   ├── tilda_normalizer.php   # Tilda формы
    │   ├── calltouch_normalizer.php # CallTouch
    │   ├── bitrix24_webhook_normalizer.php # Bitrix24 webhook
    │   ├── koltaсh_normalizer.php # Koltaсh
    │   └── generic_normalizer.php # Универсальный fallback
    ├── queue/                     # Очереди обработки
    │   ├── raw/                   # Сырые входящие данные
    │   ├── raw/raw_errors/        # Ошибки определения типа
    │   ├── detected/              # Данные с определённым типом
    │   ├── normalized/            # Нормализованные данные
    │   ├── processed/             # Успешно обработанные
    │   ├── duplicates/            # Дубликаты
    │   └── failed/                # Ошибки создания лидов
    ├── logs/                      # Логи
    └── test/                      # Тесты
```

## Архитектура обработки

```
Входящий запрос (POST/GET)
    ↓
webhook.php: парсинг, определение домена, валидация телефона
    ↓
RequestTracker: проверка request_id (избежание дублей)
    ↓
Сохранение в queue/raw/
    ↓
processAllQueues() — три этапа:
    ↓
1. processRawFiles() [data_type_detector]
   raw → detected (поиск нормализатора в ИБ54 по домену/formid/SOURCE_DESCRIPTION)
    ↓
2. processDetectedFiles() [queue_manager]
   detected → normalized (нормализатор преобразует в стандартный формат)
    ↓
3. processNormalizedFiles() [lead_processor]
   normalized → processed (проверка дублей, поиск в ИБ54, создание лида в Bitrix24)
```

## Ключевые компоненты

### webhook.php
- Принимает POST (JSON, form-urlencoded, multipart) и GET
- Определяет `source_domain` из HTTP_REFERER, HTTP_ORIGIN, extra.href, CallTouch URL и др.
- Унифицирует поле телефона (Phone, phone, contacts.phone, callerphone)
- Валидирует телефон (пустые блокируются, кроме Bitrix24 webhook)
- Сохраняет сырые данные в `queue/raw/`
- Использует `RequestTracker` для дедупликации по request_id
- Запускает синхронную обработку через `processAllQueues()` с блокировкой (flock)
- Отправляет уведомление в чат при накоплении 10+ файлов в raw

### Нормализаторы
- **Tilda** — формы Tilda (`__submission`, formname)
- **CallTouch** — CallTouch (callerphone, siteId, subPoolName)
- **Bitrix24** — вебхуки Bitrix24 (SOURCE_DESCRIPTION)
- **Koltaсh** — Koltaсh (answers, raw)
- **Generic** — fallback для неизвестных форматов

Нормализатор выбирается по данным из инфоблока 54 (по домену, formid или SOURCE_DESCRIPTION).

### Bitrix24 интеграция
- Создание лидов через REST API (`crm.lead.add`)
- Инфоблок 54: настройки по домену (исполнитель, источник, наблюдатели, инфоповод)
- Для CallTouch — поиск по subPoolName + siteId (PROPERTY_199)
- Кэширование запросов к ИБ54 (2 часа)

### Защита от дублей
- **RequestTracker** — hash от сырых данных, статусы: processing, success, failed
- **DuplicateChecker** — телефон + домен, логи `processed_phones.log`, `processing_phones.log`

## Конфигурация (config.php)

| Параметр | Описание |
|----------|----------|
| `queue_dir` | Папка очередей |
| `logs_dir` | Папка логов |
| `portal_webhooks` | URL вебхука Bitrix24 |
| `error_chat_id` | ID чата для уведомлений |
| `max_files_per_run` | Макс. файлов за запуск (50) |
| `max_execution_time` | Таймаут цикла (25 сек) |
| `lock_timeout` | Таймаут блокировок (300 сек) |
| `raw_files_notification_threshold` | Порог уведомления (10 файлов) |

## Запуск

### Веб
- **Webhook:** `POST/GET` на `webhook.php`
- **Управление очередями:** `retry-raw-errors.php`

### CLI
```bash
# Обработка всех очередей
php queue_manager.php run

# Статистика
php queue_manager.php stats

# Очистка старых файлов (7 дней)
php queue_manager.php cleanup 7

# Повтор failed
php queue_manager.php retry

# Повтор raw_errors
php queue_manager.php retry-raw-errors
```

### Cron
```bash
*/2 * * * * php /path/to/universal-system/queue_manager.php run
```

## Логи

- `logs/global.log` — основной лог
- `logs/queue_processing.log` — обработка очередей
- `logs/processed_phones.log` — обработанные телефоны
- `logs/processing_phones.log` — телефоны в обработке
- `logs/request_tracker.log` — отслеживание request_id
- `logs/raw_requests.log` — сырые запросы

## Документация

- `universal-system/QUEUE_MANAGEMENT.md` — управление очередями и веб-интерфейс

## Требования

- PHP 7.4+
- Bitrix24 (REST API)
- Доступ к Bitrix (prolog_before.php) для ИБ54, списков 19 и 22
