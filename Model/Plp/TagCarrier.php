<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Framework\DataObject\IdentityInterface;

/**
 * A bare carrier for a set of cache tags, so CacheInvalidator can hand them to
 * Magento's own `clean_cache_by_tags` event — the same mechanism core catalog
 * saves use to purge FPC and issue Varnish PURGE requests. Nothing else.
 */
final class TagCarrier implements IdentityInterface
{
    /**
     * @param string[] $tags
     */
    public function __construct(private readonly array $tags)
    {
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        return $this->tags;
    }
}
