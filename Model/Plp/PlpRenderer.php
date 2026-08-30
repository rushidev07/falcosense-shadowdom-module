<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Framework\Escaper;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The ONE renderer for the listing page — server-side, light DOM, from a
 * PlpResult. Its output is:
 *   - the crawlable page in the initial response (Block\Plp\Grid), and
 *   - the fragment returned by /smsl/plp/grid on every filter/sort/page change.
 *
 * Both come from here, so there is no second (JS) renderer to keep in sync — the
 * widget hydrates this markup and swaps this markup, it never rebuilds cards.
 *
 * Layout mirrors the previous production experience: a persistent left filter
 * rail + a product grid, rich cards (wishlist, "Sold By", From/Deal pricing,
 * FREE Shipping, Add/Options). Everything is FalcoSense-namespaced (.fs-plp*)
 * and paired with a scoped reset in grid.phtml, so the host theme's CSS can't
 * disturb it and it can't leak out. Filters are light DOM (not shadow DOM) so
 * they're a normal, persistent sidebar exactly like the old build.
 *
 * The <script class="fs-plp-payload"> inside the wrapper is the exact PlpResult
 * the HTML was drawn from — the widget reads it to hydrate and to refresh the
 * sidebar, guaranteeing server markup and client state agree.
 */
class PlpRenderer
{
    /** Fixed price bands, matching the old production sidebar. */
    private const PRICE_BUCKETS = [
        ['label' => 'Under $50',       'min' => null, 'max' => 50.0],
        ['label' => '$50 – $100',      'min' => 50.0,  'max' => 100.0],
        ['label' => '$100 – $250',     'min' => 100.0, 'max' => 250.0],
        ['label' => '$250 – $500',     'min' => 250.0, 'max' => 500.0],
        ['label' => '$500 – $1,000',   'min' => 500.0, 'max' => 1000.0],
    ];

    private const OPTIONS_VISIBLE = 5;

