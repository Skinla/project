<?php
/**
 * Тест: чтение скрытого UF поля UF_VI_BACKPHONE профиля сотрудника (через CUser::GetList + SELECT).
 */

if (!defined('NOT_CHECK_PERMISSIONS')) {
    define('NOT_CHECK_PERMISSIONS', true);
}
if (!defined('NO_KEEP_STATISTIC')) {
    define('NO_KEEP_STATISTIC', true);
}
if (!defined('NO_AGENT_CHECK')) {
    define('NO_AGENT_CHECK', true);
}

require_once __DIR__ . '/bootstrap.php';

$fieldCode = isset($_REQUEST['field']) ? preg_replace('/[^A-Z0-9_]/i', '', (string)$_REQUEST['field']) : 'UF_VI_BACKPHONE';
if ($fieldCode === '') {
    $fieldCode = 'UF_VI_BACKPHONE';
}

$userId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
$format = isset($_REQUEST['format']) ? trim((string)$_REQUEST['format']) : '';

$report = lead_callback_v2_vi_backphone_build_report($userId, $fieldCode);

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

header('Content-Type: text/html; charset=utf-8');
echo lead_callback_v2_vi_backphone_render_html($userId, $fieldCode, $report);

/**
 * @param int    $userId
 * @param string $fieldCode
 * @return array
 */
function lead_callback_v2_vi_backphone_build_report($userId, $fieldCode)
{
    $report = [
        'ok' => false,
        'user_id' => $userId,
        'field' => $fieldCode,
        'checked_at' => date('c'),
        'bitrix_user_id' => (isset($GLOBALS['USER']) && $GLOBALS['USER'] instanceof CUser) ? (int)$GLOBALS['USER']->GetID() : 0,
        'value' => null,
        'value_present_in_row' => false,
        'row_keys_sample' => [],
        'error' => '',
    ];

    if ($userId <= 0) {
        $report['error'] = 'Укажите положительный user_id';
        return $report;
    }

    if (!class_exists('CUser')) {
        $report['error'] = 'CUser недоступен';
        return $report;
    }

    $by = 'id';
    $order = 'asc';
    $filter = ['ID' => $userId];
    $params = [
        'FIELDS' => ['ID', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'LOGIN', 'EMAIL', 'WORK_POSITION'],
        'SELECT' => [$fieldCode],
    ];

    $rs = CUser::GetList($by, $order, $filter, $params);
    if (!$rs) {
        $report['error'] = 'CUser::GetList вернул пустой результат';
        return $report;
    }

    $row = $rs->Fetch();
    if (!is_array($row)) {
        $report['error'] = 'Пользователь не найден: ID=' . $userId;
        return $report;
    }

    $keys = array_keys($row);
    sort($keys);
    $report['row_keys_sample'] = array_slice($keys, 0, 40);
    $report['value_present_in_row'] = array_key_exists($fieldCode, $row);
    $report['value'] = $report['value_present_in_row'] ? $row[$fieldCode] : null;
    $report['ok'] = $report['value_present_in_row'];
    if (!$report['value_present_in_row']) {
        $report['error'] = 'Ключ ' . $fieldCode . ' отсутствует в строке пользователя (поле не выбралось или не существует для USER)';
    }

    $report['user_preview'] = [
        'ID' => $row['ID'] ?? '',
        'NAME' => $row['NAME'] ?? '',
        'LAST_NAME' => $row['LAST_NAME'] ?? '',
        'LOGIN' => $row['LOGIN'] ?? '',
        'EMAIL' => $row['EMAIL'] ?? '',
    ];

    return $report;
}

/**
 * @param int    $userId
 * @param string $fieldCode
 * @param array  $report
 * @return string
 */
function lead_callback_v2_vi_backphone_render_html($userId, $fieldCode, array $report)
{
    $h = static function ($s) {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    };

    $jsonUrl = '?format=json&user_id=' . urlencode((string)$userId) . '&field=' . urlencode($fieldCode);

    $statusClass = !empty($report['ok']) ? 'ok' : 'fail';
    $valueStr = $report['value_present_in_row']
        ? (string)$report['value']
        : '(ключ не пришёл в выборке)';

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Lead Callback V2: UF_VI_BACKPHONE</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 24px; max-width: 900px; }
        label { display: block; margin: 12px 0 4px; font-weight: 600; }
        input[type="text"], input[type="number"] { width: 100%; max-width: 420px; padding: 8px; }
        button { margin-top: 16px; padding: 10px 18px; cursor: pointer; }
        .hint { color: #555; font-size: 0.9rem; margin-top: 8px; }
        .result { margin-top: 24px; padding: 16px; border-radius: 8px; background: #f6f7f9; }
        .ok { border-left: 4px solid #2a7; }
        .fail { border-left: 4px solid #c33; }
        pre { white-space: pre-wrap; word-break: break-all; background: #fff; padding: 12px; border-radius: 6px; }
        a { color: #06c; }
    </style>
</head>
<body>
    <h1>Lead Callback V2: тест UF_VI_BACKPHONE</h1>
    <p>Чтение через <code>CUser::GetList</code> с явным <code>SELECT</code> (как для скрытых UF).</p>

    <form method="get" action="">
        <label for="user_id">ID пользователя (сотрудника)</label>
        <input type="number" name="user_id" id="user_id" min="1" value="<?php echo $h($userId > 0 ? (string)$userId : ''); ?>" required>

        <label for="field">Код поля (по умолчанию UF_VI_BACKPHONE)</label>
        <input type="text" name="field" id="field" value="<?php echo $h($fieldCode); ?>">

        <button type="submit">Показать</button>
        <p class="hint">JSON: <a href="<?php echo $h($jsonUrl); ?>"><?php echo $h($jsonUrl); ?></a></p>
    </form>

    <?php if ($userId > 0) { ?>
    <div class="result <?php echo $h($statusClass); ?>">
        <p><strong>Поле:</strong> <code><?php echo $h($fieldCode); ?></code></p>
        <p><strong>Значение:</strong> <code><?php echo $h($valueStr); ?></code></p>
        <?php if (!empty($report['error'])) { ?>
            <p><strong>Ошибка / примечание:</strong> <?php echo $h($report['error']); ?></p>
        <?php } ?>
        <p><strong>Текущий пользователь PHP (после bootstrap):</strong> <?php echo $h((string)($report['bitrix_user_id'] ?? '')); ?></p>
        <p><strong>Ключ есть в строке Fetch:</strong> <?php echo !empty($report['value_present_in_row']) ? 'да' : 'нет'; ?></p>
        <h3>Полный отчёт (JSON)</h3>
        <pre><?php echo $h(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
    </div>
    <?php } ?>

    <p class="hint">Доступ: как у остальных скриптов v2 — админ портала или <code>cron_key</code> из <code>config.php</code>.</p>
</body>
</html>
    <?php
    return (string)ob_get_clean();
}
