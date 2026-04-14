<?php
/**
 * add_iblock54.php — однокликовое добавление элемента в список 54 (NAME + PROPERTY_199)
 * Версия с нативным API Bitrix24
 */

require_once(__DIR__ . '/bitrix_init.php');
require_once(__DIR__ . '/iblock_functions.php');

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

CModule::IncludeModule("iblock");
CModule::IncludeModule("lists");

// Настройки списков
$iblockId = $config['iblock']['iblock_54_id'] ?? 54;
$iblockTypeId = $config['iblock']['iblock_type_id'] ?? 'lists_socnet';
$socnetGroupId = $config['iblock']['socnet_group_id'] ?? 1;

// 1) Проверка на существование элемента с той же парой NAME + PROPERTY_199
$filter = [
    'IBLOCK_ID' => $iblockId,
    'NAME' => $name,
    'ACTIVE' => 'Y',
];

$dbRes = CIBlockElement::GetList(
    ['ID' => 'ASC'],
    $filter,
    false,
    ['nTopCount' => 10],
    ['ID', 'NAME']
);

$existing = null;
while ($arElement = $dbRes->Fetch()) {
    $elementId = $arElement['ID'];
    
    // Проверяем PROPERTY_199
    $dbProps = CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => 'PROPERTY_199']);
    while ($arProp = $dbProps->Fetch()) {
        if ($arProp['CODE'] === 'PROPERTY_199' && $arProp['VALUE'] == $siteId) {
            $existing = $arElement;
            break 2;
        }
    }
}

if ($existing && !empty($existing['ID'])) {
    render_page('Элемент уже существует', '<p class="ok">Элемент найден (ID: <b>'
        . htmlspecialchars((string)$existing['ID']) . '</b>, NAME: <b>'
        . htmlspecialchars((string)$existing['NAME']) . '</b>).</p>'
        . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
    exit;
}

// Поиск города по названию siteName в списке 22 и получение его ID
$cityElementId = null;
if ($siteName !== '') {
    $citiesIblockId = $config['iblock']['iblock_22_id'] ?? 22;
    
    $cityFilter = [
        'IBLOCK_ID' => $citiesIblockId,
        'NAME' => $siteName,
        'ACTIVE' => 'Y',
    ];
    
    $cityDbRes = CIBlockElement::GetList(
        ['ID' => 'ASC'],
        $cityFilter,
        false,
        ['nTopCount' => 1],
        ['ID', 'NAME']
    );
    
    if ($cityElement = $cityDbRes->Fetch()) {
        $cityElementId = (int)$cityElement['ID'];
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

// 2) Добавление элемента
$el = new CIBlockElement;

$arFields = [
    'IBLOCK_ID' => $iblockId,
    'IBLOCK_TYPE_ID' => $iblockTypeId,
    'NAME' => $name,
    'ACTIVE' => 'Y',
    'CODE' => 'ct_' . bin2hex(random_bytes(4)) . '_' . time(),
    'PROPERTY_VALUES' => [
        'PROPERTY_199' => $siteId,
        'PROPERTY_191' => $cityElementId,
        'PROPERTY_202' => 131, // calltouch
        'PROPERTY_379' => $excludeFlag ? 133 : null, // нет
    ],
];

// Для групповых списков
if ($iblockTypeId === 'lists_socnet') {
    $arFields['SOCNET_GROUP_ID'] = $socnetGroupId;
}

$elementId = $el->Add($arFields);

if ($elementId) {
    // Успех
    $redirect = 'https://bitrix.dreamteamcompany.ru/workgroups/group/1/lists/54/view/0/?list_section_id=';
    render_page('Элемент создан', '<p class="ok">Элемент успешно создан (ID: <b>'
        . htmlspecialchars((string)$elementId) . '</b>).</p>'
        . '<div class="btns"><a class="btn" href="' . htmlspecialchars($redirect, ENT_QUOTES) . '">Перейти к списку 54</a></div>');
    exit;
} else {
    $error = $el->LAST_ERROR;
    http_response_code(500);
    ob_start();
    echo '<p class="err">Ошибка создания элемента</p>';
    echo '<pre class="muted">' . htmlspecialchars($error) . '</pre>';
    $content = ob_get_clean();
    render_page('Ошибка создания элемента', $content . '<div class="btns"><a class="btn" href="' . htmlspecialchars($viewList, ENT_QUOTES) . '">Открыть список 54</a></div>');
    exit;
}

