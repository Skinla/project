# Диагностика webhook `fphog6k77e7ghhgq`

Дата: 2026-03-08
Домен: `bitrix.dreamteamcompany.ru`
Webhook:

```text
http://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/
```

## Что проверили

### 1. Проверка по HTTP

Запрос:

```bash
curl -i "http://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Ответ:

```text
HTTP/1.1 401 Unauthorized
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

Вывод:

- этот webhook нельзя вызывать по `http`;
- Bitrix REST для него требует `https`.

### 2. Проверка scope по HTTPS

Запрос:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/scope.json"
```

Ответ:

```text
HTTP/1.1 200 OK
{"result":["crm"], ...}
```

Вывод:

- webhook имеет `crm` scope.

### 3. Проверка методов по HTTPS

Запрос:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/methods.json"
```

Ответ:

- в списке есть `crm.lead.get`;
- также доступны другие `crm.*` методы.

Вывод:

- webhook создан корректно для CRM.

### 4. Проверка рабочего вызова по HTTPS

Запрос:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Ответ:

```text
HTTP/1.1 200 OK
{"result":{"ID":"634333", ...}}
```

Вывод:

- webhook рабочий;
- метод `crm.lead.get` рабочий;
- лид `634333` существует и читается корректно.

## Причина ошибки

Для URL:

```text
http://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/
```

причина ошибки `HTTP/1.1 401 Unauthorized` такая:

- запрос идет по `http`, а Bitrix REST требует `https`;
- поэтому портал возвращает:

```text
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

## Правильный рабочий вариант

Использовать нужно только `https`:

```text
https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/
```

Пример:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

## Итог

Webhook `fphog6k77e7ghhgq` исправен.

Причина ошибки только одна: вызов через `http` вместо `https`.

## Проверка внешнего контура

### Что проверили снаружи

Обычный внешний HTTPS-запрос:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Ответ:

```text
HTTP/2 401
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

Принудительный `HTTP/1.1`:

```bash
curl --http1.1 -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Ответ:

```text
HTTP/1.1 401 Unauthorized
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

Запрос с ручной передачей заголовка:

```bash
curl -k -i -H "X-Forwarded-Proto: https" "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Ответ:

```text
HTTP/2 200
{"result":{"ID":"634333", ...}}
```

### Вывод

Это доказывает, что внешняя проблема не в самом webhook и не в методе `crm.lead.get`.

Причина в том, что на внешнем контуре до Bitrix не доходит корректный признак HTTPS.

Иными словами:

- TLS снаружи поднимается нормально;
- сертификат валидный;
- но backend/Bitrix не считает внешний запрос HTTPS, пока не увидит `X-Forwarded-Proto: https`.

### Точная причина внешней ошибки

С высокой вероятностью внешний reverse proxy / балансировщик / nginx:

- терминирует HTTPS;
- проксирует запрос дальше по HTTP;
- не передает `X-Forwarded-Proto: https` и/или не выставляет `HTTPS=on`.

Из-за этого Bitrix отвечает:

```text
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

### Что нужно исправить

На внешнем прокси или nginx нужно корректно передавать признак HTTPS в backend:

```nginx
proxy_set_header X-Forwarded-Proto https;
```

или, если запрос идет напрямую в PHP через `fastcgi`:

```nginx
fastcgi_param HTTPS on;
```

Если используется переменная схемы, обычно безопаснее:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

## Финальный вывод

Webhook `fphog6k77e7ghhgq` рабочий.

Если запрос идет с сервера портала, он работает.

Если запрос идет снаружи, ошибка возникает из-за некорректной передачи признака HTTPS во внешнем прокси-контуре.
# Диагностика Bitrix webhook

Дата: 2026-03-08
Домен: `bitrix.dreamteamcompany.ru`
Проверяемый webhook:

```text
http://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634

Понять, почему "не работают вебхуки" на портале Bitrix, и отделить:

- проблему HTTPS/TLS;
- проблему DNS/маршрутизации;
- проблему прав конкретного webhook-токена;
- проблему конкретного REST-метода.

## Блок 1. Базовая проверка webhook URL

### Что проверяли

```bash
curl -i "http://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634333"
```

### Результат

```text
HTTP/1.1 401 Unauthorized
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

### Вывод

По `http` запрос ожидаемо отклоняется. Это нормальное поведение для REST webhook Bitrix.

## Блок 2. Проверка того же webhook по HTTPS

