<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Api;

use AcademyProfi\CatalogApp\Bitrix\BitrixException;
use AcademyProfi\CatalogApp\Bitrix\BitrixWebhookClient;
use AcademyProfi\CatalogApp\Cache\FileCache;
use AcademyProfi\CatalogApp\Http\JsonResponse;
use AcademyProfi\CatalogApp\Http\ResponseException;
use AcademyProfi\CatalogApp\Logging\Logger;

final class ApiController
{
    private const DEFAULT_CATALOG_PRICE_GROUP_ID = 2;

    public function __construct(
        private readonly BitrixWebhookClient $b24,
        private readonly FileCache $cache,
        private readonly Logger $logger,
        private readonly int $iblockId,
        private readonly array $settings,
    ) {
    }

    public function product(): never
    {
        $idRaw = $_GET['id'] ?? null;
        if (!is_string($idRaw) && !is_numeric($idRaw)) {
            JsonResponse::badRequest('Invalid id');
        }

        $id = (int) $idRaw;
        if ($id <= 0) {
            JsonResponse::badRequest('Invalid id');
        }

        $ttl = (int)($this->settings['api']['cacheTtlSeconds'] ?? 600);
        $cacheKey = 'product:' . $id;

        try {
            $res = $this->cache->remember($cacheKey, $ttl, function () use ($id) {
                $resp = $this->b24->call('catalog.product.get', ['id' => $id]);
                $product = $resp['result']['product'] ?? null;
                if (!is_array($product)) {
                    throw new BitrixException('Unexpected product response shape', null, null, ['id' => $id]);
                }

                $priceGroupId = (int)($this->settings['bitrix24']['catalogPriceGroupId'] ?? self::DEFAULT_CATALOG_PRICE_GROUP_ID);
                $prices = $this->fetchCatalogPricesByProductIds([$id], $priceGroupId);
                $catalogPrice = $prices[$id] ?? null;

                $ui = [
                    'ctaLabel' => (string)($this->settings['ui']['cta']['label'] ?? 'Оставить заявку'),
                    'ctaAnchor' => (string)($this->settings['ui']['cta']['anchor'] ?? '#request'),
                ];

                return ProductMapper::toProductDto($product, $ui, $catalogPrice);
            });

            $this->logger->info('api.product', ['id' => $id, 'cache' => $res['hit'] ? 'hit' : 'miss']);
            JsonResponse::ok($res['value']);
        } catch (BitrixException $e) {
            $this->logger->error('api.product failed', [
                'id' => $id,
                'error' => $e->bitrixError,
                'error_description' => $e->bitrixErrorDescription,
                'message' => $e->getMessage(),
            ]);
            JsonResponse::badGateway('Bitrix24 API unavailable');
        } catch (ResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('api.product internal error', ['id' => $id, 'message' => $e->getMessage()]);
            if (defined('ACADEMYPROFI_TEST_MODE') && ACADEMYPROFI_TEST_MODE === true) {
                JsonResponse::send(500, ['error' => $e->getMessage()]);
            }
            JsonResponse::serverError('Internal error');
        }
    }

