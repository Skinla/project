<?php
declare(strict_types=1);

/**
 * Bitrix (Box) web script: dump iblock/list fields & properties as JSON.
 *
 * Place this file on server at:
 *   /local/handlers/list-122/dump.php
 *
 * Open in browser (must be logged in as admin):
 *   https://<your-domain>/local/handlers/list-122/dump.php
 *
 * Optional:
 *   ?iblock_id=122
 */

use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode(
        $body,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
if ($docRoot === '' || !is_dir($docRoot)) {
    respond(500, [
        'ok' => false,
        'error' => 'DOCUMENT_ROOT is not set or invalid.',
        'document_root' => $docRoot,
    ]);
}

/** @noinspection PhpIncludeInspection */
require_once $docRoot . '/bitrix/modules/main/include/prolog_before.php';

global $USER;
if (!($USER instanceof CUser) || !$USER->IsAuthorized() || !$USER->IsAdmin()) {
    respond(403, [
        'ok' => false,
        'error' => 'Forbidden. Login as admin.',
    ]);
}

if (!Loader::includeModule('iblock')) {
    respond(500, [
        'ok' => false,
        'error' => 'Failed to load iblock module.',
    ]);
}

$iblockId = 123;
if (isset($_GET['iblock_id'])) {
    $iblockId = (int)$_GET['iblock_id'];
}
if ($iblockId <= 0) {
    respond(422, [
        'ok' => false,
        'error' => 'Invalid iblock_id.',
    ]);
}

$iblock = IblockTable::getByPrimary($iblockId)->fetch();
if (!is_array($iblock)) {
    respond(404, [
        'ok' => false,
        'error' => 'IBlock not found.',
        'iblock_id' => $iblockId,
    ]);
}

// Standard element fields (not properties)
$fields = [];
if (class_exists('CIBlock') && method_exists('CIBlock', 'GetFields')) {
    /** @var array<string, mixed> $fields */
    $fields = (array)CIBlock::GetFields((int)$iblockId);
}

$properties = [];
$propRes = PropertyTable::getList([
    'filter' => [
        '=IBLOCK_ID' => $iblockId,
        '=ACTIVE' => 'Y',
    ],
    'order' => [
        'SORT' => 'ASC',
        'ID' => 'ASC',
    ],
    'select' => [
        'ID',
        'NAME',
        'CODE',
        'ACTIVE',
        'SORT',
        'PROPERTY_TYPE',
        'USER_TYPE',
        'MULTIPLE',
        'IS_REQUIRED',
        'LINK_IBLOCK_ID',
        'LIST_TYPE',
        'ROW_COUNT',
        'COL_COUNT',
        'DEFAULT_VALUE',
        'WITH_DESCRIPTION',
        'HINT',
        'FILTRABLE',
        'SEARCHABLE',
    ],
]);

while ($row = $propRes->fetch()) {
    $propertyId = (int)($row['ID'] ?? 0);

    $item = [
        'ID' => $propertyId,
        'NAME' => (string)($row['NAME'] ?? ''),
        'CODE' => (string)($row['CODE'] ?? ''),
        'SORT' => (int)($row['SORT'] ?? 0),
        'ACTIVE' => (string)($row['ACTIVE'] ?? ''),
        'PROPERTY_TYPE' => (string)($row['PROPERTY_TYPE'] ?? ''),
        'USER_TYPE' => $row['USER_TYPE'] !== null ? (string)$row['USER_TYPE'] : null,
        'MULTIPLE' => (string)($row['MULTIPLE'] ?? 'N'),
        'IS_REQUIRED' => (string)($row['IS_REQUIRED'] ?? 'N'),
        'WITH_DESCRIPTION' => (string)($row['WITH_DESCRIPTION'] ?? 'N'),
        'HINT' => $row['HINT'] !== null ? (string)$row['HINT'] : null,
        'LIST_TYPE' => $row['LIST_TYPE'] !== null ? (string)$row['LIST_TYPE'] : null,
        'LINK_IBLOCK_ID' => $row['LINK_IBLOCK_ID'] !== null ? (int)$row['LINK_IBLOCK_ID'] : null,
        'DEFAULT_VALUE' => $row['DEFAULT_VALUE'],
        'SETTINGS' => [
            'ROW_COUNT' => $row['ROW_COUNT'] !== null ? (int)$row['ROW_COUNT'] : null,
            'COL_COUNT' => $row['COL_COUNT'] !== null ? (int)$row['COL_COUNT'] : null,
            'FILTRABLE' => $row['FILTRABLE'] !== null ? (string)$row['FILTRABLE'] : null,
            'SEARCHABLE' => $row['SEARCHABLE'] !== null ? (string)$row['SEARCHABLE'] : null,
        ],
        'ENUM' => null,
    ];

    // For list properties dump enum items
    if ($item['PROPERTY_TYPE'] === 'L' && class_exists('CIBlockPropertyEnum')) {
        $enums = [];
        /** @noinspection PhpUndefinedMethodInspection */
        $enumRes = CIBlockPropertyEnum::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            ['PROPERTY_ID' => $propertyId]
        );
        while ($enum = $enumRes->Fetch()) {
            $enums[] = [
                'ID' => isset($enum['ID']) ? (int)$enum['ID'] : null,
                'VALUE' => isset($enum['VALUE']) ? (string)$enum['VALUE'] : null,
                'XML_ID' => isset($enum['XML_ID']) ? (string)$enum['XML_ID'] : null,
                'SORT' => isset($enum['SORT']) ? (int)$enum['SORT'] : null,
                'DEF' => isset($enum['DEF']) ? (string)$enum['DEF'] : null,
            ];
        }
        $item['ENUM'] = $enums;
    }

    $properties[] = $item;
}

respond(200, [
    'ok' => true,
    'iblock' => $iblock,
    'fields' => $fields,
    'properties' => $properties,
]);

