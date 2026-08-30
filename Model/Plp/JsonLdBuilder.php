<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Structured data for the listing, built from the SAME PlpResult that produced
 * the visible grid — so the price/availability in the JSON-LD can never disagree
 * with the price/availability a shopper (or an answer engine) sees. That parity
 * is the whole reason this exists rather than reusing Magento's own catalog
 * structured data, which reads from the DB.
 *
 * Emits ItemList (+ per-item Product/Offer) and, for category pages,
 * BreadcrumbList. Only rendered into the canonical view — paged/filtered views
 * are noindex, so structured data there would be noise.
 */
class JsonLdBuilder
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, array{name: string, url: string}> $breadcrumbs
     * @return string one or more <script type="application/ld+json"> blocks, or ''
     */
    public function build(PlpResult $result, PlpQuery $query, array $breadcrumbs = []): string
    {
        if (!$result->isUsable()) {
            return '';
        }

        $blocks = [$this->itemList($result, $query)];

        if ($breadcrumbs !== []) {
            $blocks[] = $this->breadcrumbList($breadcrumbs);
        }

        $out = '';
        foreach ($blocks as $block) {
            $json = json_encode($block, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
            if ($json !== false) {
                $out .= '<script type="application/ld+json">' . $json . '</script>';
            }
        }

        return $out;
    }

    private function itemList(PlpResult $result, PlpQuery $query): array
    {
        $currency = $this->currencyCode($query->storeId);
        $offset   = ($result->page - 1) * max(1, $result->perPage);

        $elements = [];
        foreach ($result->items as $i => $item) {
            $product = [
                '@type' => 'Product',
                'name'  => $item->name,
                'url'   => $item->url,
            ];

            if ($item->imageUrl !== '') {
                $product['image'] = $item->imageUrl;
            }
            if ($item->sku !== '') {
                $product['sku'] = $item->sku;
            }
            if ($item->brand !== null && $item->brand !== '') {
                $product['brand'] = ['@type' => 'Brand', 'name' => $item->brand];
            }
            if ($item->rating !== null && $item->ratingCount !== null && $item->ratingCount > 0) {
                $product['aggregateRating'] = [
                    '@type'       => 'AggregateRating',
                    'ratingValue' => round($item->rating, 1),
                    'reviewCount' => $item->ratingCount,
                ];
            }

            $price = $item->effectivePrice();
            if ($price !== null) {
                $product['offers'] = [
                    '@type'         => 'Offer',
                    'price'         => number_format($price, 2, '.', ''),
                    'priceCurrency' => $currency,
                    'availability'  => $item->inStock
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                    'url'           => $item->url,
                ];
            }

            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $offset + $i + 1,
                'item'     => $product,
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'numberOfItems'   => $result->total,
            'itemListElement' => $elements,
        ];
    }

    /**
     * @param array<int, array{name: string, url: string}> $breadcrumbs
     */
    private function breadcrumbList(array $breadcrumbs): array
    {
        $elements = [];
        foreach (array_values($breadcrumbs) as $i => $crumb) {
            $elements[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'name'     => $crumb['name'],
                'item'     => $crumb['url'],
            ];
        }

        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }

    private function currencyCode(int $storeId): string
    {
        try {
            return (string) $this->storeManager->getStore($storeId)->getCurrentCurrencyCode();
        } catch (\Throwable $e) {
            $this->logger->warning('[SmartSearchLuma][PLP] Currency code lookup failed: ' . $e->getMessage());
            return 'USD';
        }
    }
}
