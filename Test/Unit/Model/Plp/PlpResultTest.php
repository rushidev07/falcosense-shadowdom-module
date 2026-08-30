<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Plp;

use Ahy\SmartSearchLuma\Model\Plp\PlpFacet;
use Ahy\SmartSearchLuma\Model\Plp\PlpItem;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use PHPUnit\Framework\TestCase;

/**
 * See NativeVariantResolverTest's header note on how these run. PlpResult is
 * serialized into the ISR cache and back on every cache hit, so the array
 * round-trip has to be lossless for the fields the renderer and JSON-LD read.
 */
class PlpResultTest extends TestCase
{
    private function sampleResult(): PlpResult
    {
        return new PlpResult(
            items: [
                new PlpItem(
                    productId: 12,
                    sku: 'DRESS-12',
                    name: 'Linen Dress',
                    url: 'https://shop.test/linen-dress.html',
                    imageUrl: 'https://cdn.test/linen.jpg',
                    price: 120.0,
                    specialPrice: 90.0,
                    inStock: true,
                    brand: 'Acme',
                    rating: 4.5,
                    ratingCount: 8,
                    badges: ['sale'],
                    swatches: [['variant_id' => '13', 'attributes' => ['Color' => 'Blue']]],
                ),
                new PlpItem(13, 'DRESS-13', 'Cotton Dress', 'https://shop.test/cotton.html', 'https://cdn.test/cotton.jpg', 60.0),
            ],
            facets: [
                new PlpFacet('color', 'Color', PlpFacet::TYPE_SWATCH, [
                    ['value' => 'Blue', 'label' => 'Blue', 'count' => 3],
                ]),
            ],
            total: 47,
            page: 1,
            perPage: 12,
            source: PlpResult::SOURCE_PLATFORM,
            fetchedAt: 1_700_000_000,
        );
    }

    public function testArrayRoundTripIsLossless(): void
    {
        $original = $this->sampleResult();
        $restored = PlpResult::fromArray($original->toArray());

        self::assertEquals($original, $restored);
    }

    public function testProductIdsListsEveryItem(): void
    {
        self::assertSame([12, 13], $this->sampleResult()->productIds());
    }

    public function testTotalPagesRoundsUp(): void
    {
        self::assertSame(4, $this->sampleResult()->totalPages()); // ceil(47 / 12)
    }

    public function testEffectivePriceAndDiscount(): void
    {
        [$first, $second] = $this->sampleResult()->items;

        self::assertSame(90.0, $first->effectivePrice());
        self::assertTrue($first->hasDiscount());

        self::assertSame(60.0, $second->effectivePrice());
        self::assertFalse($second->hasDiscount());
    }

    public function testUnavailableIsNotUsable(): void
    {
        $r = PlpResult::unavailable();

        self::assertTrue($r->isUnavailable());
        self::assertFalse($r->isUsable());
    }

    public function testSuccessfulButEmptyIsNotUsable(): void
    {
        $r = new PlpResult([], [], 0, 1, 12, PlpResult::SOURCE_PLATFORM, time());

        self::assertFalse($r->isUnavailable());
        self::assertFalse($r->isUsable(), 'An empty category must not publish a crawlable empty grid.');
    }

    public function testWithSourcePreservesEverythingElse(): void
    {
        $stale = $this->sampleResult()->withSource(PlpResult::SOURCE_STALE);

        self::assertTrue($stale->isStale());
        self::assertSame($this->sampleResult()->productIds(), $stale->productIds());
        self::assertSame(47, $stale->total);
    }

    public function testSpecialPriceThatIsNotLowerIsNotADiscount(): void
    {
        $item = new PlpItem(1, 'X', 'X', 'u', 'i', 50.0, 55.0);

        self::assertFalse($item->hasDiscount());
        self::assertSame(50.0, $item->effectivePrice());
    }
}
