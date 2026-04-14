# Lead Callback V2

Отдельный PHP-обработчик callback без БП-логики внутри шаблона БП. БП только вызывает `start.php`, а дальнейший цикл выполняет `process.php`.

## Что внутри

- `start.php` - стартует job по лиду, сохраняет состояние в JSON и сразу запускает первый проход обработки.
- `process.php` - обрабатывает due jobs, подбирает оператора, запускает Vox callback, фиксирует результат.
- `bootstrap.php` - локальный bootstrap проекта, не зависит от соседних обработчиков.
- `config.php` - локальная конфигурация проекта.
- `LeadCallbackService.php` - основной сервис с логикой, перенесенной из `lead_callback`.
- `LeadCallbackQueueStore.php` - файловое хранилище job-очереди.
- `vox_callback_operator_client.js` - локальная копия Vox-сценария для callback.
- `jobs/` - папка с JSON-состоянием задач, создается автоматически.

## Используемые зависимости Bitrix

- `crm`
- `iblock`
- `timeman`
- `voximplant`

## Используемые сущности Bitrix

### Поля лида

- `ID`
- `STATUS_ID`
- `SOURCE_ID`
- `ASSIGNED_BY_ID`
- `PHONE`
- `UF_CRM_1744362815` - город
- `UF_CRM_1771439155` - счетчик попыток
- `UF_CRM_1773155019732` - текстовый статус callback
- `UF_CRM_1772538740` - флаг запуска callback

### Список 22

- `PROPERTY_613` - callback SIP-линия
- `SIP_PAROL` - пароль линии
- `PROPERTY_920` / `ROUTING_LEAD` - `rule_id` сценария Voximplant для `StartScenarios`
- `CHASOVOY_POYAS` - смещение часового пояса
- `PROPERTY_400` - рабочее время в формате `HH:MM-HH:MM`

### Список 128

- `GOROD` - город
- `OPERATOR` - пользователь
- `VNUTRENNIY_NOMER` - внутренний номер

## Настройка

### 1. Voximplant

Нужен опубликованный сценарий из `vox_callback_operator_client.js` и route/rule, сохраненный в `PROPERTY_920` / `ROUTING_LEAD` списка 22.

Secrets задаются так:

- `LEAD_CALLBACK_V2_VOX_ACCOUNT_ID`
- `LEAD_CALLBACK_V2_VOX_API_KEY`

Поддерживается fallback на старые env:

- `LEAD_CALLBACK_VOX_ACCOUNT_ID`
- `LEAD_CALLBACK_VOX_API_KEY`

Дополнительные опции через локальный `config.php`:

- `LEAD_CALLBACK_V2_SYSTEM_USER_ID` - ID системного пользователя Bitrix, от имени которого читать и обновлять лиды
- `LEAD_CALLBACK_V2_TEST_MODE` - если `true`, запрос в Vox не отправляется, а только логируется как dry-run
- `LEAD_CALLBACK_V2_TEST_RESULT` - симулируемый результат в test mode: `connected`, `operator_no_answer`, `client_no_answer`, `client_busy`, `cancelled`
- `LEAD_CALLBACK_V2_OPERATOR_ROUTE_MODE` - маршрут оператора: `user` или `sip`
- `LEAD_CALLBACK_V2_OPERATOR_SIP_DESTINATION_TEMPLATE` - шаблон SIP-адреса, например `sip:{extension}@pbx.example.local`

Пример для `config.php`:

```php
'LEAD_CALLBACK_V2_SYSTEM_USER_ID' => 1,
'LEAD_CALLBACK_V2_TEST_MODE' => true,
'LEAD_CALLBACK_V2_TEST_RESULT' => 'connected',
'LEAD_CALLBACK_V2_OPERATOR_ROUTE_MODE' => 'sip',
'LEAD_CALLBACK_V2_OPERATOR_SIP_DESTINATION_TEMPLATE' => 'sip:{extension}@pbx.example.local',
```

Для коробки это важный параметр: внутренние CRM-методы часто требуют авторизованного пользователя с правами на лиды.

