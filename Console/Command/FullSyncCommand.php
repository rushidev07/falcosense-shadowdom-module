<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Console\Command;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Ahy\SmartSearchLuma\Service\SyncLockManager;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class FullSyncCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Data               $helper,
        private readonly ProductSyncService $syncService,
        private readonly CollectionFactory  $collectionFactory,
        private readonly SyncLockManager    $lockManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('smartsearchluma:sync:full')
             ->setDescription('Sync Magento products to the search platform via ingest API.')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Ignore cursor — send ALL products regardless of updated_at')
             ->addOption('store', null, InputOption::VALUE_OPTIONAL, 'Magento store ID', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = (int) $input->getOption('store');
        $force   = (bool) $input->getOption('force');

        if (!$this->helper->isEnabled()) {
            $output->writeln('<comment>[SmartSearchLuma] Sync is disabled.</comment>');
            return 1;
        }

        if (!$this->helper->getEndpointUrl($storeId)) {
            $output->writeln('<error>[SmartSearchLuma] Platform Endpoint URL is not configured. Aborting.</error>');
            $this->lockManager->writeResult(false, 'Platform Endpoint URL is not configured.');
            $this->lockManager->release();
            return 1;
        }

        if (!$this->helper->getApiKey($storeId)) {
            $output->writeln('<error>[SmartSearchLuma] API Key is not configured. Aborting.</error>');
            $this->lockManager->writeResult(false, 'API Key is not configured.');
            $this->lockManager->release();
            return 1;
        }

        // Take over the 'admin' pre-lock created by the controller, or acquire fresh if running via CLI directly
        if (!$this->lockManager->acquireOrTakeOver('command')) {
            $info = $this->lockManager->getLockInfo();
            $output->writeln(sprintf(
                '<error>[SmartSearchLuma] Sync already running (via %s, started %s). Aborting.</error>',
                $info['source']  ?? 'unknown',
                $info['started'] ?? 'unknown'
            ));
            return 1;
        }

        // Emergency cleanup — releases lock even if exit()/die() or a fatal error bypasses finally
        $lockManager = $this->lockManager;
        register_shutdown_function(static function () use ($lockManager): void {
            $lockManager->release();
        });

        $lastSyncAt    = $force ? null : $this->helper->getLastSyncAt();
        $syncStartedAt = date('Y-m-d H:i:s');
        $mode          = $force ? 'Force-full (all products)' : 'Smart (updated since ' . ($lastSyncAt ?? 'beginning') . ')';

        $output->writeln("<info>[SmartSearchLuma] Sync started — {$mode}</info>");
        $output->writeln('<comment>[SmartSearchLuma] Lock acquired. Delete ' . $this->lockManager->getLockPath() . ' to abort.</comment>');

        $start        = microtime(true);
        $page         = 1;
        $totalIndexed = 0;
        $totalFailed  = 0;
        $aborted      = false;

        try {
            do {
                if (!$this->lockManager->isLocked()) {
                    $output->writeln('<comment>[SmartSearchLuma] Lock file removed — sync aborted by external signal.</comment>');
                    $aborted = true;
                    break;
                }

                $collection = $this->buildCollection($lastSyncAt, $storeId, $page);
                $products   = array_values($collection->getItems());

                if (empty($products)) break;

                $count   = -3;
                $attempt = 0;
                while ($count === -3 && $attempt <= 3) {
                    if (!$this->lockManager->isLocked()) {
                        $output->writeln('<comment>[SmartSearchLuma] Lock file removed during retry — aborting.</comment>');
                        $aborted = true;
                        break 2;
                    }
                    if ($attempt > 0) {
                        $sleep = min(60, 10 * $attempt);
                        $output->writeln(sprintf('<comment>[SmartSearchLuma] Transient error on page %d, retrying in %ds (attempt %d/3)...</comment>', $page, $sleep, $attempt));
                        sleep($sleep);
                    }
                    $count = $this->syncService->syncBatch($products, $storeId);
                    $attempt++;
                }

                if ($count === -1) {
                    $msg = 'Connection failed or invalid API key — check endpoint URL and credentials.';
                    $output->writeln('<error>[SmartSearchLuma] ' . $msg . ' Aborting.</error>');
                    $this->lockManager->writeResult(false, $msg);
                    return 1;
                }

                if ($count === -2) {
                    $output->writeln('<comment>[SmartSearchLuma] Product limit reached on the platform. Remaining products skipped.</comment>');
                    break;
                }

                if ($count === -3) {
                    $output->writeln(sprintf('<error>[SmartSearchLuma] Page %d failed after 3 retries — skipping batch of %d products.</error>', $page, count($products)));
                    $totalFailed += count($products);
                    $page++;
                    continue;
                }

                $totalIndexed += $count;
                $output->writeln(sprintf('[SmartSearchLuma] Page %d → sent %d products (running total: %d)', $page, $count, $totalIndexed));
                $page++;
            } while (count($products) === self::BATCH_SIZE);

            $elapsed = round(microtime(true) - $start, 1);

            if ($aborted) {
                $this->lockManager->writeResult(false, "Sync aborted after {$elapsed}s — sent {$totalIndexed} products before abort.");
                $output->writeln(sprintf('<comment>[SmartSearchLuma] Sync aborted after %ss — sent: %d before abort.</comment>', $elapsed, $totalIndexed));
                return 2;
            }

            if (!$aborted && $totalFailed === 0) {
                $this->helper->setLastSyncAt($syncStartedAt);
            }

            if ($totalFailed > 0) {
                $this->lockManager->writeResult(false, "Completed in {$elapsed}s — sent: {$totalIndexed}, failed: {$totalFailed}.");
            } else {
                $this->lockManager->writeResult(true, "Synced {$totalIndexed} products in {$elapsed}s.");
            }

            $output->writeln(sprintf('<info>[SmartSearchLuma] Done in %ss — sent: %d, failed: %d</info>', $elapsed, $totalIndexed, $totalFailed));
            return $totalFailed === 0 ? 0 : 1;

        } finally {
            $this->lockManager->release();
            $output->writeln('<comment>[SmartSearchLuma] Lock released.</comment>');
        }
    }

    private function buildCollection(?string $lastSyncAt, int $storeId, int $page): \Magento\Catalog\Model\ResourceModel\Product\Collection
    {
        $collection = $this->collectionFactory->create();

        if ($storeId > 0) {
            $collection->setStoreId($storeId);
            $collection->addStoreFilter($storeId);
        }

        $collection->addAttributeToSelect('*');
        $collection->addAttributeToFilter('status', \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED);

        if ($lastSyncAt !== null) {
            $collection->addAttributeToFilter('updated_at', ['gt' => $lastSyncAt]);
        }

        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::BATCH_SIZE);
        $collection->setCurPage($page);

        return $collection;
    }
}
