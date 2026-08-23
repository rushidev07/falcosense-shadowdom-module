<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Magento\Catalog\Model\Product;

class AttributeChangeDetector
{
    private const WATCHED = ['name', 'price', 'special_price', 'status', 'url_key'];

    public function hasRelevantChange(Product $product): bool
    {
        if (!$product->getOrigData()) {
            return true;
        }

        foreach (self::WATCHED as $attr) {
            if ((string) $product->getOrigData($attr) !== (string) $product->getData($attr)) {
                return true;
            }
        }

        return false;
    }
}
