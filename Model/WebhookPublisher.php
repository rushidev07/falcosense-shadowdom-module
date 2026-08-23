<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;

class WebhookPublisher
{
    private const TOPIC = 'ahy.smartsearchluma.product.sync';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly LoggerInterface   $logger,
    ) {}

    public function publish(int $productId, int $storeId): void
    {
        $message = json_encode(['product_id' => $productId, 'store_id' => $storeId]);
        $this->publisher->publish(self::TOPIC, $message);
        $this->logger->info(sprintf('[SmartSearchLuma] Queued webhook for product %d (store %d).', $productId, $storeId));
    }
}
