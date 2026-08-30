<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Service\Plp;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Cache\Type\Plp as PlpCache;
use Ahy\SmartSearchLuma\Model\Plp\PlpItem;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Ahy\SmartSearchLuma\Service\Plp\CachedPlpProvider;
use Magento\Framework\Serialize\SerializerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * See NativeVariantResolverTest's header note on how these run. The behaviour
 * pinned here is the fallback ladder: fresh cache -> live fetch (and cache) ->
 * last-known-good (stale) -> unavailable.
 */
class CachedPlpProviderTest extends TestCase
{
    private PlpDataProviderInterface&MockObject $upstream;
    private PlpCache&MockObject $cache;
    private Data&MockObject $helper;
    private CachedPlpProvider $provider;

    protected function setUp(): void
    {
        $this->upstream = $this->createMock(PlpDataProviderInterface::class);
        $this->cache = $this->createMock(PlpCache::class);
        $this->helper = $this->createMock(Data::class);
        $this->helper->method('getPlpCacheTtl')->willReturn(600);

        // Real JSON serializer behaviour without pulling the framework class in.
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('serialize')->willReturnCallback(static fn ($v) => json_encode($v));
        $serializer->method('unserialize')->willReturnCallback(static fn ($v) => json_decode((string) $v, true));

        $this->provider = new CachedPlpProvider(
            $this->upstream,
            $this->cache,
            $serializer,
            $this->helper,
            $this->createMock(LoggerInterface::class),
        );
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

    private function usableResult(): PlpResult
    {
        return new PlpResult(
            items: [new PlpItem(1, 'S', 'Name', 'https://x/p.html', 'https://x/i.jpg', 10.0)],
            facets: [],
            total: 30,
            page: 1,
            perPage: 12,
            source: PlpResult::SOURCE_PLATFORM,
            fetchedAt: 1_700_000_000,
        );
    }

    public function testFreshCacheHitSkipsUpstream(): void
    {
        $payload = json_encode($this->usableResult()->toArray());
        $this->cache->method('load')->willReturnCallback(
            static fn (string $id) => str_ends_with($id, '_fresh') ? $payload : false
        );

        $this->upstream->expects(self::never())->method('fetch');

        $result = $this->provider->fetch($this->query());

        self::assertSame(30, $result->total);
        self::assertSame(PlpResult::SOURCE_PLATFORM, $result->source);
    }

    public function testCacheMissFetchesUpstreamAndWritesBothFreshAndLkg(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->upstream->method('fetch')->willReturn($this->usableResult());

        $savedIds = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $id, $tags, $ttl) use (&$savedIds) {
                $savedIds[] = $id;
                return true;
            }
        );

        $result = $this->provider->fetch($this->query());

        self::assertSame(30, $result->total);
        self::assertCount(2, $savedIds);
        self::assertNotEmpty(array_filter($savedIds, static fn ($id) => str_ends_with($id, '_fresh')));
        self::assertNotEmpty(array_filter($savedIds, static fn ($id) => str_ends_with($id, '_lkg')));
    }

    public function testCacheEntriesAreTaggedWithCatalogTags(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->upstream->method('fetch')->willReturn($this->usableResult());

        $tagsSeen = [];
        $this->cache->method('save')->willReturnCallback(
            function ($data, $id, $tags, $ttl) use (&$tagsSeen) {
                $tagsSeen = array_merge($tagsSeen, $tags);
                return true;
            }
        );

        $this->provider->fetch($this->query());

        self::assertContains('cat_c_5', $tagsSeen, 'category tag lets a category save evict the snapshot');
        self::assertContains('cat_p_1', $tagsSeen, 'product tag lets a product save evict the snapshot');
        self::assertContains(PlpCache::CACHE_TAG, $tagsSeen);
    }

    public function testPlatformUnavailableFallsBackToLastKnownGoodMarkedStale(): void
    {
        $lkgPayload = json_encode($this->usableResult()->toArray());
        $this->cache->method('load')->willReturnCallback(
            static fn (string $id) => str_ends_with($id, '_lkg') ? $lkgPayload : false
        );
        $this->upstream->method('fetch')->willReturn(PlpResult::unavailable());

        $result = $this->provider->fetch($this->query());

        self::assertTrue($result->isStale());
        self::assertSame(30, $result->total);
    }

    public function testPlatformUnavailableAndNoLkgYieldsUnavailable(): void
    {
        $this->cache->method('load')->willReturn(false);
        $this->upstream->method('fetch')->willReturn(PlpResult::unavailable());

        self::assertTrue($this->provider->fetch($this->query())->isUnavailable());
    }

    public function testSuccessfulButEmptyResultIsPassedThroughNotReplacedWithStale(): void
    {
        $lkgPayload = json_encode($this->usableResult()->toArray());
        $this->cache->method('load')->willReturnCallback(
            static fn (string $id) => str_ends_with($id, '_lkg') ? $lkgPayload : false
        );
        // Empty but successful — a genuinely empty category.
        $this->upstream->method('fetch')->willReturn(
            new PlpResult([], [], 0, 1, 12, PlpResult::SOURCE_PLATFORM, time())
        );

        $result = $this->provider->fetch($this->query());

        self::assertFalse($result->isStale(), 'An empty category must not resurrect stale products.');
        self::assertFalse($result->isUsable());
    }
}
