# План работ с минимальным риском: отключение Xdebug, включение Zend OPcache, проверка, откат

Контекст (по текущему состоянию):
- ОС: RHEL 9.x (Remi), PHP 8.3.x
- SAPI: Apache `mod_php` (Server API: Apache 2.0 Handler), `php-fpm` не используется
- `xdebug` подключён через `/etc/php.d/15-xdebug.ini`
- `Zend OPcache` не грузится: `php --ri "Zend OPcache" -> not present`
- `opcache.so` есть: `/usr/lib64/php/modules/opcache.so`
- `/etc/php.d/10-opcache.ini` пустой (по `sed` — нет содержимого), поэтому OPcache не подключён

Цель:
- снизить CPU и I/O нагрузку на PHP за счёт OPcache
- убрать лишнюю прод-нагрузку от xdebug
- сделать изменения с быстрым и понятным откатом

## 0) Рекомендации по выполнению
- Делать в окно работ (после 20:00).
- Иметь доступ по SSH и консоль/ILO на случай, если Apache не поднимется.
- Все изменения выполняются **без вмешательства в код/БД** (только конфиги PHP и рестарт Apache).

## 1) Pre-check (фиксируем текущее состояние)

```bash
date
hostname
uname -a
df -h /home / | sed -n '1,5p'

php -v
php --ini
php -m | egrep -i 'xdebug|opcache|apcu' || true
php --ri "Zend OPcache" || true

systemctl status httpd --no-pager -l | sed -n '1,40p'
```

Ожидаемо сейчас:
- `php -m` покажет `xdebug`, но не покажет `Zend OPcache`
- `php --ri "Zend OPcache"` выдаст `not present`

## 2) Бэкап конфигов (возможность мгновенного отката)

```bash
ts="$(date +%F_%H%M%S)"
mkdir -p "/root/php-ini-backup-$ts"
cp -a /etc/php.ini "/root/php-ini-backup-$ts/"
cp -a /etc/php.d "/root/php-ini-backup-$ts/"
echo "Backup saved to /root/php-ini-backup-$ts"
```

## 3) Отключить Xdebug (минимальный риск, часто даёт быстрый эффект)

Проверяем, что ini существует:

```bash
ls -l /etc/php.d/15-xdebug.ini
```

Отключаем автоподхват (не удаляя пакет):

```bash
mv /etc/php.d/15-xdebug.ini /etc/php.d/15-xdebug.ini.disabled
```

## 4) Включить Zend OPcache (подключить как Zend extension)

Сначала создаём бэкап (отдельно, точечно):

```bash
cp -a /etc/php.d/10-opcache.ini "/etc/php.d/10-opcache.ini.bak.$(date +%F_%H%M%S)" 2>/dev/null || true
```

Заполняем `/etc/php.d/10-opcache.ini` корректными директивами.
Важно: OPcache подключается как **Zend extension**, поэтому нужна строка `zend_extension=opcache`.

```bash
tee /etc/php.d/10-opcache.ini >/dev/null <<'EOF'
; Zend OPcache
zend_extension=opcache

; Enable OPcache for web (recommended)
opcache.enable=1
opcache.enable_cli=0

; Reasonable defaults for Bitrix
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=100000

; Safe defaults: code updates still apply
opcache.validate_timestamps=1
opcache.revalidate_freq=60

; Disable JIT for stability
opcache.jit=0
opcache.jit_buffer_size=0
EOF
```

## 5) Валидация конфигов перед рестартом Apache

```bash
# Проверяем синтаксис PHP
php -l /etc/php.d/10-opcache.ini >/dev/null && echo "10-opcache.ini: OK"

# Проверяем, что файл действительно не пустой и содержит zend_extension
grep -n "zend_extension" /etc/php.d/10-opcache.ini
```

## 6) Рестарт Apache и проверка

```bash
systemctl restart httpd
systemctl status httpd --no-pager -l | sed -n '1,60p'
```

Проверка, что расширения реально подхватились:

```bash
php -m | egrep -i 'xdebug|opcache' || true
php --ri "Zend OPcache" | sed -n '1,120p'
```

Ожидаемо:
- `xdebug` **не** должен присутствовать в `php -m`
- `php --ri "Zend OPcache"` должен показывать секцию с настройками

Проверка в вебе (Битрикс → phpinfo):
- должна появиться секция **Zend OPcache**

## 7) Опционально: оценка/чистка кэшей (только если нужно срочно освободить место)

**Безопасно посмотреть размеры:**

```bash
du -sh \
  /home/bitrix/www/upload/resize_cache \
  /home/bitrix/www/upload/main_preview \
  /home/bitrix/www/upload/tmp_cloud_files \
  /home/bitrix/www/upload/tmp \
  /home/bitrix/www/bitrix/cache \
  /home/bitrix/www/bitrix/managed_cache 2>/dev/null
```

**Безопасная чистка (кэш/временное):**

```bash
rm -rf /home/bitrix/www/upload/resize_cache/*
rm -rf /home/bitrix/www/upload/main_preview/*
rm -rf /home/bitrix/www/upload/tmp_cloud_files/*
rm -rf /home/bitrix/www/upload/tmp/*
```

Примечание: после чистки первые открытия страниц/картинок могут быть медленнее, пока кэш пересоздаётся.

## 8) Откат (если Apache/PHP не стартует или стало хуже)

### Быстрый откат (точечный)

```bash
# Вернуть xdebug как было
if [ -f /etc/php.d/15-xdebug.ini.disabled ]; then mv /etc/php.d/15-xdebug.ini.disabled /etc/php.d/15-xdebug.ini; fi

# Вернуть opcache ini из бэкапа, если делали
bak="$(ls -1t /etc/php.d/10-opcache.ini.bak.* 2>/dev/null | head -1)"
if [ -n "$bak" ]; then cp -a "$bak" /etc/php.d/10-opcache.ini; fi

systemctl restart httpd
systemctl status httpd --no-pager -l | sed -n '1,60p'
```

### Полный откат из каталога бэкапа

Подставьте путь, который вывело на шаге 2:

```bash
# пример:
# ts="2026-01-30_200000"
# cp -a "/root/php-ini-backup-$ts/php.ini" /etc/php.ini
# cp -a "/root/php-ini-backup-$ts/php.d/." /etc/php.d/
# systemctl restart httpd
```

## 9) Пост-мониторинг (10–15 минут)

```bash
top -b -n1 | sed -n '1,20p'
ps -eo pid,cmd,%cpu,%mem --sort=-%cpu | head -25
```

Если наблюдаются тормоза/100% CPU:
- проверить, что `xdebug` точно отключён (`php -m | grep -i xdebug` должен быть пустым)
- проверить, что OPcache включён (`php --ri "Zend OPcache"` и `opcache.enable => On`)

