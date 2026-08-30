<?php
declare(strict_types=1);

namespace Ahy\SmartSearchLuma\Model\Plp;

/**
 * One product card's data, exactly as it will be shown — resolved and absolute,
 * no further lookups needed by the renderer or the client. Every field here
 * came from the *one* platform response for this listing, so the server-rendered
 * card, the JSON-LD entry, and the client's hydrated card are guaranteed to
 * agree (that agreement is the whole point of the rebuild).
 *
 * Prices are raw numbers in the store's currency; formatting is the renderer's
 * job so currency/locale stay a presentation concern.
 */
final class PlpItem
{
    /**
     * @param string[]                       $badges  e.g. ['sale', 'new']
     * @param array<int, array<string,mixed>> $swatches opaque per-variant data passed straight to the widget for hydration
     */
    public function __construct(
        public readonly int $productId,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $url,
        public readonly string $imageUrl,
        public readonly ?float $price,
        public readonly ?float $specialPrice = null,
        public readonly bool $inStock = true,
        public readonly ?string $brand = null,
        public readonly ?float $rating = null,
        public readonly ?int $ratingCount = null,
        public readonly array $badges = [],
        public readonly array $swatches = [],
    ) {
    }

    public function effectivePrice(): ?float
    {
        if ($this->specialPrice !== null && $this->price !== null && $this->specialPrice < $this->price) {
            return $this->specialPrice;
        }

        return $this->price;
    }

    public function hasDiscount(): bool
    {
        return $this->specialPrice !== null
            && $this->price !== null
            && $this->specialPrice < $this->price;
    }

    public function toArray(): array
    {
        return [
            'product_id'    => $this->productId,
            'sku'           => $this->sku,
            'name'          => $this->name,
            'url'           => $this->url,
            'image'         => $this->imageUrl,
            'price'         => $this->price,
            'special_price' => $this->specialPrice,
            'in_stock'      => $this->inStock,
            'brand'         => $this->brand,
            'rating'        => $this->rating,
            'rating_count'  => $this->ratingCount,
            'badges'        => $this->badges,
            'swatches'      => $this->swatches,
        ];
    }

    public static function fromArray(array $d): self
    {
        return new self(
            productId: (int) ($d['product_id'] ?? $d['id'] ?? 0),
            sku: (string) ($d['sku'] ?? ''),
            name: (string) ($d['name'] ?? ''),
            url: (string) ($d['url'] ?? ''),
            imageUrl: (string) ($d['image'] ?? $d['image_url'] ?? ''),
            price: isset($d['price']) && $d['price'] !== null ? (float) $d['price'] : null,
            specialPrice: isset($d['special_price']) && $d['special_price'] !== null ? (float) $d['special_price'] : null,
            inStock: (bool) ($d['in_stock'] ?? true),
            brand: isset($d['brand']) && $d['brand'] !== '' ? (string) $d['brand'] : null,
            rating: isset($d['rating']) && $d['rating'] !== null ? (float) $d['rating'] : null,
            ratingCount: isset($d['rating_count']) && $d['rating_count'] !== null ? (int) $d['rating_count'] : null,
            badges: array_values(array_map('strval', (array) ($d['badges'] ?? []))),
            swatches: is_array($d['swatches'] ?? null) ? $d['swatches'] : [],
        );
    }
}
