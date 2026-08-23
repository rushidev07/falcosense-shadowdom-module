<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Api;

use Ahy\SmartSearchLuma\Model\Cart\CartAddRequest;
use Ahy\SmartSearchLuma\Model\Cart\CartAddResult;

/**
 * The one Port the widget's add-to-cart request is resolved through. The default
 * implementation (Model\Cart\NativeCartAdapter) drives Magento's own real cart/quote
 * flow — the same path a theme's native "Add to Cart" button triggers — so any
 * plugin/observer already hooked onto native add-to-cart keeps firing unmodified.
 *
 * A client with a non-standard cart (marketplace, custom checkout) swaps this via
 * one <preference> line in their own module's di.xml — no core code touched.
 */
interface CartAdapterInterface
{
    public function addToCart(CartAddRequest $request): CartAddResult;
}
