# SSH подключение “в чате” (Cursor/Agent) — инструкция

Эта инструкция описывает **как подключаться к серверу из чата/агента**, где **нельзя интерактивно вводить пароль**. Поэтому используем **только SSH‑ключ**.

## 0) Важно (про пароль)
- В “чате” SSH идёт в **неинтерактивном** режиме: **окна ввода пароля не будет**.
- Если нужно подключение “логин+пароль” — делай это **в обычном терминале у себя**, не в чате.

## 1) Сгенерировать ключ на стороне “чата”
На машине, где запускается агент (WSL/Cursor):

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
ssh-keygen -t ed25519 -C "victor@cursor-wsl" -f ~/.ssh/bitrix_root_ed25519 -N ""
```

Публичный ключ:

```bash
cat ~/.ssh/bitrix_root_ed25519.pub
```

## 2) Добавить публичный ключ на сервер (под root)
Подключись к серверу **любым доступным способом** (хоть паролем) и выполни:

```bash
mkdir -p /root/.ssh
chmod 700 /root/.ssh

cat >> /root/.ssh/authorized_keys <<'EOF'
<ВСТАВЬ СЮДА СТРОКУ ИЗ ~/.ssh/bitrix_root_ed25519.pub>
EOF

chmod 600 /root/.ssh/authorized_keys
```

## 3) Проверка коннекта из “чата”

```bash
ssh -p 2222 -i ~/.ssh/bitrix_root_ed25519 \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=accept-new \
  root@bitrix.axiom24.ru "whoami && hostname && pwd"
```

Ожидаемо: `root`, `bitrix.axiom24.ru`, `/root`.

## 4) Типовые команды для деплоя (пример)
Пример безопасной заливки папки приложения **без `storage/`**:

```bash
rsync -az --delete \
  --exclude 'storage/**' \
  -e "ssh -p 2222 -i $HOME/.ssh/bitrix_root_ed25519 -o BatchMode=yes -o StrictHostKeyChecking=accept-new" \
  "local/akademia-profi/academyprofi-catalog-app/" \
  root@bitrix.axiom24.ru:/home/bitrix/www/local/akademia-profi/academyprofi-catalog-app/
```

Права/владелец (если нужно):

```bash
ssh -p 2222 -i ~/.ssh/bitrix_root_ed25519 root@bitrix.axiom24.ru -o BatchMode=yes \
  "chown -R bitrix:bitrix /home/bitrix/www/local/akademia-profi/academyprofi-catalog-app"
```

## 5) Частые проблемы и быстрые фиксы

### `Permission denied (publickey,...)`
- ключ **не добавлен** в `/root/.ssh/authorized_keys` или добавлен “не тот”
- проверь права:

```bash
chmod 700 /root/.ssh
chmod 600 /root/.ssh/authorized_keys
```

### “Host key verification failed”
Если у сервера сменился host key:

```bash
ssh-keygen -R "[bitrix.axiom24.ru]:2222"
```

### Пароль просит, но ввода нет
Это значит, что ключ не сработал и SSH пытается перейти к паролю. В “чате” пароль ввести нельзя — **почини ключ** (п.2–3).

