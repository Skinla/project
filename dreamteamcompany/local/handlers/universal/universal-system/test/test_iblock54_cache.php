<?php
// test_iblock54_cache.php
//
// Тестовый скрипт для ПРОВЕРКИ кэширования элементов ИБ54
// Тестирует работу Bitrix Main Data Cache перед внедрением оптимизации
//
// Запускать в проде руками по URL вида:
//   /local/handlers/universal/universal-system/test/test_iblock54_cache.php?token=XXX&domain=example.com
//
// Параметры:
//   token - секретный токен для доступа
//   domain - тестовый домен для проверки (опционально, по умолчанию используется из конфига)
//   subpool - subPoolName для CallTouch (опционально)
//   siteid - siteId для CallTouch (опционально)
//
// РЕКОМЕНДУЕТСЯ:
// - добавить какой-то простой секретный token в GET, чтобы не светить тест снаружи.

// ---------------- БАЗОВАЯ ЗАЩИТА ----------------

// Простейшая защита через токен, чтобы скрипт не дёргали случайно извне
$expectedToken = 'change-me-token'; // при необходимости поменяешь на свой
$passedToken   = $_GET['token'] ?? '';

if ($expectedToken !== '' && $passedToken !== $expectedToken) {
    header('HTTP/1.1 403 Forbidden');
    echo "ACCESS DENIED\n";
    exit;
}

// ---------------- ИНИЦИАЛИЗАЦИЯ BITRIX ----------------

use Bitrix\Main\Loader;
use Bitrix\Main\Data\Cache;

// Убедимся, что DOCUMENT_ROOT задан
if (empty($_SERVER['DOCUMENT_ROOT'])) {
    // Попробуем восстановить относительным путём от текущего файла
    $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../..');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$errors = [];

if (!Loader::includeModule('iblock')) {
    $errors[] = 'Не удалось подключить модуль iblock';
}
if (!Loader::includeModule('main')) {
    $errors[] = 'Не удалось подключить модуль main';
}

header('Content-Type: text/plain; charset=utf-8');

echo "Bitrix IBlock54 Cache Test\n";
echo "==========================\n\n";

if ($errors) {
    echo "ОШИБКИ ИНИЦИАЛИЗАЦИИ:\n";
    foreach ($errors as $e) {
        echo "- $e\n";
    }
    echo "\nДальнейшие тесты невозможны.\n";
    exit;
}

// ---------------- ЗАГРУЗКА КОНФИГУРАЦИИ ----------------

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    echo "ОШИБКА: файл config.php не найден\n";
    exit;
}

$config = require $configPath;

// Добавляем iblock ID если его нет
if (!isset($config['iblock'])) {
    $config['iblock'] = [];
}
$config['iblock']['iblock_54_id'] = $config['iblock']['iblock_54_id'] ?? 54;

// ---------------- ЗАГРУЗКА ФУНКЦИЙ ----------------

require_once __DIR__ . '/../lead_processor.php';

// ---------------- УТИЛИТЫ ДЛЯ ВЫВОДА ----------------

function t_print_result(string $title, bool $ok, array $details = []): void
{
    echo ($ok ? "[OK]  " : "[FAIL]") . " $title\n";
    if ($details) {
        foreach ($details as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            echo "    - $k: $v\n";
        }
    }
    echo "\n";
}

function t_measure_time(callable $callback): array
{
    $start = microtime(true);
    $result = $callback();
    $end = microtime(true);
    $time = ($end - $start) * 1000; // в миллисекундах
    
    return [
        'result' => $result,
        'time' => round($time, 2)
    ];
}

// ---------------- ТЕСТОВЫЕ ДАННЫЕ ----------------

$testDomain = $_GET['domain'] ?? 'tgl-vsezubysrazy.ru'; // Дефолтный тестовый домен
$testSubPool = $_GET['subpool'] ?? '';
$testSiteId = $_GET['siteid'] ?? '';

echo "Параметры теста:\n";
echo "  - test_domain = $testDomain [дефолт]\n";
if ($testSubPool && $testSiteId) {
    echo "  - test_subpool = $testSubPool\n";
    echo "  - test_siteid = $testSiteId\n";
}
echo "\n";

// ---------------- ТЕСТ 1: ПРОВЕРКА ДОСТУПНОСТИ КЭША ----------------

