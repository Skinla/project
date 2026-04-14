# Описание проекта `call-payload`

## Для чего проект

Проект принимает webhook с данными звонка, извлекает из текста оценки по критериям и записывает результаты в инфоблок Bitrix (iblock `122`).

Основная цель: превратить сырые данные из интеграции (`CALL_ID` + `RAW_PAYLOAD_JSON`) в структурированные поля Bitrix для аналитики качества звонков.

## Логика работы

1. Входная точка `call_payload_handler.php` принимает только `POST` с JSON.
2. Проверяются обязательные поля:
   - `CALL_ID` (непустая строка),
   - `RAW_PAYLOAD_JSON` (строка/объект/массив).
3. Загружается конфиг `config/score_parser.php`.
4. `call_payload_handler.php` собирает:
   - текст для парсера,
   - дополнительные метаданные (`PHONE_NUMBER`, `PORTAL_NUMBER`, `CRM_*`, `CALL_START_DATE`, `CALL_DURATION`, `CALL_TYPE` и т.д.),
   - единый логгер с ограничением файла лога 2 МБ.
5. `MarkdownScoreParser` разбирает payload:
   - 17 критериев,
   - `BASE_SCORE`, `FINAL_SCORE`,
   - суммарные показатели,
   - дополнительные признаки (`IS_BOOKED`, дата/время звонка, статус семейного предложения).
6. `Iblock122ScoreWriter` пишет данные в Bitrix:
   - формирует `PROPERTY_VALUES`,
   - всегда создает новый элемент списка,
   - выполняет верификацию записанных свойств.
7. Возвращается JSON-ответ API о результате обработки.
8. Все runtime-логи пишутся в единый файл `storage/integration.log`.

## Ключевые сущности

- `ScorePayloadDTO` — DTO с итогом парсинга (оценки, итоговые поля, предупреждения).
- `ScoreParserInterface` — контракт парсера.
- `IblockScoreWriterInterface` — контракт записи в Bitrix.
- `MarkdownScoreParser` — реализация извлечения данных из markdown/текста.
- `BitrixBootstrap` — инициализация Bitrix окружения и модуля `iblock`.
- `Iblock122ScoreWriter` — маппинг DTO в свойства инфоблока и запись.
- `CappedFileLogger` — логгер с ограничением размера файла.

## Список файлов и назначение

### Корень

- `.section.php` — служебный файл раздела Bitrix (`call_payload`).
- `call_payload_handler.php` — основной webhook-обработчик (валидация, парсинг, извлечение метаданных, запись в Bitrix, логирование, ответ API).
- `process_all_payloads.php` — исторический пакетный обработчик сохраненных JSON-файлов; в текущем упрощенном runtime не используется.
- `project_test.php` — self-check проекта: проверка файлов, классов, методов, ключей конфига и тестовый прогон парсера на sample-файлах.
- `ping_ok.php` — легкий health-check структуры проекта (наличие файлов/папок/классов/функций).
- `dump.php` — утилита для Bitrix (дамп полей и свойств инфоблока в JSON, включая enum-значения).
- `WORK_DESCRIPTION.md` — текущий файл с документацией проекта.

### Конфигурация

- `config/.section.php` — служебный файл раздела Bitrix (`config`).
- `config/score_parser.php` — основной конфиг интеграции: id инфоблока, коды свойств, map критериев, путь к логу, лимит размера лога, fail-fast и прочие настройки записи.

### Исходники `src`

- `src/.section.php` — служебный файл раздела Bitrix (`src`).

#### Контракты

- `src/Contracts/.section.php` — служебный файл раздела Bitrix (`Contracts`).
- `src/Contracts/ScoreParserInterface.php` — интерфейс парсера входного текста в `ScorePayloadDTO`.
- `src/Contracts/IblockScoreWriterInterface.php` — интерфейс writer-класса для записи DTO в инфоблок.

#### DTO

- `src/DTO/.section.php` — служебный файл раздела Bitrix (`DTO`).
- `src/DTO/ScorePayloadDTO.php` — immutable DTO с результатами парсинга, геттерами и методами `withCallDateTime()` и `withMetadata()`.

#### Парсер

- `src/Parser/.section.php` — служебный файл раздела Bitrix (`Parser`).
- `src/Parser/MarkdownScoreParser.php` — парсинг текста: критерии, итоговый балл, снижения, `IS_BOOKED`, дата звонка, статус семейного предложения.

#### Bitrix

- `src/Bitrix/.section.php` — служебный файл раздела Bitrix (`Bitrix`).
- `src/Bitrix/BitrixBootstrap.php` — bootstrap Bitrix (`prolog_before.php`, подключение модуля `iblock`).
- `src/Bitrix/Iblock122ScoreWriter.php` — формирование и запись свойств элемента в инфоблок, верификация записи и debug-лог.

#### Логирование

- `src/CappedFileLogger.php` — файловый логгер с обрезкой старых записей при достижении лимита размера.

### Данные и логи

- `storage/integration.log` — единый runtime-лог обработчика и writer, ограниченный по размеру 2 МБ.
- `storage/call_payloads/` — историческая папка от старой схемы с файловым хранением payload; в текущем runtime не используется.

## Что важно учитывать

- Проект рассчитан на окружение Bitrix (классы `CIBlockElement`, `CIBlockProperty`, `CIBlockPropertyEnum`).
- Без доступного Bitrix окружения запись в iblock не выполнится.
- Каждый webhook сейчас создает новый элемент списка; обновление существующего элемента по `CALL_ID` отключено.
- Маппинг 17 критериев, агрегатных и дополнительных полей полностью задается через `config/score_parser.php`.
