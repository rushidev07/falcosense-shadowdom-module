<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Plp;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Cache\Type\Plp as PlpCache;
use Ahy\SmartSearchLuma\Model\Plp\BreadcrumbResolver;
use Ahy\SmartSearchLuma\Model\Plp\JsonLdBuilder;
use Ahy\SmartSearchLuma\Model\Plp\PlpContextProvider;
use Ahy\SmartSearchLuma\Model\Plp\PlpRenderer;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The crawlable, light-DOM product grid — the single-source render. Placed into
 * `content` right after the native product list, which CategoryListPlugin has
 * emptied out when this block is active. Renders nothing at all (native page
 * stands unchanged) whenever PlpContextProvider has no usable result: SSR off,
 * not a listing page, platform down with no last-known-good, empty category, or
 * the coverage guard tripped.
 *
 * FPC-safe: getIdentities() carries the category + every product tag, so a
 * product save (which core already purges those tags for) and this module's own
 * post-sync purge both drop the cached page.
 */
class Grid extends Template implements IdentityInterface
{
    public function __construct(
        Context $context,
        private readonly PlpContextProvider $ctx,
        private readonly PlpRenderer $renderer,
        private readonly JsonLdBuilder $jsonLd,
        private readonly BreadcrumbResolver $breadcrumbResolver,
        private readonly Data $helper,
        private readonly HttpRequest $request,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isActive(): bool
    {
        return $this->ctx->isActive();
    }

    public function getGridHtml(): string
    {
        $result = $this->ctx->getResult();
        $query  = $this->ctx->getQuery();
        if ($result === null || $query === null) {
            return '';
        }

        return $this->renderer->render($result, $query, $this->currentListingUrl());
    }

    public function getJsonLdHtml(): string
    {
        $result = $this->ctx->getResult();
        $query  = $this->ctx->getQuery();
        if ($result === null || $query === null || !$query->isCanonicalView()) {
            return '';
        }

        $breadcrumbs = [];
        $category = $this->ctx->getCategory();
        if ($category !== null) {
            $breadcrumbs = $this->breadcrumbResolver->forCategory($category, $query->storeId);
        }

        return $this->jsonLd->build($result, $query, $breadcrumbs);
    }

    /**
     * Admin-configured CSS selector (shared with the widget's category
     * enhancement) for the native grid to hide once our SSR grid is on the page
     * — for JS-less visitors and crawlers. Sanitised to selector-safe characters
     * so it can be emitted into a <style> without an injection vector. Empty =
     * don't emit the rule.
     */
    public function getNativeGridSelector(): string
    {
        $raw = trim($this->helper->getCategoryGridSelector());
        if ($raw === '') {
            return '';
        }

        $safe = preg_replace('/[^a-zA-Z0-9 ._#>:,\[\]="\'\-]/', '', $raw);

        return (string) $safe;
    }

    public function getCacheLifetime()
    {
        $result = $this->ctx->getResult();
        if ($result !== null && $result->isStale()) {
            // Platform was down for this render. Cap how long the block_html
            // cache reuses this stale markup. (Note: the *page* FPC entry still
            // lives for the global FPC TTL — a long platform outage can leave
            // some pages showing last-known-good until their normal expiry, the
            // warmer, or a product-save purge refreshes them.)
            return 120;
        }

        return parent::getCacheLifetime();
    }

    /**
     * @return string[]
     */
    public function getIdentities(): array
    {
        $result = $this->ctx->getResult();
        $query  = $this->ctx->getQuery();
        if ($result === null) {
            return [];
        }

        $tags = [PlpCache::CACHE_TAG];
        if ($query !== null && $query->categoryId !== null) {
            $tags[] = 'cat_c_' . $query->categoryId;
        }
        foreach ($result->productIds() as $id) {
            $tags[] = 'cat_p_' . $id;
        }

        return $tags;
    }

    private function currentListingUrl(): string
    {
        try {
            $base = rtrim($this->_storeManager->getStore()->getBaseUrl(), '/');
        } catch (\Throwable $e) {
            $base = '';
        }

        return $base . (string) $this->request->getRequestUri();
    }
}
