<?php
/**
 * Тестовый скрипт для поиска лидов по телефону
 * Показывает сырой запрос и ответ Bitrix API
 */

// Включаем отображение ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

try {
    require_once(__DIR__ . '/bitrix_init.php');
    require_once(__DIR__ . '/helper_functions.php');
} catch (Exception $e) {
    die("Ошибка инициализации: " . htmlspecialchars($e->getMessage()) . "<br>Файл: " . htmlspecialchars($e->getFile()) . "<br>Строка: " . $e->getLine());
} catch (Error $e) {
    die("Критическая ошибка: " . htmlspecialchars($e->getMessage()) . "<br>Файл: " . htmlspecialchars($e->getFile()) . "<br>Строка: " . $e->getLine());
}

// Загружаем конфигурацию
$configPath = __DIR__ . '/calltouch_config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) { $config = []; }

$logFile = $config['global_log'] ?? 'calltouch_common.log';

// Функция нормализации телефона
if (!function_exists('normalizePhone')) {
    function normalizePhone($raw) {
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 11 && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        } elseif (strlen($digits) === 10) {
            $digits = '7' . $digits;
        }
        return $digits;
    }
}

$phone = $_POST['phone'] ?? $_GET['phone'] ?? '';
$results = [];
$errors = [];
$queries = [];
$normalizedPhone = '';
$phoneVariants = [];

