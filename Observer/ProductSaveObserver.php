<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\AttributeChangeDetector;
use Ahy\SmartSearchLuma\Model\Plp\AffectedCategoryResolver;
use Ahy\SmartSearchLuma\Model\Plp\CacheInvalidator;
use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Cache\FrontendInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class ProductSaveObserver implements ObserverInterface
{
    private const RATE_LIMIT_PER_MINUTE = 120;
    private const CACHE_KEY = 'smartsearchluma_observer_rate_';

    public function __construct(
        private readonly Data                    $helper,
        private readonly AttributeChangeDetector $changeDetector,
        private readonly ProductSyncService      $syncService,
        private readonly FrontendInterface       $cache,
        private readonly LoggerInterface         $logger,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory       $collectionFactory,
        private readonly CacheInvalidator        $cacheInvalidator,
        private readonly AffectedCategoryResolver $affectedCategoryResolver,
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product || !$product->getId()) {
            return;
        }

        $storeId = (int) $product->getStoreId();

        if (!$this->helper->isEnabled($storeId) || !$this->helper->isRealtimeSyncEnabled($storeId)) {
            return;
        }

        if (!$this->isWithinRateLimit($storeId)) {
            $this->logger->warning(sprintf('[SmartSearchLuma][RT] Rate limit reached (%d/min) — product %d will sync via cron.', self::RATE_LIMIT_PER_MINUTE, $product->getId()));
            return;
        }

        $hasChange = $this->changeDetector->hasRelevantChange($product);

        if (!is_array($product->getData('stock_data')) && !$hasChange) {
            return;
        }

        $this->syncService->sync($product, $storeId);
        $syncedIds = array_merge(
            [(int) $product->getId()],
            $this->syncParentConfigurable((int) $product->getId(), $storeId)
        );

        // Purge the affected listing caches — but only now, after the sync above
        // has pushed the change to the platform. Purging any earlier would just
        // let the next render re-cache the old data for a full TTL.
        if ($this->helper->isPlpSsrEnabled($storeId)) {
            $this->cacheInvalidator->invalidate(
                $this->affectedCategoryResolver->tagsForProducts($syncedIds)
            );
        }
    }

    /**
     * @return int[] parent product ids that were synced
     */
    private function syncParentConfigurable(int $childId, int $storeId): array
    {
        $parentIds = [];
        try {
            $parents = $this->collectionFactory->create();
            $parents->addAttributeToSelect('*');
            $parents->joinField('child_id', 'catalog_product_relation', 'child_id', 'parent_id=entity_id', ['child_id' => $childId]);

            if ($parents->getSize() === 0) {
                return [];
            }

            foreach ($parents as $parent) {
                if ((int) $parent->getId() === $childId) {
                    continue;
                }
                $this->syncService->sync($parent, $storeId);
                $parentIds[] = (int) $parent->getId();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][RT] Could not sync parent for child ' . $childId . ': ' . $e->getMessage());
        }

        return $parentIds;
    }

    private function isWithinRateLimit(int $storeId): bool
    {
        $cacheKey = self::CACHE_KEY . $storeId . '_' . date('YmdHi');
        $current  = (int) ($this->cache->load($cacheKey) ?: 0);

        if ($current >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        $this->cache->save((string) ($current + 1), $cacheKey, [], 65 - (int) date('s'));
        return true;
    }
}
