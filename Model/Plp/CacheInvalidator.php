<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Ahy\SmartSearchLuma\Model\Cache\Type\Plp as PlpCache;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;

/**
 * The one place PLP caches get purged. Handles both layers in one call:
 *
 *  - the snapshot cache (ahy_smartsearch_plp) — cleaned directly by tag;
 *  - FPC + Varnish — via Magento's own `clean_cache_by_tags` event, exactly as
 *    a core catalog save does it.
 *
 * Ordering matters and is the caller's job: invalidate only AFTER the change has
 * reached the platform's index, otherwise the very next render just re-caches
 * the stale data. See ProductSaveObserver / StockChangeObserver.
 */
class CacheInvalidator
{
    /** Above this many tags, a targeted purge isn't worth it — flush the lot. */
    private const BULK_TAG_THRESHOLD = 300;

    public function __construct(
        private readonly PlpCache $plpCache,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param string[] $tags Magento cache tags (cat_p_*, cat_c_*)
     */
    public function invalidate(array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags)));
        if ($tags === []) {
            return;
        }

        if (count($tags) > self::BULK_TAG_THRESHOLD) {
            $this->invalidateAll();
            return;
        }

        try {
            // Per-tag clean: TagScope always ANDs its own scope tag in, so each
            // tag has to be cleaned on its own pass to keep "purge anything
            // carrying this tag" semantics (a single multi-tag call would AND
            // them all together and purge almost nothing).
            foreach ($tags as $tag) {
                $this->plpCache->clean(\Zend_Cache::CLEANING_MODE_MATCHING_TAG, [$tag]);
            }
            $this->eventManager->dispatch('clean_cache_by_tags', ['object' => new TagCarrier($tags)]);
            $this->logger->info('[SmartSearchLuma][PLP] Invalidated ' . count($tags) . ' listing tag(s).');
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][PLP] Invalidation failed: ' . $e->getMessage());
        }
    }

    /**
     * Flush every FalcoSense listing snapshot and let FPC/Varnish drop anything
     * tagged for this module. Used for bulk syncs (full sync, large delta,
     * imports) where per-category purge would be a PURGE storm.
     */
    public function invalidateAll(): void
    {
        try {
            $this->plpCache->clean();
            $this->eventManager->dispatch(
                'clean_cache_by_tags',
                ['object' => new TagCarrier([PlpCache::CACHE_TAG])]
            );
            $this->logger->info('[SmartSearchLuma][PLP] Flushed all listing snapshots.');
        } catch (\Throwable $e) {
            $this->logger->error('[SmartSearchLuma][PLP] Full invalidation failed: ' . $e->getMessage());
        }
    }
}
