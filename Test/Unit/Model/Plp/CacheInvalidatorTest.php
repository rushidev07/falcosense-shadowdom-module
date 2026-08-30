<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Test\Unit\Model\Plp;

use Ahy\SmartSearchLuma\Model\Cache\Type\Plp as PlpCache;
use Ahy\SmartSearchLuma\Model\Plp\CacheInvalidator;
use Ahy\SmartSearchLuma\Model\Plp\TagCarrier;
use Magento\Framework\Event\ManagerInterface as EventManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * See NativeVariantResolverTest's header note on how these run. Pins that a
 * purge hits both layers — the snapshot cache directly, and FPC/Varnish via the
 * same clean_cache_by_tags event core catalog saves use.
 */
class CacheInvalidatorTest extends TestCase
{
    private PlpCache&MockObject $cache;
    private EventManager&MockObject $eventManager;
    private CacheInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->cache = $this->createMock(PlpCache::class);
        $this->eventManager = $this->createMock(EventManager::class);
        $this->invalidator = new CacheInvalidator(
            $this->cache,
            $this->eventManager,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testTargetedInvalidateCleansEachTagAndFiresTheEvent(): void
    {
        $cleaned = [];
        $this->cache->method('clean')->willReturnCallback(function ($mode, $tags = []) use (&$cleaned) {
            $cleaned[] = $tags;
            return true;
        });

        $dispatched = null;
        $this->eventManager->expects(self::once())->method('dispatch')
            ->willReturnCallback(function ($name, $data) use (&$dispatched) {
                $dispatched = [$name, $data];
            });

        $this->invalidator->invalidate(['cat_c_5', 'cat_p_10', 'cat_p_10', '']);

        // deduped + empties dropped -> 2 per-tag clean passes
        self::assertCount(2, $cleaned);
        self::assertSame(['cat_c_5'], $cleaned[0]);
        self::assertSame(['cat_p_10'], $cleaned[1]);

        self::assertSame('clean_cache_by_tags', $dispatched[0]);
        self::assertInstanceOf(TagCarrier::class, $dispatched[1]['object']);
        self::assertSame(['cat_c_5', 'cat_p_10'], $dispatched[1]['object']->getIdentities());
    }

    public function testEmptyTagListIsANoOp(): void
    {
        $this->cache->expects(self::never())->method('clean');
        $this->eventManager->expects(self::never())->method('dispatch');

        $this->invalidator->invalidate([]);
        $this->invalidator->invalidate(['', null, false]);
    }

    public function testOversizedTagListFallsBackToFullFlush(): void
    {
        $tags = [];
        for ($i = 0; $i < 400; $i++) {
            $tags[] = 'cat_p_' . $i;
        }

        $modes = [];
        $this->cache->method('clean')->willReturnCallback(function ($mode = null, $t = []) use (&$modes) {
            $modes[] = $mode;
            return true;
        });

        $dispatched = null;
        $this->eventManager->method('dispatch')->willReturnCallback(function ($n, $d) use (&$dispatched) {
            $dispatched = $d;
        });

        $this->invalidator->invalidate($tags);

        // invalidateAll -> a single clean() with no explicit tag list
        self::assertCount(1, $modes);
        self::assertSame([PlpCache::CACHE_TAG], $dispatched['object']->getIdentities());
    }
}
