<?php
declare(strict_types=1);

use AcademyProfi\CatalogApp\Api\ApiController;
use AcademyProfi\CatalogApp\Bitrix\BitrixWebhookClient;
use AcademyProfi\CatalogApp\Cache\FileCache;
use AcademyProfi\CatalogApp\Env;
use AcademyProfi\CatalogApp\Http\JsonResponse;
use AcademyProfi\CatalogApp\Logging\Logger;
use AcademyProfi\CatalogApp\ProjectConfig;
use AcademyProfi\CatalogApp\Security\Cors;
use AcademyProfi\CatalogApp\Settings;
use AcademyProfi\CatalogApp\Storage\Paths;

require_once __DIR__ . '/src/autoload.php';

$envPath = getenv('BITRIX_ACCESS_ENV_PATH') ?: '/etc/academyprofi/bitrix.access.env';
$env = Env::loadFile($envPath);

$settings = Settings::load($env);
$projectConfig = ProjectConfig::load()->raw;

$logger = new Logger(Paths::logsDir($env) . '/app.log');

$allowedDomains = $settings->raw['security']['cors']['allowedDomains'] ?? [];
if (!is_array($allowedDomains)) {
    $allowedDomains = [];
}
(new Cors($allowedDomains))->handle();

$webhookBaseUrl = Env::get($env, 'BITRIX24_WEBHOOK_BASE_URL');
if (!is_string($webhookBaseUrl) || trim($webhookBaseUrl) === '') {
    // Dev-friendly fallback: if fixtures exist, use mock mode.
    $fixtureProbe = __DIR__ . '/fixtures/catalog.product.get_18.json';
    if (is_file($fixtureProbe)) {
        $webhookBaseUrl = 'mock://';
    } else {
        JsonResponse::serverError('Server is not configured (missing BITRIX24_WEBHOOK_BASE_URL)');
    }
}

$iblockId = (int) (Env::get($env, 'BITRIX24_IBLOCK_ID') ?? ($projectConfig['bitrix24']['iblockId'] ?? 14));
if ($iblockId <= 0) {
    $iblockId = 14;
}

$cache = new FileCache(Paths::cacheDir($env), $logger);
$b24 = new BitrixWebhookClient($webhookBaseUrl, $logger);

$controller = new ApiController($b24, $cache, $logger, $iblockId, array_merge($settings->raw, $projectConfig));

// Routing
$route = (string)($_GET['route'] ?? '');
$path = $route !== '' ? $route : (string)($_SERVER['PATH_INFO'] ?? '');

if ($path === '') {
    // Try to extract PATH_INFO from REQUEST_URI after handler.php
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $pos = strpos($uri, 'handler.php');
    if ($pos !== false) {
        $after = substr($uri, $pos + strlen('handler.php'));
        $after = explode('?', $after, 2)[0];
        $path = $after;
    }
}

$path = '/' . ltrim($path, '/');

$basePath = (string)($settings->raw['api']['basePath'] ?? '/academyprofi-catalog');
if ($basePath !== '' && str_starts_with($path, $basePath)) {
    $path = substr($path, strlen($basePath));
    $path = '/' . ltrim($path, '/');
}

switch ($path) {
    case '/':
    case '/health':
        JsonResponse::ok([
            'ok' => true,
            'app' => $settings->raw['meta']['name'] ?? 'academyprofi-catalog-module',
            'version' => $settings->raw['meta']['version'] ?? null,
            'bitrix' => [
                'mode' => str_starts_with((string)$webhookBaseUrl, 'mock://') ? 'mock' : 'webhook',
            ],
        ]);

    case '/product':
        $controller->product();

    case '/search':
        $controller->search();

    case '/products':
        $controller->products();

    default:
        JsonResponse::send(404, ['error' => 'Not found', 'path' => $path]);
}