### Что проверяли

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634333"
```

### Результат

```text
HTTP/1.1 401 Unauthorized
{"error":"insufficient_scope","error_description":"The request requires higher privileges than provided by the webhook token"}
```

### Вывод

Это ключевой результат:

- портал корректно принимает HTTPS-запрос;
- проблема уже не в `Https required`;
- токен webhook существует и обрабатывается порталом;
- ошибка указывает на недостаточные права (`scope`) именно для метода `crm.lead.get`.

Предварительный вывод: проблема не в HTTPS-конфигурации, а в правах конкретного webhook.

## Блок 3. Проверка доступности сайта по HTTPS

### Что проверяли

```bash
curl -k -I "https://bitrix.dreamteamcompany.ru/"
```

### Результат

```text
HTTP/1.1 200 OK
Strict-Transport-Security: max-age=63072000; includeSubDomains; preload
```

### Вывод

HTTPS на сайте в целом работает.

## Блок 4. Проверка TLS-сертификата

### Что проверяли

```bash
openssl s_client -connect bitrix.dreamteamcompany.ru:443 -servername bitrix.dreamteamcompany.ru </dev/null
```

### Результат

Ключевые факты:

- соединение установлено;
- сертификат выдан для `bitrix.dreamteamcompany.ru`;
- цепочка Let's Encrypt валидна;
- `Verify return code: 0 (ok)`;
- используется `TLSv1.3`.

### Дополнительное наблюдение

В выводе видно:

```text
Connecting to 192.168.45.220
```

Это значит, что на сервере имя `bitrix.dreamteamcompany.ru` сейчас резолвится во внутренний адрес `192.168.45.220`.

Само по себе это не ошибка, если именно этот адрес обслуживает nginx и сертификат соответствует домену.

## Актуальный промежуточный вывод

На текущем этапе нет подтверждения, что "вебхуки на портале не работают вообще".

Наоборот, есть подтверждение, что:

- HTTPS работает;
- TLS работает;
- REST endpoint отвечает;
- конкретный webhook-токен не имеет достаточных прав для `crm.lead.get`.

Наиболее вероятная причина текущей ошибки:

- webhook создан без scope `crm`, либо
- webhook создан с ограниченным набором CRM-прав, недостаточным для чтения лидов.

## Следующий блок проверки

Нужно проверить, какие scope доступны у токена, и сравнить:

- работает ли `lists.*`;
- работает ли `crm.*`;
- какие методы разрешены для этого webhook.

## Команды для следующего блока

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/scope.json"
```

Проверка списка доступных scope для webhook.

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/methods.json"
```

Проверка списка разрешенных методов для текущего webhook.

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/lists.field.get.json?IBLOCK_TYPE_ID=lists_socnet&IBLOCK_ID=128"
```

Контрольный тест метода, который ранее уже отрабатывал успешно.

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.fields.json"
```

Проверка, доступен ли вообще CRM scope для чтения метаданных лидов.

## Что нужно будет определить после следующего блока

1. У webhook нет `crm` scope вообще.
2. У webhook есть `crm`, но нет достаточного уровня прав на лиды.
3. Используется не тот webhook-токен.
4. Метод вызывается корректно, но ограничен правами пользователя, от имени которого создан webhook.

## Блок 5. Проверка с моей стороны и сверка через `b24-dev-mcp`

### Что проверяли

Живой запрос из моей среды:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634333"
```

Документацию метода через `b24-dev-mcp`:

- поиск метода `crm.lead.get`;
- получение деталей метода `crm.lead.get`.

### Результат живого запроса из моей среды

