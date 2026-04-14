# LEAD_AUTOMATION_VERIFICATION

Проверки после внедрения автозапуска robots и BizProc при создании лида.

## Что проверить

1. Создать тестовый лид через обычный путь `Calltouch -> queue -> calltouch_processor.php`.
2. Убедиться, что лид реально создался в CRM.
3. Проверить, что в карточку лида записан корректный `ASSIGNED_BY_ID`, пришедший из списка `22`.
4. Проверить, что `CURRENT_USER` при создании совпал с этим пользователем по логам `calltouch_common.log`.
5. Проверить, что CRM robots стартовали именно на событие создания лида.
6. Проверить, что BizProc стартовал именно на событие создания лида.
7. Проверить, что наблюдатели по-прежнему устанавливаются после создания.
8. Проверить, что повторные события с тем же `ctCallerId` не создают новый лид.
9. Проверить, что дедупликация по телефону и заголовку не сломалась.
10. Проверить, что при ошибке старта robots или BizProc лид все равно остается успешно созданным.

## Какие логи смотреть

- `calltouch_native/calltouch_logs/calltouch_common.log`
- `calltouch_native/calltouch_logs/processor_start.log`
- `calltouch_native/calltouch_logs/bitrix_init.log`
- `calltouch_native/calltouch_logs/calltouch_site_<siteId>.log`

## Какие сообщения должны появиться в логе

- сообщение о выбранном `CURRENT_USER` из `ASSIGNED_BY_ID`
- сообщение об успешном создании лида
- сообщение о запуске CRM robots
- сообщение о запуске BizProc
- при проблеме: отдельная ошибка robots или BizProc без падения общего процесса создания лида
