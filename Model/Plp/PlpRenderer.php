<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Framework\Escaper;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * The ONE renderer for the product grid — server-side, light DOM, from a
 * PlpResult. Its output is:
 *   - the crawlable grid in the initial page response (Block\Plp\Grid), and
 *   - the fragment returned by /smsl/plp/grid on every filter/sort/page change.
 *
 * Because both come from here, there is no second (JS) grid renderer to keep in
 * sync — the widget hydrates this markup and swaps this markup, it never rebuilds
 * cards itself. Markup is intentionally plain and FalcoSense-namespaced
 * (.fs-plp*), paired with a scoped reset in grid.phtml, so a host theme's CSS
 * can't meaningfully disturb it without being able to reach into it.
 *
 * The <script class="fs-plp-payload"> inside the wrapper is the exact PlpResult
 * the HTML was drawn from — the widget reads it to hydrate and to refresh the
 * (shadow-DOM) filter chrome, guaranteeing server markup and client state agree.
 */
class PlpRenderer
{
    public function __construct(
        private readonly Escaper $escaper,
        private readonly PriceCurrencyInterface $priceCurrency,
    ) {
    }

    /**
     * @param string $paginationBaseUrl current listing URL; {p} is replaced per page
     */
    public function render(PlpResult $result, PlpQuery $query, string $paginationBaseUrl): string
    {
        $out  = '<div class="fs-plp" data-fs-source="' . $this->escaper->escapeHtmlAttr($result->source) . '"'
            . ' data-fs-total="' . (int) $result->total . '"'
            . ' data-fs-page="' . (int) $result->page . '"'
            . ' data-fs-per-page="' . (int) $result->perPage . '">';

        $out .= '<script type="application/json" class="fs-plp-payload">'
            . $this->safeJson($this->payload($result, $query))
            . '</script>';

        $out .= $this->renderToolbar($result, $query, $paginationBaseUrl);
        $out .= $this->renderGrid($result, $query);
        $out .= $this->renderPagination($result, $paginationBaseUrl);

        $out .= '</div>';

        return $out;
    }

    /**
     * The embeddable payload: the result plus just enough context for the widget
     * to know what it's looking at and where to send fragment requests.
     */
    public function payload(PlpResult $result, PlpQuery $query): array
    {
        return [
            'context'      => $query->contextType,
            'categoryId'   => $query->categoryId,
            'categoryName' => $query->categoryName,
            'searchQuery'  => $query->searchQuery,
            'sort'         => $query->sort,
            'result'       => $result->toArray(),
        ];
    }

    private function renderToolbar(PlpResult $result, PlpQuery $query, string $baseUrl): string
    {
        $count = $result->total === 1
            ? __('1 item')
            : __('%1 items', $result->total);

        $html = '<div class="fs-plp-toolbar">';
        $html .= '<p class="fs-plp-count">' . $this->escaper->escapeHtml((string) $count) . '</p>';

        // Progressive-enhancement sort: a real GET form that works with no JS.
        // The widget replaces this with its own shadow-DOM sort control.
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

    private function renderGrid(PlpResult $result, PlpQuery $query): string
    {
        if ($result->items === []) {
            return '<p class="fs-plp-empty">' . $this->escaper->escapeHtml((string) __('No products to show.')) . '</p>';
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
        $url  = $this->escaper->escapeUrl($item->url);
        $name = $this->escaper->escapeHtml($item->name);

        $html = '<li class="fs-plp-card" data-product-id="' . (int) $item->productId . '"'
            . ' data-sku="' . $this->escaper->escapeHtmlAttr($item->sku) . '">';

        $html .= '<a class="fs-plp-card-media" href="' . $url . '">'
            . '<img src="' . $this->escaper->escapeUrl($item->imageUrl) . '" alt="' . $name . '"'
            . ' loading="lazy" decoding="async">'
            . '</a>';

        $html .= '<a class="fs-plp-card-title" href="' . $url . '">' . $name . '</a>';

        if ($item->brand !== null && $item->brand !== '') {
            $html .= '<p class="fs-plp-card-brand">' . $this->escaper->escapeHtml($item->brand) . '</p>';
        }

        $html .= $this->renderPrice($item, $storeId);

        if ($item->rating !== null) {
            $html .= '<p class="fs-plp-card-rating" aria-label="'
                . $this->escaper->escapeHtmlAttr((string) __('Rating: %1 out of 5', round($item->rating, 1))) . '">'
                . $this->escaper->escapeHtml((string) round($item->rating, 1))
                . ($item->ratingCount !== null ? ' (' . (int) $item->ratingCount . ')' : '')
                . '</p>';
        }

        if (!$item->inStock) {
            $html .= '<p class="fs-plp-card-stock">' . $this->escaper->escapeHtml((string) __('Out of stock')) . '</p>';
        }

        $html .= '</li>';

        return $html;
    }

    private function renderPrice(PlpItem $item, int $storeId): string
    {
        if ($item->price === null) {
            return '';
        }

        if ($item->hasDiscount()) {
            return '<p class="fs-plp-card-price fs-plp-card-price--sale">'
                . '<span class="fs-plp-price-now">' . $this->escaper->escapeHtml($this->money($item->specialPrice, $storeId)) . '</span> '
                . '<s class="fs-plp-price-was">' . $this->escaper->escapeHtml($this->money($item->price, $storeId)) . '</s>'
                . '</p>';
        }

        return '<p class="fs-plp-card-price">'
            . '<span class="fs-plp-price-now">' . $this->escaper->escapeHtml($this->money($item->price, $storeId)) . '</span>'
            . '</p>';
    }

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

    private function money(?float $amount, int $storeId): string
    {
        return $this->priceCurrency->format((float) $amount, false, PriceCurrencyInterface::DEFAULT_PRECISION, $storeId);
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
        // Default slash-escaping already turns "</script>" into "<\/script>";
        // JSON_HEX_TAG/AMP close the remaining "<!--" / bare "<" edge cases.
        // Quotes stay literal — this sits in <script type="application/json">,
        // which the browser does not parse as HTML.
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
        return $json !== false ? $json : '{}';
    }
}
