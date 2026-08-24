<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Block\Widget;

use Ahy\SmartSearchLuma\Helper\Data;
use Ahy\SmartSearchLuma\Model\Widget\CardHtmlRenderer;
use Ahy\SmartSearchLuma\Service\SearchTokenService;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * The entire PHP-to-JS handoff for the Shadow DOM widget: one mount point
 * (<falcosense-root>) plus one config JSON payload, added via one additive
 * layout block in before.body.end — a container Magento's core framework owns,
 * present regardless of theme. Nothing else on the page is touched.
 *
 * Page type is read from the actual dispatched controller action
 * (getFullActionName()), not guessed from the URL — this is what lets one
 * mount point serve the header-search-everywhere case and the
 * category/search-results takeover case with the same markup.
 */
class Bootstrap extends Template
{
    private const SSR_SHELL_PAGE_SIZE = 24;

    public function __construct(
        Context $context,
        private readonly Data $helper,
        private readonly SearchTokenService $tokenService,
        private readonly LayerResolver $layerResolver,
        private readonly CardHtmlRenderer $cardRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function isActive(): bool
    {
        return $this->helper->isWidgetEnabled();
    }

    /**
     * Category browsing is deliberately never taken over by the widget (see
     * boot.js — mountTakeover only fires for pageType 'search'), so there is
     * no JS on category pages left to adopt this shell into a live, interactive
     * grid. Emitting it there would leave a real, server-rendered product grid
     * sitting inert in the page with nothing managing it. Disabled until/unless
     * category gets its own fail-open-compatible enhancement, at which point
     * this can be re-scoped rather than re-built.
     */
    public function isSsrShellActive(): bool
    {
        return false;
    }

    public function getSsrShellCss(): string
    {
        return CardHtmlRenderer::css();
    }

    public function getSsrShellCardsHtml(): string
    {
        if (!$this->isSsrShellActive()) {
            return '';
        }

        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
            if (!$category || !$category->getId()) {
                return '';
            }

            $collection = $category->getProductCollection();
            $collection->addAttributeToSelect(['name', 'price', 'small_image']);
            $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
            $collection->setPageSize(self::SSR_SHELL_PAGE_SIZE);
            $collection->setCurPage(1);

            return $this->cardRenderer->render($collection->getItems(), $this->getMediaBaseUrl());
        } catch (\Throwable $e) {
            // Falls back to an empty shell only — the widget's own JS-driven
            // takeover still activates normally afterward. This can only ever
            // affect what a crawler sees before JS runs, never the real
            // shopper's experience, which is why this is safe to swallow.
            return '';
        }
    }

    public function getConfigJson(): string
    {
        if (!$this->isActive()) {
            // Still emit a minimal, inert payload — boot.js checks `active`
            // itself and does nothing further if it's false. Never emit
            // nothing, so there's exactly one code path to reason about.
            return json_encode(['active' => false]);
        }

        $config = [
            'active' => true,
            'pageType' => $this->resolvePageType(),
            'productsApiUrl' => $this->buildPlatformUrl('/api/v1/products'),
            'suggestUrl' => $this->buildPlatformUrl('/api/v1/suggest'),
            'cartAddUrl' => $this->getUrl('smsl/cart/add'),
            'mediaBaseUrl' => $this->getMediaBaseUrl(),
            'searchToken' => $this->tokenService->getToken(0),
            'perPage' => $this->helper->getProductsPerPage(),
            // Explicit override for theme-sync.js's DOM-based auto-detection —
            // empty string means "let the frontend detect it," never a value
            // that itself needs validating here.
            'themeAccentColor' => $this->helper->getThemeAccentColor(),
        ];

        $pageType = $config['pageType'];
        if ($pageType === 'category') {
            $config['categoryName'] = $this->getCurrentCategoryName();
            $config['categoryEnhancementActive'] = $this->helper->isCategoryEnhancementEnabled();
            $config['nativeGridSelector'] = $this->helper->getCategoryGridSelector();
        } elseif ($pageType === 'search') {
            $config['searchQuery'] = (string) $this->getRequest()->getParam('q', '');
        }

        return json_encode($config, JSON_UNESCAPED_SLASHES);
    }

    private function resolvePageType(): string
    {
        // catalogsearch_result_index and fs_search_index render the identical
        // page (see view/frontend/layout/fs_search_index.xml) via two different
        // URLs — both must resolve to 'search' or the widget would silently
        // stop taking over the page for whichever URL isn't listed here.
        return match ($this->getRequest()->getFullActionName()) {
            'catalog_category_view' => 'category',
            'catalogsearch_result_index', 'fs_search_index' => 'search',
            default => 'other',
        };
    }

    private function getCurrentCategoryName(): string
    {
        try {
            $layer = $this->layerResolver->get();
            $category = $layer->getCurrentCategory();
            return (string) ($category ? $category->getName() : '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getMediaBaseUrl(): string
    {
        try {
            return rtrim(
                $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            ) . '/catalog/product';
        } catch (\Throwable $e) {
            return '/media/catalog/product';
        }
    }

    private function buildPlatformUrl(string $path): string
    {
        $endpoint = $this->helper->getEndpointUrl();
        if (!$endpoint) {
            return 'http://localhost:8080' . $path;
        }
        $parts = parse_url($endpoint);
        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        return $base . $path;
    }
}
