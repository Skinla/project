# Опыт: форма "Написать сотруднику" (uskov.ru)

## Симптом
- На странице сотрудника `https://www.uskov.ru/about/staff/<employee>/` кнопка **"Написать сообщение"** открывает попап-форму.
- Форма отправлялась, но письмо **не доходило конкретному сотруднику** (email не подставлялся как получатель).

## Где реализовано

### 1) Страница сотрудника (передача email сотрудника во фронт)
- Компонент: `bitrix:news` шаблон `employees`
- Файл:
  - `/home/uskov-1/uskov.ru/docs/local/templates/main/components/bitrix/news/employees/bitrix/news.detail/.default/template.php`
- В кнопке "Написать сообщение" прокидывается:
  - `data-person` (ID сотрудника)
  - `data-email` (свойство сотрудника `EMAIL`)

### 2) JS (подстановка в форму и AJAX-отправка)
- Файл:
  - `/home/uskov-1/uskov.ru/docs/local/templates/main/tpl/assets/js/main.js`
- Логика:
  - при открытии попапа копирует `data-person` → `input[name="person"]`
  - копирует `data-email` → `input[name="mailto"]`
  - отправляет форму AJAX на:
    - `POST /local/tools/write-message.php`

### 3) Обработчик (серверная отправка)
- Файл:
  - `/home/uskov-1/uskov.ru/docs/local/tools/write-message.php`
- Принимает POST-параметры:
  - `name`, `mail`, `message`
  - `person` (ID сотрудника)
  - `mailto` (email сотрудника)
  - `g-recaptcha-response`, `lang`
- Делает:
  - проверка reCAPTCHA
  - запись сообщения в инфоблок IB_FORM_MESSAGE
  - отправка почтового события Bitrix:
    - `CEvent::Send("MESSAGE_PERSONAL", $siteID, $arEventFields)`

## Причина проблемы

### A) В обработчике email получателя не передавался в событие
В `/local/tools/write-message.php` в массиве `$arEventFields` стояла строка с переменной **без ключа**:

- было (ошибка):
  - `$employee_mail,`

Из-за этого поле `EMAIL_TO` не попадало в `CEvent::Send(...)`, и почтовый шаблон не мог подставить адрес получателя.

### B) Почтовый шаблон для s1 был захардкожен на офисные адреса (исправлено)
Изначально для `MESSAGE_PERSONAL` (LID = `s1`) поле "Кому" было фиксированным, а не `#EMAIL_TO#`.
В результате письмо не могло уходить на конкретного сотрудника.

## Решение

### 1) Исправлен `$arEventFields` (вариант 1: минимальный фикс)
Файл:
- `/home/uskov-1/uskov.ru/docs/local/tools/write-message.php`

Сделано:
- заменено "голое" `$employee_mail,` на:
  - `'EMAIL_TO' => $employee_mail,`

### 2) Почтовые шаблоны события `MESSAGE_PERSONAL`
В админке Bitrix для события `MESSAGE_PERSONAL`:
- для `s1` и `s2` выставлено:
  - `EMAIL_TO = #EMAIL_TO#`

### 3) От кого приходят письма (`DEFAULT_EMAIL_FROM`)
Шаблон `MESSAGE_PERSONAL` использует:
- `EMAIL_FROM = #DEFAULT_EMAIL_FROM#`

Источник `#DEFAULT_EMAIL_FROM#` для `s1`:
- `main.email_from` (в Главном модуле) может быть `spb@uskov.ru`
- но у сайта `s1` в настройках "Сайты" было:
  - `EMAIL = s.uskova@uskov.ru`
и фактически "From:" подставлялся оттуда.

Чтобы изменить дефолтный адрес отправителя:
- либо в "Сайты" → `s1` поменять поле `E-mail`
- либо в почтовом шаблоне `MESSAGE_PERSONAL (s1)` жёстко указать `EMAIL_FROM` конкретным адресом

## Проверка (факты)

### Bitrix очередь событий
Проверяли таблицу `b_event` (по `EVENT_NAME='MESSAGE_PERSONAL'`):
- после фикса появились события с заполненным `EMAIL_TO`
- `SUCCESS_EXEC=Y` означает, что Bitrix вызвал отправку успешно

### Exim (реальная доставка на SMTP-релей)
Проверяли `/var/log/exim/main.log`:
- есть строки `=> <recipient>` с ответом `250 OK` от SMTP-релея
- это означает: письмо ушло с сервера и принято релеем; если не видно во "Входящих", дальше искать в спаме/фильтрах почты получателя

## Замечания/улучшения (на будущее)
- Сейчас `mailto` приходит из браузера (скрытое поле). Надёжнее получать email на сервере по `person` (ID сотрудника) и игнорировать `mailto` из POST.
- В логах Exim видно переписывание envelope-from на `postmaster@uskov-1.nichost.ru` — это правило MTA и может влиять на доставляемость/отображение.