echo "ТЕСТЫ КЭШИРОВАНИЯ:\n";
echo "-------------------\n\n";

$cacheAvailable = false;
try {
    $cache = Cache::createInstance();
    if ($cache) {
        $cacheAvailable = true;
        t_print_result("Проверка доступности Bitrix Cache", true, [
            'class' => get_class($cache),
            'cache_dir' => $_SERVER['DOCUMENT_ROOT'] . '/bitrix/cache'
        ]);
    } else {
        t_print_result("Проверка доступности Bitrix Cache", false, [
            'error' => 'Cache::createInstance() вернул null'
        ]);
    }
} catch (Exception $e) {
    t_print_result("Проверка доступности Bitrix Cache", false, [
        'error' => $e->getMessage()
    ]);
}

if (!$cacheAvailable) {
    echo "КРИТИЧЕСКАЯ ОШИБКА: Кэш недоступен. Дальнейшие тесты невозможны.\n";
    exit;
}

// ---------------- ТЕСТ 2: ПРОВЕРКА КЭШИРОВАНИЯ ПО ДОМЕНУ (БЕЗ КЭША) ----------------

echo "ТЕСТ 2: Получение элемента ИБ54 по домену (БЕЗ кэша)\n";
echo "----------------------------------------------------\n\n";

// Очищаем кэш перед тестом
$cacheDir = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/cache';
$cacheKey = 'iblock54_domain_' . md5($testDomain);
$cachePath = $cacheDir . '/' . md5($cacheKey);
if (file_exists($cachePath)) {
    @unlink($cachePath);
    echo "  - Кэш очищен перед тестом\n\n";
}

$firstRequest = t_measure_time(function() use ($testDomain, $config) {
    return getElementDataFromIblock54ByDomain($testDomain, $config);
});

if ($firstRequest['result']) {
    $firstElementId = $firstRequest['result']['element']['ID'] ?? 'unknown';
    $firstPropertiesCount = count($firstRequest['result']['properties'] ?? []);
    
    t_print_result("Первый запрос (без кэша)", true, [
        'element_id' => $firstElementId,
        'element_name' => $firstRequest['result']['element']['NAME'] ?? 'unknown',
        'properties_count' => $firstPropertiesCount,
        'time_ms' => $firstRequest['time'] . ' ms',
        'from_cache' => false
    ]);
} else {
    t_print_result("Первый запрос (без кэша)", false, [
        'error' => 'Элемент не найден',
        'time_ms' => $firstRequest['time'] . ' ms'
    ]);
    echo "ВНИМАНИЕ: Элемент не найден. Проверьте, что домен '$testDomain' существует в ИБ54.\n";
    echo "Используйте параметр ?domain=ваш_домен для тестирования другого домена.\n\n";
}

// ---------------- ТЕСТ 3: ПРОВЕРКА КЭШИРОВАНИЯ ПО ДОМЕНУ (С КЭШЕМ) ----------------

echo "ТЕСТ 3: Получение элемента ИБ54 по домену (С кэшем)\n";
echo "----------------------------------------------------\n\n";

$secondRequest = t_measure_time(function() use ($testDomain, $config) {
    return getElementDataFromIblock54ByDomain($testDomain, $config);
});

if ($secondRequest['result']) {
    $secondElementId = $secondRequest['result']['element']['ID'] ?? 'unknown';
    $secondPropertiesCount = count($secondRequest['result']['properties'] ?? []);
    
    // Проверяем, что данные совпадают
    $dataMatch = ($firstRequest['result'] && 
                  $firstRequest['result']['element']['ID'] === $secondRequest['result']['element']['ID'] &&
                  json_encode($firstRequest['result']) === json_encode($secondRequest['result']));
    
    // Проверяем, что второй запрос быстрее (должен быть из кэша)
    $faster = $secondRequest['time'] < $firstRequest['time'];
    
    t_print_result("Второй запрос (с кэшем)", true, [
        'element_id' => $secondElementId,
        'element_name' => $secondRequest['result']['element']['NAME'] ?? 'unknown',
        'properties_count' => $secondPropertiesCount,
        'time_ms' => $secondRequest['time'] . ' ms',
        'from_cache' => $faster ? 'вероятно да' : 'вероятно нет',
        'data_match' => $dataMatch ? 'да' : 'нет',
        'speedup' => $faster ? round(($firstRequest['time'] - $secondRequest['time']) / $firstRequest['time'] * 100, 1) . '% быстрее' : 'не быстрее'
    ]);
    
    if (!$dataMatch) {
        echo "  ⚠ ВНИМАНИЕ: Данные из кэша не совпадают с оригиналом!\n\n";
    }
} else {
    t_print_result("Второй запрос (с кэшем)", false, [
        'error' => 'Элемент не найден',
        'time_ms' => $secondRequest['time'] . ' ms'
    ]);
}

