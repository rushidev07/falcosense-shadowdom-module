<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Api;

use Ahy\SmartSearchLuma\Model\Cart\ResolvedVariant;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Resolves a shopper's selected options (e.g. ['Color' => 'Black', 'Size' => 'M'])
 * against a product's real Magento configurable-attribute data — never a SKU-string
 * guess. The default implementation (Model\Cart\NativeVariantResolver) uses Magento's
 * actual configurable-product API.
 *
 * $productId is, pragmatically, the Magento entity ID (see the implementation plan's
 * §1 — true platform-ID/entity-ID opacity is a separate, future sync-pipeline project).
 */
interface VariantResolverInterface
{
    /**
     * @param int $productId Magento entity ID of the (possibly configurable) product.
     * @param array<string,string> $selectedOptions Attribute label => selected value label.
     * @throws NoSuchEntityException If the product doesn't exist, or the selected
     *                                options don't resolve to a real, purchasable variant.
     */
    public function resolve(int $productId, array $selectedOptions): ResolvedVariant;
}
