<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cart;

/**
 * What a CartAdapterInterface implementation actually needs to add something to a
 * real Magento cart. productId is always the *parent* configurable product's entity
 * ID when superAttributes is non-empty — Magento's native configurable add-to-cart
 * contract resolves the correct child internally from (parent ID + super_attribute),
 * exactly like a theme's own "Add to Cart" button already does. Never the resolved
 * child's own ID posted directly — that bypass is the exact bug this replaces.
 */
final class CartAddRequest
{
    /**
     * @param array<int,int> $superAttributes attribute_id => option_id. Empty for simple products.
     */
    public function __construct(
        public readonly int $productId,
        public readonly float $qty,
        public readonly array $superAttributes = [],
    ) {
    }
}