    public function search(): never
    {
        $q = (string)($_GET['q'] ?? '');
        $q = trim($q);
        $min = (int)($this->settings['bitrix24']['search']['minQueryLength'] ?? 3);
        $limit = (int)($this->settings['bitrix24']['search']['limit'] ?? 8);
        $limit = max(1, min($limit, 50));
        $prefetchPrefixLen = (int)($this->settings['bitrix24']['search']['prefetchPrefixLength'] ?? 5);
        $prefetchPrefixLen = max(3, min($prefetchPrefixLen, 10));
        // How many candidates to scan across paginated API calls
        $prefetchMax = (int)($this->settings['bitrix24']['search']['prefetchMax'] ?? 200);
        $prefetchMax = max(50, min($prefetchMax, 500));

        if (mb_strlen($q) < $min) {
            JsonResponse::badRequest('Query too short');
        }

        $iblockId = $this->readOptionalPositiveInt('iblockId');
        $sectionId = $this->readOptionalPositiveInt('sectionId');
        $effectiveIblockId = $iblockId ?? $this->iblockId;

        $ttl = 120;
        $cacheKey = 'search:v2:' . mb_strtolower($q) . ':limit=' . $limit
            . ':prefetchPrefixLen=' . $prefetchPrefixLen
            . ':prefetchMax=' . $prefetchMax
            . ':iblockId=' . $effectiveIblockId
            . ':sectionId=' . ($sectionId ?? 0);

        try {
            $res = $this->cache->remember($cacheKey, $ttl, function () use ($q, $limit, $effectiveIblockId, $sectionId, $prefetchPrefixLen, $prefetchMax) {
                $qLower = mb_strtolower($q);

                $fetchPage = function (string $query, int $start) use ($effectiveIblockId, $sectionId): array {
                    $payload = [
                        'select' => [
                            'id',
                            'iblockId',
                            'name',
                            'property112',
                        ],
                        'filter' => [
                            'iblockId' => $effectiveIblockId,
                            '%name' => $query,
                        ],
                        'order' => ['id' => 'desc'],
                        'start' => $start,
                    ];
                    if ($sectionId !== null) {
                        $payload['filter']['iblockSectionId'] = $sectionId;
                    }

                    $resp = $this->b24->call('catalog.product.list', $payload);
                    $products = $resp['result']['products'] ?? null;
                    $next = $resp['next'] ?? null;
                    return [
                        'products' => is_array($products) ? $products : [],
                        'next' => is_numeric($next) ? (int)$next : null,
                    ];
                };

                $pickedProducts = [];
                $seenIds = [];

                $consume = function (array $products, int &$scanned) use (&$pickedProducts, &$seenIds, $limit, $qLower, $prefetchMax): void {
                    foreach ($products as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $scanned += 1;
                        if ($scanned > $prefetchMax) {
                            break;
                        }

                        $name = (string)($p['name'] ?? '');
                        if ($name === '' || mb_stripos($name, $qLower) === false) {
                            continue;
                        }

                        $id = (int)($p['id'] ?? 0);
                        if ($id > 0 && isset($seenIds[$id])) {
                            continue;
                        }
                        if ($id > 0) {
                            $seenIds[$id] = true;
                        }

                        $pickedProducts[] = $p;
                        if (count($pickedProducts) >= $limit) {
                            break;
                        }
                    }
                };

                $scanQuery = function (string $query) use (&$pickedProducts, &$seenIds, $limit, $prefetchMax, $fetchPage, $consume): void {
                    $scanned = 0;
                    $start = 0;
                    while (count($pickedProducts) < $limit && $scanned < $prefetchMax) {
                        $page = $fetchPage($query, $start);
                        $products = $page['products'] ?? [];
                        if (!is_array($products) || count($products) === 0) {
                            break;
                        }
                        $consume($products, $scanned);
                        if (count($pickedProducts) >= $limit || $scanned >= $prefetchMax) {
                            break;
                        }
                        $next = $page['next'] ?? null;
                        if (!is_int($next) || $next <= $start) {
                            break;
                        }
                        $start = $next;
                    }
                };

                // 1) Try native search first (paginated).
                $scanQuery($q);

                // 2) If Bitrix doesn't match inside word, broaden query and post-filter in PHP.
                if (count($pickedProducts) < $limit) {
                    $prefix = mb_substr($q, 0, $prefetchPrefixLen);
                    if ($prefix !== '' && $prefix !== $q) {
                        $scanQuery($prefix);
                    }
                }

                $priceGroupId = (int)($this->settings['bitrix24']['catalogPriceGroupId'] ?? self::DEFAULT_CATALOG_PRICE_GROUP_ID);
                $ids = array_values(array_filter(array_map(static fn($p) => is_array($p) ? (int)($p['id'] ?? 0) : 0, $pickedProducts), static fn($id) => $id > 0));
                $prices = $this->fetchCatalogPricesByProductIds($ids, $priceGroupId);

                $items = [];
                foreach ($pickedProducts as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $id = (int)($p['id'] ?? 0);
                    $items[] = ProductMapper::toSearchItem($p, $prices[$id] ?? null);
                }

                return ['items' => $items];
            });

            $this->logger->info('api.search', [
                'q' => $q,
                'iblockId' => $effectiveIblockId,
                'sectionId' => $sectionId,
                'cache' => $res['hit'] ? 'hit' : 'miss',
            ]);
            JsonResponse::ok(array_merge($res['value'], [
                'filters' => [
                    'iblockId' => $effectiveIblockId,
                    'sectionId' => $sectionId,
                ],
            ]));
        } catch (BitrixException $e) {
            $this->logger->error('api.search failed', ['q' => $q, 'message' => $e->getMessage()]);
            JsonResponse::badGateway('Bitrix24 API unavailable');
        } catch (ResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('api.search internal error', ['q' => $q, 'message' => $e->getMessage()]);
            if (defined('ACADEMYPROFI_TEST_MODE') && ACADEMYPROFI_TEST_MODE === true) {
                JsonResponse::send(500, ['error' => $e->getMessage()]);
            }
            JsonResponse::serverError('Internal error');
        }
    }

