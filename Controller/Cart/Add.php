<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Controller\Cart;

use Ahy\SmartSearchLuma\Api\CartAdapterInterface;
use Ahy\SmartSearchLuma\Api\VariantResolverInterface;
use Ahy\SmartSearchLuma\Model\Cart\CartAddRequest;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * POST /smsl/cart/add — the single endpoint the widget calls for every add-to-cart,
 * across all four surfaces (search overlay, category page, search results page, and
 * sliders — see the implementation plan). The widget only ever sends an opaque
 * product id, selected option labels, and a qty; every Magento-specific detail
 * (super_attribute resolution, the actual cart write) happens behind
 * VariantResolverInterface/CartAdapterInterface.
 *
 * This is the module's first POST-handling, anonymous-accessible controller, so its
 * CSRF handling is explicit rather than inherited: it validates the same form_key
 * cookie every Magento page already sets, the same mechanism native "Add to Cart"
 * already relies on — no new trust model introduced.
 */
class Add implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly VariantResolverInterface $variantResolver,
        private readonly CartAdapterInterface $cartAdapter,
        private readonly FormKey $formKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        $productId = (int) $this->request->getParam('platform_product_id');
        $qty = (float) ($this->request->getParam('qty') ?: 1);

        $rawOptions = $this->request->getParam('selected_options');
        if (is_array($rawOptions)) {
            $selectedOptions = $rawOptions;
        } else {
            $decoded = json_decode((string) $rawOptions, true);
            $selectedOptions = is_array($decoded) ? $decoded : [];
        }

        if ($productId <= 0) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => 'Missing or invalid product id.',
            ]);
        }

        try {
            $resolved = $this->variantResolver->resolve($productId, $selectedOptions);

            if (!$resolved->inStock) {
                return $result->setHttpResponseCode(409)->setData([
                    'success' => false,
                    'message' => (string) __('This item is currently out of stock.'),
                ]);
            }

            $cartRequest = new CartAddRequest(
                productId: $resolved->requestProductId,
                qty: $qty,
                superAttributes: $resolved->superAttributes,
            );

            $addResult = $this->cartAdapter->addToCart($cartRequest);

            if (!$addResult->success) {
                return $result->setHttpResponseCode(422)->setData([
                    'success' => false,
                    'message' => $addResult->message,
                ]);
            }

            return $result->setData([
                'success' => true,
                'message' => $addResult->message,
            ]);
        } catch (LocalizedException $e) {
            $this->logger->warning('[SmartSearchLuma][Cart] Add rejected: ' . $e->getMessage());
            return $result->setHttpResponseCode(422)->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][Cart] Unexpected error: ' . $e->getMessage());
            return $result->setHttpResponseCode(500)->setData([
                'success' => false,
                'message' => (string) __('Unable to add this item to your cart right now.'),
            ]);
        }
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $submitted = (string) $request->getParam('form_key');
        if ($submitted === '') {
            return false;
        }

        return hash_equals((string) $this->formKey->getFormKey(), $submitted);
    }
}
