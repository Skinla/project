# SAFE_CHANGE_CHECKLIST

Краткий чеклист для безопасных изменений в проекте `calltouch`.

## Перед началом изменений

- Убедись, что меняешь файл в активной ветке `calltouch_native/*`, а не legacy-дубль в корне проекта.
- Определи, затрагивает ли изменение один из критичных файлов: `calltouch_gateway.php`, `calltouch_native/calltouch_processor.php`, `calltouch_native/bitrix_init.php`, `calltouch_native/lead_prepare.php`, `calltouch_native/lead_functions.php`, `calltouch_native/iblock_functions.php`, `calltouch_native/calltouch_config.php`.
- Проверь, не влияет ли изменение на `queue`, `queue_errors`, `calltouch_logs` или `ctcallerid_index.json`.
- Уточни, меняется ли бизнес-логика по `allowed_callphases`, дедупликации, `ctCallerId` или маппингу через списки `54/19/22`.
- Если правка касается авторизации, retry или админских endpoint'ов, считай ее изменением повышенного риска.

## Перед правкой логики обработки

- Зафиксируй текущий путь данных: `Calltouch -> gateway -> queue -> processor -> Bitrix24`.
- Проверь, не нарушит ли изменение поиск пары `NAME + PROPERTY_199(siteId)` в списке `54`.
- Проверь, не повлияет ли изменение на нормализацию телефона в формате `+7XXXXXXXXXX`.
- Убедись, что логика `create/update/skip/error` останется взаимоисключающей и понятной.
- Подумай, что произойдет при параллельной обработке одного и того же файла.

## Перед правкой конфигурации

- Не меняй одновременно код и критичные параметры конфига без необходимости.
- С осторожностью меняй `allowed_callphases`, `deduplication.*`, `ctCallerId.*`, `iblock.*`, `logs_dir`, `errors_dir`, `index_file`.
- Проверь, какая именно версия конфига используется: рабочая логика должна ориентироваться на `calltouch_native/calltouch_config.php`.
- Если меняешь форму в `config_manager.php`, проверь, что она не ломает формат PHP-конфига и backup сохраняется.

## Во время изменений

- Сохраняй совместимость CLI и HTTP режимов там, где это важно для `calltouch_processor.php`.
- Не ломай раннее логирование в `processor_start.log` и `gateway_exec.log`.
- Не удаляй полезные диагностические сообщения, пока не добавлена эквивалентная замена.
- Не меняй схему свойств `PROPERTY_*` и `UF_CRM_*`, если не проверено их реальное использование в `Bitrix24`.
- Если меняешь bootstrap в `bitrix_init.php`, считай это высокорисковой правкой.

## После изменений

- Проверь сценарий приема нового payload и постановки файла в `queue`.
- Проверь сценарий успешной обработки и удаления файла из очереди.
- Проверь сценарий ошибки и перемещения файла в `queue_errors`.
- Проверь сценарий requeue через `requeue_and_process.php` или `queue_manager.php`.
- Проверь сценарий дедупликации по телефону и заголовку.
- Проверь сценарий повторного события с тем же `ctCallerId`.
- Убедись, что чат-уведомления не сломались, если правка затрагивала ошибки или доступ.

## Что смотреть при регрессии

- `calltouch_native/calltouch_logs/gateway_exec.log`
- `calltouch_native/calltouch_logs/processor_start.log`
- `calltouch_native/calltouch_logs/calltouch_common.log`
- `calltouch_native/calltouch_logs/bitrix_init.log`
- `calltouch_native/calltouch_logs/calltouch_site_<siteId>.log`
- `calltouch_native/queue`
- `calltouch_native/queue_errors`
- `calltouch_native/ctcallerid_index.json`

## Красные флаги

- Правка сделана только в корневом дубле файла, а не в `calltouch_native`.
- После изменения payload остается в `queue` без понятной причины.
- Файлы массово уходят в `queue_errors` с `wrong_phase` или `name_property199_pair_not_found`.
- Начали дублироваться лиды.
- Перестали обновляться лиды по дедупликации.
- Повторные события перестали распознаваться по `ctCallerId`.
- Веб-интерфейсы или service endpoint'ы начали работать без ожидаемой защиты или перестали работать совсем.

## Минимальный безопасный порядок работы

1. Найти активный файл и связанную цепочку вызовов.
2. Понять, затрагивается ли очередь, конфиг, `Bitrix` bootstrap или CRM-логика.
3. Внести минимальное изменение.
4. Проверить логи и оба исхода: success и error.
5. Проверить retry и дедупликацию, если изменение касается процессора или лида.
