<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Plp;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpRenderer;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * GET /smsl/plp/grid — the fragment endpoint. Every filter/sort/page change the
 * widget makes comes here; it renders the SAME PlpRenderer markup the initial
 * page did, so there is never a second (JS) renderer to keep in sync. The widget
 * swaps the returned <div class="fs-plp"> in place and re-hydrates it.
 *
 * Params: context=category|search, category_id, q, p, sort, filter[code][]=val,
 *         price_min, price_max, base_url (for the <a> pagination fallback).
 *
 * Cacheable by a CDN/Varnish when the render is fresh — the response carries an
 * explicit Cache-Control. The data layer underneath is cached regardless.
 */
class Grid implements HttpGetActionInterface
{
    private const ALLOWED_SORTS = ['position', 'relevance', 'price_asc', 'price_desc', 'name'];

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RawFactory $rawFactory,
        private readonly PlpDataProviderInterface $provider,
        private readonly PlpRenderer $renderer,
        private readonly Data $helper,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute()
    {
        $raw = $this->rawFactory->create();
        $raw->setHeader('Content-Type', 'text/html; charset=UTF-8');

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return $raw->setHttpResponseCode(500)->setContents('');
        }

        if (!$this->helper->isPlpSsrEnabled($storeId)) {
            return $raw->setHttpResponseCode(404)->setContents('');
        }

        $query = $this->buildQuery($storeId);
        if ($query === null) {
            return $raw->setHttpResponseCode(400)->setContents('');
        }

        $result = $this->provider->fetch($query);

        if ($result->isUnavailable()) {
            // Tell the widget to keep whatever it's showing.
            $raw->setHeader('Cache-Control', 'no-store', true);
            return $raw->setContents('<div class="fs-plp" data-fs-source="unavailable"></div>');
        }

        $html = $this->renderer->render($result, $query, $this->baseUrl());

        if ($result->source === PlpResult::SOURCE_PLATFORM) {
            $ttl = max(30, $this->helper->getPlpCacheTtl($storeId));
            $raw->setHeader('Cache-Control', 'public, max-age=' . $ttl, true);
        } else {
            $raw->setHeader('Cache-Control', 'no-store', true);
        }

        return $raw->setContents($html);
    }

    private function buildQuery(int $storeId): ?PlpQuery
    {
        $context = (string) $this->request->getParam('context', PlpQuery::CONTEXT_CATEGORY);
        $page    = max(1, (int) $this->request->getParam('p', 1));
        $perPage = $this->helper->getProductsPerPage($storeId);
        $sort    = (string) $this->request->getParam('sort', 'position');
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'position';
        }

        $filters  = $this->parseFilters();
        $priceMin = $this->parseFloat('price_min');
        $priceMax = $this->parseFloat('price_max');
        $platformStoreId = $this->helper->getPlatformStoreId($storeId);

        if ($context === PlpQuery::CONTEXT_SEARCH) {
            $q = trim((string) $this->request->getParam('q', ''));
            if ($q === '') {
                return null;
            }

            return new PlpQuery(
                contextType: PlpQuery::CONTEXT_SEARCH,
                storeId: $storeId,
                platformStoreId: $platformStoreId,
                page: $page,
                perPage: $perPage,
                sort: $sort,
                filters: $filters,
                priceMin: $priceMin,
                priceMax: $priceMax,
                searchQuery: $q,
            );
        }

        $categoryId = (int) $this->request->getParam('category_id', 0);
        if ($categoryId <= 0) {
            return null;
        }

        try {
            $category = $this->categoryRepository->get($categoryId, $storeId);
            $categoryName = (string) $category->getName();
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Fragment: unknown category ' . $categoryId);
            return null;
        }

        if ($categoryName === '') {
            return null;
        }

        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: $storeId,
            platformStoreId: $platformStoreId,
            page: $page,
            perPage: $perPage,
            sort: $sort,
            filters: $filters,
            priceMin: $priceMin,
            priceMax: $priceMax,
            categoryId: $categoryId,
            categoryName: $categoryName,
        );
    }

    /**
     * @return array<string, string[]>
     */
    private function parseFilters(): array
    {
        $raw = $this->request->getParam('filter');
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $values) {
            $key = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key);
            if ($key === '') {
                continue;
            }
            $clean = [];
            foreach ((array) $values as $value) {
                $value = trim((string) $value);
                if ($value !== '' && mb_strlen($value) <= 128) {
                    $clean[] = $value;
                }
            }
            if ($clean !== []) {
                $out[$key] = array_slice(array_values(array_unique($clean)), 0, 20);
            }
        }

        return array_slice($out, 0, 15, true);
    }

    private function parseFloat(string $param): ?float
    {
        $value = $this->request->getParam($param);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        $f = (float) $value;

        return $f >= 0 ? $f : null;
    }

    private function baseUrl(): string
    {
        $base = trim((string) $this->request->getParam('base_url', ''));
        if ($base !== '' && preg_match('#^https?://#i', $base)) {
            return $base;
        }

        try {
            return $this->storeManager->getStore()->getBaseUrl();
        } catch (\Throwable $e) {
            return '/';
        }
    }
}
