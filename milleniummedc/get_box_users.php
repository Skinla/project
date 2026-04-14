<?php
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
if (!$docRoot || !is_dir($docRoot . '/bitrix')) {
    $docRoot = '/home/bitrix/www';
}
$_SERVER['DOCUMENT_ROOT'] = $docRoot;
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require_once '/home/bitrix/www/bitrix/modules/main/include/prolog_before.php';

$res = CUser::GetList('ID', 'ASC', ['ACTIVE' => 'Y'], ['FIELDS' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME']]);
$users = [];
while ($u = $res->Fetch()) {
    $users[] = ['ID' => $u['ID'], 'LOGIN' => $u['LOGIN'], 'EMAIL' => $u['EMAIL'], 'NAME' => $u['NAME'], 'LAST_NAME' => $u['LAST_NAME']];
}
echo json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
