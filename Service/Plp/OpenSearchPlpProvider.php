<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service\Plp;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\PlpFacet;
use Ahy\SmartSearchLuma\Model\Plp\PlpItem;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Ahy\SmartSearchLuma\Service\SearchTokenService;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The Adapter: turns a PlpQuery into a platform request and the platform's
 * response into a PlpResult. This is the ONLY file that knows the platform's
 * wire format — swap it (one <preference> in a client module) and everything
 * above PlpDataProviderInterface is untouched.
 *
 * ---------------------------------------------------------------------------
 * ASSUMED CONTRACT (D3 in FALCOSENSE-PLP-ISR-BUILD.md — confirm against the
 * real endpoint, then adjust only this class):
 *
 *   GET {endpoint_base}/api/v1/products      // same endpoint the storefront JS calls
 *     ?category={name}          | q={query}
 *     &store_id={platformStoreId}
 *     &page={n}&per_page={n}
 *     &sort={relevance|price_asc|price_desc|name_asc|newest}
 *     &search_token={token}            // primary auth (same as the browser); api_key sent too as fallback
 *     &api_key={key}
 *     [&filter[{code}][]={value} ...]
 *     [&price_min={n}&price_max={n}]
 *
 *   200 JSON:
 *   {
 *     "success": true,
 *     "results": [ {                 // "data" also accepted
 *        "product_id": 123, "sku": "ABC", "name": "...",
 *        "url": "https://store/abc.html",   // or "url_path":"abc.html" / "url_key":"abc"
 *        "image": "https://cdn/x.jpg",      // absolute, media-relative, or "no_selection"
 *        "price": 49.0, "special_price": 39.0|null,
 *        "in_stock": true, "brand": "...", "rating": 4.5, "rating_count": 12,
 *        "badges": ["sale"], "variants": [ ... ]   // variants passed through opaque
 *     } ],
 *     "facets": [ { "key":"color","label":"Color","type":"swatch",
 *                   "options":[ {"value":"Black","label":"Black","count":12} ] } ],
 *     "pagination": { "total": 240, "page": 1, "per_page": 24 }   // "total" top-level also accepted
 *   }
 * ---------------------------------------------------------------------------
 */
class OpenSearchPlpProvider implements PlpDataProviderInterface
{
    private const SORT_MAP = [
        'position'   => 'relevance',
        'relevance'  => 'relevance',
        ''           => 'relevance',
        'price_asc'  => 'price_asc',
        'price_desc' => 'price_desc',
        'name'       => 'name_asc',
        'name_asc'   => 'name_asc',
        'name_desc'  => 'name_desc',
        'newest'     => 'newest',
    ];

    private const FALLBACK_IMAGE = '/media/wysiwyg/no_selection.jpg';

