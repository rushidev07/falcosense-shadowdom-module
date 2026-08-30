<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Dedicated cache type for cached platform PLP responses (the ISR data layer)
 * and the last-known-good fallback blobs. Its own type so an operator can flush
 * just these from Stores > Cache Management without nuking FPC or config cache,
 * and so entries can carry Magento's own catalog tags (cat_p_*, cat_c_*) —
 * meaning a normal product save already evicts every cached listing that product
 * appears in, no custom purge code needed for the data layer.
 */
class Plp extends TagScope
{
    public const TYPE_IDENTIFIER = 'ahy_smartsearch_plp';
    public const CACHE_TAG = 'AHY_SMARTSEARCH_PLP';

    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
