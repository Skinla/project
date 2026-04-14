## Деплой и секреты (Bitrix Box)

### Что коммитим в репозиторий
- **Коммитим**: `bitrix.access.env.example` (шаблон).
- **НЕ коммитим**: `bitrix.access.env` (реальные секреты) — он уже в `.gitignore`.
- **НЕ коммитим**: `webhook_check/` (дампы ответов API) — уже в `.gitignore`.

### Где хранить реальные секреты на продакшне
Вариант A (проще всего): отдельный файл на сервере **вне web‑root**, доступный только пользователю, под которым выполняется PHP (в Bitrix Box это обычно `bitrix`).

Пример пути:
- `/etc/academyprofi/bitrix.access.env`

Создание на сервере:

```bash
sudo mkdir -p /etc/academyprofi
sudo cp /project/Academy/Website/bitrix.access.env.example /etc/academyprofi/bitrix.access.env
sudo nano /etc/academyprofi/bitrix.access.env

# права: читать может только root и пользователь веб-сервера Bitrix Box (обычно `bitrix`), без “world”
sudo chown root:bitrix /etc/academyprofi/bitrix.access.env
sudo chmod 640 /etc/academyprofi/bitrix.access.env
```

Дальше приложение **уже** читает этот файл по умолчанию:
- `BITRIX_ACCESS_ENV_PATH=/etc/academyprofi/bitrix.access.env` (опционально; если не задан — используется этот путь по умолчанию)
- `install.php` и `handler.php` одинаково используют `BITRIX_ACCESS_ENV_PATH` → удобно для dev/prod.

### Какие секреты реально нужны для работы
- **Bitrix24 webhook**:
  - `BITRIX24_WEBHOOK_BASE_URL`
  - `BITRIX24_IBLOCK_ID`
- **Bitrix Box local app**:
  - `BITRIX_APP_CLIENT_ID`
  - `BITRIX_APP_CLIENT_SECRET`
  - `BITRIX_BOX_BASE_URL` + пути `install/handler` (это не секреты, но конфиг)

### Где лежит приложение на Bitrix Box
Файлы приложения должны быть размещены в web-root Bitrix Box:
- `/home/bitrix/www/local/akademia-profi/academyprofi-catalog-app/`

Публичные URL (как минимум):
- `/local/akademia-profi/academyprofi-catalog-app/install.php`
- `/local/akademia-profi/academyprofi-catalog-app/handler.php/health`

### Быстрая проверка, что секреты не попали в git

```bash
cd /project/Academy/Website
git status --ignored
git check-ignore -v bitrix.access.env webhook_check/ || true
```

