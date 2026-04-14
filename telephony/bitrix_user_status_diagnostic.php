<?php

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');

error_reporting(E_ALL);
ini_set('display_errors', '1');

const EXIT_OK = 0;
const EXIT_ERROR = 1;

// Change this value for quick local testing.
$defaultUserId = 1;
$defaultDocumentRoot = '';
$defaultPortalBase = '';
$defaultWebhookUser = '';
$defaultWebhookCode = '';
$webAccessToken = 'change-me';
$allowAuthorizedPortalUser = true;
$requireAdminForWebUser = true;
$defaultWebFormat = 'html'; // html|json

$options = $isCli
    ? getopt('', [
        'user-id:',
        'document-root::',
        'portal-base::',
        'webhook-user::',
        'webhook-code::',
        'pretty::',
        'format::',
    ])
    : [
        'user-id' => $_GET['user_id'] ?? null,
        'document-root' => $_GET['document_root'] ?? null,
        'portal-base' => $_GET['portal_base'] ?? null,
        'webhook-user' => $_GET['webhook_user'] ?? null,
        'webhook-code' => $_GET['webhook_code'] ?? null,
        'pretty' => $_GET['pretty'] ?? '1',
        'format' => $_GET['format'] ?? $defaultWebFormat,
        'token' => $_GET['token'] ?? null,
    ];

$outputFormat = strtolower((string)($options['format'] ?? 'json'));
$isHtmlOutput = !$isCli && $outputFormat !== 'json';

$userId = isset($options['user-id']) ? (int)$options['user-id'] : $defaultUserId;
if ($userId <= 0) {
    outputAndExit(
        [
            'error' => 'invalid_user_id',
            'error_description' => 'Set $defaultUserId in the script or pass user_id/--user-id',
            'usage_cli' => 'php bitrix_user_status_diagnostic.php [--user-id=123] [--document-root=/var/www/site] [--portal-base=http://10.0.0.10] [--webhook-user=1 --webhook-code=token] [--pretty=1]',
            'usage_web' => '/local/test/bitrix_user_status_diagnostic.php?token=change-me&user_id=123&pretty=1',
        ],
        EXIT_ERROR,
        $isCli
    );
}

$documentRoot = isset($options['document-root']) && (string)$options['document-root'] !== ''
    ? (string)$options['document-root']
    : ($defaultDocumentRoot !== '' ? $defaultDocumentRoot : detectDocumentRoot(__DIR__));

$portalBase = isset($options['portal-base']) && (string)$options['portal-base'] !== ''
    ? rtrim((string)$options['portal-base'], '/')
    : rtrim($defaultPortalBase, '/');

$webhookUser = isset($options['webhook-user']) && (string)$options['webhook-user'] !== ''
    ? (string)$options['webhook-user']
    : $defaultWebhookUser;

$webhookCode = isset($options['webhook-code']) && (string)$options['webhook-code'] !== ''
    ? (string)$options['webhook-code']
    : $defaultWebhookCode;

if ($documentRoot === '' || !is_dir($documentRoot . '/bitrix')) {
    outputAndExit(
        [
            'error' => 'invalid_document_root',
            'error_description' => 'Could not detect Bitrix document root. Set $defaultDocumentRoot or pass document_root/--document-root',
        ],
        EXIT_ERROR,
        $isCli
    );
}

$_SERVER['DOCUMENT_ROOT'] = rtrim($documentRoot, '/');
$_SERVER['SCRIPT_FILENAME'] = __FILE__;
$_SERVER['SCRIPT_NAME'] = basename(__FILE__);
$_SERVER['REQUEST_METHOD'] = $isCli ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('BX_NO_ACCELERATOR_RESET', true);
define('BX_WITH_ON_AFTER_EPILOG', false);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

if (!$isCli) {
    enforceWebAccess(
        (string)($options['token'] ?? ''),
        $webAccessToken,
        $allowAuthorizedPortalUser,
        $requireAdminForWebUser
    );
}

$result = [
    'script' => [
        'time' => date('c'),
        'mode' => $isCli ? 'cli' : 'web',
        'user_id' => $userId,
        'document_root' => $_SERVER['DOCUMENT_ROOT'],
        'portal_base' => $portalBase !== '' ? $portalBase : null,
        'rest_enabled' => ($portalBase !== '' && $webhookUser !== '' && $webhookCode !== ''),
    ],
    'modules' => [],
    'user' => [],
    'timeman' => [],
    'im' => [],
    'telephony' => [],
    'mobile_web' => [],
    'rest_via_internal_ip' => [],
    'errors' => [],
];

