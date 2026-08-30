<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Observer;

use Ahy\SmartSearchLuma\Model\Plp\PlpContextProvider;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Faceted-navigation hygiene for the SSR listings. When FalcoSense is rendering
 * the grid and the URL is a non-canonical view (a filter, a sort, page 2+), tell
 * crawlers not to index it — the standard way to keep parameter URLs out of the
 * index without blocking crawl.
 *
 * Deliberately only sets robots, not canonical: Magento's own category canonical
 * (catalog/seo/category_canonical_tag) already points paged/filtered category
 * URLs at the clean category URL, and adding a second <link rel="canonical">
 * here would conflict with it. Enable that native setting for the canonical
 * half; this covers the noindex half (and the search-results page, which has no
 * native canonical).
 *
 * Runs on layout_generate_blocks_after so PageConfig changes still land in
 * <head>. Shares PlpContextProvider's memoised result — no extra platform call.
 */
class PlpSeoObserver implements ObserverInterface
{
    private const NEUTRAL_PARAMS = ['id', 'q', 'cat', '___store', '___from_store', 'SID'];

    public function __construct(
        private readonly PlpContextProvider $ctx,
        private readonly PageConfig $pageConfig,
        private readonly HttpRequest $request,
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->ctx->isActive()) {
            return;
        }

        $query = $this->ctx->getQuery();
        if ($query === null) {
            return;
        }

        if ($query->isCanonicalView() && !$this->hasExtraQueryParams()) {
            return; // clean canonical URL — leave Magento's own meta alone
        }

        $this->pageConfig->setRobots('NOINDEX,FOLLOW');
    }

    private function hasExtraQueryParams(): bool
    {
        foreach ($this->request->getParams() as $key => $value) {
            if (in_array($key, self::NEUTRAL_PARAMS, true)) {
                continue;
            }
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }
}
