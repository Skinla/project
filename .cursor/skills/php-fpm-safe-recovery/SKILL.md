---
name: php-fpm-safe-recovery
description: Безопасное восстановление PHP-FPM и связки nginx->Apache->PHP-FPM в BitrixEnv. Использовать при запросах про PHP-FPM, Primary script unknown, File not found, 502/504, proxy_fcgi, DocumentRoot, права доступа и после изменений Apache/FPM.
---

# PHP-FPM Safe Recovery (BitrixEnv)

## Быстрые переменные (заполнить сначала)
```bash
DOMAIN="bx.example.com"
DOCROOT="/home/bitrix/www"
FPM_PORT="9000"
APACHE_BACKEND_PORT="8888"
FPM_USER="apache"
FPM_GROUP="apache"
```

## Адаптация за 2 минуты
1. Заполни переменные из блока выше.
2. Проверь preflight (ниже) и убедись, что это BitrixEnv.
3. В командах ниже подставляй `$DOMAIN`, `$DOCROOT`, `$FPM_PORT`, `$APACHE_BACKEND_PORT`.
4. Если preflight не проходит, не применять этот skill "в лоб" — сначала определить реальный стек.

## Preflight (подходит ли сервер)
```bash
command -v nginx
command -v httpd
command -v php-fpm
test -f /etc/httpd/bx/conf/default.conf && echo "OK: bitrix apache vhost"
test -f /etc/php-fpm.d/www.conf && echo "OK: fpm pool"
```

## Когда применять
- Пользователь просит "починить PHP-FPM", "поднять портал", "после перевода на FPM всё упало".
- Ошибки: `AH01071: Primary script unknown`, `File not found.`, `502/504`, `Permission denied`.
- Были изменения в `SetHandler`, `ProxyPassMatch`, `www.conf`, правах на webroot.

## Цель
- Восстановить работу портала с минимальным риском.
- Сначала локализовать причину, потом вносить минимальные изменения.
- Всегда иметь быстрый rollback.

## Правила безопасности
1. Не менять несколько подсистем сразу без промежуточной проверки.
2. Перед правками делать backup файлов конфигов (`cp -a ... .bak_YYYYmmddHHMMSS`).
3. После каждого изменения: `config test -> restart/reload -> curl check -> logs`.
4. Не удалять "старые" блоки, пока не подтвержден новый рабочий путь.
5. В конце удалять тестовый `phpinfo.php`, если создавался.

## Боевой опыт (чтобы не словить повторно)

### 1) `PrivateTmp` у php-fpm ломает `/tmp/php_upload/www`
Если `systemctl show php-fpm -p PrivateTmp` -> `yes`, FPM может не видеть хостовый `/tmp`.
Предпочтительный вариант:
```bash
mkdir -p /var/lib/php/upload_tmp
chown root:apache /var/lib/php/upload_tmp
chmod 770 /var/lib/php/upload_tmp
```
И в `/etc/php-fpm.d/www.conf`:
```ini
php_admin_value[upload_tmp_dir] = /var/lib/php/upload_tmp
```

### 2) `BX_TEMPORARY_FILES_DIRECTORY` должен существовать и быть writable
Bitrix checker использует:
`/home/bitrix/.bx_temp/sitemanager/`
```bash
mkdir -p /home/bitrix/.bx_temp/sitemanager
chown -R bitrix:bitrix /home/bitrix/.bx_temp
chmod 2770 /home/bitrix/.bx_temp /home/bitrix/.bx_temp/sitemanager
```

### 3) Права на writable-каталоги Bitrix
После перехода на FPM проверить/исправить:
```bash
chmod -R g+rwX /home/bitrix/www/bitrix/cache /home/bitrix/www/bitrix/managed_cache /home/bitrix/www/bitrix/stack_cache /home/bitrix/www/bitrix/tmp /home/bitrix/www/upload
find /home/bitrix/www/bitrix/cache /home/bitrix/www/bitrix/managed_cache /home/bitrix/www/bitrix/stack_cache /home/bitrix/www/bitrix/tmp /home/bitrix/www/upload -type d -exec chmod g+s {} \;
```