$result['modules']['main'] = Loader::includeModule('main');
$result['modules']['timeman'] = Loader::includeModule('timeman');
$result['modules']['im'] = Loader::includeModule('im');
$result['modules']['voximplant'] = Loader::includeModule('voximplant');

try {
    $result['user'] = getUserData($userId);
} catch (Throwable $e) {
    $result['errors'][] = 'user: ' . $e->getMessage();
}

if ($result['modules']['timeman']) {
    try {
        $result['timeman'] = getTimemanData($userId);
    } catch (Throwable $e) {
        $result['errors'][] = 'timeman: ' . $e->getMessage();
    }
} else {
    $result['timeman'] = ['available' => false, 'reason' => 'timeman module not loaded'];
}

if ($result['modules']['im']) {
    try {
        $result['im'] = getImData($userId);
    } catch (Throwable $e) {
        $result['errors'][] = 'im: ' . $e->getMessage();
    }
} else {
    $result['im'] = ['available' => false, 'reason' => 'im module not loaded'];
}

if ($result['modules']['voximplant']) {
    try {
        $result['telephony'] = getTelephonyData($userId);
    } catch (Throwable $e) {
        $result['errors'][] = 'telephony: ' . $e->getMessage();
    }
} else {
    $result['telephony'] = ['available' => false, 'reason' => 'voximplant module not loaded'];
}

try {
    $result['mobile_web'] = getMobileWebData($userId, [
        'user' => $result['user'] ?? [],
        'im' => $result['im'] ?? [],
        'telephony' => $result['telephony'] ?? [],
        'portal_base' => $portalBase,
        'webhook_user' => $webhookUser,
        'webhook_code' => $webhookCode,
    ]);
} catch (Throwable $e) {
    $result['errors'][] = 'mobile_web: ' . $e->getMessage();
}