    public function __construct(
        private readonly Data $helper,
        private readonly PlatformHttpClient $http,
        private readonly StoreManagerInterface $storeManager,
        private readonly SearchTokenService $tokenService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(PlpQuery $query): PlpResult
    {
        $productsUrl = $this->productsUrl($query->storeId);
        $apiKey      = $this->helper->getApiKey($query->storeId);

        if ($productsUrl === '' || $apiKey === '') {
            $this->logger->warning('[SmartSearchLuma][PLP] Platform endpoint URL or API key not configured — cannot render from platform.');
            return PlpResult::unavailable();
        }

        try {
            $response = $this->http->getJson(
                $productsUrl,
                $this->buildParams($query, $apiKey),
                ['X-Api-Key: ' . $apiKey],
                $this->helper->getPlpPlatformTimeoutMs($query->storeId)
            );
        } catch (PlatformRequestException $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Platform request failed: ' . $e->getMessage());
            return PlpResult::unavailable();
        }

        return $this->mapResponse($response, $query);
    }

    /**
     * Same endpoint the storefront JS uses: {endpoint_base}/api/v1/products.
     * Falls back to the standalone `search_url` config if `endpoint_url` isn't
     * set, for older installs.
     */
    private function productsUrl(int $storeId): string
    {
        $endpoint = $this->helper->getEndpointUrl($storeId);
        if ($endpoint !== '') {
            $parts = parse_url($endpoint);
            $base  = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
            if (!empty($parts['port'])) {
                $base .= ':' . $parts['port'];
            }
            if (($parts['host'] ?? '') !== '') {
                return $base . '/api/v1/products';
            }
        }

        return $this->helper->getSearchUrl($storeId);
    }

    /**
     * @return array<string, scalar|array|null>
     */
    private function buildParams(PlpQuery $query, string $apiKey): array
    {
        $params = [
            'store_id' => $query->platformStoreId,
            'page'     => $query->page,
            'per_page' => $query->perPage,
            'sort'     => self::SORT_MAP[$query->sort] ?? $query->sort,
            'api_key'  => $apiKey,
        ];

        // The storefront search endpoint authenticates with the short-lived
        // search_token (same credential the browser sends); api_key above is a
        // fallback. Token fetch is cached, so this is cheap after the first call.
        try {
            $token = $this->tokenService->getToken($query->storeId);
            if ($token !== '') {
                $params['search_token'] = $token;
            }
        } catch (\Throwable $e) {
            // proceed with api_key only
        }

        if ($query->isSearch()) {
            $params['q'] = $query->searchQuery;
        } else {
            $params['category'] = $query->categoryName;
            if ($query->categoryId !== null) {
                $params['category_id'] = $query->categoryId;
            }
        }

        if ($query->filters !== []) {
            $params['filter'] = $query->filters;
        }
        if ($query->priceMin !== null) {
            $params['price_min'] = $query->priceMin;
        }
        if ($query->priceMax !== null) {
            $params['price_max'] = $query->priceMax;
        }

        return $params;
    }

    private function mapResponse(array $response, PlpQuery $query): PlpResult
    {
        $rows = $response['results'] ?? $response['data'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }

        $mediaBase = $this->mediaBaseUrl($query->storeId);
        $storeBase = $this->storeBaseUrl($query->storeId);

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $item = $this->mapItem($row, $mediaBase, $storeBase);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $facets = [];
        foreach ((array) ($response['facets'] ?? []) as $facet) {
            if (is_array($facet)) {
                $facets[] = PlpFacet::fromArray($facet);
            }
        }

        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];
        $total = (int) ($pagination['total'] ?? $response['total'] ?? count($items));

        return new PlpResult(
            items: $items,
            facets: $facets,
            total: $total,
            page: (int) ($pagination['page'] ?? $query->page),
            perPage: (int) ($pagination['per_page'] ?? $query->perPage),
            source: PlpResult::SOURCE_PLATFORM,
            fetchedAt: time(),
            meta: [
                'context'  => $query->contextType,
                'category' => $query->categoryName,
                'query'    => $query->searchQuery,
            ],
        );
    }

    private function mapItem(array $row, string $mediaBase, string $storeBase): ?PlpItem
    {
        $productId = (int) ($row['product_id'] ?? $row['id'] ?? 0);
        if ($productId <= 0) {
            return null;
        }

        return new PlpItem(
            productId: $productId,
            sku: (string) ($row['sku'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            url: $this->resolveProductUrl($row, $storeBase),
            imageUrl: $this->resolveImageUrl((string) ($row['image'] ?? $row['image_url'] ?? ''), $mediaBase),
            price: isset($row['price']) && $row['price'] !== null ? (float) $row['price'] : null,
            specialPrice: isset($row['special_price']) && $row['special_price'] !== null && (float) $row['special_price'] > 0
                ? (float) $row['special_price']
                : null,
            inStock: (bool) ($row['in_stock'] ?? true),
            brand: isset($row['brand']) && $row['brand'] !== '' ? (string) $row['brand'] : null,
            rating: isset($row['rating']) && $row['rating'] !== null ? (float) $row['rating'] : null,
            ratingCount: isset($row['rating_count']) && $row['rating_count'] !== null ? (int) $row['rating_count'] : null,
            badges: array_values(array_map('strval', (array) ($row['badges'] ?? []))),
            swatches: is_array($row['variants'] ?? null) ? $row['variants'] : (is_array($row['swatches'] ?? null) ? $row['swatches'] : []),
        );
    }

    private function resolveProductUrl(array $row, string $storeBase): string
    {
        foreach (['url', 'product_url'] as $key) {
            $val = (string) ($row[$key] ?? '');
            if ($val !== '' && preg_match('#^https?://#i', $val)) {
                return $val;
            }
        }

        $path = (string) ($row['url_path'] ?? '');
        if ($path === '') {
            $urlKey = (string) ($row['url_key'] ?? '');
            $path = $urlKey !== '' ? $urlKey . '.html' : '';
        }

        if ($path === '') {
            return $storeBase;
        }

        return rtrim($storeBase, '/') . '/' . ltrim($path, '/');
    }

    private function resolveImageUrl(string $image, string $mediaBase): string
    {
        $image = trim($image);
        if ($image === '' || $image === 'no_selection') {
            return self::FALLBACK_IMAGE;
        }
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        return rtrim($mediaBase, '/') . '/' . ltrim($image, '/');
    }

    private function mediaBaseUrl(int $storeId): string
    {
        try {
            return rtrim(
                $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
                '/'
            ) . '/catalog/product';
        } catch (\Throwable $e) {
            return '/media/catalog/product';
        }
    }

    private function storeBaseUrl(int $storeId): string
    {
        try {
            return $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        } catch (\Throwable $e) {
            return '/';
        }
    }
}
