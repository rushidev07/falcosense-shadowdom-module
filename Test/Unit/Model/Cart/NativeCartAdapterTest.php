<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Cart;

use Ahy\SmartSearchLuma\Model\Cart\CartAddRequest;
use Ahy\SmartSearchLuma\Model\Cart\NativeCartAdapter;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Cart as CheckoutCart;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Item;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * See the note in NativeVariantResolverTest — written and traced against Magento's
 * real Checkout\Model\Cart API, not executed in this environment (no PHP runtime
 * available here). The required-custom-option enforcement case is deliberately not
 * duplicated here as a mock-based test: since this adapter calls Magento's *real*
 * Cart::addProduct(), that validation is Magento's own and is covered by Phase 3's
 * end-to-end verification against a real product with a required custom option, not
 * by mocking Cart itself into pretending to enforce something it, in this test,
 * wouldn't actually be running.
 */
class NativeCartAdapterTest extends TestCase
{
    private CheckoutCart&MockObject $cart;
    private ProductRepositoryInterface&MockObject $productRepository;
    private LoggerInterface&MockObject $logger;
    private NativeCartAdapter $adapter;

    protected function setUp(): void
    {
        $this->cart = $this->createMock(CheckoutCart::class);
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->adapter = new NativeCartAdapter($this->cart, $this->productRepository, $this->logger);
    }

    public function testSimpleProductAddSucceeds(): void
    {
        $product = $this->createMock(Product::class);
        $this->productRepository->method('getById')->with(555)->willReturn($product);

        $quoteItem = $this->createMock(Item::class);
        $quoteItem->method('getId')->willReturn(9001);

        $this->cart->expects(self::once())
            ->method('addProduct')
            ->with($product, self::callback(function (DataObject $params) {
                return $params->getData('product') === 555
                    && $params->getData('qty') === 1.0
                    && $params->getData('super_attribute') === null;
            }))
            ->willReturn($quoteItem);

        $this->cart->expects(self::once())->method('save');

        $result = $this->adapter->addToCart(new CartAddRequest(productId: 555, qty: 1.0));

        self::assertTrue($result->success);
        self::assertSame(9001, $result->quoteItemId);
    }

    public function testConfigurableAddPassesSuperAttributeThrough(): void
    {
        $product = $this->createMock(Product::class);
        $this->productRepository->method('getById')->with(100)->willReturn($product);

        $quoteItem = $this->createMock(Item::class);
        $quoteItem->method('getId')->willReturn(9002);

        $this->cart->expects(self::once())
            ->method('addProduct')
            ->with($product, self::callback(function (DataObject $params) {
                return $params->getData('product') === 100
                    && $params->getData('super_attribute') === [93 => 49, 142 => 17];
            }))
            ->willReturn($quoteItem);

        $result = $this->adapter->addToCart(
            new CartAddRequest(productId: 100, qty: 1.0, superAttributes: [93 => 49, 142 => 17])
        );

        self::assertTrue($result->success);
    }

    public function testMagentoValidationFailureIsReportedNotThrown(): void
    {
        // Exercises what happens when Magento's own cart flow rejects the add —
        // e.g. a required custom option was missing, or the item is no longer
        // saleable. The caller (Controller\Cart\Add) gets a clean failure result,
        // never an uncaught exception.
        $product = $this->createMock(Product::class);
        $this->productRepository->method('getById')->willReturn($product);

        $this->cart->method('addProduct')
            ->willThrowException(new LocalizedException(__('This product requires a required custom option.')));

        $this->logger->expects(self::once())->method('warning');

        $result = $this->adapter->addToCart(new CartAddRequest(productId: 100, qty: 1.0));

        self::assertFalse($result->success);
        self::assertNotSame('', $result->message);
    }

    public function testStringReturnFromAddProductIsTreatedAsFailure(): void
    {
        // Magento\Checkout\Model\Cart::addProduct() can return a plain string
        // message on certain non-exception validation failures rather than
        // throwing — this must not be mistaken for a successful add.
        $product = $this->createMock(Product::class);
        $this->productRepository->method('getById')->willReturn($product);

        $this->cart->method('addProduct')->willReturn('Please specify a required option.');
        $this->cart->expects(self::never())->method('save');

        $result = $this->adapter->addToCart(new CartAddRequest(productId: 100, qty: 1.0));

        self::assertFalse($result->success);
        self::assertSame('Please specify a required option.', $result->message);
    }
}
