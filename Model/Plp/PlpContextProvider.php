<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Ahy\SmartSearchLuma\Api\PlpDataProviderInterface;
use Ahy\SmartSearchLuma\Helper\Data;
use Psr\Log\LoggerInterface;

/**
 * The single entry point every frontend block uses to get "the FalcoSense
 * listing for this page, or nothing." Resolves once per request and memoises,
 * so the light-DOM grid block, the JSON-LD block, and Block\Widget\Bootstrap
 * all render from the *same* PlpResult — no double fetch, no chance of three
 * blocks disagreeing.
 *
 * getResult() returns non-null only when FalcoSense should visibly take over
 * this listing. It returns null (caller renders/keeps the native page) when:
 *   - this isn't a FalcoSense listing page, or SSR is disabled;
 *   - the platform is unreachable AND there is no last-known-good blob;
 *   - the platform returned an empty result for a category;
 *   - the C1 coverage guard tripped (platform has far fewer products than
 *     Magento thinks the category holds — i.e. the catalog isn't fully synced).
 */
class PlpContextProvider
{
    private bool $resolved = false;
    private ?PlpResult $result = null;
    private ?PlpQuery $query = null;

    public function __construct(
        private readonly PageContext $pageContext,
        private readonly PlpDataProviderInterface $provider,
        private readonly Data $helper,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getResult(): ?PlpResult
    {
        $this->resolve();
        return $this->result;
    }

    public function getQuery(): ?PlpQuery
    {
        $this->resolve();
        return $this->query;
    }

    /**
     * The current Magento category, when this is a category listing and the
     * takeover is active — for breadcrumb JSON-LD and canonical URLs. Null for
     * search pages or when inactive.
     */
    public function getCategory(): ?\Magento\Catalog\Model\Category
    {
        if (!$this->isActive()) {
            return null;
        }

        return $this->pageContext->getCurrentCategory();
    }

    public function isActive(): bool
    {
        return $this->getResult() !== null;
    }

    private function resolve(): void
    {
        if ($this->resolved) {
            return;
        }
        $this->resolved = true;

        $query = $this->pageContext->buildQuery();
        if ($query === null) {
            return;
        }
        $this->query = $query;

        $result = $this->provider->fetch($query);

        if (!$result->isUsable()) {
            if ($result->isUnavailable()) {
                $this->logger->info('[SmartSearchLuma][PLP] No platform data for this listing — native page will render.');
            }
            return;
        }

        if ($query->isCategory() && $this->coverageGuardTrips($result, $query)) {
            return;
        }

        $this->result = $result;
    }

    /**
     * C1: if the platform reports far fewer products than Magento believes the
     * category contains, the catalog almost certainly isn't fully synced — a
     * server-rendered grid missing most of its products is worse than letting
     * the native grid render. Disabled by default (ratio 0).
     */
    private function coverageGuardTrips(PlpResult $result, PlpQuery $query): bool
    {
        $ratio = $this->helper->getPlpFallbackMinRatio($query->storeId);
        if ($ratio <= 0.0) {
            return false;
        }

        $nativeCount = $this->pageContext->getNativeProductCount();
        if ($nativeCount <= 0) {
            return false;
        }

        if ($result->total >= (int) ceil($nativeCount * $ratio)) {
            return false;
        }

        $this->logger->warning(sprintf(
            '[SmartSearchLuma][PLP] Coverage guard: category "%s" — platform has %d of ~%d products (min ratio %.2f). Falling back to native.',
            (string) $query->categoryName,
            $result->total,
            $nativeCount,
            $ratio
        ));

        return true;
    }
}
