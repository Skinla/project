# project

Компонент Bitrix: `dreamteam:level.happiness`.

Развёртывание: скопируй каталог  
`project/dreamteamcompany/local/components/dreamteam/level.happiness/`  
в корень сайта (рядом с `/bitrix/`), чтобы путь на сервере был  
`/local/components/dreamteam/level.happiness/`.

## Cursor / MCP

В корне лежит шаблон **`mcp.json`** (без секретов) и инструкция **`MCP_SETUP.md`** — как перенести настройки MCP на другой компьютер.

Каталог **`.cursor/skills/`** — Agent Skills для Cursor (Bitrix24, вебхуки и т.д.). На новом ПК скопируй всю папку `.cursor` в корень проекта или в `~/.cursor` по [документации Cursor Skills](https://cursor.com/docs), чтобы правила подхватывались в этом репозитории.