if ($portalBase !== '' && $webhookUser !== '' && $webhookCode !== '') {
    try {
        $result['rest_via_internal_ip'] = [
            'user_get' => callRest($portalBase, $webhookUser, $webhookCode, 'user.get', ['ID' => $userId]),
            'user_current' => callRest($portalBase, $webhookUser, $webhookCode, 'user.current', []),
            'timeman_status' => callRest($portalBase, $webhookUser, $webhookCode, 'timeman.status', ['USER_ID' => $userId]),
            'im_user_status_get_current_webhook_user' => callRest($portalBase, $webhookUser, $webhookCode, 'im.user.status.get', []),
        ];
    } catch (Throwable $e) {
        $result['errors'][] = 'rest_via_internal_ip: ' . $e->getMessage();
    }
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
if (!empty($options['pretty'])) {
    $jsonFlags |= JSON_PRETTY_PRINT;
}

if ($isHtmlOutput) {
    renderHtmlReport($result);
    exit(EXIT_OK);
}

if (!$isCli && !headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

echo json_encode($result, $jsonFlags) . PHP_EOL;
exit(EXIT_OK);

function detectDocumentRoot(string $startDir): string
{
    $dir = realpath($startDir) ?: $startDir;

    while ($dir !== '' && $dir !== '/' && $dir !== '.') {
        if (is_dir($dir . '/bitrix')) {
            return $dir;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return '';
}

function getUserData(int $userId): array
{
    $user = \CUser::GetByID($userId)->Fetch();
    if (!is_array($user)) {
        throw new RuntimeException('User not found: ' . $userId);
    }

    return [
        'ID' => (int)$user['ID'],
        'LOGIN' => (string)($user['LOGIN'] ?? ''),
        'NAME' => trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? '')),
        'ACTIVE' => (string)($user['ACTIVE'] ?? ''),
        'EMAIL' => (string)($user['EMAIL'] ?? ''),
        'WORK_POSITION' => (string)($user['WORK_POSITION'] ?? ''),
        'PERSONAL_MOBILE' => (string)($user['PERSONAL_MOBILE'] ?? ''),
        'UF_PHONE_INNER' => (string)($user['UF_PHONE_INNER'] ?? ''),
        'TIMESTAMP_X' => normalizeDateValue($user['TIMESTAMP_X'] ?? null),
        'LAST_LOGIN' => normalizeDateValue($user['LAST_LOGIN'] ?? null),
        'LAST_ACTIVITY_DATE' => normalizeDateValue($user['LAST_ACTIVITY_DATE'] ?? null),
        'IS_ONLINE' => detectOnlineFlag($user),
        'RAW_SAFE' => redactSensitiveFields($user),
    ];
}

function getTimemanData(int $userId): array
{
    $data = [
        'available' => true,
        'status' => null,
        'status_text_ru' => null,
        'can_open_day' => null,
        'can_pause_day' => null,
        'can_close_day' => null,
        'raw' => [],
    ];

    if (class_exists('\Bitrix\Timeman\Service\Path\PathManager')) {
        $data['note'] = 'D7 Timeman services found, but stable CLI contract differs by version.';
    }

    if (class_exists('CTimeManUser')) {
        $tmUser = new \CTimeManUser($userId);
        if (method_exists($tmUser, 'State')) {
            $state = $tmUser->State();
            if (is_array($state)) {
                $data['status'] = (string)($state['STATUS'] ?? '');
            } elseif (is_string($state)) {
                $data['status'] = $state;
            } else {
                $data['status'] = null;
            }
            $data['status_text_ru'] = mapTimemanStatus($data['status']);
            $data['raw']['State'] = $state;
        }
        if (method_exists($tmUser, 'CanOpenDay')) {
            $data['can_open_day'] = (bool)$tmUser->CanOpenDay();
        }
        if (method_exists($tmUser, 'CanPauseDay')) {
            $data['can_pause_day'] = (bool)$tmUser->CanPauseDay();
        }
        if (method_exists($tmUser, 'CanCloseDay')) {
            $data['can_close_day'] = (bool)$tmUser->CanCloseDay();
        }
    } else {
        $data['available'] = false;
        $data['reason'] = 'CTimeManUser class not found';
    }

    return $data;
}

function getImData(int $userId): array
{
    $data = [
        'available' => true,
        'user_id' => $userId,
        'status' => null,
        'status_text_ru' => null,
        'is_online' => null,
        'last_activity_date' => null,
        'raw' => [],
    ];

    $user = \CUser::GetByID($userId)->Fetch();
    if (is_array($user)) {
        $data['is_online'] = detectOnlineFlag($user);
        $data['last_activity_date'] = normalizeDateValue($user['LAST_ACTIVITY_DATE'] ?? null);
        $data['raw']['user_fields'] = [
            'IS_ONLINE' => $user['IS_ONLINE'] ?? null,
            'LAST_ACTIVITY_DATE' => normalizeDateValue($user['LAST_ACTIVITY_DATE'] ?? null),
        ];
    }

    // Cross-version safe: try legacy IM status API if available.
    if (class_exists('CIMStatus') && method_exists('CIMStatus', 'GetList')) {
        try {
            $statusRes = \CIMStatus::GetList([], ['=USER_ID' => $userId]);
            if (is_object($statusRes) && method_exists($statusRes, 'Fetch')) {
                $statusRow = $statusRes->Fetch();
                if (is_array($statusRow)) {
                    $data['raw']['im_status_row'] = $statusRow;
                    if (!empty($statusRow['STATUS'])) {
                        $data['status'] = (string)$statusRow['STATUS'];
                    }
                }
            }
        } catch (Throwable $e) {
            $data['raw']['im_error'] = $e->getMessage();
        }
    }

    if ($data['status'] !== null) {
        $data['status_text_ru'] = mapImStatus($data['status']);
    }

    return $data;
}

function getTelephonyData(int $userId): array
{
    global $DB;

    $data = [
        'available' => true,
        'user_id' => $userId,
        'current_flags' => [],
        'active_calls' => [],
        'raw' => [],
    ];

    if (class_exists('CVoxImplantIncoming') && method_exists('CVoxImplantIncoming', 'getUserInfo')) {
        $userInfo = \CVoxImplantIncoming::getUserInfo($userId, false);
        $data['raw']['getUserInfo'] = $userInfo;
        if (is_array($userInfo)) {
            $data['current_flags'] = [
                'BUSY' => normalizeBoolish($userInfo['BUSY'] ?? null),
                'ONLINE' => normalizeBoolish($userInfo['ONLINE'] ?? null),
                'AVAILABLE' => normalizeBoolish($userInfo['AVAILABLE'] ?? null),
                'USER_HAVE_PHONE' => normalizeBoolish($userInfo['USER_HAVE_PHONE'] ?? null),
                'USER_HAVE_MOBILE' => normalizeBoolish($userInfo['USER_HAVE_MOBILE'] ?? null),
            ];
        }
    } else {
        $data['raw']['getUserInfo'] = 'CVoxImplantIncoming::getUserInfo not available';
    }

    $callColumns = getTableColumns('b_voximplant_call');
    $callUserColumns = getTableColumns('b_voximplant_call_user');

    if (empty($callColumns) || empty($callUserColumns)) {
        $data['available'] = false;
        $data['raw']['schema_error'] = 'Could not read voximplant table schema';
        return $data;
    }

    $selectParts = [
        'cu.CALL_ID',
        'cu.USER_ID',
    ];

    if (in_array('ROLE', $callUserColumns, true)) {
        $selectParts[] = 'cu.ROLE';
    }
    if (in_array('STATUS', $callUserColumns, true)) {
        $selectParts[] = 'cu.STATUS AS USER_STATUS';
    }
    if (in_array('DEVICE', $callUserColumns, true)) {
        $selectParts[] = 'cu.DEVICE';
    }
    if (in_array('INSERTED', $callUserColumns, true)) {
        $selectParts[] = 'cu.INSERTED';
    }
    if (in_array('STATUS', $callColumns, true)) {
        $selectParts[] = 'c.STATUS AS CALL_STATUS';
    }
    if (in_array('PORTAL_USER_ID', $callColumns, true)) {
        $selectParts[] = 'c.PORTAL_USER_ID';
    }
    if (in_array('USER_ID', $callColumns, true)) {
        $selectParts[] = 'c.USER_ID AS CALL_USER_ID';
    }
    if (in_array('PHONE_NUMBER', $callColumns, true)) {
        $selectParts[] = 'c.PHONE_NUMBER';
    }
    if (in_array('CALLER_ID', $callColumns, true)) {
        $selectParts[] = 'c.CALLER_ID';
    }
    if (in_array('LAST_PING', $callColumns, true)) {
        $selectParts[] = 'c.LAST_PING';
    }

    $orderBy = in_array('ID', $callUserColumns, true) ? 'cu.ID DESC' : 'cu.CALL_ID DESC';
    $sql = "
        SELECT " . implode(",\n            ", $selectParts) . "
        FROM b_voximplant_call_user cu
        INNER JOIN b_voximplant_call c ON c.CALL_ID = cu.CALL_ID
        WHERE cu.USER_ID = " . $userId . "
        ORDER BY " . $orderBy . "
        LIMIT 20
    ";

    $callRows = [];
    $dbResult = $DB->Query($sql);
    while ($row = $dbResult->Fetch()) {
        $callRows[] = $row;
    }

    $data['raw']['recent_call_rows'] = $callRows;
    $data['active_calls'] = array_values(array_filter($callRows, static function (array $row): bool {
        $callStatus = (string)($row['CALL_STATUS'] ?? '');
        $userStatus = (string)($row['USER_STATUS'] ?? '');

        return in_array($callStatus, ['waiting', 'connecting', 'connected'], true)
            || in_array($userStatus, ['waiting', 'connecting', 'connected'], true);
    }));

    return $data;
}

function getMobileWebData(int $userId, array $context = []): array
{
    global $DB;

    $user = is_array($context['user'] ?? null) ? $context['user'] : [];
    $im = is_array($context['im'] ?? null) ? $context['im'] : [];
    $telephony = is_array($context['telephony'] ?? null) ? $context['telephony'] : [];
    $portalBase = trim((string)($context['portal_base'] ?? ''));
    $webhookUser = trim((string)($context['webhook_user'] ?? ''));
    $webhookCode = trim((string)($context['webhook_code'] ?? ''));

    $data = [
        'available' => true,
        'user_id' => $userId,
        'source_guess' => 'unknown',
        'note' => 'Best effort. Bitrix docs do not provide a guaranteed mobile/web online source flag.',
        'possible_sources' => [],
        'session_summary' => [
            'mobile' => 0,
            'web' => 0,
            'unknown' => 0,
        ],
        'recent_sessions' => [],
        'variants' => [
            'user_fields' => [],
            'im' => [],
            'telephony' => [],
            'rest' => [],
            'session_db' => [],
        ],
        'raw' => [],
    ];

    $data['variants']['user_fields'] = [
        'PERSONAL_MOBILE' => $user['PERSONAL_MOBILE'] ?? null,
        'UF_PHONE_INNER' => $user['UF_PHONE_INNER'] ?? null,
        'UF_VI_PHONE' => $user['RAW_SAFE']['UF_VI_PHONE'] ?? null,
        'UF_VI_BACKPHONE' => $user['RAW_SAFE']['UF_VI_BACKPHONE'] ?? null,
    ];

    $data['variants']['im'] = [
        'status' => $im['status'] ?? null,
        'status_text_ru' => $im['status_text_ru'] ?? null,
        'is_online' => $im['is_online'] ?? null,
        'last_activity_date' => $im['last_activity_date'] ?? null,
    ];

    $telephonyFlags = is_array($telephony['current_flags'] ?? null) ? $telephony['current_flags'] : [];
    $data['variants']['telephony'] = [
        'ONLINE' => $telephonyFlags['ONLINE'] ?? null,
        'AVAILABLE' => $telephonyFlags['AVAILABLE'] ?? null,
        'USER_HAVE_PHONE' => $telephonyFlags['USER_HAVE_PHONE'] ?? null,
        'USER_HAVE_MOBILE' => $telephonyFlags['USER_HAVE_MOBILE'] ?? null,
        'active_calls_count' => isset($telephony['active_calls']) && is_array($telephony['active_calls']) ? count($telephony['active_calls']) : null,
    ];

    if (($telephonyFlags['USER_HAVE_MOBILE'] ?? null) === true) {
        $data['possible_sources'][] = 'mobile_supported';
    }
    if (($telephonyFlags['USER_HAVE_PHONE'] ?? null) === true) {
        $data['possible_sources'][] = 'desktop_phone_supported';
    }

    if ($portalBase !== '' && $webhookUser !== '' && $webhookCode !== '') {
        $data['variants']['rest']['enabled'] = true;
        try {
            $data['variants']['rest']['im_user_get'] = callRest($portalBase, $webhookUser, $webhookCode, 'im.user.get', ['ID' => $userId]);
        } catch (Throwable $e) {
            $data['variants']['rest']['im_user_get_error'] = $e->getMessage();
        }
        try {
            $data['variants']['rest']['user_get'] = callRest($portalBase, $webhookUser, $webhookCode, 'user.get', ['FILTER' => ['ID' => $userId]]);
        } catch (Throwable $e) {
            $data['variants']['rest']['user_get_error'] = $e->getMessage();
        }
    } else {
        $data['variants']['rest'] = [
            'enabled' => false,
            'reason' => 'portal_base/webhook_user/webhook_code not provided',
        ];
    }

    if (!is_object($DB)) {
        $data['available'] = false;
        $data['variants']['session_db']['reason'] = 'DB connection is not available';
        return $data;
    }

    $sessionColumns = getTableColumns('b_user_session');
    $data['raw']['session_columns'] = $sessionColumns;
    if (empty($sessionColumns)) {
        $data['variants']['session_db']['reason'] = 'b_user_session table not found';
        return $data;
    }

    $candidateUserColumns = [];
    foreach (['USER_ID', 'USERID', 'ID_USER', 'SESSION_USER_ID'] as $column) {
        if (in_array($column, $sessionColumns, true)) {
            $candidateUserColumns[] = $column;
        }
    }

    $data['variants']['session_db']['candidate_user_columns'] = $candidateUserColumns;
    $rows = [];

    if (!empty($candidateUserColumns)) {
        $rows = loadSessionRowsByUserId($DB, 'b_user_session', $candidateUserColumns[0], $userId, $sessionColumns);
        $data['variants']['session_db']['mode'] = 'direct_column';
        $data['variants']['session_db']['used_column'] = $candidateUserColumns[0];
    } else {
        $rows = loadSessionRowsByDataSnippet($DB, 'b_user_session', $userId, $sessionColumns);
        $data['variants']['session_db']['mode'] = 'data_snippet_search';
        $data['variants']['session_db']['reason'] = 'No direct user id column found; searched recent session payloads.';
    }

    foreach ($rows as $row) {
        $source = classifyClientSource($row);
        $data['recent_sessions'][] = [
            'source' => $source,
            'SESSION_ID' => $row['SESSION_ID'] ?? null,
            'TIMESTAMP_X' => normalizeDateValue($row['TIMESTAMP_X'] ?? null),
            'DATE_ACTIVE' => normalizeDateValue($row['DATE_ACTIVE'] ?? null),
            'IP_ADDR' => $row['IP_ADDR'] ?? null,
            'USER_AGENT' => $row['USER_AGENT'] ?? null,
            'DATA_SNIPPET' => $row['DATA_SNIPPET'] ?? null,
        ];

        if (!isset($data['session_summary'][$source])) {
            $data['session_summary'][$source] = 0;
        }
        $data['session_summary'][$source]++;
    }

    if ($data['session_summary']['mobile'] > 0 && $data['session_summary']['web'] === 0) {
        $data['source_guess'] = 'mobile';
    } elseif ($data['session_summary']['web'] > 0 && $data['session_summary']['mobile'] === 0) {
        $data['source_guess'] = 'web';
    } elseif ($data['session_summary']['web'] > 0 && $data['session_summary']['mobile'] > 0) {
        $data['source_guess'] = 'mixed';
    }

    return $data;
}

function loadSessionRowsByUserId($db, string $tableName, string $userColumn, int $userId, array $sessionColumns): array
{
    $selectParts = ['us.' . $userColumn . ' AS SESSION_USER_ID'];
    foreach (['ID', 'SESSION_ID', 'TIMESTAMP_X', 'DATE_ACTIVE', 'DATE_INSERT', 'DATE_UPDATE', 'IP_ADDR', 'USER_AGENT'] as $column) {
        if (in_array($column, $sessionColumns, true)) {
            $selectParts[] = 'us.' . $column;
        }
    }

    if (in_array('DATA', $sessionColumns, true)) {
        $selectParts[] = 'SUBSTRING(us.DATA, 1, 500) AS DATA_SNIPPET';
    } elseif (in_array('SESSION_DATA', $sessionColumns, true)) {
        $selectParts[] = 'SUBSTRING(us.SESSION_DATA, 1, 500) AS DATA_SNIPPET';
    }

    $orderBy = buildSessionOrderBy($sessionColumns);
    $sql = "
        SELECT " . implode(",\n            ", $selectParts) . "
        FROM `" . $tableName . "` us
        WHERE us." . $userColumn . " = " . $userId . "
        ORDER BY " . $orderBy . "
        LIMIT 20
    ";

    $rows = [];
    $dbResult = $db->Query($sql);
    while ($row = $dbResult->Fetch()) {
        $rows[] = $row;
    }

    return $rows;
}

function loadSessionRowsByDataSnippet($db, string $tableName, int $userId, array $sessionColumns): array
{
    $selectParts = [];
    foreach (['ID', 'SESSION_ID', 'TIMESTAMP_X', 'DATE_ACTIVE', 'DATE_INSERT', 'DATE_UPDATE', 'IP_ADDR', 'USER_AGENT'] as $column) {
        if (in_array($column, $sessionColumns, true)) {
            $selectParts[] = 'us.' . $column;
        }
    }

    if (in_array('DATA', $sessionColumns, true)) {
        $selectParts[] = 'SUBSTRING(us.DATA, 1, 2000) AS DATA_SNIPPET';
    } elseif (in_array('SESSION_DATA', $sessionColumns, true)) {
        $selectParts[] = 'SUBSTRING(us.SESSION_DATA, 1, 2000) AS DATA_SNIPPET';
    } else {
        return [];
    }

    $orderBy = buildSessionOrderBy($sessionColumns);
    $sql = "
        SELECT " . implode(",\n            ", $selectParts) . "
        FROM `" . $tableName . "` us
        ORDER BY " . $orderBy . "
        LIMIT 200
    ";

    $rows = [];
    $dbResult = $db->Query($sql);
    while ($row = $dbResult->Fetch()) {
        if (matchesUserIdInSessionData((string)($row['DATA_SNIPPET'] ?? ''), $userId)) {
            $rows[] = $row;
        }
    }

    return array_slice($rows, 0, 20);
}

function buildSessionOrderBy(array $sessionColumns): string
{
    foreach (['DATE_ACTIVE', 'TIMESTAMP_X', 'DATE_UPDATE', 'DATE_INSERT', 'ID'] as $column) {
        if (in_array($column, $sessionColumns, true)) {
            return 'us.' . $column . ' DESC';
        }
    }

    return '1 DESC';
}

function matchesUserIdInSessionData(string $data, int $userId): bool
{
    if ($data === '' || $userId <= 0) {
        return false;
    }

    $quotedUserId = preg_quote((string)$userId, '/');
    $patterns = [
        '/USER_ID[^0-9]{0,20}' . $quotedUserId . '/i',
        '/BX_USER_ID[^0-9]{0,20}' . $quotedUserId . '/i',
        '/AUTH_USER_ID[^0-9]{0,20}' . $quotedUserId . '/i',
        '/s:[0-9]+:"' . $quotedUserId . '"/i',
        '/i:' . $quotedUserId . '[;}]?/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $data)) {
            return true;
        }
    }

    return false;
}

function callRest(string $portalBase, string $webhookUser, string $webhookCode, string $method, array $payload): array
{
    $url = $portalBase . '/rest/' . rawurlencode($webhookUser) . '/' . rawurlencode($webhookCode) . '/' . $method . '.json';

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $error);
    }

    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);

    return [
        'http_code' => $httpCode,
        'request' => [
            'method' => $method,
            'payload' => $payload,
            'url' => $url,
        ],
        'response' => $decoded !== null ? $decoded : $body,
    ];
}