### 1.1. Как теперь уходит оператор в Vox

По умолчанию:

- выбирается `OPERATOR` из списка `128`
- в `script_custom_data` передается `operator_user_id`
- сценарий звонит через `VoxEngine.callUser(String(operator_user_id), sip_line)`

Если нужен вызов не по `USER_ID`, а по SIP:

- включите `LEAD_CALLBACK_V2_OPERATOR_ROUTE_MODE = sip`
- задайте `LEAD_CALLBACK_V2_OPERATOR_SIP_DESTINATION_TEMPLATE`
- тогда в `script_custom_data` уйдут:
  - `operator_destination_type = sip`
  - `operator_destination = <собранный SIP URI>`

Шаблон поддерживает подстановки:

- `{extension}`
- `{user_id}`
- `{sip_line}`

### 2. Права доступа

Скрипты используют локальный `bootstrap.php`, значит доступны:

- под админом Bitrix
- или по `cron_key`, если он задан в `lead_callback_v2/config.php`

### 3. Cron или Bitrix Agent

Минимально нужен периодический запуск:

```bash
* * * * * /usr/bin/php /path/to/bitrix/local/handlers/dozvon/lead_callback_v2/process.php limit=20 >> /tmp/lead_callback_v2.log 2>&1
```

Допустим и Bitrix Agent с вызовом этого же скрипта из CLI/внутреннего раннера.

## Вебхук из БП

Рекомендуемый endpoint:

```text
POST /local/handlers/dozvon/lead_callback_v2/start.php
```

Минимально обязательные параметры:

- `lead_id`

Рекомендуемый snapshot из лида:

- `status_id`
- `source_id`
- `city_id`
- `phone`
`portal_host` можно не передавать: по умолчанию используется `bitrix.dreamteamcompany.ru`.

Готовый текст для блока запроса в БП:

```text
lead_id={{=Document:ID}}
status_id={{=Document:STATUS_ID}}
source_id={{=Document:SOURCE_ID}}
city_id={{=Document:UF_CRM_1744362815}}
phone={{=firstvalue({=Document:PHONE})}}
```

## Поведение цикла

1. `start.php` создает или переиспользует активную job по лиду и сразу выполняет первый проход обработки.
2. `process.php` берет due job для последующих проходов и retry.
3. Сервис валидирует лид: `NEW`, не исключенный `SOURCE_ID`, заполнен город, лимит попыток не исчерпан.
4. Сервис загружает город из списка 22 и обновляет лид в статус `В процессе callback`.
5. Сервис подбирает оператора из списка 128 с учетом `TimeMan` и `BUSY`.
6. Запускается `StartScenarios` Voximplant с `script_custom_data`.
7. Следующий проход worker читает результат через `GetCallHistory` и fallback на `\Bitrix\Voximplant\StatisticTable`.
8. Лид либо завершается успешно, либо переводится в `PROCESSED` с финальной причиной, либо job уходит на retry.

В `test mode`:

1. запрос в Vox не отправляется
2. payload пишется в лог
3. worker использует симулированный результат из `LEAD_CALLBACK_V2_TEST_RESULT`

## Формат ответов

### `start.php`

```json
{
  "ok": true,
  "created": true,
  "dispatched": true,
  "job": {
    "job_id": "callback_123_20260409_120000_ab12cd34",
    "lead_id": 123,
    "state": "queued"
  }
}
```

### `process.php`

```json
{
  "ok": true,
  "processed": 1,
  "items": [
    {
      "lead_id": 123,
      "ok": true,
      "job": {
        "state": "waiting_result",
        "call_id": "vox_123456"
      }
    }
  ]
}
```

## Полезные ручные вызовы

```bash
curl -X POST "https://portal/local/handlers/dozvon/lead_callback_v2/start.php" \
  -d "lead_id=123" \
  -d "status_id=NEW" \
  -d "source_id=WEB" \
  -d "city_id=456" \
  -d "phone=+79991234567"
```

```bash
php /path/to/bitrix/local/handlers/dozvon/lead_callback_v2/process.php lead_id=123 limit=1 force=1
```
