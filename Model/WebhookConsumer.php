<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Ahy\SmartSearchLuma\Service\ProductSyncService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

class WebhookConsumer
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductSyncService         $syncService,
        private readonly LoggerInterface            $logger,
    ) {}

    public function process(string $message): void
    {
        $data      = json_decode($message, true);
        $productId = (int) ($data['product_id'] ?? 0);
        $storeId   = (int) ($data['store_id'] ?? 0);

        $this->logger->info(sprintf('[SmartSearchLuma][Consumer] Processing product %d (store %d).', $productId, $storeId));

        try {
            $product = $this->productRepository->getById($productId, false, $storeId ?: null, true);
            $success = $this->syncService->sync($product, $storeId);

            if (!$success) {
                throw new \RuntimeException('[SmartSearchLuma][Consumer] Sync failed for product ' . $productId . ' — will retry.');
            }

            $this->logger->info('[SmartSearchLuma][Consumer] Product ' . $productId . ' synced successfully.');
        } catch (\RuntimeException $e) {
            $this->logger->warning($e->getMessage());
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][Consumer] Unexpected error for product ' . $productId . ': ' . $e->getMessage());
            throw $e;
        }
    }
}