function detectOnlineFlag(array $user): ?bool
{
    if (array_key_exists('IS_ONLINE', $user)) {
        return normalizeBoolish($user['IS_ONLINE']);
    }

    return null;
}

function normalizeBoolish($value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (in_array($value, [1, '1', 'Y', 'y', 'true', 'TRUE'], true)) {
        return true;
    }

    if (in_array($value, [0, '0', 'N', 'n', 'false', 'FALSE'], true)) {
        return false;
    }

    return null;
}

function normalizeDateValue($value): ?string
{
    if ($value instanceof \Bitrix\Main\Type\DateTime) {
        return $value->format('c');
    }

    if ($value instanceof \Bitrix\Main\Type\Date) {
        return $value->format('Y-m-d');
    }

    if ($value instanceof \DateTimeInterface) {
        return $value->format('c');
    }

    if (is_string($value) && $value !== '') {
        return $value;
    }

    return null;
}

function classifyClientSource(array $row): string
{
    $haystack = strtolower(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

    if ($haystack === '') {
        return 'unknown';
    }

    if (preg_match('/android|iphone|ipad|ios|bitrixmobile|bxmobile|mobileapp|\\bmobile\\b/', $haystack)) {
        return 'mobile';
    }

    if (preg_match('/mozilla|chrome|safari|firefox|edg|opera|macintosh|windows|linux|x11|desktop|electron/', $haystack)) {
        return 'web';
    }

    return 'unknown';
}

function mapTimemanStatus(?string $status): ?string
{
    $map = [
        'OPENED' => 'Начат рабочий день',
        'PAUSED' => 'Рабочий день на паузе',
        'CLOSED' => 'Рабочий день завершен',
        'EXPIRED' => 'Рабочий день истек и не закрыт',
    ];

    return $status !== null && isset($map[$status]) ? $map[$status] : null;
}

function mapImStatus(?string $status): ?string
{
    $map = [
        'online' => 'В сети',
        'dnd' => 'Не беспокоить',
        'away' => 'Отошел',
        'break' => 'Перерыв',
    ];

    return $status !== null && isset($map[$status]) ? $map[$status] : null;
}

function outputAndExit(array $payload, int $exitCode, bool $isCli): void
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;

    if (!$isCli && !headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode($payload, $jsonFlags) . PHP_EOL;
    exit($exitCode);
}

function renderHtmlReport(array $result): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $script = $result['script'] ?? [];
    $user = $result['user'] ?? [];
    $timeman = $result['timeman'] ?? [];
    $im = $result['im'] ?? [];
    $telephony = $result['telephony'] ?? [];
    $mobileWeb = $result['mobile_web'] ?? [];
    $errors = $result['errors'] ?? [];

    $rows = [
        'Время' => $script['time'] ?? null,
        'Режим' => $script['mode'] ?? null,
        'USER_ID' => $script['user_id'] ?? null,
        'Document root' => $script['document_root'] ?? null,
    ];

    $summaryRows = [
        'Пользователь' => ($user['NAME'] ?? '-') . ' (ID ' . ($user['ID'] ?? '-') . ')',
        'Online' => boolToRu($user['IS_ONLINE'] ?? null),
        'Рабочий день' => ($timeman['status_text_ru'] ?? '-') . (($timeman['status'] ?? null) ? ' [' . $timeman['status'] . ']' : ''),
        'IM статус' => ($im['status_text_ru'] ?? '-') . (($im['status'] ?? null) ? ' [' . $im['status'] . ']' : ''),
        'Телефония BUSY' => boolToRu($telephony['current_flags']['BUSY'] ?? null),
        'Телефония AVAILABLE' => boolToRu($telephony['current_flags']['AVAILABLE'] ?? null),
        'Источник mobile/web' => ($mobileWeb['source_guess'] ?? '-') !== '' ? (string)($mobileWeb['source_guess'] ?? '-') : '-',
        'Активных звонков (по БД)' => isset($telephony['active_calls']) && is_array($telephony['active_calls']) ? (string)count($telephony['active_calls']) : '0',
    ];

    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><title>Bitrix User Status Diagnostic</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f6f8fb;color:#1f2937;margin:0;padding:20px}h1,h2{margin:0 0 12px}';
    echo '.card{background:#fff;border:1px solid #dbe3ef;border-radius:10px;padding:14px 16px;margin:0 0 14px}';
    echo 'table{width:100%;border-collapse:collapse}td,th{border-bottom:1px solid #eef2f7;padding:8px 6px;text-align:left;vertical-align:top}';
    echo 'th{width:260px;color:#475569;font-weight:600}pre{background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow:auto}';
    echo '.ok{color:#15803d}.bad{color:#b91c1c}</style></head><body>';
    echo '<h1>Bitrix User Status Diagnostic</h1>';

    echo '<div class="card"><h2>Контекст запуска</h2>' . htmlTable($rows) . '</div>';
    echo '<div class="card"><h2>Сводка статусов</h2>' . htmlTable($summaryRows) . '</div>';
    echo '<div class="card"><h2>Модули</h2>' . htmlTable($result['modules'] ?? []) . '</div>';

    if (!empty($errors)) {
        echo '<div class="card"><h2 class="bad">Ошибки</h2><pre>' . h(json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></div>';
    }

    echo '<div class="card"><h2>Активные звонки</h2><pre>' . h(json_encode($telephony['active_calls'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></div>';
    echo '<div class="card"><h2>Mobile / Web</h2><pre>' . h(json_encode($mobileWeb, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></div>';
    echo '<div class="card"><h2>REST через internal IP</h2><pre>' . h(json_encode($result['rest_via_internal_ip'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></div>';
    echo '<div class="card"><h2>Полный JSON</h2><pre>' . h(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre></div>';

    echo '</body></html>';
}

function htmlTable(array $rows): string
{
    $html = '<table>';
    foreach ($rows as $key => $value) {
        $html .= '<tr><th>' . h((string)$key) . '</th><td>' . h(formatValue($value)) . '</td></tr>';
    }
    $html .= '</table>';

    return $html;
}

function formatValue($value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if ($value === null) {
        return '-';
    }
    if (is_scalar($value)) {
        return (string)$value;
    }

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-';
}

function boolToRu($value): string
{
    if ($value === true) {
        return 'Да';
    }
    if ($value === false) {
        return 'Нет';
    }

    return '-';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function getTableColumns(string $tableName): array
{
    global $DB;

    $tableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
    if ($tableName === '') {
        return [];
    }

    $columns = [];
    $rs = $DB->Query('SHOW COLUMNS FROM `' . $tableName . '`');
    while ($row = $rs->Fetch()) {
        if (!empty($row['Field'])) {
            $columns[] = (string)$row['Field'];
        }
    }

    return $columns;
}

function redactSensitiveFields(array $input): array
{
    $sensitivePatterns = [
        'PASSWORD',
        'CHECKWORD',
        'TOKEN',
        'SECRET',
        'UF_VI_PASSWORD',
        'UF_VI_PHONE_PASSWORD',
    ];

    $result = [];
    foreach ($input as $key => $value) {
        $keyString = (string)$key;
        $isSensitive = false;
        foreach ($sensitivePatterns as $pattern) {
            if (stripos($keyString, $pattern) !== false) {
                $isSensitive = true;
                break;
            }
        }

        if ($isSensitive) {
            $result[$key] = '***redacted***';
            continue;
        }

        if (is_array($value)) {
            $result[$key] = redactSensitiveFields($value);
            continue;
        }

        $result[$key] = $value;
    }

    return $result;
}

function enforceWebAccess(
    string $providedToken,
    string $webAccessToken,
    bool $allowAuthorizedPortalUser,
    bool $requireAdminForWebUser
): void {
    $tokenValid = ($webAccessToken !== '') && hash_equals($webAccessToken, $providedToken);
    if ($tokenValid) {
        return;
    }

    if ($allowAuthorizedPortalUser) {
        global $USER;
        $isAuthorized = is_object($USER) && method_exists($USER, 'IsAuthorized') && $USER->IsAuthorized();
        $isAdmin = is_object($USER) && method_exists($USER, 'IsAdmin') && $USER->IsAdmin();

        if ($isAuthorized && (!$requireAdminForWebUser || $isAdmin)) {
            return;
        }
    }

    http_response_code(403);
    echo json_encode(
        [
            'error' => 'forbidden',
            'error_description' => 'Access denied. Use valid token or open as authorized portal admin user.',
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ) . PHP_EOL;
    exit(EXIT_ERROR);
}