// ---------------- ТЕСТ 4: ПРОВЕРКА ПРОИЗВОДИТЕЛЬНОСТИ (МНОЖЕСТВЕННЫЕ ЗАПРОСЫ) ----------------

echo "ТЕСТ 4: Производительность при множественных запросах\n";
echo "------------------------------------------------------\n\n";

$iterations = 10;
$times = [];
$cacheHits = 0;

for ($i = 1; $i <= $iterations; $i++) {
    $request = t_measure_time(function() use ($testDomain, $config) {
        return getElementDataFromIblock54ByDomain($testDomain, $config);
    });
    
    $times[] = $request['time'];
    
    // Если запрос быстрее среднего времени первого запроса, считаем что это кэш
    if ($request['time'] < $firstRequest['time'] * 0.5) {
        $cacheHits++;
    }
}

$avgTime = round(array_sum($times) / count($times), 2);
$minTime = round(min($times), 2);
$maxTime = round(max($times), 2);
$estimatedCacheHits = $cacheHits;
$estimatedDbQueries = $iterations - $cacheHits;

t_print_result("Множественные запросы ($iterations итераций)", true, [
    'avg_time_ms' => $avgTime . ' ms',
    'min_time_ms' => $minTime . ' ms',
    'max_time_ms' => $maxTime . ' ms',
    'estimated_cache_hits' => $estimatedCacheHits,
    'estimated_db_queries' => $estimatedDbQueries,
    'cache_hit_rate' => round($estimatedCacheHits / $iterations * 100, 1) . '%',
    'first_request_time' => $firstRequest['time'] . ' ms (для сравнения)'
]);

// ---------------- ТЕСТ 5: ПРОВЕРКА TTL (ВРЕМЯ ЖИЗНИ КЭША) ----------------

echo "ТЕСТ 5: Проверка TTL (время жизни кэша)\n";
echo "----------------------------------------\n\n";

// Очищаем кэш
$cachePath = $cacheDir . '/' . md5($cacheKey);
if (file_exists($cachePath)) {
    @unlink($cachePath);
}

// Получаем элемент (создаст кэш)
$beforeTTL = t_measure_time(function() use ($testDomain, $config) {
    return getElementDataFromIblock54ByDomain($testDomain, $config);
});

echo "  - Элемент получен, кэш создан\n";
echo "  - Ожидание 2 секунды...\n";
sleep(2);

// Получаем элемент снова (должен быть из кэша)
$afterTTL = t_measure_time(function() use ($testDomain, $config) {
    return getElementDataFromIblock54ByDomain($testDomain, $config);
});

$stillCached = $afterTTL['time'] < $beforeTTL['time'] * 0.5;

t_print_result("TTL проверка (2 секунды)", $stillCached, [
    'before_ttl_time_ms' => $beforeTTL['time'] . ' ms',
    'after_ttl_time_ms' => $afterTTL['time'] . ' ms',
    'still_cached' => $stillCached ? 'да' : 'нет',
    'note' => 'Кэш должен работать минимум 2 секунды (обычно TTL = 3600+ секунд)'
]);

// ---------------- ТЕСТ 6: ПРОВЕРКА КЭШИРОВАНИЯ ПО SUBPOOL + SITEID (если указаны) ----------------

