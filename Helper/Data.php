<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Data extends AbstractHelper
{
    private const XML_PATH_FRONTEND_ENABLED  = 'smart_search/general/frontend_enabled';
    private const XML_PATH_WIDGET_ENABLED   = 'smart_search/general/widget_enabled';
    private const XML_PATH_SSR_SHELL_ENABLED = 'smart_search/general/ssr_shell_enabled';
    private const XML_PATH_CATEGORY_ENHANCEMENT_ENABLED = 'smart_search/general/category_enhancement_enabled';
    private const XML_PATH_CATEGORY_GRID_SELECTOR = 'smart_search/general/category_grid_selector';
    private const XML_PATH_THEME_ACCENT_COLOR = 'smart_search/general/theme_accent_color';
    private const XML_PATH_ENABLED          = 'smart_search/general/enabled';
    private const XML_PATH_REALTIME_ENABLED = 'smart_search/general/realtime_sync_enabled';

    private const XML_PATH_PLP_SSR_ENABLED   = 'smart_search/plp/ssr_enabled';
    private const XML_PATH_PLP_CACHE_TTL     = 'smart_search/plp/cache_ttl';
    private const XML_PATH_PLP_TIMEOUT_MS    = 'smart_search/plp/platform_timeout_ms';
    private const XML_PATH_PLP_FALLBACK_RATIO = 'smart_search/plp/fallback_min_ratio';
    private const XML_PATH_PLP_WARM_ENABLED   = 'smart_search/plp/warm_enabled';
    private const XML_PATH_PLP_WARM_LIMIT     = 'smart_search/plp/warm_limit';
    private const XML_PATH_ENDPOINT_URL     = 'smart_search/general/endpoint_url';
    private const XML_PATH_SEARCH_URL       = 'smart_search/general/search_url';
    private const XML_PATH_PRODUCTS_PER_PAGE = 'smart_search/general/products_per_page';
    private const XML_PATH_API_KEY          = 'smart_search/general/api_key';
    private const XML_PATH_PLATFORM_STORE_ID = 'smart_search/general/platform_store_id';
    private const XML_PATH_LAST_SYNC_AT     = 'smart_search/cron/last_sync_at';

    private const XML_PATH_NR_ENABLED         = 'smart_search/no_results_modal/enabled';
    private const XML_PATH_NR_TITLE           = 'smart_search/no_results_modal/title';
    private const XML_PATH_NR_SUBTITLE        = 'smart_search/no_results_modal/subtitle';
    private const XML_PATH_NR_HEADING         = 'smart_search/no_results_modal/section_heading';
    private const XML_PATH_NR_PRODUCT_COUNT   = 'smart_search/no_results_modal/product_count';
    private const XML_PATH_FULL_SYNC_FLAG   = 'smart_search/cron/full_sync_requested';

    private ScopeConfigInterface $config;
    private WriterInterface $configWriter;
    private StoreManagerInterface $storeManager;

    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config        = $scopeConfig;
        $this->configWriter  = $configWriter;
        $this->storeManager  = $storeManager;
    }

    public function isFrontendEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_FRONTEND_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Rollout-only flag distinguishing "the new Shadow DOM widget is the active
     * implementation" from "FalcoSense's frontend is enabled at all"
     * (isFrontendEnabled()). Lets each phase of the widget migration be verified in
     * isolation and rolled back instantly without touching the legacy path. Collapses
     * back to a single flag once the migration is complete (see the implementation
     * plan, Phase 4).
     */
    public function isWidgetEnabled(int|string|null $storeId = null): bool
    {
        return $this->isFrontendEnabled($storeId)
            && $this->config->isSetFlag(self::XML_PATH_WIDGET_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Declarative Shadow DOM shell: real, crawlable product markup rendered
     * PHP-side into the initial response, using Magento's own category product
     * collection (never a live platform round-trip — that would make the whole
     * page wait on the platform, exactly what the fundamentals guide warns
     * against). Independent of widget_enabled's rollout status but only ever
     * meaningful when the widget is also active, since it's the same mount
     * point the widget then enhances in place.
     */
    public function isSsrShellEnabled(int|string|null $storeId = null): bool
    {
        return $this->isWidgetEnabled($storeId)
            && $this->config->isSetFlag(self::XML_PATH_SSR_SHELL_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Fail-open category-page enhancement — deliberately a separate flag from
     * the search widget's own rollout (isWidgetEnabled), not a page-type branch
     * of the same switch. Category and search accept opposite trade-offs
     * (fail-open, always-safe vs. no-fallback), so their rollouts must be
     * independently verifiable and independently revertible: turning this off
     * must never affect search, and vice versa.
     */
    public function isCategoryEnhancementEnabled(int|string|null $storeId = null): bool
    {
        return $this->isWidgetEnabled($storeId)
            && $this->config->isSetFlag(self::XML_PATH_CATEGORY_ENHANCEMENT_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Unlike #search_mini_form (a confirmed, framework-wide Magento contract —
     * see search-attach.js), there is no equally universal selector for "the
     * native product grid": Luma, Hyvä, and heavily customized themes structure
     * this differently enough that one hardcoded guess isn't safe to promise.
     * Admin-configurable with a common Luma-family default; documented as
     * something to verify per theme, not assumed to just work everywhere.
     */
    public function getCategoryGridSelector(int|string|null $storeId = null): string
    {
        $value = (string) $this->config->getValue(self::XML_PATH_CATEGORY_GRID_SELECTOR, ScopeInterface::SCOPE_STORE, $storeId);
        return $value !== '' ? $value : '.products.wrapper';
    }

    /**
     * Explicit merchant override for the widget's accent color, taking
     * precedence over theme-sync.js's DOM-based auto-detection on the
     * frontend. Empty by default — auto-detection from the theme's own
     * primary-action color is the primary mechanism; this exists only as an
     * escape hatch for the rare theme where that heuristic picks the wrong
     * color.
     */
    public function getThemeAccentColor(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_THEME_ACCENT_COLOR, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isRealtimeSyncEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_REALTIME_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Master switch for server-side rendering of category/search listings from
     * the platform (the single-source + ISR path). Gated only on the frontend
     * being enabled — deliberately independent of widget_enabled, so the
     * crawlable server render can be turned on before the Shadow DOM hydration
     * layer is rolled out on a given store.
     */
    public function isPlpSsrEnabled(int|string|null $storeId = null): bool
    {
        return $this->isFrontendEnabled($storeId)
            && $this->config->isSetFlag(self::XML_PATH_PLP_SSR_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Blanket TTL (seconds) for a cached platform PLP response. This is the
     * safety-net freshness bound; event-driven purge (Phase B) handles the
     * changes that actually matter. 5-15 min is the sane range.
     */
    public function getPlpCacheTtl(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PLP_CACHE_TTL, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 600;
    }

    /**
     * Hard budget (ms) for the server-side platform call on the render path.
     * Exceed it and the request is abandoned in favour of the last-known-good
     * blob, then the native grid — a slow platform must never slow the page.
     */
    public function getPlpPlatformTimeoutMs(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PLP_TIMEOUT_MS, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 500;
    }

    /**
     * C1 coverage guard. If the platform returns fewer than (this ratio x the
     * category's native product count), assume the catalog isn't fully synced
     * and let the native grid render instead. 0 disables the guard (default).
     */
    public function getPlpFallbackMinRatio(int|string|null $storeId = null): float
    {
        $val = (float) $this->config->getValue(self::XML_PATH_PLP_FALLBACK_RATIO, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? min($val, 1.0) : 0.0;
    }

    /**
     * When on, a cron pre-fetches the top categories' snapshots so even the
     * first visitor after a TTL expiry gets an instant cache hit instead of
     * waiting on a live platform call.
     */
    public function isPlpWarmEnabled(int|string|null $storeId = null): bool
    {
        return $this->isPlpSsrEnabled($storeId)
            && $this->config->isSetFlag(self::XML_PATH_PLP_WARM_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getPlpWarmLimit(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PLP_WARM_LIMIT, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? min($val, 500) : 50;
    }

    public function getEndpointUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_ENDPOINT_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getSearchUrl(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_SEARCH_URL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getProductsPerPage(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_PRODUCTS_PER_PAGE, ScopeInterface::SCOPE_STORE, $storeId);
        return $val > 0 ? $val : 12;
    }

    public function getEventsEndpointUrl(int|string|null $storeId = null): string
    {
        $ingest = $this->getEndpointUrl($storeId);
        if (!$ingest) {
            return '';
        }
        $parts = parse_url($ingest);
        $base  = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . '/api/v1/events';
    }

    public function getApiKey(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_API_KEY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Magento store view -> platform tenant/store id.
     *
     * An explicit, stored mapping (per store view) wins — set it and the id is
     * stable regardless of how many store views exist. Only when nothing is
     * configured does it fall back to the legacy position-derived id, which
     * shifts if store views are added or removed (see fundamentals guide §11);
     * the config field exists specifically to retire that fragility.
     */
    public function getPlatformStoreId(int|string|null $storeId = null): int
    {
        if ($storeId === null) {
            $storeId = (int) $this->storeManager->getStore()->getId();
        }

        $explicit = (int) $this->config->getValue(
            self::XML_PATH_PLATFORM_STORE_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($explicit > 0) {
            return $explicit;
        }

        $storeIds = array_keys($this->storeManager->getStores(false));
        sort($storeIds);
        $position = array_search((int) $storeId, $storeIds, true);

        return $position !== false ? (int) $position + 1 : 1;
    }

    public function isNoResultsModalEnabled(int|string|null $storeId = null): bool
    {
        return $this->config->isSetFlag(self::XML_PATH_NR_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalTitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_TITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalSubtitle(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_SUBTITLE, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsModalHeading(int|string|null $storeId = null): string
    {
        return (string) $this->config->getValue(self::XML_PATH_NR_HEADING, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getNoResultsProductCount(int|string|null $storeId = null): int
    {
        $val = (int) $this->config->getValue(self::XML_PATH_NR_PRODUCT_COUNT, ScopeInterface::SCOPE_STORE, $storeId);
        return max(1, min(12, $val ?: 6));
    }


    public function getLastSyncAt(): ?string
    {
        $value = $this->config->getValue(self::XML_PATH_LAST_SYNC_AT, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        return ($value && $value !== '') ? (string) $value : null;
    }

    public function setLastSyncAt(string $datetime): void
    {
        $this->configWriter->save(self::XML_PATH_LAST_SYNC_AT, $datetime, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function isFullSyncRequested(): bool
    {
        return (bool) $this->config->getValue(self::XML_PATH_FULL_SYNC_FLAG, ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
    }

    public function requestFullSync(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 1, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }

    public function clearFullSyncFlag(): void
    {
        $this->configWriter->save(self::XML_PATH_FULL_SYNC_FLAG, 0, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0);
    }
}