```text
HTTP/2 401
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

### Что подтверждает `b24-dev-mcp`

Для метода `crm.lead.get`:

- scope: `crm`;
- обязательный параметр: `id` (integer);
- среди штатных ошибок есть:
  - `INVALID_REQUEST` — для вызова методов требуется использовать HTTPS;
  - `insufficient_scope` — токен вебхука не имеет достаточных привилегий;
  - `INVALID_CREDENTIALS` / `Access denied` — недостаточно прав пользователя/токена.

### Вывод

Есть два разных наблюдения для одного и того же метода:

- на стороне пользователя по HTTPS получен `insufficient_scope`;
- из моей среды по HTTPS получен `INVALID_REQUEST: Https required`.

Это означает, что поведение зависит от точки входа/маршрута запроса, а не только от самого метода:

- либо запросы приходят к разным backend-путям/прокси-контурам;
- либо для части маршрутов HTTPS до Bitrix доходит корректно, а для части нет;
- при корректном определении HTTPS метод упирается уже в права webhook (`scope`).

### Практический смысл

`b24-dev-mcp` подтверждает, что оба ответа являются валидными и ожидаемыми для `crm.lead.get`:

- `Https required` — если портал не считает запрос HTTPS;
- `insufficient_scope` — если у webhook нет нужных прав на CRM/лиды.

Следовательно, на текущем этапе нужно проверять сразу две вещи:

1. Стабильно ли портал определяет HTTPS для всех путей и точек входа.
2. Имеет ли webhook `norwshsh3anccilk` scope `crm` и право на чтение лидов.

## Блок 6. Проверка `scope` и списка методов webhook

### Что проверяли

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/scope.json"
```

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/methods.json"
```

### Результат `scope.json`

```text
{"result":["lists","sonet_group"], ...}
```

### Результат `methods.json`

В списке методов присутствуют только группы:

- служебные REST-методы (`batch`, `scope`, `methods`, `method.get`, ...);
- `sonet_group.*`;
- `lists.*`.

Методов `crm.*` в списке нет.

### Вывод

Это окончательное подтверждение причины:

- webhook `norwshsh3anccilk` создан только со scope:
  - `lists`
  - `sonet_group`
- scope `crm` у него отсутствует;
- метод `crm.lead.get` не может работать с этим токеном в принципе.

Именно поэтому:

- `lists.field.get` работает;
- `crm.lead.get` возвращает ошибку прав (`insufficient_scope`) либо может упираться в различия маршрута, но даже при корректном HTTPS все равно не заработает без `crm` scope.

## Окончательный диагноз

Проблема не в том, что "вебхуки на портале не работают".

Проблема в том, что используется webhook без нужных прав для CRM.

Конкретно:

- текущий webhook подходит для `lists.*` и `sonet_group.*`;
- текущий webhook не подходит для `crm.lead.get`;
- для чтения лида нужен другой webhook, созданный со scope `crm` (и желательно с правами на чтение CRM у пользователя, от имени которого создан webhook).

## Что нужно сделать

1. Создать новый входящий webhook с правами `CRM`.
2. Либо отредактировать существующий webhook и добавить scope `crm`, если портал это позволяет.
3. Проверить вызов уже новым URL:

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/<user_id>/<new_token>/crm.lead.get.json?id=634333"
```

## Короткая формулировка причины

`crm.lead.get` не работает не потому, что сломан REST или HTTPS, а потому что webhook `norwshsh3anccilk` не имеет `crm` scope.

## Блок 7. Проверка второго webhook для CRM

### Проверяемый webhook

```text
http://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/
```

### Что проверяли

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/scope.json"
```

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

### Что получил я из своей среды

Для обоих запросов:

```text
HTTP/2 401
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

### Вывод

С моей точки доступа этот webhook пока не удается проверить по существу, потому что до выполнения метода портал отвечает `Https required`.

Это не позволяет из моей среды определить:

- есть ли у webhook `crm` scope;
- существует ли лид `634333`;
- хватает ли прав на чтение лида.

### Что это означает practically

Для первого webhook у пользователя уже был подтвержден "живой" HTTPS-ответ с данными scope и methods.

Для второго webhook надо проверять именно с сервера пользователя по SSH, потому что:

- у пользователя HTTPS-запросы доходят корректнее;
- в моей среде часть запросов к этому порталу определяется Bitrix как не-HTTPS.

### Следующие команды для проверки второго webhook

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/scope.json"
```

Проверить, есть ли `crm` в scope.

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/methods.json"
```

Проверить, есть ли методы `crm.*`.

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Проверить реальный ответ метода `crm.lead.get` с нового webhook.

## Блок 8. Подтверждение прав второго webhook

### Результат `scope.json`

```text
{"result":["crm"], ...}
```

### Результат `methods.json`

Во втором webhook доступен большой набор `crm.*`-методов, включая:

- `crm.lead.fields`
- `crm.lead.add`
- `crm.lead.get`
- `crm.lead.list`
- `crm.lead.update`
- `crm.lead.delete`

### Вывод

Это подтверждает, что второй webhook `fphog6k77e7ghhgq` создан корректно для CRM.

Следствия:

- проблема первого webhook была именно в отсутствии `crm` scope;
- второй webhook подходит для вызова `crm.lead.get`;
- если `crm.lead.get` с ним не сработает, причина будет уже не в scope, а в одном из следующих вариантов:
  - лид `634333` не существует;
  - у пользователя webhook нет права читать этот лид;
  - есть ограничение доступа на уровне CRM-прав пользователя.

## Актуальный статус диагностики

На текущий момент установлено:

