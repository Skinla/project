<?php
declare(strict_types=1);

/**
 * Local self-tests without Bitrix server.
 *
 * Run (from repo root):
 *   /mnt/c/xampp/php/php.exe local/akademia-profi/academyprofi-catalog-app/dev/self_test.php
 */

define('ACADEMYPROFI_TEST_MODE', true);

require_once __DIR__ . '/../src/autoload.php';

use AcademyProfi\CatalogApp\Http\ResponseException;

putenv('BITRIX24_WEBHOOK_BASE_URL=mock://');
putenv('BITRIX24_IBLOCK_ID=14');

function runRequest(string $path, array $query = []): array
{
    $_GET = $query;
    $_POST = [];
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'CONTENT_TYPE' => '',
        'PATH_INFO' => $path,
    ];

    try {
        require __DIR__ . '/../handler.php';
        throw new RuntimeException('Handler did not terminate');
    } catch (ResponseException $e) {
        return [
            'status' => $e->status,
            'data' => $e->data,
        ];
    }
}

$health = runRequest('/academyprofi-catalog/health');
assert($health['status'] === 200);
assert(($health['data']['ok'] ?? false) === true);

$product = runRequest('/academyprofi-catalog/product', ['id' => '18']);
if ($product['status'] !== 200) {
    var_export($product);
    echo PHP_EOL;
    exit(1);
}
assert(($product['data']['id'] ?? null) === 18);
assert(($product['data']['registry'] ?? null) === 'ФИС ФРДО');
assert(is_array($product['data']['requirements'] ?? null));
assert(($product['data']['priceRetail']['amount'] ?? null) === 5000);

$search = runRequest('/academyprofi-catalog/search', ['q' => 'инф']);
assert($search['status'] === 200);
assert(is_array($search['data']['items'] ?? null));

$products = runRequest('/academyprofi-catalog/products', ['start' => '0']);
assert($products['status'] === 200);
assert(is_array($products['data']['items'] ?? null));
assert(($products['data']['total'] ?? null) === 2004);

echo "OK: self_test passed\n";

