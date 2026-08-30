<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Cron;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Plp\AffectedCategoryResolver;
use Ahy\SmartSearchLuma\Model\Plp\CacheInvalidator;
use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Ahy\SmartSearchLuma\Service\SyncLockManager;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Psr\Log\LoggerInterface;

class ProductSync
{
    private const BATCH_SIZE = 500;

    /** Beyond this many changed products, purge the whole listing cache instead of per-category. */
    private const BULK_INVALIDATION_THRESHOLD = 2000;

    public function __construct(
        private readonly Data               $helper,
        private readonly ProductSyncService $syncService,
        private readonly CollectionFactory  $collectionFactory,
        private readonly LoggerInterface    $logger,
        private readonly SyncLockManager    $lockManager,
        private readonly CacheInvalidator   $cacheInvalidator,
        private readonly AffectedCategoryResolver $affectedCategoryResolver,
    ) {}

    public function execute(): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        if (!$this->lockManager->acquireOrTakeOver('cron')) {
            $info = $this->lockManager->getLockInfo();
            $this->logger->warning(sprintf(
                '[SmartSearchLuma][Cron] Sync already running (via %s, started %s) — skipping.',
                $info['source']  ?? 'unknown',
                $info['started'] ?? 'unknown'
            ));
            return;
        }

        $lockManager = $this->lockManager;
        register_shutdown_function(static function () use ($lockManager): void {
            $lockManager->release();
        });

        $syncStartedAt = date('Y-m-d H:i:s');
        $forceFullSync = $this->helper->isFullSyncRequested();
        $lastSyncAt    = $forceFullSync ? null : $this->helper->getLastSyncAt();

        $this->logger->info(sprintf('[SmartSearchLuma][Cron] Sync started. mode=%s cursor=%s', $forceFullSync ? 'full' : 'delta', $lastSyncAt ?? 'none'));

        $page        = 1;
        $totalSynced = 0;
        $aborted     = false;
        $changedIds  = [];

        try {
            do {
                if (!$this->lockManager->isLocked()) {
                    $this->logger->warning('[SmartSearchLuma][Cron] Lock file removed — sync aborted by external signal.');
                    $this->lockManager->writeResult(false, 'Aborted by external signal.');
                    $aborted = true;
                    break;
                }

                $collection = $this->buildCollection($lastSyncAt, $page);
                $products   = array_values($collection->getItems());

                if (empty($products)) break;

                $count = $this->syncService->syncBatch($products);

                if ($count === -1) {
                    $msg = 'Connection failed or invalid API key — cursor not advanced.';
                    $this->logger->error('[SmartSearchLuma][Cron] ' . $msg);
                    $this->lockManager->writeResult(false, $msg);
                    return;
                }

                if (count($changedIds) < self::BULK_INVALIDATION_THRESHOLD) {
                    foreach ($products as $p) {
                        $changedIds[] = (int) $p->getId();
                    }
                }

                $totalSynced += max(0, $count);
                $page++;
            } while (count($products) === self::BATCH_SIZE);

            if (!$aborted) {
                $this->helper->setLastSyncAt($syncStartedAt);

                if ($forceFullSync) {
                    $this->helper->clearFullSyncFlag();
                }

                $this->invalidateListingCaches($forceFullSync, $changedIds);

                $this->lockManager->writeResult(true, "Synced {$totalSynced} products.");
                $this->logger->info('[SmartSearchLuma][Cron] Done. synced=' . $totalSynced . ' cursor=' . $syncStartedAt);
            }

        } finally {
            $this->lockManager->release();
        }
    }

    /**
     * One purge at the end of the run — never per batch — so a large delta or a
     * full sync coalesces into a single invalidation instead of a PURGE storm.
     *
     * @param int[] $changedIds
     */
    private function invalidateListingCaches(bool $forceFullSync, array $changedIds): void
    {
        if (!$this->helper->isPlpSsrEnabled()) {
            return;
        }

        if ($forceFullSync || count($changedIds) >= self::BULK_INVALIDATION_THRESHOLD) {
            $this->cacheInvalidator->invalidateAll();
            return;
        }

        if ($changedIds !== []) {
            $this->cacheInvalidator->invalidate(
                $this->affectedCategoryResolver->tagsForProducts($changedIds)
            );
        }
    }

    private function buildCollection(?string $lastSyncAt, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addAttributeToSelect(['name', 'sku', 'price', 'special_price', 'status', 'visibility', 'url_key', 'description', 'short_description', 'image', 'color', 'material', 'size', 'manufacturer', 'product_brand']);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }
}
