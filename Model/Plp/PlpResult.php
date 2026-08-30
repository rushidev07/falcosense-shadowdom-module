<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

/**
 * The full, rendered-ready payload for one listing view. This is:
 *  - what the PHP renderer draws the light-DOM grid + JSON-LD from,
 *  - what gets embedded verbatim in the page for the client to hydrate from,
 *  - what gets stored in the ISR data cache and the last-known-good blob.
 *
 * All three consumers read the same object, so they cannot drift from each
 * other. `source` records where this particular instance came from so the
 * client and any monitoring can tell a fresh platform render from a stale
 * fallback from a "platform gave us nothing" situation.
 */
final class PlpResult
{
    /** Fresh (or cache-fresh) response straight from the platform. */
    public const SOURCE_PLATFORM = 'platform';

    /** Platform was unreachable/slow; served the last good response we had. */
    public const SOURCE_STALE = 'platform_stale';

    /** No platform data at all — caller must fall back to native Magento rendering. */
    public const SOURCE_UNAVAILABLE = 'unavailable';

    /**
     * @param PlpItem[]  $items
     * @param PlpFacet[] $facets
     */
    public function __construct(
        public readonly array $items,
        public readonly array $facets,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly string $source = self::SOURCE_PLATFORM,
        public readonly int $fetchedAt = 0,
        public readonly array $meta = [],
    ) {
    }

    public static function unavailable(): self
    {
        return new self([], [], 0, 1, 0, self::SOURCE_UNAVAILABLE, time());
    }

    public function isUnavailable(): bool
    {
        return $this->source === self::SOURCE_UNAVAILABLE;
    }

    public function isStale(): bool
    {
        return $this->source === self::SOURCE_STALE;
    }

    /**
     * Usable = we have real products to show. An empty-but-successful platform
     * response (a genuinely empty category) is NOT usable for SSR — better to
     * let the native page render than to publish an empty crawlable grid.
     */
    public function isUsable(): bool
    {
        return !$this->isUnavailable() && $this->items !== [];
    }

    public function totalPages(): int
    {
        if ($this->perPage < 1) {
            return 1;
        }

        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /**
     * @return int[] every product id in this payload — used for cache tagging
     *              so a product save evicts exactly the listings it appears in
     */
    public function productIds(): array
    {
        return array_map(static fn (PlpItem $i) => $i->productId, $this->items);
    }

    public function withSource(string $source): self
    {
        return new self(
            $this->items,
            $this->facets,
            $this->total,
            $this->page,
            $this->perPage,
            $source,
            $this->fetchedAt,
            $this->meta,
        );
    }

    public function toArray(): array
    {
        return [
            'items'      => array_map(static fn (PlpItem $i) => $i->toArray(), $this->items),
            'facets'     => array_map(static fn (PlpFacet $f) => $f->toArray(), $this->facets),
            'total'      => $this->total,
            'page'       => $this->page,
            'per_page'   => $this->perPage,
            'source'     => $this->source,
            'fetched_at' => $this->fetchedAt,
            'meta'       => $this->meta,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            items: array_map(
                static fn (array $i) => PlpItem::fromArray($i),
                array_values((array) ($d['items'] ?? []))
            ),
            facets: array_map(
                static fn (array $f) => PlpFacet::fromArray($f),
                array_values((array) ($d['facets'] ?? []))
            ),
            total: (int) ($d['total'] ?? 0),
            page: (int) ($d['page'] ?? 1),
            perPage: (int) ($d['per_page'] ?? 0),
            source: (string) ($d['source'] ?? self::SOURCE_PLATFORM),
            fetchedAt: (int) ($d['fetched_at'] ?? 0),
            meta: is_array($d['meta'] ?? null) ? $d['meta'] : [],
        );
    }
}