    public function __construct(
        private readonly Escaper $escaper,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {
    }

    public function render(PlpResult $result, PlpQuery $query, string $paginationBaseUrl): string
    {
        $out  = '<div class="fs-plp" data-fs-source="' . $this->escaper->escapeHtmlAttr($result->source) . '"'
            . ' data-fs-total="' . (int) $result->total . '"'
            . ' data-fs-page="' . (int) $result->page . '"'
            . ' data-fs-per-page="' . (int) $result->perPage . '">';

        $out .= '<script type="application/json" class="fs-plp-payload">'
            . $this->safeJson($this->payload($result, $query))
            . '</script>';

        $out .= '<div class="fs-plp-layout">';
        $out .= $this->renderSidebar($result, $query);
        $out .= '<div class="fs-plp-main">';
        $out .= $this->renderToolbar($result, $query, $paginationBaseUrl);
        $out .= $this->renderGrid($result, $query);
        $out .= $this->renderPagination($result, $paginationBaseUrl);
        $out .= '</div>'; // .fs-plp-main
        $out .= '</div>'; // .fs-plp-layout

        $out .= '</div>'; // .fs-plp

        return $out;
    }

    public function payload(PlpResult $result, PlpQuery $query): array
    {
        return [
            'context'      => $query->contextType,
            'categoryId'   => $query->categoryId,
            'categoryName' => $query->categoryName,
            'searchQuery'  => $query->searchQuery,
            'sort'         => $query->sort,
            'filters'      => $query->filters,
            'priceMin'     => $query->priceMin,
            'priceMax'     => $query->priceMax,
            'result'       => $result->toArray(),
        ];
    }

    // ── Sidebar ──────────────────────────────────────────────────────────────

    private function renderSidebar(PlpResult $result, PlpQuery $query): string
    {
        $count = $result->total === 1 ? __('1 Item') : __('%1 Items', $result->total);

        $html  = '<aside class="fs-plp-sidebar">';
        $html .= '<p class="fs-plp-sidebar-count">(' . $this->escaper->escapeHtml((string) $count) . ')</p>';
        $html .= $this->renderActiveChips($query);
        $html .= '<div class="fs-plp-filters">';
        $html .= $this->renderPriceGroup($query);
        foreach ($result->facets as $facet) {
            $html .= $this->renderFacetGroup($facet, $query);
        }
        $html .= '</div>';
        $html .= '</aside>';

        return $html;
    }

    private function renderActiveChips(PlpQuery $query): string
    {
        $chips = [];
        foreach ($query->filters as $key => $values) {
            foreach ((array) $values as $value) {
                $chips[] = '<button type="button" class="fs-plp-chip" data-fs-filter-key="'
                    . $this->escaper->escapeHtmlAttr((string) $key) . '" data-fs-filter-value="'
                    . $this->escaper->escapeHtmlAttr((string) $value) . '">'
                    . $this->escaper->escapeHtml((string) $value) . ' <span aria-hidden="true">&times;</span></button>';
            }
        }
        if ($query->priceMin !== null || $query->priceMax !== null) {
            $chips[] = '<button type="button" class="fs-plp-chip" data-fs-price-clear="1">'
                . $this->escaper->escapeHtml($this->priceLabel($query->priceMin, $query->priceMax))
                . ' <span aria-hidden="true">&times;</span></button>';
        }

        if ($chips === []) {
            return '';
        }

        return '<div class="fs-plp-active">'
            . '<div class="fs-plp-active-head"><span>' . $this->escaper->escapeHtml((string) __('Filters')) . '</span>'
            . '<button type="button" class="fs-plp-clear-all">' . $this->escaper->escapeHtml((string) __('Clear all')) . '</button></div>'
            . '<div class="fs-plp-chips">' . implode('', $chips) . '</div></div>';
    }

    private function renderPriceGroup(PlpQuery $query): string
    {
        $html  = '<section class="fs-plp-fgroup" data-fs-group="price">';
        $html .= $this->groupHead((string) __('Price'));
        $html .= '<ul class="fs-plp-fopts">';
        foreach (self::PRICE_BUCKETS as $b) {
            $active = $this->priceBucketActive($b, $query);
            $html .= '<li><label class="fs-plp-fopt' . ($active ? ' is-active' : '') . '">'
                . '<input type="checkbox" class="fs-plp-price-check"' . ($active ? ' checked' : '')
                . ' data-fs-price-min="' . ($b['min'] !== null ? (float) $b['min'] : '') . '"'
                . ' data-fs-price-max="' . ($b['max'] !== null ? (float) $b['max'] : '') . '">'
                . '<span class="fs-plp-fopt-label">' . $this->escaper->escapeHtml((string) $b['label']) . '</span>'
                . '</label></li>';
        }
        $html .= '</ul></section>';

        return $html;
    }

    private function renderFacetGroup(PlpFacet $facet, PlpQuery $query): string
    {
        if ($facet->options === []) {
            return '';
        }

        $selected = array_map('strval', (array) ($query->filters[$facet->key] ?? []));
        $total    = count($facet->options);

        $html  = '<section class="fs-plp-fgroup" data-fs-group="' . $this->escaper->escapeHtmlAttr($facet->key) . '">';
        $html .= $this->groupHead($facet->label !== '' ? $facet->label : $facet->key);
        $html .= '<ul class="fs-plp-fopts">';

        foreach ($facet->options as $i => $opt) {
            $value  = (string) ($opt['value'] ?? '');
            $label  = (string) ($opt['label'] ?? $value);
            $active = in_array($value, $selected, true);
            $hidden = $i >= self::OPTIONS_VISIBLE ? ' hidden' : '';

            $html .= '<li class="fs-plp-fopt-li' . $hidden . '">'
                . '<label class="fs-plp-fopt' . ($active ? ' is-active' : '') . '">'
                . '<input type="checkbox" class="fs-plp-facet-check"' . ($active ? ' checked' : '')
                . ' data-fs-filter-key="' . $this->escaper->escapeHtmlAttr($facet->key) . '"'
                . ' data-fs-filter-value="' . $this->escaper->escapeHtmlAttr($value) . '">'
                . '<span class="fs-plp-fopt-label">' . $this->escaper->escapeHtml($label) . '</span>';
            if (isset($opt['count']) && $opt['count'] !== null) {
                $html .= '<span class="fs-plp-fopt-count">(' . (int) $opt['count'] . ')</span>';
            }
            $html .= '</label></li>';
        }

        if ($total > self::OPTIONS_VISIBLE) {
            $html .= '<li><button type="button" class="fs-plp-more">'
                . $this->escaper->escapeHtml((string) __('See More +')) . '</button></li>';
        }

        $html .= '</ul></section>';

        return $html;
    }

    private function groupHead(string $label): string
    {
        return '<button type="button" class="fs-plp-fhead">'
            . '<span>' . $this->escaper->escapeHtml($label) . '</span>'
            . '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 9l-7 7-7-7"/></svg>'
            . '</button>';
    }

    // ── Toolbar ──────────────────────────────────────────────────────────────

    private function renderToolbar(PlpResult $result, PlpQuery $query, string $baseUrl): string
    {
        $html  = '<div class="fs-plp-toolbar">';
        $html .= '<button type="button" class="fs-plp-filter-toggle">' . $this->escaper->escapeHtml((string) __('Filters')) . '</button>';
        $html .= '<form class="fs-plp-sort" method="get" action="' . $this->escaper->escapeUrl($this->stripParam($baseUrl, 'p')) . '">';
        $html .= $this->hiddenParamsExcept($baseUrl, ['product_list_order', 'p']);
        $html .= '<label class="fs-plp-sort-label">' . $this->escaper->escapeHtml((string) __('Sort by')) . ' ';
        $html .= '<select name="product_list_order" onchange="this.form.submit()">';
        foreach ($this->sortOptions() as $value => $label) {
            $selected = $query->sort === $value ? ' selected' : '';
            $html .= '<option value="' . $this->escaper->escapeHtmlAttr($value) . '"' . $selected . '>'
                . $this->escaper->escapeHtml((string) $label) . '</option>';
        }
        $html .= '</select></label></form>';
        $html .= '</div>';

        return $html;
    }

    // ── Grid + cards ─────────────────────────────────────────────────────────

    private function renderGrid(PlpResult $result, PlpQuery $query): string
    {
        if ($result->items === []) {
            return '<div class="fs-plp-empty">'
                . '<p>' . $this->escaper->escapeHtml((string) __('No products match your search.')) . '</p>'
                . '</div>';
        }

        $html = '<ol class="fs-plp-grid">';
        foreach ($result->items as $item) {
            $html .= $this->renderCard($item, $query->storeId);
        }
        $html .= '</ol>';

        return $html;
    }

    private function renderCard(PlpItem $item, int $storeId): string
    {
        $url         = $this->escaper->escapeUrl($item->url);
        $name        = $this->escaper->escapeHtml($item->name);
        $hasVariants = $item->swatches !== [];
        $seller      = $item->brand !== null && $item->brand !== '' ? $item->brand : (string) __('The Everest Marketplace');

        $html  = '<li class="fs-plp-card" data-product-id="' . (int) $item->productId . '"'
            . ' data-sku="' . $this->escaper->escapeHtmlAttr($item->sku) . '"'
            . ' data-type="' . ($hasVariants ? 'configurable' : 'simple') . '">';

        $html .= '<button type="button" class="fs-plp-card-wish" aria-label="' . $this->escaper->escapeHtmlAttr((string) __('Add to Wish List')) . '">'
            . '<svg viewBox="0 0 15.91 20" width="18" height="18" fill="currentColor" aria-hidden="true">'
            . '<path d="M14.41,1.5v16L9.27,12.71a1.62,1.62,0,0,0-2.19,0L1.5,17.58V1.5H14.41M14.76,0H1.15A1.16,1.16,0,0,0,0,1.15v18.1a.74.74,0,0,0,1.24.56l6.83-6a.12.12,0,0,1,.18,0l6.38,5.9a.74.74,0,0,0,.52.21.76.76,0,0,0,.76-.77v-18A1.15,1.15,0,0,0,14.76,0Z"/></svg>'
            . '</button>';

        $html .= '<a class="fs-plp-card-media" href="' . $url . '">'
            . '<img src="' . $this->escaper->escapeUrl($item->imageUrl) . '" alt="' . $name . '" loading="lazy" decoding="async">'
            . '</a>';

        $html .= '<a class="fs-plp-card-title" href="' . $url . '">' . $name . '</a>';
        $html .= '<p class="fs-plp-card-seller">' . $this->escaper->escapeHtml((string) __('Sold By %1', $seller)) . '</p>';

        $html .= $this->renderPrice($item, $storeId, $hasVariants);

        $html .= '<div class="fs-plp-card-foot">';
        $html .= '<span class="fs-plp-card-ship">' . $this->escaper->escapeHtml((string) __('FREE Shipping')) . '</span>';
        $label = $hasVariants ? __('Options') : __('Add');
        $html .= '<button type="button" class="fs-plp-card-add" data-type="' . ($hasVariants ? 'configurable' : 'simple') . '"'
            . ($item->inStock ? '' : ' disabled') . '>'
            . $this->escaper->escapeHtml((string) ($item->inStock ? $label : __('Out of stock')))
            . '</button>';
        $html .= '</div>';

        $html .= '</li>';

        return $html;
    }

    private function renderPrice(PlpItem $item, int $storeId, bool $hasVariants): string
    {
        if ($item->price === null) {
            return '<p class="fs-plp-card-price fs-plp-card-price--none">'
                . $this->escaper->escapeHtml((string) __('See Options')) . '</p>';
        }

        if ($item->hasDiscount()) {
            return '<p class="fs-plp-card-price fs-plp-card-price--sale">'
                . '<span class="fs-plp-price-flag">' . $this->escaper->escapeHtml((string) __('Deal')) . '</span>'
                . '<span class="fs-plp-price-now">' . $this->escaper->escapeHtml($this->money($item->specialPrice, $storeId)) . '</span>'
                . '<s class="fs-plp-price-was">' . $this->escaper->escapeHtml($this->money($item->price, $storeId)) . '</s>'
                . '</p>';
        }

        return '<p class="fs-plp-card-price">'
            . ($hasVariants ? '<span class="fs-plp-price-flag">' . $this->escaper->escapeHtml((string) __('From')) . '</span>' : '')
            . '<span class="fs-plp-price-now">' . $this->escaper->escapeHtml($this->money($item->price, $storeId)) . '</span>'
            . '</p>';
    }

    // ── Pagination ───────────────────────────────────────────────────────────

    private function renderPagination(PlpResult $result, string $baseUrl): string
    {
        $totalPages = $result->totalPages();
        if ($totalPages <= 1) {
            return '';
        }

        $current = max(1, $result->page);
        $html = '<nav class="fs-plp-pagination" aria-label="' . $this->escaper->escapeHtmlAttr((string) __('Pagination')) . '">';

        if ($current > 1) {
            $html .= '<a class="fs-plp-page fs-plp-page--prev" rel="prev" href="'
                . $this->escaper->escapeUrl($this->pageUrl($baseUrl, $current - 1)) . '">'
                . $this->escaper->escapeHtml((string) __('Previous')) . '</a>';
        }

        $html .= '<span class="fs-plp-page-status">'
            . $this->escaper->escapeHtml((string) __('Page %1 of %2', $current, $totalPages)) . '</span>';

        if ($current < $totalPages) {
            $html .= '<a class="fs-plp-page fs-plp-page--next" rel="next" href="'
                . $this->escaper->escapeUrl($this->pageUrl($baseUrl, $current + 1)) . '">'
                . $this->escaper->escapeHtml((string) __('Next')) . '</a>';
        }

        $html .= '</nav>';

        return $html;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, \Magento\Framework\Phrase>
     */
    private function sortOptions(): array
    {
        return [
            'position'   => __('Relevance'),
            'price_asc'  => __('Price: Low to High'),
            'price_desc' => __('Price: High to Low'),
            'name'       => __('Name'),
        ];
    }

    private function priceBucketActive(array $bucket, PlpQuery $query): bool
    {
        $min = $bucket['min'] !== null ? (float) $bucket['min'] : null;
        $max = $bucket['max'] !== null ? (float) $bucket['max'] : null;

        return $query->priceMin === $min && $query->priceMax === $max;
    }

    private function priceLabel(?float $min, ?float $max): string
    {
        if ($min === null && $max !== null) {
            return (string) __('Under %1', $this->money($max, 0));
        }
        if ($min !== null && $max === null) {
            return (string) __('%1+', $this->money($min, 0));
        }

        return $this->money($min, 0) . ' – ' . $this->money($max, 0);
    }

    private function money(?float $amount, int $storeId): string
    {
        return $this->priceCurrency->format((float) $amount, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId ?: null);
    }

    private function pageUrl(string $baseUrl, int $page): string
    {
        $baseUrl = $this->stripParam($baseUrl, 'p');
        if ($page <= 1) {
            return $baseUrl;
        }
        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . 'p=' . $page;
    }

    private function stripParam(string $url, string $param): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) === 1) {
            return $url;
        }
        parse_str($parts[1], $q);
        unset($q[$param]);
        $qs = http_build_query($q);
        return $qs === '' ? $parts[0] : $parts[0] . '?' . $qs;
    }

    private function hiddenParamsExcept(string $url, array $exclude): string
    {
        $parts = explode('?', $url, 2);
        if (count($parts) === 1) {
            return '';
        }
        parse_str($parts[1], $q);

        $html = '';
        foreach ($q as $key => $value) {
            if (in_array($key, $exclude, true) || is_array($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . $this->escaper->escapeHtmlAttr((string) $key) . '"'
                . ' value="' . $this->escaper->escapeHtmlAttr((string) $value) . '">';
        }
        return $html;
    }

    private function safeJson(array $data): string
    {
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
        return $json !== false ? $json : '{}';
    }
}