### 4) HTTP auth для checker/интеграций
В Apache vhost нужны:
```apache
SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1
SetEnvIfNoCase ^Authorization$ "(.+)" REMOTE_USER=$1
```
И `CGIPassAuth On` в `Directory`-контексте.

### 5) У checker может быть "залипший" старый результат
Всегда сверять timestamp запуска и строки `check_upload/check_upload_big/check_upload_raw` в новом журнале.
Не принимать решение по старому блоку лога.

## Базовый workflow

### 1) Снять быстрый срез состояния
```bash
hostname
systemctl is-active nginx httpd php-fpm
netstat -nlpt | egrep ":80|:443|:${APACHE_BACKEND_PORT}|:${FPM_PORT}"
```

### 2) Проверить, что webroot и PHP-файлы существуют
```bash
ls -la "$DOCROOT"
ls -la "$DOCROOT/index.php"
```

### 3) Проверить ответы по цепочке
```bash
curl -I "http://127.0.0.1:${APACHE_BACKEND_PORT}/"
curl -I "https://${DOMAIN}/"
```

### 4) Проверить логи
```bash
journalctl -u httpd -n 100 --no-pager
tail -n 100 /var/log/httpd/error_log
```

## Диагностика `Primary script unknown`
Обычно это одно из:
- некорректный `SCRIPT_FILENAME` (не тот путь в `ProxyPassMatch`/`SetHandler`);
- FPM-воркер не может пройти по пути к файлу из-за прав;
- chroot/doc_root конфликтуют с путём до скрипта.

Проверки:
```bash
grep -nE 'ProxyPassMatch|SetHandler|FilesMatch|DocumentRoot' /etc/httpd/bx/conf/default.conf
grep -nE 'listen|user|group|chroot|doc_root' /etc/php-fpm.d/www.conf
namei -l "$DOCROOT/index.php"
id "$FPM_USER"
id bitrix
```

## Эталон для BitrixEnv (предпочтительно)

### Apache vhost (`/etc/httpd/bx/conf/default.conf`)
Использовать явный `ProxyPassMatch`:
```apache
ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:9000/home/bitrix/www/$1
```

### PHP-FPM pool (`/etc/php-fpm.d/www.conf`)
- `listen = 127.0.0.1:9000`
- `user = apache`
- `group = apache`
- обычно **без** `php_admin_value[doc_root]` и без `chroot` (если не настроено осознанно).

Примечание: если изменил порт или путь, синхронизируй их в Apache (`ProxyPassMatch`) и FPM (`listen`).

## Критичный кейс прав (часто ломает портал)
Если FPM работает от `apache`, а путь закрыт:
- `/home/bitrix` с `700` и `apache` не в группе `bitrix` -> скрипты не читаются.

Безопасный фикс:
```bash
usermod -a -G bitrix apache
chmod 750 /home/bitrix
systemctl restart php-fpm
systemctl restart httpd
systemctl restart nginx
```

Проверка:
```bash
curl -I "http://127.0.0.1:${APACHE_BACKEND_PORT}/"
curl -I "https://${DOMAIN}/"
```

Вариант для нестандартного пользователя пула:
```bash
usermod -a -G bitrix "$FPM_USER"
```

## Шаблон безопасного изменения конфига
```bash
cp -a /etc/httpd/bx/conf/default.conf /etc/httpd/bx/conf/default.conf.bak_$(date +%Y%m%d%H%M%S)
cp -a /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.bak_$(date +%Y%m%d%H%M%S)

# ...внести минимальную правку...

apachectl -t
php-fpm -t
systemctl restart php-fpm
systemctl restart httpd
systemctl restart nginx
```

## Rollback (если стало хуже)
```bash
cp -a /etc/httpd/bx/conf/default.conf.bak_<stamp> /etc/httpd/bx/conf/default.conf
cp -a /etc/php-fpm.d/www.conf.bak_<stamp> /etc/php-fpm.d/www.conf
systemctl restart php-fpm
systemctl restart httpd
systemctl restart nginx
```

## Формат ответа пользователю
- Коротко: причина -> что изменено -> результат проверок.
- Обязательно привести HTTP-коды (`Apache local`, `public HTTPS`) и статус сервисов.
- Если риск не устранён полностью, явно указать остаточный риск и следующий безопасный шаг.
