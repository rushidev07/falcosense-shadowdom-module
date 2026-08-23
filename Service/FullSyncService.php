<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\FullSyncPublisher;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;

class FullSyncService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly FullSyncPublisher $publisher,
        private readonly Data $helper,
        private readonly LoggerInterface $logger,
    ) {}

    public function queueAll(int $magentoStoreId = 0): array
    {
        $page         = 1;
        $totalQueued  = 0;
        $totalBatches = 0;

        do {
            $ids = $this->fetchProductIdPage($page, $magentoStoreId);

            if (empty($ids)) {
                break;
            }

            $this->publisher->publishBatch($ids, $magentoStoreId);

            $totalQueued  += count($ids);
            $totalBatches++;
            $page++;
        } while (count($ids) === self::BATCH_SIZE);

        $this->logger->info(sprintf('[SmartSearchLuma][FullSync] Done queuing. products=%d batches=%d', $totalQueued, $totalBatches));

        return ['products' => $totalQueued, 'batches' => $totalBatches];
    }

    private function fetchProductIdPage(int $page, int $storeId): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addIdOnly();
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        if ($storeId > 0) {
            $collection->addStoreFilter($storeId);
        }

        return array_map('intval', $collection->getAllIds());
    }
}
