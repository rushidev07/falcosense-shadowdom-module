<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Api\Data;

interface WebhookMessageInterface
{
    public function getProductId(): int;
    public function getStoreId(): int;
}
