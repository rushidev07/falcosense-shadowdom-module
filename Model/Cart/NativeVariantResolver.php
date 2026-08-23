<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cart;

use Ahy\SmartSearchLuma\Api\VariantResolverInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Default VariantResolverInterface implementation. Ported from the already-correct
 * logic in Controller\Product\VariantImages.php (real configurable-attribute data,
 * Configurable::getUsedProducts) — not a new mapping layer, not the SKU-string
 * guessing the legacy front-end JS relies on.
 */
class NativeVariantResolver implements VariantResolverInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Configurable $configurableType,
        private readonly StockRegistryInterface $stockRegistry,
    ) {
    }

    public function resolve(int $productId, array $selectedOptions): ResolvedVariant
    {
        $product = $this->productRepository->getById($productId);

        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            $stockItem = $this->stockRegistry->getStockItem($product->getId());

            return new ResolvedVariant(
                requestProductId: $productId,
                sellableProductId: (int) $product->getId(),
                superAttributes: [],
                isConfigurable: false,
                inStock: (bool) $stockItem->getIsInStock(),
            );
        }

        $superAttributes = $this->matchSuperAttributes($product, $selectedOptions);

        $childProduct = $this->configurableType->getProductByAttributes($superAttributes, $product);
        if (!$childProduct || !$childProduct->getId()) {
            throw new NoSuchEntityException(
                __('The selected combination of options is not available for this product.')
            );
        }

        $stockItem = $this->stockRegistry->getStockItem((int) $childProduct->getId());

        return new ResolvedVariant(
            requestProductId: $productId,
            sellableProductId: (int) $childProduct->getId(),
            superAttributes: $superAttributes,
            isConfigurable: true,
            inStock: (bool) $stockItem->getIsInStock(),
        );
    }

    /**
     * Matches shopper-selected option labels (e.g. ['Color' => 'Black']) against this
     * product's real configurable attributes, returning attribute_id => option_id.
     * Throws if any selected attribute can't be matched, or if the product has a
     * configurable attribute the shopper didn't provide a selection for at all —
     * either way, that's not a real, fully-specified purchasable combination yet.
     *
     * @param array<string,string> $selectedOptions
     * @return array<int,int>
     */
    private function matchSuperAttributes(\Magento\Catalog\Model\Product $product, array $selectedOptions): array
    {
        $configurableAttributes = $this->configurableType->getConfigurableAttributesAsArray($product);
        $superAttributes = [];

        foreach ($configurableAttributes as $attributeData) {
            $label = (string) ($attributeData['label'] ?? '');
            $attributeId = (int) ($attributeData['attribute_id'] ?? $attributeData['id'] ?? 0);

            if ($label === '' || $attributeId === 0) {
                continue;
            }

            if (!array_key_exists($label, $selectedOptions)) {
                throw new NoSuchEntityException(
                    __('No option was selected for "%1".', $label)
                );
            }

            $selectedValue = (string) $selectedOptions[$label];
            $matchedOptionId = null;

            foreach ($attributeData['values'] ?? [] as $valueData) {
                $valueLabel = (string) ($valueData['label'] ?? $valueData['store_label'] ?? '');
                if (strcasecmp($valueLabel, $selectedValue) === 0) {
                    $matchedOptionId = (int) $valueData['value_index'];
                    break;
                }
            }

            if ($matchedOptionId === null) {
                throw new NoSuchEntityException(
                    __('"%1" is not a valid option for "%2".', $selectedValue, $label)
                );
            }

            $superAttributes[$attributeId] = $matchedOptionId;
        }

        return $superAttributes;
    }
}