if ($testSubPool && $testSiteId) {
    echo "\nТЕСТ 6: Получение элемента ИБ54 по subPool + siteId (CallTouch)\n";
    echo "---------------------------------------------------------------\n\n";
    
    // Очищаем кэш
    $cacheKeySubPool = 'iblock54_subpool_' . md5($testSubPool . $testSiteId);
    $cachePathSubPool = $cacheDir . '/' . md5($cacheKeySubPool);
    if (file_exists($cachePathSubPool)) {
        @unlink($cachePathSubPool);
    }
    
    $firstSubPoolRequest = t_measure_time(function() use ($testSubPool, $testSiteId, $config) {
        return getElementDataFromIblock54BySubPoolAndSiteId($testSubPool, $testSiteId, $config);
    });
    
    if ($firstSubPoolRequest['result']) {
        $firstSubPoolElementId = $firstSubPoolRequest['result']['element']['ID'] ?? 'unknown';
        
        t_print_result("Первый запрос subPool (без кэша)", true, [
            'element_id' => $firstSubPoolElementId,
            'element_name' => $firstSubPoolRequest['result']['element']['NAME'] ?? 'unknown',
            'time_ms' => $firstSubPoolRequest['time'] . ' ms'
        ]);
        
        // Второй запрос (с кэшем)
        $secondSubPoolRequest = t_measure_time(function() use ($testSubPool, $testSiteId, $config) {
            return getElementDataFromIblock54BySubPoolAndSiteId($testSubPool, $testSiteId, $config);
        });
        
        if ($secondSubPoolRequest['result']) {
            $fasterSubPool = $secondSubPoolRequest['time'] < $firstSubPoolRequest['time'];
            
            t_print_result("Второй запрос subPool (с кэшем)", true, [
                'element_id' => $secondSubPoolRequest['result']['element']['ID'] ?? 'unknown',
                'time_ms' => $secondSubPoolRequest['time'] . ' ms',
                'from_cache' => $fasterSubPool ? 'вероятно да' : 'вероятно нет',
                'speedup' => $fasterSubPool ? round(($firstSubPoolRequest['time'] - $secondSubPoolRequest['time']) / $firstSubPoolRequest['time'] * 100, 1) . '% быстрее' : 'не быстрее'
            ]);
        }
    } else {
        t_print_result("Первый запрос subPool (без кэша)", false, [
            'error' => 'Элемент не найден',
            'time_ms' => $firstSubPoolRequest['time'] . ' ms'
        ]);
    }
}

// ---------------- ИТОГОВЫЙ ОТЧЕТ ----------------

echo "\n";
echo "ИТОГОВЫЙ ОТЧЕТ:\n";
echo "---------------\n\n";

$summary = [
    'Кэш доступен' => $cacheAvailable ? '✓' : '✗',
    'Первый запрос (мс)' => $firstRequest['time'] ?? 'N/A',
    'Второй запрос (мс)' => $secondRequest['time'] ?? 'N/A',
    'Ускорение' => isset($firstRequest['time']) && isset($secondRequest['time']) && $secondRequest['time'] < $firstRequest['time'] 
        ? round(($firstRequest['time'] - $secondRequest['time']) / $firstRequest['time'] * 100, 1) . '%' 
        : 'N/A',
    'Среднее время (10 запросов, мс)' => $avgTime ?? 'N/A',
    'Оценка попаданий в кэш' => isset($estimatedCacheHits) ? $estimatedCacheHits . '/' . $iterations . ' (' . round($estimatedCacheHits / $iterations * 100, 1) . '%)' : 'N/A'
];

foreach ($summary as $key => $value) {
    echo "  $key: $value\n";
}

echo "\n";
echo "РЕКОМЕНДАЦИИ:\n";
echo "-------------\n\n";

if ($cacheAvailable) {
    echo "  ✓ Кэш доступен и работает\n";
    
    if (isset($secondRequest['time']) && $secondRequest['time'] < $firstRequest['time'] * 0.5) {
        echo "  ✓ Кэширование работает корректно (второй запрос быстрее)\n";
    } else {
        echo "  ⚠ Кэширование может не работать (второй запрос не быстрее)\n";
        echo "     Проверьте настройки Bitrix Cache и права на директорию /bitrix/cache\n";
    }
    
    if (isset($estimatedCacheHits) && $estimatedCacheHits >= $iterations * 0.8) {
        echo "  ✓ Высокий процент попаданий в кэш (" . round($estimatedCacheHits / $iterations * 100, 1) . "%)\n";
    } else {
        echo "  ⚠ Низкий процент попаданий в кэш\n";
    }
    
    echo "\n  → Можно внедрять кэширование в production\n";
} else {
    echo "  ✗ Кэш недоступен. Проверьте настройки Bitrix.\n";
    echo "  → НЕ рекомендуется внедрять кэширование до устранения проблемы\n";
}

echo "\n";

