# CRM Metadata App

Приложение показывает метаданные Bitrix по двум источникам:

- `Облако` - данные собираются на лету через VibeCode API;
- `Коробка` - данные читаются из сохраненного snapshot в `data/box`.

Внутри каждого источника доступны разделы:

- `CRM`
- `Смарт-процессы`
- `Бизнес-процессы`

Дополнительно доступен отдельный режим `Сопоставление`, который сравнивает облако и коробку по названию и показывает коды с обеих сторон.

Для CRM и смарт-процессов выводятся поля, пользовательские `UF_CRM_*`, воронки и стадии. Для бизнес-процессов показывается реестр шаблонов БП.

## Запуск

Для облака:

```bash
export VIBECODE_API_KEY="ваш_ключ"
export VIBECODE_PORTAL="ваш_портал.bitrix24.ru"
python3 server.py
```

После запуска откройте [http://localhost:8000](http://localhost:8000).

## Выгрузка коробки

Snapshot коробки хранится в `data/box`:

- `data/box/catalog.json` - каталог сущностей для вкладки `Коробка`;
- `data/box/entities/*.json` - готовые payload-файлы по каждой сущности.

Чтобы обновить данные коробки, запустите экспорт по SSH:

```bash
python3 scripts/export_box_metadata.py \
  --host bitrix.tms.net.ru \
  --user root \
  --password 'пароль'
```

Скрипт подключается к коробке по SSH, запускает PHP внутри Bitrix, получает:

- CRM поля и пользовательские поля;
- воронки и стадии;
- смарт-процессы;
- шаблоны бизнес-процессов.

Для **человекочитаемых подписей** штатных полей (в т.ч. в режиме «Сопоставление») PHP на коробке: сначала `getFieldCaption()` фабрики CRM, если пусто — языковая фраза `Loc::getMessage('CRM_COMPANY_FIELD_ADDRESS_2')` и т.п. (`CRM_LEAD_FIELD_*`, `CRM_DEAL_FIELD_*`, …), как у подписей в смарт-процессах и в примере с `LANG` в `milleniummedc/get_target_fields.php` для UF.

Локально (без пересъёмки snapshot) подписи можно дополнить файлом `data/field_title_hints_ru.json` — его подхватывает `server.py` и `export_box_metadata.py`.

После успешного запуска snapshot будет перезаписан в `data/box`.

## Проверки (audit)

Целостность snapshot коробки (наличие `entities/*.json`, парсинг JSON, опционально «свежесть» `generatedAt` и лишние файлы):

```bash
python3 scripts/audit_box_snapshot.py
python3 scripts/audit_box_snapshot.py --check-orphans --max-age-hours 48 --strict
```

Сводка сопоставления облако ↔ коробка (та же логика, что у `/api/entity-mapping`; нужен `VIBECODE_API_KEY`):

```bash
export VIBECODE_API_KEY="..."
python3 scripts/audit_cloud_box_mapping.py
python3 scripts/audit_cloud_box_mapping.py --scope all --fail-on-unmatched
python3 scripts/audit_cloud_box_mapping.py --scope fields --json
```

Режим `--scope all` делает много запросов к VibeCode (поля и списочные значения по всем совпавшим по названию сущностям).

Подписи полей коробки в UI (если в snapshot `title` совпадает с кодом): опциональный словарь `data/field_title_hints_ru.json` подмешивается при чтении snapshot и при SSH-экспорте. Проверка:

```bash
python3 scripts/verify_box_field_title_hints.py --slug companies --max-bad 0
```

## Интерфейс

Локально:

- облако: [http://localhost:8000/leads](http://localhost:8000/leads)
- коробка: [http://localhost:8000/leads?source=box](http://localhost:8000/leads?source=box)
- сопоставление: [http://localhost:8000/leads?view=mapping](http://localhost:8000/leads?view=mapping)
- сделки коробки: [http://localhost:8000/deals?source=box](http://localhost:8000/deals?source=box)
- смарт-процессы доступны по адресам вида `/sp-<entityTypeId>`, например:
  [http://localhost:8000/sp-1038?source=box](http://localhost:8000/sp-1038?source=box)

Если приложение запущено на сервере:

- главная страница: [http://93.77.183.129:3000/](http://93.77.183.129:3000/)
- облако, лиды: [http://93.77.183.129:3000/leads](http://93.77.183.129:3000/leads)
- облако, сделки: [http://93.77.183.129:3000/deals](http://93.77.183.129:3000/deals)
- облако, счета: [http://93.77.183.129:3000/invoices](http://93.77.183.129:3000/invoices)
- облако, контакты: [http://93.77.183.129:3000/contacts](http://93.77.183.129:3000/contacts)
- облако, компании: [http://93.77.183.129:3000/companies](http://93.77.183.129:3000/companies)
- облако, предложения: [http://93.77.183.129:3000/quotes](http://93.77.183.129:3000/quotes)
- облако, шаблоны БП: [http://93.77.183.129:3000/bp-templates](http://93.77.183.129:3000/bp-templates)
- коробка, лиды: [http://93.77.183.129:3000/leads?source=box](http://93.77.183.129:3000/leads?source=box)
- коробка, сделки: [http://93.77.183.129:3000/deals?source=box](http://93.77.183.129:3000/deals?source=box)
- коробка, счета: [http://93.77.183.129:3000/invoices?source=box](http://93.77.183.129:3000/invoices?source=box)
- коробка, контакты: [http://93.77.183.129:3000/contacts?source=box](http://93.77.183.129:3000/contacts?source=box)
- коробка, компании: [http://93.77.183.129:3000/companies?source=box](http://93.77.183.129:3000/companies?source=box)
- коробка, предложения: [http://93.77.183.129:3000/quotes?source=box](http://93.77.183.129:3000/quotes?source=box)
- коробка, шаблоны БП: [http://93.77.183.129:3000/bp-templates?source=box](http://93.77.183.129:3000/bp-templates?source=box)
- сопоставление: [http://93.77.183.129:3000/leads?view=mapping](http://93.77.183.129:3000/leads?view=mapping)
- смарт-процессы облака доступны по адресам вида `/sp-<entityTypeId>`, например:
  [http://93.77.183.129:3000/sp-145](http://93.77.183.129:3000/sp-145)
- смарт-процессы коробки доступны по адресам вида `/sp-<entityTypeId>?source=box`, например:
  [http://93.77.183.129:3000/sp-1038?source=box](http://93.77.183.129:3000/sp-1038?source=box)

## Сопоставление

Режим `Сопоставление` сравнивает облако и коробку по названию и показывает таблицы:

- сущности: `название / код в облаке / код в коробке`
- поля: `сущность / название / код в облаке / код в коробке`
- списочные значения: `сущность и поле / название / код в облаке / код в коробке`

Правила:

- **поля и списки:** сначала пара по **каноническому коду** (облако camelCase / коробка `UPPER_SNAKE`, граница буква–цифра), затем остаток — по **названию** и мягким алиасам;
- **сущности:** по названию (как раньше);
- в таблицах красным выделяются строки, где пара не найдена с одной из сторон.

## Продакшен: подключение и выкладка (репликация для агента)

Ниже сводка для **другого агента или человека**, чтобы с нуля подключиться к серверу, открыть приложение и повторить выкладку.

### Роли и адреса

| Что | Хост | Порт | Назначение |
|-----|------|------|------------|
| Веб-приложение CRM метаданные | `93.77.183.129` | `3000` | HTTP, браузер и `curl` |
| Удалённый MCP VibeCode (опционально) | `93.77.183.129` | `8765` | SSE для Cursor, путь `/sse` |
| SSH | `93.77.183.129` | `22` | Администрирование |

Публичный базовый URL приложения: [http://93.77.183.129:3000/](http://93.77.183.129:3000/)

Параметр источника в URL: `?source=cloud` (по умолчанию) или `?source=box`. Режим сопоставления: `?view=mapping` (см. раздел «Интерфейс» выше).

### SSH-доступ (root)

```text
Хост:     93.77.183.129
Пользователь: root
Пароль:   9Y2tpv7yggxrGemVMdCGcA
```

Подключение вручную:

```bash
ssh -o StrictHostKeyChecking=accept-new root@93.77.183.129
```

Если нужен неинтерактивный вход (например, из скрипта агента), на машине, где есть `sshpass`:

```bash
sshpass -p '9Y2tpv7yggxrGemVMdCGcA' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@93.77.183.129 "hostname"
```

### Где на диске лежит приложение

| Путь | Назначение |
|------|------------|
| `/opt/lead-meta-app/` | Код: `server.py`, `static/`, `data/`, `scripts/`, `vibe-guide.json` |
| `/etc/lead-meta-app.env` | Секреты и переменные окружения для сервиса (права `600`) |
| `/etc/systemd/system/lead-meta-app.service` | Unit systemd |
| `/root/lead-meta-app.tar.gz` | Временный архив при выкладке (можно перезаписывать) |

### Переменные окружения (`/etc/lead-meta-app.env`)

Файл читает unit `lead-meta-app`. Минимально нужны:

| Переменная | Обязательно | Описание |
|------------|-------------|----------|
| `PORT` | да | Порт HTTP, в продакшене `3000` |
| `VIBECODE_API_KEY` | да | Ключ VibeCode API (`vibe_api_...`) |
| `VIBECODE_PORTAL` | нет | Подпись портала в UI, например `tms24.bitrix24.ru` |
| `BITRIX_MCP_TOKEN` | нет | JWT для `https://mcp.bitrix24.com/mcp/` — нужен для **названий воронок сделок** через MCP; без него облако работает, но имена воронок могут быть обобщёнными |

Пример содержимого (подставьте актуальный `BITRIX_MCP_TOKEN` из `~/.cursor/mcp.json` → `mcpServers.bitrix24-portal.headers.Authorization`, значение после префикса `Bearer `):

```bash
cat <<'EOF' >/etc/lead-meta-app.env
PORT=3000
VIBECODE_API_KEY=vibe_api_vMf468FHPNbeaINE25RWI0OZtTfMASXz_84ca14
VIBECODE_PORTAL=tms24.bitrix24.ru
BITRIX_MCP_TOKEN=ВСТАВИТЬ_JWT_ИЗ_CURSOR_MCP
EOF
chmod 600 /etc/lead-meta-app.env
```

Просмотр уже настроенного файла на сервере (после входа по SSH):

```bash
cat /etc/lead-meta-app.env
```

### Unit systemd `lead-meta-app`

Если unit ещё не создан, создайте `/etc/systemd/system/lead-meta-app.service`:

```ini
[Unit]
Description=CRM metadata app (lead-meta-app)
After=network.target

[Service]
Type=simple
EnvironmentFile=/etc/lead-meta-app.env
WorkingDirectory=/opt/lead-meta-app
ExecStart=/usr/bin/python3 /opt/lead-meta-app/server.py
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

Затем:

```bash
systemctl daemon-reload
systemctl enable lead-meta-app
systemctl restart lead-meta-app
systemctl status lead-meta-app --no-pager
```

Проверка, что процесс слушает все интерфейсы:

```bash
ss -ltnp | grep 3000
```

Ожидается строка с `0.0.0.0:3000` и `python3`.

### Файрвол

Если включён `ufw`, откройте порт приложения:

```bash
ufw allow 3000/tcp comment lead-meta-app
ufw status
```

### Выкладка обновления (ручной пошаговый)

Из **корня репозитория** на машине разработчика:

```bash
tar -czf app.tar.gz server.py static README.md vibe-guide.json data scripts
scp app.tar.gz root@93.77.183.129:/root/lead-meta-app.tar.gz
```

На сервере (после SSH):

```bash
rm -rf /opt/lead-meta-app/*
tar -xzf /root/lead-meta-app.tar.gz -C /opt/lead-meta-app
systemctl restart lead-meta-app
journalctl -u lead-meta-app -n 50 --no-pager
```

### Выкладка одной командой (для агента с `sshpass`)

Из каталога с клоном репозитория (`server.py` в текущей директории):

```bash
tar -czf app.tar.gz server.py static README.md vibe-guide.json data scripts && \
sshpass -p '9Y2tpv7yggxrGemVMdCGcA' scp -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
  app.tar.gz root@93.77.183.129:/root/lead-meta-app.tar.gz && \
sshpass -p '9Y2tpv7yggxrGemVMdCGcA' ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null root@93.77.183.129 \
  "rm -rf /opt/lead-meta-app/* && tar -xzf /root/lead-meta-app.tar.gz -C /opt/lead-meta-app && systemctl restart lead-meta-app && systemctl is-active lead-meta-app"
```

### Проверки после выкладки

На сервере:

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://127.0.0.1:3000/
curl -sS "http://127.0.0.1:3000/api/entity-meta?entity=leads" | head -c 200
```

Снаружи (с любой машины с доступом в интернет):

```bash
curl -sS -o /dev/null -w "%{http_code}\n" http://93.77.183.129:3000/
curl -sS "http://93.77.183.129:3000/api/entity-meta?entity=leads" | head -c 200
```

### Удалённый MCP VibeCode на том же хосте (для Cursor)

Отдельный сервис `vibecode-mcp` может слушать SSE на порту **8765**. В `~/.cursor/mcp.json` запись `vibecode` обычно выглядит так:

```json
"vibecode": {
  "type": "sse",
  "url": "http://93.77.183.129:8765/sse",
  "headers": {
    "X-API-Key": "kCMSMz12_j9Wbb6SFoBlHlx1z9Z8VH76t_VoGnMIsh8"
  }
}
```

Проверка SSE (должен быть `200` и `text/event-stream`):

```bash
curl -i -N --max-time 3 -H 'X-API-Key: kCMSMz12_j9Wbb6SFoBlHlx1z9Z8VH76t_VoGnMIsh8' http://93.77.183.129:8765/sse
```

Статус сервисов на сервере:

```bash
systemctl status lead-meta-app vibecode-mcp --no-pager
```

### Публичные URL приложения (кратко)

- главная: [http://93.77.183.129:3000/](http://93.77.183.129:3000/)
- облако: `/leads`, `/deals`, `/invoices`, `/contacts`, `/companies`, `/quotes`, `/bp-templates`
- коробка: те же пути с `?source=box`
- сопоставление: [http://93.77.183.129:3000/leads?view=mapping](http://93.77.183.129:3000/leads?view=mapping)
- смарт-процессы: `http://93.77.183.129:3000/sp-<entityTypeId>` и с `?source=box`

Полный список см. раздел «Интерфейс» выше (там же локальные URL для разработки).

## API

- `/api/entity-catalog?source=cloud|box` - список сущностей для выбранного источника;
- `/api/entity-meta?entity=...&source=cloud|box` - метаданные выбранной сущности.
- `/api/entity-mapping` - агрегированное сопоставление сущностей, полей и списочных значений между облаком и коробкой.

## Что внутри

- `server.py` - HTTP-сервер, API и чтение snapshot коробки;
- `static/index.html` - интерфейс с режимами просмотра данных и сопоставления;
- `scripts/export_box_metadata.py` - SSH-экспорт метаданных коробки;
- `data/box` - сохраненные snapshot-файлы коробки;
- `data/field_title_hints_ru.json` - опциональные русские подписи полей коробки, если в snapshot только код;
- `scripts/verify_box_field_title_hints.py` - проверка, что штатные поля не остались с title=код.
