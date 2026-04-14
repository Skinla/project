# academyprofi-catalog-app (Bitrix Box local app)

Файлы деплоя на Bitrix Box:
- `install.php` — initial installation path (принимает `ONAPPINSTALL`, сохраняет токены, регистрирует блоки)
- `handler.php` — обработчик API и вспомогательных роутов
- `assets/` — CSS/JS, подключаемые в `manifest.assets.*` при `landing.repo.register`

Локальная разработка: см. `VERIFICATION_PLAN.md` в корне репозитория.

