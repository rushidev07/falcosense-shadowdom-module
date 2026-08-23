<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Plugin;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\OpenSearchService;
use Magento\Catalog\Block\Product\ListProduct;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Replaces the category PLP product collection with results ranked by OpenSearch.
 *
 * Falls back to Magento's native collection when:
 *  - The module is disabled
 *  - No current category is detected (non-category pages, search results)
 *  - The user has applied an explicit toolbar sort other than position
 *  - OpenSearch returns no results or is unreachable
 */
class CategoryListPlugin
{
    /**
     * Default sort value used by Magento category pages when no explicit sort is chosen.
     * OS ranking is applied only on this value so user-selected sorts still work.
     */
    private const DEFAULT_SORT = 'position';

    public function __construct(
        private readonly Data                  $helper,
        private readonly OpenSearchService     $openSearchService,
        private readonly CollectionFactory     $collectionFactory,
        private readonly CatalogConfig         $catalogConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
    ) {}

    public function aroundGetLoadedProductCollection(ListProduct $subject, callable $proceed)
    {
        if (!$this->helper->isFrontendEnabled()) {
            return $proceed();
        }

        $category = $subject->getLayer()->getCurrentCategory();
        if (!$category || !$category->getId() || !$category->getName()) {
            return $proceed();
        }

        // When the user applies a toolbar sort (price, name, etc.) defer to Magento.
        $sort = (string) $subject->getRequest()->getParam('product_list_order', '');
        if ($sort !== '' && $sort !== self::DEFAULT_SORT) {
            return $proceed();
        }

        $page    = max(1, (int) $subject->getRequest()->getParam('p', 1));
        $storeId = (int) $this->storeManager->getStore()->getId();

        $result = $this->openSearchService->getCategoryProducts(
            $category->getName(),
            $page,
            $storeId
        );

        if (empty($result['ids'])) {
            return $proceed();
        }

        try {
            return $this->buildCollection($result['ids'], $storeId);
        } catch (\Throwable $e) {
            $this->logger->error('SmartSearch PLP: collection build failed: ' . $e->getMessage());
            return $proceed();
        }
    }

    /**
     * Build a Magento product collection for the given IDs, preserving OpenSearch ranking order.
     */
    private function buildCollection(array $ids, int $storeId)
    {
        $collection = $this->collectionFactory->create();

        $collection->addAttributeToSelect($this->catalogConfig->getProductAttributes());
        $collection->addStoreFilter($storeId);
        $collection->addMinimalPrice();
        $collection->addFinalPrice();
        $collection->addTaxPercents();
        $collection->addUrlRewrite();
        $collection->addAttributeToFilter('entity_id', ['in' => $ids]);

        // Preserve OpenSearch popularity/relevance order using MySQL FIELD().
        // FIELD() returns the 1-based position of entity_id in the list,
        // so the first ID in the list sorts first.
        $idList = implode(',', $ids); // already validated as intval above
        $collection->getSelect()->order(new \Zend_Db_Expr("FIELD(e.entity_id, {$idList})"));

        return $collection;
    }
}
