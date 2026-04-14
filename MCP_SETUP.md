# Настройка MCP в Cursor на другом компьютере

Этот репозиторий содержит в корне шаблон **`mcp.json`** (без секретов). Рабочий файл Cursor хранит здесь:

| ОС | Путь |
|----|------|
| Linux / macOS | `~/.cursor/mcp.json` |
| Windows | `%USERPROFILE%\.cursor\mcp.json` |

**Не коммить** в git заполненный `mcp.json` с реальными токенами.

В корне репозитория лежит **`.cursor/skills/`** — объединённые скиллы из всех каталогов `/project/**/.cursor/skills/` (один файл на имя скилла; разные версии сшиты с пометкой «Дополнение»). Вложенные копии skills в подпапках репо при синке из диска не дублируются — источник правды в корневом `.cursor/skills/`.

---

## 1. Скопировать конфиг

**Вариант А — с рабочего ПК (рекомендуется):** скопируй со старой машины файл `~/.cursor/mcp.json` целиком на новую и положи по пути выше. Перезапусти Cursor.

**Вариант Б — из этого репозитория:** скопируй из корня репо файл `mcp.json` в `~/.cursor/mcp.json`, затем замени все плейсхолдеры `<...>` на реальные значения (см. ниже).

---

## 2. Что нужно установить на новой машине

| Зависимость | Для чего |
|-------------|----------|
| **Cursor** (актуальная версия) | MCP-хост |
| **Node.js + npm** | сервер `chrome-devtools` запускается через `npx` |
| **Docker** (опционально, для GitHub MCP) | образ `ghcr.io/github/github-mcp-server` |

Проверка:

```bash
node -v
npx --version
docker --version   # если используешь блок github через Docker
```

На **Windows** установи [Docker Desktop](https://www.docker.com/products/docker-desktop/). На Linux — `docker.io` и добавь пользователя в группу `docker`, либо см. раздел «GitHub MCP» ниже.

---

## 3. Сервисы в `mcp.json` (по порядку)

### `b24-dev-mcp` и `bitrix24-docs`

Удалённые **SSE**-серверы Bitrix24 (`https://mcp-dev.bitrix24.tech/mcp`). Секретов в URL нет; если Cursor не подключается — проверь VPN/файрвол и доступность хоста.

### `bitrix24-portal`

Подключение к порталу Bitrix24 через MCP.

- В шаблоне: **`Authorization: Bearer <BITRIX24_MCP_JWT>`**.
- JWT выдаётся при привязке MCP к порталу (авторизация в Bitrix24 / настройки приложения MCP). Скопируй актуальный Bearer с рабочего `~/.cursor/mcp.json` или перевыпусти в админке портала, если истёк срок.

### `vibecode`

Свой SSE-сервер по HTTP.

- **`url`**: замени `<VIBECODE_HOST>` на IP или hostname машины, где крутится сервис (порт обычно `8765`, путь `/sse`).
- **`X-API-Key`**: подставь `<VIBECODE_API_KEY>` — секретный ключ с того же рабочего конфига или с сервера vibecode.

Если сервер недоступен с нового ПК (другая сеть), нужен VPN, проброс порта или смена URL.

### `chrome-devtools`

Запуск: `npx -y chrome-devtools-mcp@latest`. Требуется сеть для первой загрузки пакета. Дополнительных ключей не нужно.

### `github`

Официальный [GitHub MCP Server](https://github.com/github/github-mcp-server) в Docker.

1. Установи Docker, выполни `docker pull ghcr.io/github/github-mcp-server:latest`.
2. Создай [Personal Access Token](https://github.com/settings/tokens) (classic) с нужными scope (минимум `repo` для приватных репозиториев).
3. В `env.GITHUB_PERSONAL_ACCESS_TOKEN` подставь токен вместо `<GITHUB_PERSONAL_ACCESS_TOKEN>`.

Если при запуске контейнера ошибка доступа к сокету Docker (`permission denied` на Linux), добавь пользователя в группу `docker` и перелогинься, либо временно используй обёртку с `sudo docker run ...` (как в локальном скрипте `run-github-mcp.sh`).

**Альтернатива без Docker:** hosted MCP от GitHub Copilot (`https://api.githubcopilot.com/mcp/` + Bearer PAT) — см. [документацию GitHub](https://github.com/github/github-mcp-server/blob/main/docs/installation-guides/install-cursor.md); в браузере этот URL может отдавать 403 — это нормально, это не веб-страница.

---

## 4. Проверка после правок

1. Сохрани `~/.cursor/mcp.json`.
2. Полностью **закрой и снова открой Cursor**.
3. **Settings → Tools & Integrations → MCP** — у серверов должен быть зелёный статус.
4. В чате/агенте проверь, что инструменты MCP доступны (например, запрос к Bitrix или список репозиториев GitHub).

---

## 5. Безопасность

- Храни копию секретов в менеджере паролей, а не в открытых чатах.
- При утечке токена — отзови его на GitHub / в Bitrix24 и создай новый.
- Репозиторий `Skinla/project` содержит только **шаблон** `mcp.json`; не заливай туда файл с настоящими ключами.
