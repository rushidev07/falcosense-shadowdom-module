<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Api;

use Ahy\SmartSearchLuma\Model\Plp\PlpQuery;
use Ahy\SmartSearchLuma\Model\Plp\PlpResult;

/**
 * The one Port every server-side listing render goes through — category pages,
 * the search-results page, and the /smsl/plp/grid fragment endpoint all ask
 * this and nothing else for "what products, in what order, with what facets."
 *
 * The default implementation is a caching decorator over the platform adapter
 * (see etc/di.xml): CachedPlpProvider -> OpenSearchPlpProvider. A client whose
 * platform speaks a different protocol swaps the *adapter* via one <preference>
 * line in their own module; the caching, fallback, and rendering layers above
 * this interface never change.
 *
 * Implementations MUST NOT throw for an unreachable/slow/empty platform —
 * return PlpResult::unavailable() (or a stale result) instead, so the single
 * caller-side code path is "did I get something usable back."
 */
interface PlpDataProviderInterface
{
    public function fetch(PlpQuery $query): PlpResult;
}
