<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cart;

final class CartAddResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message = '',
        public readonly ?int $quoteItemId = null,
    ) {
    }
}
