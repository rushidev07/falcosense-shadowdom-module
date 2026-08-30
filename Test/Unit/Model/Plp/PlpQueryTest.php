<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Plp;

use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use PHPUnit\Framework\TestCase;

/**
 * See NativeVariantResolverTest's header note on how these run. The cache key is
 * the load-bearing contract here: it must be identical across requests for the
 * same view (or the ISR cache never hits) and must change the moment any input
 * that affects the rendered result changes (or a stale view is served).
 */
class PlpQueryTest extends TestCase
{
    private function categoryQuery(array $overrides = []): PlpQuery
    {
        return new PlpQuery(
            contextType: $overrides['contextType'] ?? PlpQuery::CONTEXT_CATEGORY,
            storeId: $overrides['storeId'] ?? 1,
            platformStoreId: $overrides['platformStoreId'] ?? 1,
            page: $overrides['page'] ?? 1,
            perPage: $overrides['perPage'] ?? 12,
            sort: $overrides['sort'] ?? 'position',
            filters: $overrides['filters'] ?? [],
            priceMin: $overrides['priceMin'] ?? null,
            priceMax: $overrides['priceMax'] ?? null,
            categoryId: $overrides['categoryId'] ?? 42,
            categoryName: $overrides['categoryName'] ?? 'Dresses',
            searchQuery: $overrides['searchQuery'] ?? null,
        );
    }

    public function testCacheKeyIsStableForIdenticalInput(): void
    {
        self::assertSame(
            $this->categoryQuery()->cacheKey(),
            $this->categoryQuery()->cacheKey()
        );
    }

    public function testPlatformStoreIdDoesNotAffectCacheKey(): void
    {
        // platformStoreId is derived from storeId; only storeId should key the cache.
        self::assertSame(
            $this->categoryQuery(['platformStoreId' => 1])->cacheKey(),
            $this->categoryQuery(['platformStoreId' => 7])->cacheKey()
        );
    }

    public function testFilterOrderDoesNotAffectCacheKey(): void
    {
        $a = $this->categoryQuery(['filters' => ['color' => ['Black', 'White'], 'size' => ['M']]]);
        $b = $this->categoryQuery(['filters' => ['size' => ['M'], 'color' => ['White', 'Black']]]);

        self::assertSame($a->cacheKey(), $b->cacheKey());
    }

    public function testEachMeaningfulInputChangesTheCacheKey(): void
    {
        $base = $this->categoryQuery()->cacheKey();

        self::assertNotSame($base, $this->categoryQuery(['page' => 2])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['perPage' => 24])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['sort' => 'price_asc'])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['storeId' => 2])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['categoryId' => 43])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['filters' => ['color' => ['Black']]])->cacheKey());
        self::assertNotSame($base, $this->categoryQuery(['priceMin' => 10.0])->cacheKey());
    }

    public function testSearchQueryIsCaseAndWhitespaceInsensitiveInCacheKey(): void
    {
        $a = new PlpQuery(PlpQuery::CONTEXT_SEARCH, 1, 1, 1, 12, 'position', [], null, null, null, null, '  Red Shoes ');
        $b = new PlpQuery(PlpQuery::CONTEXT_SEARCH, 1, 1, 1, 12, 'position', [], null, null, null, null, 'red shoes');

        self::assertSame($a->cacheKey(), $b->cacheKey());
    }

    public function testIsCanonicalView(): void
    {
        self::assertTrue($this->categoryQuery()->isCanonicalView());
        self::assertTrue($this->categoryQuery(['sort' => 'relevance'])->isCanonicalView());
        self::assertFalse($this->categoryQuery(['page' => 2])->isCanonicalView());
        self::assertFalse($this->categoryQuery(['sort' => 'price_asc'])->isCanonicalView());
        self::assertFalse($this->categoryQuery(['filters' => ['color' => ['Black']]])->isCanonicalView());
        self::assertFalse($this->categoryQuery(['priceMax' => 99.0])->isCanonicalView());
    }
}
