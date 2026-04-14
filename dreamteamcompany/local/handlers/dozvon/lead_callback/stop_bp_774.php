<?php
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__ . '/../../../..');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
header('Content-Type: text/plain; charset=utf-8');

\Bitrix\Main\Loader::includeModule('bizproc');

$db = \Bitrix\Main\Application::getConnection();

$result = $db->query(
    "SELECT s.ID, s.DOCUMENT_ID_INT, s.STATE_TITLE, s.STARTED
     FROM b_bp_workflow_state s
     INNER JOIN b_bp_workflow_instance i ON i.ID = s.ID
     WHERE s.WORKFLOW_TEMPLATE_ID = 774"
);

$ids = [];
while ($row = $result->fetch()) {
    echo "ACTIVE: wf={$row['ID']} lead={$row['DOCUMENT_ID_INT']} state={$row['STATE_TITLE']} started={$row['STARTED']}\n";
    $ids[] = "'" . $db->getSqlHelper()->forSql($row['ID']) . "'";
}

if (empty($ids)) {
    echo "\nНет активных процессов для шаблона 774. Всё остановлено.\n";
    exit;
}

$idList = implode(',', $ids);
$db->query("DELETE FROM b_bp_workflow_instance WHERE ID IN ({$idList})");

echo "\nШаблон 774: остановлено " . count($ids) . " активных процессов\n";
