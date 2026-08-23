<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Ahy\SmartSearchLuma\Api\Data\WebhookMessageInterface;

class WebhookMessage implements WebhookMessageInterface
{
    public function __construct(
        private int $productId = 0,
        private int $storeId   = 0,
    ) {}

    public function getProductId(): int { return $this->productId; }
    public function getStoreId(): int   { return $this->storeId; }
}
