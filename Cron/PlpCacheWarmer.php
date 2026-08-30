<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Cron;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the top categories' snapshots hot so the first visitor after a TTL
 * expiry gets a cache hit, not a live platform round-trip. Only the canonical
 * view (page 1, default sort, no filters) is warmed — that's what the cached,
 * crawlable page actually serves; deep views are fetched on demand.
 *
 * Off by default. When on, runs every few minutes (see crontab.xml).
 */
class PlpCacheWarmer
{
    public function __construct(
        private readonly Data $helper,
        private readonly PlpDataProviderInterface $provider,
        private readonly CategoryCollectionFactory $categoryCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$this->helper->isPlpWarmEnabled($storeId)) {
                continue;
            }

            $warmed = 0;
            foreach ($this->topCategories($storeId, $this->helper->getPlpWarmLimit($storeId)) as $category) {
                $query = new PlpQuery(
                    contextType: PlpQuery::CONTEXT_CATEGORY,
                    storeId: $storeId,
                    platformStoreId: $this->helper->getPlatformStoreId($storeId),
                    page: 1,
                    perPage: $this->helper->getProductsPerPage($storeId),
                    sort: 'position',
                    categoryId: (int) $category->getId(),
                    categoryName: (string) $category->getName(),
                );

                try {
                    $this->provider->fetch($query);
                    $warmed++;
                } catch (\Throwable $e) {
                    $this->logger->warning('[SmartSearchLuma][PLP] Warm failed for category ' . $category->getId() . ': ' . $e->getMessage());
                }
            }

            if ($warmed > 0) {
                $this->logger->info(sprintf('[SmartSearchLuma][PLP] Warmed %d category snapshot(s) for store %d.', $warmed, $storeId));
            }
        }
    }

    /**
     * @return iterable<\Magento\Catalog\Model\Category>
     */
    private function topCategories(int $storeId, int $limit): iterable
    {
        try {
            $collection = $this->categoryCollectionFactory->create();
            $collection->setStore($storeId);
            $collection->addAttributeToSelect(['name']);
            $collection->addAttributeToFilter('is_active', 1);
            $collection->addAttributeToFilter('include_in_menu', 1);
            $collection->addAttributeToFilter('level', ['gteq' => 2]);
            $collection->addAttributeToFilter('name', ['neq' => '']);
            $collection->setOrder('level', 'ASC');
            $collection->setOrder('position', 'ASC');
            $collection->setPageSize($limit);
            $collection->setCurPage(1);

            return $collection->getItems();
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Could not load categories to warm: ' . $e->getMessage());
            return [];
        }
    }
}
