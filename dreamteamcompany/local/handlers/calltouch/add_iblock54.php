<?php
// add_iblock54.php — однокликовое добавление элемента в список 54 (NAME + PROPERTY_199)

// Результат выводим как HTML
header('Content-Type: text/html; charset=utf-8');

// Простая обёртка для красивого вывода
function render_page(string $title, string $bodyHtml): void {
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
       . '<style>
            :root{--bg:#0f172a;--card:#111827;--muted:#9ca3af;--ok:#16a34a;--warn:#f59e0b;--err:#ef4444;--link:#60a5fa}
            html,body{height:100%}
            body{margin:0;background:linear-gradient(135deg,#0f172a,#111827);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu;display:flex;align-items:center;justify-content:center;color:#e5e7eb}
            .card{width:min(780px,92vw);background:#0b1220;border:1px solid #1f2937;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.4);overflow:hidden}
            .head{padding:18px 22px;border-bottom:1px solid #1f2937;background:#0a0f1c}
            .head h1{margin:0;font-size:18px;font-weight:600}
            .body{padding:22px}
            .body p{margin:0 0 12px}
            .grid{display:grid;gap:10px}
            .btns{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
            a.btn,button.btn{appearance:none;border:1px solid #334155;background:#111827;color:#e5e7eb;padding:10px 14px;border-radius:10px;text-decoration:none}
            a.btn:hover,button.btn:hover{border-color:#60a5fa;color:#fff}
            .ok{color:var(--ok)} .err{color:var(--err)} .muted{color:var(--muted)}
        </style></head><body>';
    echo '<div class="card"><div class="head"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1></div><div class="body">' . $bodyHtml . '</div></div>';
    echo '</body></html>';
}

$baseDir = __DIR__;
$configPath = $baseDir . '/calltouch_config.php';
$config = is_file($configPath) ? include $configPath : [];
if (!is_array($config)) { $config = []; }

$webhookUrl = $config['bitrix']['webhookUrl'] ?? '';
if ($webhookUrl === '') {
    http_response_code(500);
    render_page('Ошибка конфигурации', '<p class="err">Не задан webhookUrl в calltouch_config.php</p>');
    exit;
}

// Ссылка на просмотр списка 54
$viewList = 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/view/0/?list_section_id=';

// Параметры
$name = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
$siteId = isset($_GET['siteId']) ? trim((string)$_GET['siteId']) : '';
$siteName = isset($_GET['siteName']) ? trim((string)$_GET['siteName']) : '';
$exclude = isset($_GET['exclude']) ? (string)$_GET['exclude'] : '';
$excludeFlag = ($exclude === '1' || strtolower($exclude) === 'y' || strtolower($exclude) === 'yes');

if ($name === '' || $siteId === '') {
    http_response_code(400);
    render_page('Недостаточно данных', '<p class="err">Нужно передать параметры <b>name</b> и <b>siteId</b>.</p>'
        . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
    exit;
}

// Подготовка REST-запроса к Bitrix24: lists.element.add
// Настройки списков: учитываем, что список 54 — групповой (workgroups)
$listsConf = $config['lists'] ?? [];
$iblockId = (int)($listsConf['iblock_id'] ?? 54);
$iblockTypeId = (string)($listsConf['iblock_type_id'] ?? 'lists_socnet'); // для групповых списков
$socnetGroupId = (int)($listsConf['socnet_group_id'] ?? 1); // ID рабочей группы

// 1) Проверка на существование элемента с той же парой NAME + PROPERTY_199
$checkMethod = 'lists.element.get.json';
$checkPayload = [
    'IBLOCK_TYPE_ID' => $iblockTypeId,
    'IBLOCK_ID' => $iblockId,
    'SOCNET_GROUP_ID' => $iblockTypeId === 'lists_socnet' ? $socnetGroupId : null,
    'FILTER' => [
        'NAME' => $name,
        'PROPERTY_199' => $siteId,
    ],
    'SELECT' => ['ID','NAME']
];
$checkPayload = array_filter($checkPayload, function($v){ return $v !== null; });

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $webhookUrl . $checkMethod,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($checkPayload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30,
]);
$chkRaw = curl_exec($ch);
$chkErr = curl_error($ch);
$chkHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$chkErr && $chkHttp === 200) {
    $chkResp = json_decode($chkRaw, true);
    if (is_array($chkResp) && !empty($chkResp['result']) && is_array($chkResp['result'])) {
        $existing = $chkResp['result'][0] ?? null;
        if ($existing && !empty($existing['ID'])) {
            $viewList = 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/' . urlencode((string)$iblockId) . '/view/0/?list_section_id=';
            render_page('Элемент уже существует', '<p class="ok">Элемент найден (ID: <b>'
                . htmlspecialchars((string)$existing['ID']) . '</b>, NAME: <b>'
                . htmlspecialchars((string)$existing['NAME']) . '</b>).</p>'
                . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
            exit;
        }
    }
}

// Поиск города по названию siteName в списке 22 и получение его ID
$cityElementId = null;
if ($siteName !== '') {
    $citiesIblockId = (int)($listsConf['cities_iblock_id'] ?? 22);
    $citiesTypeId = (string)($listsConf['cities_iblock_type_id'] ?? $iblockTypeId);
    $citiesGroupId = (int)($listsConf['cities_socnet_group_id'] ?? $socnetGroupId);

    $cityPayload = [
        'IBLOCK_TYPE_ID' => $citiesTypeId,
        'IBLOCK_ID' => $citiesIblockId,
        'SOCNET_GROUP_ID' => $citiesTypeId === 'lists_socnet' ? $citiesGroupId : null,
        'FILTER' => [
            'NAME' => $siteName,
        ],
        'SELECT' => ['ID','NAME']
    ];
    $cityPayload = array_filter($cityPayload, function($v){ return $v !== null; });

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $webhookUrl . 'lists.element.get.json',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($cityPayload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $cityRaw = curl_exec($ch);
    $cityHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($cityHttp === 200) {
        $cityResp = json_decode($cityRaw, true);
        if (is_array($cityResp) && !empty($cityResp['result'][0]['ID'])) {
            $cityElementId = (int)$cityResp['result'][0]['ID'];
        }
    }
}

// Если город обязателен: без найденного города элемент не создаём
if ($siteName === '' || $cityElementId === null) {
    http_response_code(400);
    render_page('Не найден город', '<p class="err">Город обязателен: в списке 22 не найден элемент с названием '
        . '<b>' . htmlspecialchars($siteName === '' ? '(пусто)' : $siteName, ENT_QUOTES) . '</b>.</p>'
        . '<p class="muted">Элемент в списке 54 не создан.</p>'
        . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
    exit;
}

// 2) Добавление
$method = 'lists.element.add.json';
$payload = [
    'IBLOCK_TYPE_ID' => $iblockTypeId,
    'IBLOCK_ID' => $iblockId,
    // Для групповых списков требуется SOCNET_GROUP_ID
    'SOCNET_GROUP_ID' => $iblockTypeId === 'lists_socnet' ? $socnetGroupId : null,
    // Можно указать SECTION_ID при необходимости
    'ELEMENT_CODE' => 'ct_' . bin2hex(random_bytes(4)) . '_' . time(),
    'FIELDS' => [
        'NAME' => $name,
        'ACTIVE' => 'Y',
        // Свойство привязки к сайту: PROPERTY_199
        'PROPERTY_199' => $siteId,
        // Город (PROPERTY_191), если найден по siteName
        'PROPERTY_191' => $cityElementId,
        // Тип интеграции (PROPERTY_202, список): calltouch = 131
        'PROPERTY_202' => 131,
        // Исключения (PROPERTY_379, тип список): 132=Да, 133=нет. По запросу использовать "нет"
        'PROPERTY_379' => $excludeFlag ? 133 : null,
    ],
];
// Уберём null-поля
$payload = array_filter($payload, function($v){ return $v !== null; });
if (isset($payload['FIELDS']) && is_array($payload['FIELDS'])) {
    $payload['FIELDS'] = array_filter($payload['FIELDS'], function($v){ return $v !== null; });
}

// Вызов
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $webhookUrl . $method,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 30,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err) {
    http_response_code(502);
    echo "CURL error: " . $err;
    echo "<br><a href='" . htmlspecialchars($viewList, ENT_QUOTES) . "'>Открыть список 54</a>";
    exit;
}

if ($http !== 200) {
    http_response_code($http);
    echo "HTTP error: " . $http . "\n" . $raw;
    echo "<br><a href='" . htmlspecialchars($viewList, ENT_QUOTES) . "'>Открыть список 54</a>";
    exit;
}

$resp = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo "JSON error: " . json_last_error_msg();
    echo "<br><a href='" . htmlspecialchars($viewList, ENT_QUOTES) . "'>Открыть список 54</a>";
    exit;
}

if (!empty($resp['result'])) {
    // Успех — можно редиректнуть к списку, либо вывести текст
    // Редирект в список 54
    $redirect = 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/view/0/?list_section_id=';
    render_page('Элемент создан', '<p class="ok">Элемент успешно создан (ID: <b>'
        . htmlspecialchars((string)$resp['result']) . '</b>).</p>'
        . '<div class="btns"><a class="btn" href="' . htmlspecialchars($redirect, ENT_QUOTES) . '">Перейти к списку 54</a></div>');
    exit;
}

http_response_code(500);
ob_start();
echo '<p class="err">Bitrix API error</p>';
echo '<pre class="muted">' . htmlspecialchars(print_r($resp, true)) . '</pre>';
$content = ob_get_clean();
render_page('Ошибка Bitrix API', $content . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
exit;


