<?php
declare(strict_types=1);

use AcademyProfi\CatalogApp\Bitrix\BitrixException;
use AcademyProfi\CatalogApp\Bitrix\BitrixOAuthClient;
use AcademyProfi\CatalogApp\Blocks\BlockRegistry;
use AcademyProfi\CatalogApp\Blocks\BlockRuntimeContext;
use AcademyProfi\CatalogApp\Env;
use AcademyProfi\CatalogApp\Http\JsonResponse;
use AcademyProfi\CatalogApp\Installations\InstallationRepository;
use AcademyProfi\CatalogApp\Logging\Logger;
use AcademyProfi\CatalogApp\ProjectConfig;
use AcademyProfi\CatalogApp\Settings;
use AcademyProfi\CatalogApp\Storage\Paths;

require_once __DIR__ . '/src/autoload.php';

$envPath = getenv('BITRIX_ACCESS_ENV_PATH') ?: '/etc/academyprofi/bitrix.access.env';
$env = Env::loadFile($envPath);
$settings = Settings::load($env);
$projectConfig = ProjectConfig::load()->raw;

$logger = new Logger(Paths::logsDir($env) . '/install.log');
$repo = new InstallationRepository(Paths::installationsDir($env));

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "academyprofi-catalog-app install.php\n";
    echo "Expected POST with ONAPPINSTALL payload.\n";
    echo "Loaded settings from: " . ($settings->loadedFromPath ?: '(default)') . "\n";
    exit;
}

// Parse incoming payload (form or JSON)
$contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
$rawBody = file_get_contents('php://input') ?: '';

$payload = [];
if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} else {
    $payload = $_POST;
}

// Bitrix event payload shape: {event, data, ts, auth, ...}
$event = (string)($payload['event'] ?? '');
if ($event !== 'ONAPPINSTALL') {
    $logger->error('install: unexpected event', ['event' => $event, 'payloadKeys' => array_keys($payload)]);
    JsonResponse::badRequest('Expected ONAPPINSTALL');
}

$auth = $payload['auth'] ?? null;
if (!is_array($auth)) {
    $logger->error('install: missing auth', ['payloadKeys' => array_keys($payload)]);
    JsonResponse::badRequest('Missing auth');
}

$memberId = (string)($auth['member_id'] ?? '');
$domain = (string)($auth['domain'] ?? '');
$clientEndpoint = (string)($auth['client_endpoint'] ?? '');
$serverEndpoint = (string)($auth['server_endpoint'] ?? '');
$applicationToken = (string)($auth['application_token'] ?? '');

$accessToken = (string)($auth['access_token'] ?? ($payload['access_token'] ?? ''));
$refreshToken = (string)($auth['refresh_token'] ?? ($payload['refresh_token'] ?? ''));

if ($memberId === '' || $clientEndpoint === '' || $accessToken === '') {
    $logger->error('install: missing required auth fields', [
        'memberId' => $memberId,
        'clientEndpoint' => $clientEndpoint,
        'hasAccessToken' => $accessToken !== '',
    ]);
    JsonResponse::badRequest('Missing required auth fields');
}

$version = null;
$data = $payload['data'] ?? null;
if (is_array($data) && isset($data['VERSION'])) {
    $version = (string)$data['VERSION'];
}

$existing = $repo->get($memberId);
$prevVersion = is_array($existing) ? (string)($existing['app_version'] ?? '') : '';
// DEV choice: always update existing blocks when re-installing (otherwise old block instances keep old CONTENT).
// If you later need to preserve user-edits in pages, change it to "reset on version upgrade" only.
$shouldReset = true;

$repo->save($memberId, [
    'created_at' => is_array($existing) ? ($existing['created_at'] ?? gmdate('c')) : gmdate('c'),
    'app_version' => $version,
    'domain' => $domain,
    'client_endpoint' => $clientEndpoint,
    'server_endpoint' => $serverEndpoint,
    'application_token' => $applicationToken,
    'access_token' => $accessToken,
    'refresh_token' => $refreshToken,
]);

