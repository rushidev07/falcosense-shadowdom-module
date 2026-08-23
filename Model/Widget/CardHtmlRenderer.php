<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Widget;

use Magento\Catalog\Model\Product;
use Magento\Framework\Escaper;

/**
 * Server-side card markup, structurally identical to render-cards.js's
 * buildCardElement() (same CSS classes: fs-card, fs-card-img-wrap, fs-card-name,
 * fs-card-price) — this is what lets the Declarative Shadow DOM shell and the
 * client-side widget agree on one shape without sharing a runtime. This is a
 * deliberate, narrowly-scoped duplication: with no Node.js in this stack, a
 * PHP-only SSR path means writing the same rendering shape twice — kept small
 * on purpose (a handful of properties, not the full interactive card), so it
 * stays easy to keep in sync by inspection rather than needing shared tooling.
 */
class CardHtmlRenderer
{
    public function __construct(private readonly Escaper $escaper)
    {
    }

    /**
     * @param Product[] $products
     */
    public function render(iterable $products, string $mediaBaseUrl): string
    {
        $html = '';
        foreach ($products as $product) {
            $html .= $this->renderOne($product, $mediaBaseUrl);
        }
        return $html;
    }

    private function renderOne(Product $product, string $mediaBaseUrl): string
    {
        $name = $this->escaper->escapeHtml((string) $product->getName());
        $url = $this->escaper->escapeUrl((string) $product->getProductUrl());
        $image = $this->escaper->escapeUrl($this->resolveImage($product, $mediaBaseUrl));
        $price = $this->escaper->escapeHtml('$' . number_format((float) $product->getPrice(), 2));

        return '<li class="fs-card" data-product-id="' . (int) $product->getId() . '">'
            . '<a class="fs-card-img-wrap" href="' . $url . '">'
            . '<img src="' . $image . '" alt="' . $name . '" loading="lazy">'
            . '</a>'
            . '<div class="fs-card-name">' . $name . '</div>'
            . '<div class="fs-card-price">' . $price . '</div>'
            . '</li>';
    }

    private function resolveImage(Product $product, string $mediaBaseUrl): string
    {
        $image = (string) $product->getData('small_image');
        if (!$image || $image === 'no_selection') {
            return '/media/wysiwyg/no_selection.jpg';
        }
        return rtrim($mediaBaseUrl, '/') . '/' . ltrim($image, '/');
    }

    /** Shared with render-cards.js so both agree on exactly what "the shell" looks like. */
    public static function css(): string
    {
        return '.fs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;'
            . 'list-style:none;margin:0;padding:24px}'
            . '.fs-card{border:1px solid #f0f0f0;border-radius:8px;overflow:hidden;font-family:system-ui,sans-serif}'
            . '.fs-card-img-wrap{display:block;aspect-ratio:1;background:#f5f5f7}'
            . '.fs-card-img-wrap img{width:100%;height:100%;object-fit:cover;display:block}'
            . '.fs-card-name{padding:8px 10px 2px;font-size:13px;font-weight:600}'
            . '.fs-card-price{padding:0 10px 10px;font-size:13px}';
    }
}
