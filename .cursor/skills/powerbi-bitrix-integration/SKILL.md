---
name: powerbi-bitrix-integration
description: Integrate Bitrix (widgets/portal UI) with external services for analytics (Power BI): iframe embedding, user allowlists, public API endpoints, Laravel controllers/routes for config delivery, basic hardening (token/IP). Use when building a Bitrix widget that consumes an external API or when exposing a safe public endpoint for Bitrix.
---

# Power BI ↔ Bitrix integration

## Patterns seen in projects

- **Bitrix-side widget rendering**:
  - рендер через “обёртку + iframe”
  - строгая валидация размеров iframe
  - показ только части сотрудников (allowlist по Bitrix user ID) + локальный in-memory cache результатов

- **External API for Bitrix**:
  - публичный endpoint “для виджета Bitrix” (без основной аутентификации)
  - отдача конфигурации (URL отчёта, параметры)
  - логирование исключений
  - рекомендация: добавить защиту (токен / IP allowlist)

## Bitrix-side debugging pattern

- Наличие отдельной debug-страницы (например, `debug_widget.php`), которая показывает:
  - текущего пользователя (ID/логин/ФИО)
  - список разрешённых ID
  - решение `shouldDisplayWidget()`
  - HTML виджета (в т.ч. preview) или причину “пустой строки”
  - подсказку: подключён ли `init.php` виджета в `/local/php_interface/init.php`

## Laravel integration checklist

- Route:
  - `GET /api/.../public` → отдаёт конфиг для Bitrix
- Controller:
  - `try/catch`, `Log::error(...)`
  - единый формат API-ответов (trait/response helpers)

## Power BI embedding (practical)

- Базовый способ: `reportEmbed` URL в iframe (самый надёжный без SDK).
- Для “одной таблицы/визуализации”:
  - проще выделить отдельную страницу отчёта в Power BI
  - либо использовать JS SDK + access token (если требуется интерактив/точный visual)