$logger->info('install: saved installation', [
    'memberId' => $memberId,
    'domain' => $domain,
    'version' => $version,
    'prevVersion' => $prevVersion,
    'reset' => $shouldReset,
]);

// Build runtime context for blocks registration
$boxBaseUrl = Env::get($env, 'BITRIX_BOX_BASE_URL') ?? ($projectConfig['bitrixBoxLocalApp']['baseDomain'] ?? '');
$handlerPath = Env::get($env, 'BITRIX_BOX_HANDLER_PATH') ?? ($projectConfig['bitrixBoxLocalApp']['paths']['handler'] ?? '');
$assetsBaseUrl = (string)($settings->raw['blocks']['assetsBaseUrl'] ?? '');

if ($assetsBaseUrl === '') {
    $assetsBaseUrl = rtrim((string)$boxBaseUrl, '/') . rtrim((string)$handlerPath, '/');
    // handlerPath ends with handler.php — assets live next to it
    $assetsBaseUrl = preg_replace('#/handler\.php$#', '', $assetsBaseUrl) ?: $assetsBaseUrl;
    $assetsBaseUrl = rtrim($assetsBaseUrl, '/') . '/assets';
}

$apiBasePath = (string)($settings->raw['api']['basePath'] ?? '/academyprofi-catalog');
$apiBaseUrl = rtrim((string)$boxBaseUrl, '/') . (string)$handlerPath . rtrim($apiBasePath, '/');

$productParam = (string)($settings->raw['routing']['productId']['param'] ?? 'product');
$sections = (string)($settings->raw['blocks']['sections'] ?? 'services,academyprofi');
$defaultProductId = (int)($settings->raw['ui']['detail']['productId'] ?? 18);
$detailPagePath = (string)($settings->raw['ui']['detail']['pagePath'] ?? '');
$ctaAnchor = (string)($settings->raw['ui']['cta']['anchor'] ?? '#request');
$ctaLabel = (string)($settings->raw['ui']['cta']['label'] ?? 'Оставить заявку');

$assetsVersion = (string)max(
    @filemtime(__DIR__ . '/assets/academyprofi-blocks.css') ?: 0,
    @filemtime(__DIR__ . '/assets/academyprofi-blocks.js') ?: 0,
    @filemtime(__DIR__ . '/assets/preview-search.png') ?: 0,
    @filemtime(__DIR__ . '/assets/preview-catalog.png') ?: 0,
    @filemtime(__DIR__ . '/assets/preview-detail.png') ?: 0,
    time()
);

$ctx = new BlockRuntimeContext($sections, $assetsBaseUrl, $assetsVersion, $apiBaseUrl, $productParam, $defaultProductId, $detailPagePath, $ctaAnchor, $ctaLabel);

// Register blocks in landing repo using OAuth token
try {
    $oauth = new BitrixOAuthClient($clientEndpoint, $accessToken, $logger);
    $registry = new BlockRegistry();
    $results = $registry->registerAll($oauth, $ctx, $logger, $shouldReset);

    JsonResponse::ok([
        'ok' => true,
        'memberId' => $memberId,
        'version' => $version,
        'reset' => $shouldReset,
        'registered' => $results,
        'assetsBaseUrl' => $assetsBaseUrl,
        'apiBaseUrl' => $apiBaseUrl,
    ]);
} catch (BitrixException $e) {
    $logger->error('install: block registration failed', [
        'memberId' => $memberId,
        'error' => $e->bitrixError,
        'error_description' => $e->bitrixErrorDescription,
        'message' => $e->getMessage(),
    ]);
    JsonResponse::badGateway('Bitrix24 API error during blocks registration');
} catch (Throwable $e) {
    $logger->error('install: internal error', ['memberId' => $memberId, 'message' => $e->getMessage()]);
    JsonResponse::serverError('Internal error');
}