1. Webhook `norwshsh3anccilk`:
   - только `lists` и `sonet_group`
   - для `crm.lead.get` не подходит

2. Webhook `fphog6k77e7ghhgq`:
   - имеет scope `crm`
   - содержит метод `crm.lead.get`
   - подходит для проверки доступа к лиду `634333`

## Следующий обязательный тест

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Этот ответ уже даст окончательный диагноз по самому лиду:

- `200 OK` + `result` -> webhook работает, лид доступен;
- `Not found` -> лида с таким ID нет;
- `Access denied` / `INVALID_CREDENTIALS` -> не хватает прав у пользователя webhook;
- иная ошибка -> разбирать отдельно.

## Блок 9. Финальная проверка рабочего CRM webhook

### Что проверяли

```bash
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

### Результат

```text
HTTP/1.1 200 OK
{"result":{"ID":"634333","TITLE":"Лид от ТВ, +79052427278  ⛔2", ...}}
```

### Что это доказывает

- webhook `fphog6k77e7ghhgq` работает;
- HTTPS для этого вызова работает;
- метод `crm.lead.get` работает;
- лид `634333` существует;
- у пользователя, от имени которого создан webhook, достаточно прав для чтения лида.

## Финальный диагноз

На портале нет общей проблемы вида "вебхуки не работают".

Фактически установлено следующее:

1. Webhook `norwshsh3anccilk`:
   - рабочий, но только для `lists` и `sonet_group`;
   - для CRM не подходит;
   - поэтому `crm.lead.get` с ним не работает.

2. Webhook `fphog6k77e7ghhgq`:
   - рабочий CRM webhook;
   - `crm.lead.get` через него успешно выполняется;
   - данные лида `634333` корректно возвращаются.

## Короткий итог

Проблема была не в портале и не в REST как таковом.

Проблема была в использовании неправильного webhook для CRM-вызова.

Для `crm.lead.get` нужно использовать webhook `fphog6k77e7ghhgq`, а не `norwshsh3anccilk`.

## Блок 10. Повторная внешняя проверка обоих webhook

### Что проверяли

Снаружи были повторно вызваны оба webhook с методом:

```text
crm.lead.get?id=634333
```

Проверки:

```bash
curl -i "http://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634333"
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/crm.lead.get.json?id=634333"
curl -i "http://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
curl -k -i "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

### Результат

Во всех четырех случаях получен один и тот же ответ:

```text
HTTP 401 Unauthorized
{"error":"INVALID_REQUEST","error_description":"Https required."}
```

### Вывод

Это отдельный внешний симптом:

- с внешней точки запроса портал трактует даже HTTPS-вызов как не-HTTPS;
- поэтому снаружи оба webhook сейчас выглядят как `401 Https required`;
- это не опровергает внутреннюю проверку, где CRM webhook реально работает.

### Дополнительная проверка с заголовком `X-Forwarded-Proto`

Для webhook `fphog6k77e7ghhgq` была выполнена внешняя проверка с явной передачей заголовка:

```bash
curl -k -i -H "X-Forwarded-Proto: https" "https://bitrix.dreamteamcompany.ru/rest/1/fphog6k77e7ghhgq/crm.lead.get.json?id=634333"
```

Результат:

```text
HTTP/2 200
{"result":{"ID":"634333", ...}}
```

Что это показывает:

- внешний HTTPS-запрос начинает работать, если вручную передать `X-Forwarded-Proto: https`;
- проблема не в самом webhook;
- проблема в том, что внешний контур не передает в Bitrix корректный признак HTTPS.

## Итог по причинам `401 Unauthorized`

Есть две разные причины `401`, в зависимости от того, откуда идет запрос и какой webhook используется:

1. Внешний запрос (снаружи):
   - причина `401`: `INVALID_REQUEST / Https required`;
   - это указывает на проблему определения HTTPS на внешнем контуре для части запросов.

2. Внутренний запрос с сервера к webhook `norwshsh3anccilk`:
   - причина `401`: `insufficient_scope`;
   - это потому, что у webhook нет `crm` scope.

3. Внутренний запрос с сервера к webhook `fphog6k77e7ghhgq`:
   - `200 OK`;
   - CRM webhook рабочий.

## Практический финальный вывод

Причина `401 Unauthorized` зависит от сценария:

- если запрос идет снаружи, текущая причина — портал считает запрос не-HTTPS;
- если запрос идет изнутри на неверный webhook, причина — нет `crm` scope;
- если запрос идет изнутри на правильный CRM webhook, ошибки нет.
