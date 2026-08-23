<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;

class FullSyncConsumer
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(string $message): void
    {
        $data       = json_decode($message, true);
        $productIds = $data['product_ids'] ?? [];
        $storeId    = (int) ($data['store_id'] ?? 0);

        if (empty($productIds)) {
            $this->logger->warning('[SmartSearchLuma][FullSyncConsumer] Received empty product_ids — skipping.');
            return;
        }

        $this->logger->info(sprintf('[SmartSearchLuma][FullSyncConsumer] Processing batch of %d products (store %d).', count($productIds), $storeId));

        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('entity_id', ['in' => $productIds]);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        if ($storeId > 0) {
            $collection->setStoreId($storeId);
        }

        $products = array_values($collection->getItems());

        if (empty($products)) {
            $this->logger->info('[SmartSearchLuma][FullSyncConsumer] No enabled products found in batch — skipping.');
            return;
        }

        $synced = $this->syncService->syncBatch($products, $storeId);

        if ($synced === -1) {
            throw new \RuntimeException('[SmartSearchLuma][FullSyncConsumer] syncBatch failed (config/connection error) — will retry.');
        }

        $this->logger->info(sprintf('[SmartSearchLuma][FullSyncConsumer] Batch complete. synced=%d of %d loaded.', $synced, count($products)));
    }
}
