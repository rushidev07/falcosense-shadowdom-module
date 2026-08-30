<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Plp;

use Ahy\SmartSearchLuma\Model\Plp\JsonLdBuilder;
use Ahy\SmartSearchLuma\Model\Plp\PlpItem;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * See NativeVariantResolverTest's header note on how these run. The structured
 * data is built from the same PlpResult as the visible grid — the point being
 * that price/availability in JSON-LD can't disagree with what a shopper sees.
 */
class JsonLdBuilderTest extends TestCase
{
    private JsonLdBuilder $builder;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('GBP');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->builder = new JsonLdBuilder($storeManager, $this->createMock(LoggerInterface::class));
    }

    private function query(): PlpQuery
    {
        return new PlpQuery(
            contextType: PlpQuery::CONTEXT_CATEGORY,
            storeId: 1,
            platformStoreId: 1,
            page: 1,
            perPage: 12,
            categoryId: 5,
            categoryName: 'Dresses',
        );
    }

    private function result(): PlpResult
    {
        return new PlpResult(
            items: [
                new PlpItem(101, 'A-101', 'Linen Dress', 'https://shop.test/linen.html', 'https://cdn.test/a.jpg', 120.0, 90.0, true, 'Acme', 4.5, 12),
                new PlpItem(102, 'A-102', 'Sold Out Dress', 'https://shop.test/sold.html', 'https://cdn.test/b.jpg', 60.0, null, false),
            ],
            facets: [],
            total: 47,
            page: 1,
            perPage: 12,
        );
    }

    private function decodeBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        return array_map(static fn ($j) => json_decode($j, true), $m[1]);
    }

    public function testEmitsItemListWithOffersFromTheSameResult(): void
    {
        $blocks = $this->decodeBlocks($this->builder->build($this->result(), $this->query()));

        self::assertCount(1, $blocks);
        $list = $blocks[0];
        self::assertSame('ItemList', $list['@type']);
        self::assertSame(47, $list['numberOfItems']);
        self::assertCount(2, $list['itemListElement']);

        $first = $list['itemListElement'][0];
        self::assertSame(1, $first['position']);
        self::assertSame('Product', $first['item']['@type']);
        self::assertSame('90.00', $first['item']['offers']['price'], 'sale price, formatted for schema');
        self::assertSame('GBP', $first['item']['offers']['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $first['item']['offers']['availability']);
        self::assertSame(['@type' => 'Brand', 'name' => 'Acme'], $first['item']['brand']);
        self::assertSame(4.5, $first['item']['aggregateRating']['ratingValue']);
    }

    public function testOutOfStockAvailabilityIsReflected(): void
    {
        $blocks = $this->decodeBlocks($this->builder->build($this->result(), $this->query()));
        $second = $blocks[0]['itemListElement'][1];

        self::assertSame('https://schema.org/OutOfStock', $second['item']['offers']['availability']);
        self::assertArrayNotHasKey('aggregateRating', $second['item']);
    }

    public function testBreadcrumbListEmittedWhenCrumbsProvided(): void
    {
        $crumbs = [
            ['name' => 'Home', 'url' => 'https://shop.test/'],
            ['name' => 'Women', 'url' => 'https://shop.test/women.html'],
            ['name' => 'Dresses', 'url' => 'https://shop.test/women/dresses.html'],
        ];

        $blocks = $this->decodeBlocks($this->builder->build($this->result(), $this->query(), $crumbs));

        self::assertCount(2, $blocks);
        self::assertSame('BreadcrumbList', $blocks[1]['@type']);
        self::assertCount(3, $blocks[1]['itemListElement']);
        self::assertSame('Women', $blocks[1]['itemListElement'][1]['name']);
        self::assertSame(2, $blocks[1]['itemListElement'][1]['position']);
    }

    public function testUnusableResultProducesNothing(): void
    {
        self::assertSame('', $this->builder->build(PlpResult::unavailable(), $this->query()));
    }

    public function testPositionsAccountForPageOffset(): void
    {
        $result = new PlpResult(
            items: [new PlpItem(1, 'S', 'P', 'https://x/p.html', 'https://x/i.jpg', 10.0)],
            facets: [],
            total: 100,
            page: 3,
            perPage: 12,
        );

        $blocks = $this->decodeBlocks($this->builder->build($result, $this->query()));

        self::assertSame(25, $blocks[0]['itemListElement'][0]['position']); // (3-1)*12 + 1
    }
}
