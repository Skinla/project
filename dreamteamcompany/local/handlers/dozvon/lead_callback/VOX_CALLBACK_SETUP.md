
# VOX Callback Setup

## Что нужно подготовить

- Опубликованный сценарий Voximplant из файла `vox_callback_operator_client.js`.
- `RULE_ID` этого сценария в списке `22`.
- Callback SIP-линия в `PROPERTY_613` списка `22`.
- Пароль линии в `SIP_PAROL` списка `22`.
- У операторов в списке `128` должны быть заполнены `OPERATOR`, `GOROD`, `VNUTRENNIY_NOMER`, `LAST_ASSIGNED_AT`.

## Публикация сценария

1. Откройте Voximplant control panel для аккаунта, который уже используется в проекте.
2. Создайте новый scenario, например `lead_callback_operator_client`.
3. Скопируйте содержимое `vox_callback_operator_client.js`.
4. Сохраните сценарий и опубликуйте его.
5. Создайте rule, которая запускает этот сценарий.
6. Возьмите `rule_id` и сохраните его в поле `RULE_ID` элемента списка `22` для нужного города.

## Какой custom data отправляет PHP

`LeadCallbackHelper::startScenario()` отправляет в `script_custom_data` такой JSON:

```json
{
  "lead_id": 123,
  "attempt_number": 1,
  "client_number": "+79991234567",
  "sip_line": "sip58",
  "sip_password": "secret",
  "operator_user_id": 17,
  "operator_extension": "101",
  "operator_name": "Ivan Operator",
  "portal_host": "bitrix.dreamteamcompany.ru"
}
```

## Важная адаптация маршрута сотрудника

Шаблон сценария по умолчанию использует `VoxEngine.callUserDirect(String(operator_user_id), sip_line)`.

Это подходит, если в вашем аккаунте Voximplant выбранный сотрудник вызывается напрямую по `USER_ID`.

Если в вашей схеме сотрудника нужно вызывать по внутреннему номеру или SIP URI, измените только функцию `startOperatorLeg()`:

```javascript
function startOperatorLeg(payload) {
  return VoxEngine.callSIP(
    'sip:' + payload.operator_extension + '@pbx.example.local',
    payload.sip_line,
    payload.sip_password
  );
}
```

После этого можно дополнительно передавать:

- `operator_destination`
- `operator_destination_type = "sip"`

## Что писать в списке 22

- `PROPERTY_613`: линия callback, например `sip58`.
- `SIP_PAROL`: пароль SIP-линии.
- `RULE_ID`: rule Voximplant для callback.
- `CHASOVOY_POYAS`: сдвиг UTC города.
- `PROPERTY_400`: интервал работы КЦ в формате `09:00-21:00`, если нужен дополнительный фильтр по времени.

## Что проверять после публикации

1. При создании лида в стадии `NEW` helper должен выставить `В процессе callback` в поле `UF_CRM_1773155019732`.
2. В логах БП должен появиться `CALLBACK START: lead=... result=started`.
3. В логах сценария Vox должны быть сообщения `callback operator answered`, `callback client answered` или `callback result=operator_no_answer`.
4. После успешного моста в лиде должен сохраниться статус `Успешно соединён`.
5. После 5 неудачных операторских попыток лид должен перейти в `PROCESSED` и получить статус `Не удалось (оператор не ответил)`.

## Что можно донастроить

- Вынести `VOX_ACCOUNT_ID` и `VOX_API_KEY` в переменные окружения `LEAD_CALLBACK_VOX_ACCOUNT_ID` и `LEAD_CALLBACK_VOX_API_KEY`.
- Если хотите детальнее разделять финальные причины в лиде, можно оставить текущие строки `клиент не ответил` и `клиент занят`, либо свести всё к двум статусам ТЗ прямо в `LeadCallbackConfig::getLeadStatusValue()`.
