<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Ahy\SmartSearchLuma\Helper\Data;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Answers one question per request: "is this a FalcoSense-owned product listing,
 * and if so, exactly which view of it?" — as a PlpQuery.
 *
 * Only the *canonical* view (page 1, no filters, default sort) is built here,
 * because that is the only view rendered into the initial HTML response. Every
 * other view (a filter, a sort, page 2) is a fragment fetch the widget makes
 * against /smsl/plp/grid, which builds its own PlpQuery from its own params.
 *
 * Page type is read from the dispatched action name, never guessed from the URL
 * string — same approach as Block\Widget\Bootstrap::resolvePageType().
 */
class PageContext
{
    private const ACTION_CATEGORY = 'catalog_category_view';
    private const ACTIONS_SEARCH  = ['catalogsearch_result_index', 'fs_search_index'];

    private bool $resolved = false;
    private ?PlpQuery $query = null;
    private ?Category $category = null;

    public function __construct(
        private readonly HttpRequest $request,
        private readonly LayerResolver $layerResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Data $helper,
    ) {
    }

    public function buildQuery(): ?PlpQuery
    {
        $this->resolve();
        return $this->query;
    }

    public function getCurrentCategory(): ?Category
    {
        $this->resolve();
        return $this->category;
    }

    /**
     * Best-effort native product count for the current category, used only by
     * the C1 coverage guard. Approximate for anchor categories (directly-assigned
     * products only) — the guard treats 0 as "no usable number, skip the check".
     */
    public function getNativeProductCount(): int
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return 0;
        }

        try {
            $count = (int) $category->getProductCount();
            if ($count > 0) {
                return $count;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return 0;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return;
        }

        if (!$this->helper->isPlpSsrEnabled($storeId)) {
            return;
        }

        $action = (string) $this->request->getFullActionName();

        if ($action === self::ACTION_CATEGORY) {
            $this->query = $this->buildCategoryQuery($storeId);
        } elseif (in_array($action, self::ACTIONS_SEARCH, true)) {
            $this->query = $this->buildSearchQuery($storeId);
        }
    }

    private function buildCategoryQuery(int $storeId): ?PlpQuery
    {
        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$category || !$category->getId() || (string) $category->getName() === '') {
            return null;
        }

        $this->category = $category;

        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: $storeId,
            platformStoreId: $this->helper->getPlatformStoreId($storeId),
            page: $this->currentPage(),
            perPage: $this->helper->getProductsPerPage($storeId),
            sort: $this->currentSort(),
            categoryId: (int) $category->getId(),
            categoryName: (string) $category->getName(),
        );
    }

    private function buildSearchQuery(int $storeId): ?PlpQuery
    {
        $q = trim((string) $this->request->getParam('q', ''));
        if ($q === '') {
            return null;
        }

        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_SEARCH,
            storeId: $storeId,
            platformStoreId: $this->helper->getPlatformStoreId($storeId),
            page: $this->currentPage(),
            perPage: $this->helper->getProductsPerPage($storeId),
            sort: $this->currentSort(),
            searchQuery: $q,
        );
    }

    private function currentPage(): int
    {
        return max(1, (int) $this->request->getParam('p', 1));
    }

    private function currentSort(): string
    {
        $sort = (string) $this->request->getParam('product_list_order', '');
        return $sort !== '' ? $sort : 'position';
    }
}
