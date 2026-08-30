<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Service\Plp;

/**
 * Thrown only inside the Plp service layer, and always caught before it reaches
 * a block or controller — CachedPlpProvider turns it into a stale result or
 * PlpResult::unavailable(). It never surfaces to a shopper.
 */
class PlatformRequestException extends \RuntimeException
{
}
