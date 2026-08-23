<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class StockChangeObserver implements ObserverInterface
{
    public function __construct(
        private readonly Data                      $helper,
        private readonly ProductSyncService        $syncService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CollectionFactory         $collectionFactory,
        private readonly LoggerInterface           $logger,
    ) {}

    public function execute(Observer $observer): void
    {
        if (!$this->helper->isEnabled() || !$this->helper->isRealtimeSyncEnabled()) {
            return;
        }

        /** @var \Magento\CatalogInventory\Model\Stock\Item $stockItem */
        $stockItem = $observer->getEvent()->getItem();

        if (!$stockItem || !$stockItem->getProductId()) {
            return;
        }

        $origQty     = (float) $stockItem->getOrigData('qty');
        $currQty     = (float) $stockItem->getQty();
        $origInStock = (int) $stockItem->getOrigData('is_in_stock');
        $currInStock = (int) $stockItem->getIsInStock();

        if ($origQty === $currQty && $origInStock === $currInStock) {
            return;
        }

        $productId = (int) $stockItem->getProductId();

        try {
            $product = $this->productRepository->getById($productId, false, null, true);
            $this->syncService->sync($product, 0, $stockItem);
            $this->syncParentConfigurable($productId);
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma] StockChangeObserver failed for product ' . $productId . ': ' . $e->getMessage());
        }
    }

    private function syncParentConfigurable(int $childId): void
    {
        try {
            $parents = $this->collectionFactory->create();
            $parents->addAttributeToSelect('*');
            $parents->joinField('child_id', 'catalog_product_relation', 'child_id', 'parent_id=entity_id', ['child_id' => $childId]);

            foreach ($parents as $parent) {
                if ((int) $parent->getId() === $childId) {
                    continue;
                }
                $this->syncService->sync($parent, 0);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][RT] Could not sync parent for child ' . $childId . ': ' . $e->getMessage());
        }
    }
}
