<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service\Plp;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Cache\Type\Plp as PlpCache;
use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;

/**
 * The ISR data layer. Wraps the platform adapter with:
 *
 *  1. a short-TTL "fresh" entry — almost every render that gets past FPC reads
 *     this instead of calling the platform;
 *  2. a long-lived "last known good" entry — served (marked stale) whenever the
 *     platform is unreachable or slow, so a cache-cold page during a platform
 *     outage still renders real FalcoSense-ranked products rather than falling
 *     straight through to the native grid.
 *
 * Entries are tagged with Magento's own cat_p_* / cat_c_* tags, so an ordinary
 * product save already evicts every cached listing the product appears in.
 * Phase B adds explicit, ordered purge on top of that for the FPC page itself.
 *
 * A genuinely empty (but successful) platform response is returned as-is, NOT
 * replaced with a stale blob — an empty category should let the native page
 * render, not publish an empty crawlable grid or resurrect deleted products.
 */
class CachedPlpProvider implements PlpDataProviderInterface
{
    private const LKG_LIFETIME = 604800; // 7 days
    private const MAX_TAGGED_PRODUCTS = 250;

    public function __construct(
        private readonly PlpDataProviderInterface $upstream,
        private readonly PlpCache $cache,
        private readonly SerializerInterface $serializer,
        private readonly Data $helper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function fetch(PlpQuery $query): PlpResult
    {
        $key      = $query->cacheKey();
        $freshKey = $key . '_fresh';
        $lkgKey   = $key . '_lkg';

        $fresh = $this->load($freshKey);
        if ($fresh !== null) {
            return $fresh;
        }

        $result = $this->upstream->fetch($query);

        if ($result->isUsable()) {
            $payload = $this->serializer->serialize($result->toArray());
            $tags    = $this->tagsFor($result, $query);
            $ttl     = max(30, $this->helper->getPlpCacheTtl($query->storeId));

            $this->cache->save($payload, $freshKey, $tags, $ttl);
            $this->cache->save($payload, $lkgKey, $tags, self::LKG_LIFETIME);

            return $result;
        }

        // Successful but empty -> hand it back untouched; the caller falls back
        // to native rendering for this listing.
        if (!$result->isUnavailable()) {
            return $result;
        }

        $stale = $this->load($lkgKey);
        if ($stale !== null) {
            $this->logger->info(sprintf(
                '[SmartSearchLuma][PLP] Platform unavailable — serving last-known-good (%s).',
                $stale->fetchedAt > 0 ? date('c', $stale->fetchedAt) : 'unknown age'
            ));
            return $stale->withSource(PlpResult::SOURCE_STALE);
        }

        return PlpResult::unavailable();
    }

    private function load(string $cacheId): ?PlpResult
    {
        $raw = $this->cache->load($cacheId);
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($raw);
            return is_array($data) ? PlpResult::fromArray($data) : null;
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Corrupt cache entry ' . $cacheId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @return string[]
     */
    private function tagsFor(PlpResult $result, PlpQuery $query): array
    {
        $tags = [PlpCache::CACHE_TAG];

        if ($query->categoryId !== null) {
            $tags[] = 'cat_c_' . $query->categoryId;
        }

        foreach (array_slice($result->productIds(), 0, self::MAX_TAGGED_PRODUCTS) as $id) {
            $tags[] = 'cat_p_' . $id;
        }

        return $tags;
    }
}
