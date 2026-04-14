# Проверка рисков модуля 3 (через webhook)

Webhook: `https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk`

**Важно:** списки 22, 128, 130, 131 имеют тип `lists_socnet`, группа `SOCNET_GROUP_ID=1`.

---

## Результаты проверки (18.03.2026)

### Список 22 (Города) — OK

| Код | Название | Тип | Назначение |
|-----|----------|-----|------------|
| CHASOVOY_POYAS | часовой пояс UTC | S | Смещение города |
| NACHALO_RABOTY_KLINIKI_MSK__KHKH_ | Интервал работы КЦ (мск, чч:мм-чч:мм) | S | Рабочее окно |
| **PROPERTY_441** | sip карусели | N | SIP-линия (число) |
| **PROPERTY_470** | интервал на SIP/город | N | Интервал/линия |
| INTERVAL_DLYA_NOCHNYKH_SMS_MSK_CHCH_MM_CHCH_MM | Интервал для ночных СМС | S | — |

**Вывод:** Поля для SIP уже есть: `PROPERTY_441` (sip карусели), `PROPERTY_470` (интервал на SIP/город). Используются как **fallback**, если у оператора нет `DEFAULT_LINE` в `voximplant.user.get`. Для звонка конкретному оператору используется линия оператора (`voximplant.user.get` → `DEFAULT_LINE`), а не городская.

### Список 128 (Статус операторов) — OK

| Код | Название | Тип | Enum/значения |
|-----|----------|-----|---------------|
| OPERATOR | Оператор | S:employee | USER_ID |
| STATUS | статус | L | 1163=free, 1164=busy |
| UPDATED_AT | UPDATED_AT | S:DateTime | — |
| IM_STATUS | IM_STATUS | L | 1165–1169 (online, dnd, away, break, offline) |
| IS_ONLINE | IS_ONLINE | L | 1170=Да, 1171=Нет |
| ACTIVE_CALLS_COUNT | ACTIVE_CALLS_COUNT | N | — |
| LAST_ACTIVITY_DATE | LAST_ACTIVITY_DATE | S:DateTime | — |
| VNUTRENNIY_NOMER | Внутренний номер | N | — |
| **GOROD** | Город | E (link to 22) | Сопоставление с CITY_ID попытки |
| **LAST_ASSIGNED_AT** | Дата последнего назначения | S:DateTime | Round-robin: обновляется при назначении звонка |

**Вывод:** Структура совпадает с `bp_list128_operator_status.txt`. Поле LAST_ASSIGNED_AT нужно добавить вручную в список 128 для равномерного распределения звонков. Enum: free=1163, busy=1164. Поле GOROD (ID 601) — привязка к списку 22; при выборе оператора фильтруем по совпадению города.

### Маршрутизация звонка на конкретного оператора

**Проблема:** `voximplant.callback.start` не имеет параметра `USER_ID`. Звонок идёт на линию, указанную в `FROM_LINE`. «System makes an incoming call to the line specified in FROM_LINE and waits for the operator to answer».

**Решение:** Использовать линию оператора (`DEFAULT_LINE` из `voximplant.user.get`), а не городскую SIP. Линия оператора — его личная SIP‑линия, звонок на неё пойдёт именно этому оператору. Fallback на городскую SIP (список 22, 441/470), если у оператора нет `DEFAULT_LINE`.

**Методы REST:**
- `voximplant.user.get` (USER_ID=[operatorUserId]) → DEFAULT_LINE
- `voximplant.callback.start` (FROM_LINE=DEFAULT_LINE или city SIP)

**Webhook** должен иметь scope **telephony** для обоих методов.

---

### voximplant.line.get — insufficient_scope

```
{"error":"insufficient_scope","error_description":"The request requires higher privileges than provided by the webhook token"}
```

**Действие:** Добавить scope `telephony` в настройках webhook (см. раздел ниже).

---

## Как добавить scope telephony в webhook

### Что такое scope

Scope (область доступа) — это набор прав, которые выдаются webhook. Методы `voximplant.line.get` и `voximplant.callback.start` относятся к scope **telephony**. Если при создании webhook этот scope не был выбран, вызовы возвращают `insufficient_scope`.

### Пошаговая инструкция

1. **Откройте раздел разработчиков**
   - В Bitrix24: **Приложения** → **Разработчикам**
   - Вкладка **«Готовые сценарии»** → **«Другое»** → **«Входящий вебхук»**

2. **Создайте новый webhook** (или отредактируйте существующий, если есть кнопка «Изменить»)
   - В форме создания webhook есть блок **«Права доступа»** / **«Разрешить доступ к»**
   - Это список инструментов Bitrix24 (CRM, Задачи, Календарь, Списки, **Телефония** и т.д.)

3. **Включите «Телефония»**
   - Найдите в списке пункт **«Телефония»** (или «Telephony»)
   - Поставьте галочку
   - Сохраните webhook

4. **Получите новый URL**
   - После сохранения Bitrix24 покажет URL вида:  
     `https://bitrix.dreamteamcompany.ru/rest/1/НОВЫЙ_КОД/`
   - Если вы редактировали существующий webhook, URL может не измениться
   - Если создали новый — обновите `MODULE3_REST_WEBHOOK_URL` в `config.php`

### Важно

- **Изменить права уже созданного webhook** в Bitrix24 обычно нельзя. Часто нужно создать новый webhook с нужными scope и заменить старый URL в конфиге.
- Scope **telephony** даёт доступ к методам телефонии, включая `voximplant.line.get`, `voximplant.callback.start`, `voximplant.infocall.*` и др.
- Отдельно есть scope **call** — он уже входит в telephony и даёт доступ к инфозвонкам.

### Где искать в интерфейсе

- **Коробка Bitrix24:** Настройки → Настройки портала → Разработчикам → Входящий вебхук
- **Облако Bitrix24:** Приложения → Разработчикам → Входящий вебхук

При создании webhook внизу формы должен быть список чекбоксов с названиями разделов (CRM, Задачи, Списки, **Телефония** и т.д.). Отметьте **Телефония**.

---

## Команды для проверки

```bash
# Списки 22, 128 — поля (IBLOCK_TYPE_ID=lists_socnet, SOCNET_GROUP_ID=1)
curl -s -X POST -H "Content-Type: application/json" \
  -d '{"IBLOCK_TYPE_ID":"lists_socnet","SOCNET_GROUP_ID":1,"IBLOCK_ID":22}' \
  "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/lists.field.get"

curl -s -X POST -H "Content-Type: application/json" \
  -d '{"IBLOCK_TYPE_ID":"lists_socnet","SOCNET_GROUP_ID":1,"IBLOCK_ID":128}' \
  "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/lists.field.get"

# voximplant.line.get (после добавления scope telephony)
curl -s -X POST -H "Content-Type: application/json" -d '{}' \
  "https://bitrix.dreamteamcompany.ru/rest/1/norwshsh3anccilk/voximplant.line.get"
```

---

## Структура таблиц voximplant (по документации)

| Таблица | Колонки | Использование в bp_list128 |
|---------|---------|----------------------------|
| b_voximplant_call | CALL_ID, USER_ID, STATUS, LAST_PING | Фильтр активных звонков (15 мин) |
| b_voximplant_call_user | CALL_ID, USER_ID, STATUS, INSERTED | Фильтр недавних подключений |
