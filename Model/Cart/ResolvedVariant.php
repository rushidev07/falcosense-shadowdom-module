<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cart;

/**
 * The real result of matching a shopper's selected options against Magento's actual
 * configurable-product data. requestProductId is always the parent/original ID the
 * resolver was asked about — that's what gets sent on to CartAdapterInterface, per
 * Magento's real add-to-cart contract (see CartAddRequest). sellableProductId (the
 * actual matched simple product, for a configurable) is exposed for stock/inspection
 * purposes only, never posted to the cart directly.
 */
final class ResolvedVariant
{
    /**
     * @param array<int,int> $superAttributes attribute_id => option_id. Empty for simple products.
     */
    public function __construct(
        public readonly int $requestProductId,
        public readonly int $sellableProductId,
        public readonly array $superAttributes,
        public readonly bool $isConfigurable,
        public readonly bool $inStock,
    ) {
    }
}
