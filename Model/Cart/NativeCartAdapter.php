<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Cart;

use Ahy\SmartSearchLuma\Api\CartAdapterInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Default CartAdapterInterface implementation. Calls Magento\Checkout\Model\Cart —
 * the same cart model Magento's own native "Add to Cart" flow (checkout/cart/add)
 * uses internally. Because this is the real path, not a bypass, any plugin/observer
 * already hooked onto native add-to-cart (loyalty points, custom pricing, marketplace
 * seller assignment) fires exactly as it would for a normal storefront add-to-cart.
 */
class NativeCartAdapter implements CartAdapterInterface
{
    public function __construct(
        private readonly CheckoutCart $cart,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function addToCart(CartAddRequest $request): CartAddResult
    {
        try {
            $product = $this->productRepository->getById($request->productId);

            $requestParams = [
                'product' => $request->productId,
                'qty' => $request->qty,
            ];

            if (!empty($request->superAttributes)) {
                $requestParams['super_attribute'] = $request->superAttributes;
            }

            $quoteItem = $this->cart->addProduct($product, new DataObject($requestParams));

            if (is_string($quoteItem)) {
                // Magento\Checkout\Model\Cart::addProduct returns a string message on
                // certain non-exception failure paths (e.g. some custom-option
                // validation failures) rather than throwing.
                return new CartAddResult(success: false, message: $quoteItem);
            }

            $this->cart->save();

            return new CartAddResult(
                success: true,
                message: (string) __('Item added to cart.'),
                quoteItemId: $quoteItem && $quoteItem->getId() ? (int) $quoteItem->getId() : null,
            );
        } catch (LocalizedException $e) {
            $this->logger->warning('[SmartSearchLuma][Cart] Add failed: ' . $e->getMessage());
            return new CartAddResult(success: false, message: $e->getMessage());
        }
    }
}
