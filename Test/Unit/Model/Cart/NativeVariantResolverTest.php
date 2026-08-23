<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Cart;

use Ahy\SmartSearchLuma\Model\Cart\NativeVariantResolver;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Note on running these: this environment has no PHP runtime, so these have been
 * written and manually traced against Magento's real Configurable/ProductRepository/
 * StockRegistry APIs, but not executed here. They're structured to run unmodified
 * under a real Magento PHPUnit bootstrap (`vendor/bin/phpunit -c dev/tests/unit`)
 * the first time this module is checked out into an environment that has one.
 */
class NativeVariantResolverTest extends TestCase
{
    private ProductRepositoryInterface&MockObject $productRepository;
    private Configurable&MockObject $configurableType;
    private StockRegistryInterface&MockObject $stockRegistry;
    private NativeVariantResolver $resolver;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->configurableType = $this->createMock(Configurable::class);
        $this->stockRegistry = $this->createMock(StockRegistryInterface::class);

        $this->resolver = new NativeVariantResolver(
            $this->productRepository,
            $this->configurableType,
            $this->stockRegistry,
        );
    }

    public function testSimpleProductResolvesToItselfWithNoSuperAttributes(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getId')->willReturn(555);

        $this->productRepository->method('getById')->with(555)->willReturn($product);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $this->stockRegistry->method('getStockItem')->with(555)->willReturn($stockItem);

        $resolved = $this->resolver->resolve(555, []);

        self::assertSame(555, $resolved->requestProductId);
        self::assertSame(555, $resolved->sellableProductId);
        self::assertSame([], $resolved->superAttributes);
        self::assertFalse($resolved->isConfigurable);
        self::assertTrue($resolved->inStock);
    }

    public function testSingleAttributeConfigurableResolvesCorrectChild(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getId')->willReturn(100);

        $this->productRepository->method('getById')->with(100)->willReturn($parent);

        $this->configurableType->method('getConfigurableAttributesAsArray')->with($parent)->willReturn([
            [
                'attribute_id' => '93',
                'label' => 'Color',
                'values' => [
                    ['value_index' => '49', 'label' => 'Black'],
                    ['value_index' => '50', 'label' => 'White'],
                ],
            ],
        ]);

        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn(201);

        $this->configurableType->expects(self::once())
            ->method('getProductByAttributes')
            ->with([93 => 49], $parent)
            ->willReturn($child);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(true);
        $this->stockRegistry->method('getStockItem')->with(201)->willReturn($stockItem);

        $resolved = $this->resolver->resolve(100, ['Color' => 'Black']);

        self::assertSame(100, $resolved->requestProductId);
        self::assertSame(201, $resolved->sellableProductId);
        self::assertSame([93 => 49], $resolved->superAttributes);
        self::assertTrue($resolved->isConfigurable);
        self::assertTrue($resolved->inStock);
    }

    public function testMultiAttributeConfigurableMatchesAllAttributes(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getId')->willReturn(100);
        $this->productRepository->method('getById')->willReturn($parent);

        $this->configurableType->method('getConfigurableAttributesAsArray')->willReturn([
            ['attribute_id' => '93', 'label' => 'Color', 'values' => [
                ['value_index' => '49', 'label' => 'Black'],
            ]],
            ['attribute_id' => '142', 'label' => 'Size', 'values' => [
                ['value_index' => '17', 'label' => 'Medium'],
            ]],
        ]);

        $child = $this->createMock(Product::class);
        $child->method('getId')->willReturn(202);

        $this->configurableType->expects(self::once())
            ->method('getProductByAttributes')
            ->with(self::callback(fn (array $a) => $a === [93 => 49, 142 => 17] || $a === [142 => 17, 93 => 49]), $parent)
            ->willReturn($child);

        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getIsInStock')->willReturn(false);
        $this->stockRegistry->method('getStockItem')->willReturn($stockItem);

        $resolved = $this->resolver->resolve(100, ['Color' => 'Black', 'Size' => 'Medium']);

        self::assertTrue($resolved->isConfigurable);
        self::assertFalse($resolved->inStock, 'Out-of-stock child must be reported, not hidden.');
    }

    public function testMissingSelectionForAConfigurableAttributeThrows(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $this->productRepository->method('getById')->willReturn($parent);

        $this->configurableType->method('getConfigurableAttributesAsArray')->willReturn([
            ['attribute_id' => '93', 'label' => 'Color', 'values' => [
                ['value_index' => '49', 'label' => 'Black'],
            ]],
        ]);

        $this->expectException(NoSuchEntityException::class);

        // No 'Color' key provided at all — must not silently proceed.
        $this->resolver->resolve(100, []);
    }

    public function testInvalidOptionValueThrows(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $this->productRepository->method('getById')->willReturn($parent);

        $this->configurableType->method('getConfigurableAttributesAsArray')->willReturn([
            ['attribute_id' => '93', 'label' => 'Color', 'values' => [
                ['value_index' => '49', 'label' => 'Black'],
            ]],
        ]);

        $this->expectException(NoSuchEntityException::class);

        // "Purple" isn't a real value for this product's Color attribute.
        $this->resolver->resolve(100, ['Color' => 'Purple']);
    }

    public function testNoMatchingChildForACompleteButUnsoldCombinationThrows(): void
    {
        $parent = $this->createMock(Product::class);
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $this->productRepository->method('getById')->willReturn($parent);

        $this->configurableType->method('getConfigurableAttributesAsArray')->willReturn([
            ['attribute_id' => '93', 'label' => 'Color', 'values' => [
                ['value_index' => '49', 'label' => 'Black'],
            ]],
        ]);

        // Every selected value matched an attribute option, but no actual child
        // product exists for this exact combination (e.g. discontinued variant).
        $this->configurableType->method('getProductByAttributes')->willReturn(null);

        $this->expectException(NoSuchEntityException::class);

        $this->resolver->resolve(100, ['Color' => 'Black']);
    }
}
