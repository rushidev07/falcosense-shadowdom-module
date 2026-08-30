<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model;

use Magento\Catalog\Model\Product;

class AttributeChangeDetector
{
    private const WATCHED = [
        'name', 'price', 'special_price', 'status', 'url_key',
        // image fields matter to the server-rendered PLP card, so a swap of just
        // the image still needs to reach the platform in real time rather than
        // waiting for the once-a-minute delta cron.
        'image', 'small_image', 'thumbnail',
    ];

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
