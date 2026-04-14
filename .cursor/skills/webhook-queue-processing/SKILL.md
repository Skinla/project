---
name: webhook-queue-processing
description: Design and maintain webhook ingestion with file-based queues: raw→detected→normalized→processed, normalizers (Tilda/Calltouch/Bitrix webhook/generic), locking, rate limiting/timeouts, dedup (phone+domain and request_id), retries, logging, chat notifications. Use when building/diagnosing PHP webhook handlers and asynchronous processing pipelines.
---

# Webhook + file-queue processing

## Reference architecture (used in projects)

- **Entry point** (`webhook.php` / gateway):
  - принимает HTTP (POST/GET)
  - логирует “сырьё”
  - сохраняет событие в файловую очередь (`raw_*.json`)
  - быстро отвечает `200 OK`
  - запускает обработку очередей (web/CLI)

- **Pipeline queues**:

```
Raw → Detected → Normalized → Processed
                ↓
            Duplicates
                ↓
             Failed
                ↓
          raw_errors / queue_errors
```

## Data-type detection + normalizers

- **Detected stage**:
  - определение источника (домен, formid, сигнатуры payload)
  - выбор нормализатора (например, из “конфига/ИБ”)

- **Normalizers**:
  - `tilda_normalizer`: формы Tilda / URL-encoded
  - `calltouch_normalizer`: Calltouch payload
  - `bitrix24_webhook_normalizer`: события/данные из Bitrix webhook
  - `generic_normalizer`: fallback

- **Normalization output** (пример набора полей):
  - `phone` (только цифры → `+7...`)
  - `name`, `email`
  - `source_domain`
  - `utm_*`, `form_name`
  - `comment` (расширенный контекст)
  - `raw_data` (для отладки)

## Reliability patterns

- **Lock files**: `*.json.lock`
  - пропуск, если lock свежий
  - авто-удаление устаревших lock (например, \(> 5\) минут)

- **Rate limiting**:
  - максимум N файлов за запуск (часто 50)
  - общий таймаут цикла (часто 25 секунд)

- **Non-blocking I/O**:
  - `flock(LOCK_EX | LOCK_NB)` + fallback запись без блокировки (лучше потерять лог, чем повиснуть)

- **Atomic-ish queue write**:
  - уникальные имена (`timestamp + random hash`)
  - попытки/повторы при коллизии

## Dedup / idempotency

### 1) Дубли по бизнес-ключу

- типовой ключ: `phone + source_domain` (или расширенный ключ)
- статусы: `processing` / `processed` / `failed`

### 2) request_id (устойчивый hash payload)

- генерация стабильного `request_id`:
  - нормализовать данные (удалить `timestamp`, `raw_file_path`, `request_id`)
  - рекурсивно `ksort`
  - `md5(json_encode(...))`

- хранение:
  - лог/реестр запросов (например, `logs/request_tracker.log`)
  - TTL (например, хранить только последние 24 часа)

- поведение:
  - при дубле можно **возвращать “success”** с уже созданным `lead_id` (идемпотентность)

## Monitoring + ops

- **Logs**:
  - `global.log`, `queue_processing.log`, `error.log`
  - дублирование критичных ошибок в Apache error_log (если доступно)

- **Web UI for retries**:
  - просмотр файлов очереди (raw/detected/normalized/failed/raw_errors)
  - кнопки “обработать/удалить/обработать всё”

- **Chat notifications (Bitrix im)**:
  - отправка в чат при критических ошибках или росте очереди
  - следить за правами вебхука (scope `im`)

## Background execution

- **Webhook gateway** может запускать CLI-обработчик “в фоне”:
  - Linux: `nohup php ... &`
  - Windows: `start /B php ...`
- Осмысленная отладка путей:
  - `realpath`, `which/where php`, проверка `file_exists/is_readable`