    public function products(): never
    {
        $startRaw = $_GET['start'] ?? '0';
        $start = is_numeric($startRaw) ? (int)$startRaw : 0;
        $start = max(0, $start);

        $pageSize = (int)($this->settings['api']['pageSize'] ?? 50);
        $pageSize = max(1, min($pageSize, 50));

        $iblockId = $this->readOptionalPositiveInt('iblockId');
        $sectionId = $this->readOptionalPositiveInt('sectionId');
        $effectiveIblockId = $iblockId ?? $this->iblockId;

        $ttl = 120;
        $cacheKey = 'products:start=' . $start . ':pageSize=' . $pageSize . ':iblockId=' . $effectiveIblockId . ':sectionId=' . ($sectionId ?? 0);

        try {
            $res = $this->cache->remember($cacheKey, $ttl, function () use ($start, $effectiveIblockId, $sectionId) {
                $payload = [
                    'select' => [
                        'id',
                        'iblockId',
                        'name',
                        'iblockSectionId',
                        'property112',
                        'property116',
                    ],
                    'filter' => [
                        'iblockId' => $effectiveIblockId,
                    ],
                    'order' => ['id' => 'desc'],
                    'start' => $start,
                ];
                if ($sectionId !== null) {
                    $payload['filter']['iblockSectionId'] = $sectionId;
                }

                $resp = $this->b24->call('catalog.product.list', $payload);
                $products = $resp['result']['products'] ?? null;
                $next = $resp['next'] ?? null;
                $total = $resp['total'] ?? null;

                $priceGroupId = (int)($this->settings['bitrix24']['catalogPriceGroupId'] ?? self::DEFAULT_CATALOG_PRICE_GROUP_ID);
                $ids = [];
                if (is_array($products)) {
                    foreach ($products as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $pid = (int)($p['id'] ?? 0);
                        if ($pid > 0) {
                            $ids[] = $pid;
                        }
                    }
                }
                $prices = $this->fetchCatalogPricesByProductIds($ids, $priceGroupId);

                $items = [];
                if (is_array($products)) {
                    foreach ($products as $p) {
                        if (!is_array($p)) {
                            continue;
                        }
                        $pid = (int)($p['id'] ?? 0);
                        $items[] = ProductMapper::toCardItem($p, $prices[$pid] ?? null);
                    }
                }

                return [
                    'items' => $items,
                    'next' => is_numeric($next) ? (int)$next : null,
                    'total' => is_numeric($total) ? (int)$total : null,
                ];
            });

            $this->logger->info('api.products', [
                'start' => $start,
                'iblockId' => $effectiveIblockId,
                'sectionId' => $sectionId,
                'cache' => $res['hit'] ? 'hit' : 'miss',
            ]);
            JsonResponse::ok(array_merge($res['value'], [
                'filters' => [
                    'iblockId' => $effectiveIblockId,
                    'sectionId' => $sectionId,
                ],
            ]));
        } catch (BitrixException $e) {
            $this->logger->error('api.products failed', ['start' => $start, 'message' => $e->getMessage()]);
            JsonResponse::badGateway('Bitrix24 API unavailable');
        } catch (ResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('api.products internal error', ['start' => $start, 'message' => $e->getMessage()]);
            if (defined('ACADEMYPROFI_TEST_MODE') && ACADEMYPROFI_TEST_MODE === true) {
                JsonResponse::send(500, ['error' => $e->getMessage()]);
            }
            JsonResponse::serverError('Internal error');
        }
    }

    private function readOptionalPositiveInt(string $key): ?int
    {
        if (!array_key_exists($key, $_GET)) {
            return null;
        }

        $raw = $_GET[$key];
        if (!is_string($raw) && !is_numeric($raw)) {
            JsonResponse::badRequest('Invalid ' . $key);
        }

        if (is_string($raw)) {
            $raw = trim($raw);
        }

        $n = (int)$raw;
        if ($n <= 0) {
            JsonResponse::badRequest('Invalid ' . $key);
        }

        return $n;
    }

    /**
     * Returns map: productId => {amount:int,currency:string}
     *
     * @param int[] $productIds
     * @return array<int, array{amount:int,currency:string}>
     */
    private function fetchCatalogPricesByProductIds(array $productIds, int $catalogPriceGroupId): array
    {
        $productIds = array_values(array_unique(array_values(array_filter($productIds, static fn($id) => is_int($id) ? $id > 0 : (int)$id > 0))));
        if (count($productIds) === 0) {
            return [];
        }

        // Bitrix supports array filter for productId. We do one call per list/search page.
        $resp = $this->b24->call('catalog.price.list', [
            'select' => ['id', 'productId', 'catalogGroupId', 'price', 'currency'],
            'filter' => ['productId' => $productIds],
        ]);

        $prices = $resp['result']['prices'] ?? null;
        if (!is_array($prices)) {
            return [];
        }

        $out = [];
        foreach ($prices as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int)($row['productId'] ?? 0);
            $groupId = (int)($row['catalogGroupId'] ?? 0);
            if ($productId <= 0 || $groupId !== $catalogPriceGroupId) {
                continue;
            }
            $currency = (string)($row['currency'] ?? '');
            $priceRaw = $row['price'] ?? null;
            if ($currency === '' || (!is_int($priceRaw) && !is_float($priceRaw) && !is_string($priceRaw))) {
                continue;
            }
            $amount = (int)round((float)$priceRaw);
            if ($amount <= 0) {
                continue;
            }
            $out[$productId] = ['amount' => $amount, 'currency' => $currency];
        }

        return $out;
    }
}