if (!empty($phone)) {
    try {
        if (!CModule::IncludeModule("crm")) {
            throw new Exception("Не удалось подключить модуль CRM");
        }
        
        // Нормализуем телефон
        $normalizedPhone = normalizePhone($phone);
        
        // Пробуем разные варианты формата телефона
        $phoneVariants = [
            $normalizedPhone, // +79049202075
        ];
        
        // Добавляем варианты без + для поиска (если телефон начинается с +7)
        if (strpos($normalizedPhone, '+7') === 0) {
            $phoneWithoutPlus = substr($normalizedPhone, 1); // 79049202075
            $phoneVariants[] = $phoneWithoutPlus;
            $phoneVariants[] = "8" . substr($phoneWithoutPlus, 1); // 89049202075
        }
        
        $phoneVariants = array_unique($phoneVariants);
        
        // Ищем по каждому варианту - пробуем оба способа для сравнения
        foreach ($phoneVariants as $phoneVariant) {
            $startTime = microtime(true);
            
            // СПОСОБ 1: Через CCrmFieldMulti (правильный способ для множественных полей)
            $dbPhones = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    'VALUE' => $phoneVariant
                ]
            );
            
            $leadIds = [];
            while ($arPhone = $dbPhones->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                if ($elementId > 0) {
                    $leadIds[] = $elementId;
                }
            }
            
            // Также пробуем поиск по частичному совпадению
            $dbPhonesLike = \CCrmFieldMulti::GetList(
                ['ID' => 'ASC'],
                [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    '%VALUE' => $phoneVariant
                ]
            );
            
            while ($arPhone = $dbPhonesLike->Fetch()) {
                $elementId = (int)($arPhone['ELEMENT_ID'] ?? 0);
                $phoneValue = $arPhone['VALUE'] ?? '';
                $normalizedPhoneValue = normalizePhone($phoneValue);
                if ($normalizedPhoneValue === $normalizedPhone && $elementId > 0) {
                    $leadIds[] = $elementId;
                }
            }
            
            $leadIds = array_unique($leadIds);
            $endTime = microtime(true);
            $queryTime = round(($endTime - $startTime) * 1000, 2);
            
            // Формируем запрос для отображения
            $queryInfo = [
                'variant' => $phoneVariant,
                'entity' => 'CRM Lead (Лиды)',
                'method' => 'CCrmFieldMulti::GetList() + CCrmLead::GetByID()',
                'filter' => [
                    'ENTITY_ID' => 'LEAD',
                    'TYPE_ID' => 'PHONE',
                    'VALUE' => $phoneVariant
                ],
                'params' => [
                    'order' => ['ID' => 'ASC'],
                    'filter' => [
                        'ENTITY_ID' => 'LEAD',
                        'TYPE_ID' => 'PHONE',
                        'VALUE' => $phoneVariant
                    ]
                ],
                'php_code' => "// ПРАВИЛЬНЫЙ СПОСОБ (по документации Bitrix):\n\$dbPhones = \\CCrmFieldMulti::GetList(\n    ['ID' => 'ASC'],\n    [\n        'ENTITY_ID' => 'LEAD',\n        'TYPE_ID' => 'PHONE',\n        'VALUE' => '$phoneVariant'\n    ]\n);\nwhile (\$arPhone = \$dbPhones->Fetch()) {\n    \$leadId = (int)\$arPhone['ELEMENT_ID'];\n    \$lead = \\CCrmLead::GetByID(\$leadId);\n    // ... обработка лида\n}",
                'found_lead_ids' => $leadIds
            ];
            
            $queries[] = $queryInfo;
            
            // Собираем сырой ответ
            $rawResponse = [];
            $variantResults = [];
            $count = 0;
            
            // Получаем лиды по найденным ID
            foreach ($leadIds as $leadId) {
                $count++;
                
                // Получаем полную информацию о лиде через GetByID
                $fullLeadInfo = \CCrmLead::GetByID($leadId);
                if (empty($fullLeadInfo)) {
                    continue;
                }
                $arLead = $fullLeadInfo; // Используем данные из GetByID
                
                // Получаем PHONE через CCrmFieldMulti (множественные поля)
                $phones = [];
                $dbPhones = \CCrmFieldMulti::GetList(
                    ['ID' => 'ASC'],
                    [
                        'ENTITY_ID' => 'LEAD',
                        'ELEMENT_ID' => $leadId,
                        'TYPE_ID' => 'PHONE'
                    ]
                );
                while ($arPhone = $dbPhones->Fetch()) {
                    $phones[] = $arPhone;
                }
                
                // Сохраняем сырой ответ (как есть от Bitrix GetList)
                $rawResponse[] = [
                    'from_GetList' => $arLead,
                    'from_GetByID' => $fullLeadInfo,
                    'from_CCrmFieldMulti_PHONE' => $phones,
                    'all_fields_GetList' => array_keys($arLead),
                    'all_fields_GetByID' => array_keys($fullLeadInfo ?? [])
                ];
                
                // Получаем телефон из CCrmFieldMulti
                $leadPhone = '';
                if (!empty($phones) && is_array($phones)) {
                    // Берем первый телефон
                    $firstPhone = $phones[0];
                    if (is_array($firstPhone) && isset($firstPhone['VALUE'])) {
                        $leadPhone = (string)$firstPhone['VALUE'];
                    } elseif (is_string($firstPhone)) {
                        $leadPhone = $firstPhone;
                    }
                }
                
                // Fallback: пробуем получить из других источников
                if (empty($leadPhone)) {
                    if (!empty($fullLeadInfo['PHONE'])) {
                        if (is_array($fullLeadInfo['PHONE'])) {
                            $firstPhone = reset($fullLeadInfo['PHONE']);
                            if (is_array($firstPhone) && isset($firstPhone['VALUE'])) {
                                $leadPhone = $firstPhone['VALUE'];
                            } elseif (is_string($firstPhone)) {
                                $leadPhone = $firstPhone;
                            }
                        } else {
                            $leadPhone = $fullLeadInfo['PHONE'];
                        }
                    } elseif (!empty($arLead['PHONE'])) {
                        $leadPhone = $arLead['PHONE'];
                    }
                }
                
                $normalizedLeadPhone = normalizePhone($leadPhone);
                
                $variantResults[] = [
                    'ID' => $leadId,
                    'TITLE' => $arLead['TITLE'] ?? '',
                    'PHONE' => $leadPhone,
                    'PHONE_RAW' => !empty($phones) ? json_encode($phones, JSON_UNESCAPED_UNICODE) : '',
                    'PHONE_NORMALIZED' => $normalizedLeadPhone,
                    'PHONE_MATCH' => ($normalizedLeadPhone === $normalizedPhone),
                    'STATUS_ID' => $arLead['STATUS_ID'] ?? '',
                    'DATE_CREATE' => $arLead['DATE_CREATE'] ?? '',
                ];
            }
            
            // Добавляем сырой ответ в queryInfo
            foreach ($queries as &$q) {
                if ($q['variant'] === $phoneVariant) {
                    $q['raw_response'] = $rawResponse;
                    break;
                }
            }
            unset($q);
            
            $results[] = [
                'variant' => $phoneVariant,
                'count' => $count,
                'query_time_ms' => $queryTime,
                'leads' => $variantResults
            ];
        }
        
    } catch (Exception $e) {
        $errors[] = "Ошибка: " . $e->getMessage() . " (файл: " . $e->getFile() . ", строка: " . $e->getLine() . ")";
    } catch (Error $e) {
        $errors[] = "Критическая ошибка: " . $e->getMessage() . " (файл: " . $e->getFile() . ", строка: " . $e->getLine() . ")";
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест поиска лидов по телефону</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            padding: 30px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 500;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #4CAF50;
        }
        button {
            background: #4CAF50;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        button:hover {
            background: #45a049;
        }
        .info {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .error {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            color: #c62828;
        }
        .results {
            margin-top: 30px;
        }
        .variant-block {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .variant-header {
            font-size: 18px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 2px solid #ddd;
        }
        .variant-info {
            color: #666;
            margin-bottom: 15px;
        }
        .query-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            overflow-x: auto;
        }
        .leads-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .leads-table th {
            background: #4CAF50;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 500;
        }
        .leads-table td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        .leads-table tr:hover {
            background: #f5f5f5;
        }
        .match-yes {
            color: #4CAF50;
            font-weight: bold;
        }
        .match-no {
            color: #f44336;
        }
        .no-results {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        pre {
            background: #f5f5f5;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Тест поиска лидов по телефону</h1>
        
        <form method="POST">
            <div class="form-group">
                <label for="phone">Номер телефона:</label>
                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($phone) ?>" placeholder="79049202075 или 7 904 920-20-75">
            </div>
            <button type="submit">Найти лиды</button>
        </form>
        
        <?php if (!empty($phone)): ?>
            <div class="info">
                <strong>Введенный телефон:</strong> <?= htmlspecialchars($phone) ?><br>
                <strong>Нормализованный:</strong> <?= htmlspecialchars($normalizedPhone ?? '') ?><br>
                <strong>Варианты для поиска:</strong> <?= htmlspecialchars(implode(', ', $phoneVariants ?? [])) ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($results)): ?>
            <div class="results">
                <h2>Результаты поиска</h2>
                
                <?php foreach ($results as $result): ?>
                    <div class="variant-block">
                        <div class="variant-header">
                            Вариант: "<?= htmlspecialchars($result['variant']) ?>"
                        </div>
                        <div class="variant-info">
                            Найдено лидов: <strong><?= $result['count'] ?></strong> | 
                            Время запроса: <strong><?= $result['query_time_ms'] ?> мс</strong>
                        </div>
                        
                        <?php if (!empty($queries)): ?>
                            <?php foreach ($queries as $query): ?>
                                <?php if ($query['variant'] === $result['variant']): ?>
                                    <div class="query-info">
                                        <strong>Сущность:</strong> <?= htmlspecialchars($query['entity']) ?><br>
                                        <strong>Метод:</strong> <code><?= htmlspecialchars($query['method']) ?></code><br><br>
                                        <strong>Параметры запроса (JSON):</strong><br>
                                        <pre><?= htmlspecialchars(json_encode($query['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                                        <br>
                                        <strong>PHP код:</strong><br>
                                        <pre><?= htmlspecialchars($query['php_code']) ?></pre>
                                        <br>
                                        <strong>Сырой ответ от Bitrix GetList (что возвращает GetList):</strong><br>
                                        <pre><?php 
                                        $getListData = [];
                                        foreach ($query['raw_response'] ?? [] as $item) {
                                            $getListData[] = $item['from_GetList'] ?? $item;
                                        }
                                        echo htmlspecialchars(json_encode($getListData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                        ?></pre>
                                        <br>
                                        <strong>Полная информация через GetByID (включая PHONE):</strong><br>
                                        <pre><?php 
                                        $getByIdData = [];
                                        foreach ($query['raw_response'] ?? [] as $item) {
                                            $getByIdData[] = $item['from_GetByID'] ?? null;
                                        }
                                        echo htmlspecialchars(json_encode($getByIdData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                        ?></pre>
                                        <br>
                                        <strong>PHONE через CCrmFieldMulti::GetList():</strong><br>
                                        <pre><?php 
                                        $phonesData = [];
                                        foreach ($query['raw_response'] ?? [] as $item) {
                                            $phonesData[] = $item['from_CCrmFieldMulti_PHONE'] ?? [];
                                        }
                                        echo htmlspecialchars(json_encode($phonesData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                        ?></pre>
                                        <br>
                                        <strong>Сравнение полей:</strong><br>
                                        <pre><?php 
                                        $comparison = [];
                                        foreach ($query['raw_response'] ?? [] as $idx => $item) {
                                            $phoneFromMulti = '';
                                            if (!empty($item['from_CCrmFieldMulti_PHONE']) && is_array($item['from_CCrmFieldMulti_PHONE'])) {
                                                $firstPhone = reset($item['from_CCrmFieldMulti_PHONE']);
                                                $phoneFromMulti = $firstPhone['VALUE'] ?? '';
                                            }
                                            
                                            $comparison[] = [
                                                'lead_id' => $item['from_GetList']['ID'] ?? 'N/A',
                                                'fields_in_GetList' => $item['all_fields_GetList'] ?? [],
                                                'fields_in_GetByID' => $item['all_fields_GetByID'] ?? [],
                                                'PHONE_in_GetList' => $item['from_GetList']['PHONE'] ?? 'NOT_FOUND',
                                                'PHONE_in_GetByID' => $item['from_GetByID']['PHONE'] ?? 'NOT_FOUND',
                                                'PHONE_from_CCrmFieldMulti' => $phoneFromMulti ?: 'NOT_FOUND',
                                                'HAS_PHONE_flag' => $item['from_GetByID']['HAS_PHONE'] ?? 'N/A',
                                            ];
                                        }
                                        echo htmlspecialchars(json_encode($comparison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                        ?></pre>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($result['leads'])): ?>
                            <table class="leads-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>TITLE</th>
                                        <th>PHONE</th>
                                        <th>PHONE (норм.)</th>
                                        <th>Совпадение</th>
                                        <th>STATUS_ID</th>
                                        <th>DATE_CREATE</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($result['leads'] as $lead): ?>
                                        <tr>
                                            <td><?= $lead['ID'] ?></td>
                                            <td><?= htmlspecialchars($lead['TITLE']) ?></td>
                                            <td>
                                                <?php if (!empty($lead['PHONE'])): ?>
                                                    <?= htmlspecialchars($lead['PHONE']) ?>
                                                <?php else: ?>
                                                    <span style="color: #999;">не найден</span>
                                                    <?php if (!empty($lead['PHONE_RAW'])): ?>
                                                        <br><small style="color: #666;">(RAW: <?= htmlspecialchars(substr($lead['PHONE_RAW'], 0, 100)) ?>)</small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($lead['PHONE_NORMALIZED'] ?: 'не найден') ?></td>
                                            <td class="<?= $lead['PHONE_MATCH'] ? 'match-yes' : 'match-no' ?>">
                                                <?= $lead['PHONE_MATCH'] ? '✓ Да' : '✗ Нет' ?>
                                            </td>
                                            <td><?= htmlspecialchars($lead['STATUS_ID']) ?></td>
                                            <td><?= htmlspecialchars($lead['DATE_CREATE']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-results">Лиды не найдены</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php elseif (!empty($phone)): ?>
            <div class="info">
                Введите номер телефона и нажмите "Найти лиды"
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

