# project

Зеркало содержимого WSL-каталога **`/project`** (`\\wsl.localhost\Ubuntu\project`): все верхнеуровневые папки (`Academy`, `dreamteamcompany`, `milleniummedc`, …) лежат в **корне репозитория**.

## Bitrix: `dreamteam:level.happiness`

Развёртывание: скопируй каталог  
`dreamteamcompany/local/components/dreamteam/level.happiness/`  
в структуру сайта (рядом с `/bitrix/`), чтобы на сервере путь был  
`/local/components/dreamteam/level.happiness/`.

## Cursor / MCP

- Шаблон **`mcp.json`** (без секретов) и **`MCP_SETUP.md`** — перенос MCP на другой компьютер.
- **`.cursor/skills/`** — объединённые skills из всех подпроектов в `/project` (дубликаты по содержимому схлопнуты; отличия добавлены блоками «Дополнение»).

## Что не попало в git

См. **`.gitignore`**: очередь/логи `universal-system`, один `.xls` >100MB (лимит GitHub).
