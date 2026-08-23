/**
 * Product data -> card markup. Image URL resolution and variant-option matching are
 * both broken out as their own pure, independently-testable functions on purpose —
 * both were real, confirmed failure points in the legacy code (images not loading;
 * the options drawer resolving the wrong/no variant), so they don't get to hide
 * inline inside a rendering function this time.
 */

const FALLBACK_IMAGE = '/media/wysiwyg/no_selection.jpg';

// Recognized size tokens for the SKU-pattern fallback below. Kept identical to the
// legacy list so behavior doesn't silently change for sites relying on it today.
const SIZE_TOKENS = new Set([
    'XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL',
    '28', '29', '30', '31', '32', '33', '34', '35', '36', '37', '38', '39',
    '40', '41', '42', '43', '44', '45', '46', '47', '48', '49', '50',
]);

/**
 * Resolves a product/variant image field into an absolute, loadable URL.
 * Handles three real shapes seen from the platform: an already-absolute URL
 * (CDN-served, possibly compressed), a Magento-relative media path, and a
 * missing/placeholder value — the last of which gets an explicit fallback
 * image rather than a silently broken <img>. This is the exact category of
 * bug (images not loading) confirmed as a real Everest failure, so it's
 * treated as its own tested unit rather than inline string-mashing.
 *
 * @param {string|null|undefined} image
 * @param {string} mediaBaseUrl - e.g. "https://store.example/media/catalog/product"
 * @returns {string}
 */
export function resolveImageUrl(image, mediaBaseUrl) {
    if (!image || image === 'no_selection' || image.trim() === '') {
        return FALLBACK_IMAGE;
    }
    if (/^https?:\/\//i.test(image)) {
        return image;
    }
    const path = image.startsWith('/') ? image : '/' + image;
    return mediaBaseUrl.replace(/\/+$/, '') + path;
}

/**
 * @param {HTMLImageElement} imgEl
 */
export function bindImageErrorFallback(imgEl) {
    imgEl.addEventListener('error', () => {
        if (imgEl.dataset.fsFallbackApplied) {
            return; // never loop if the fallback image itself 404s
        }
        imgEl.dataset.fsFallbackApplied = '1';
        imgEl.src = FALLBACK_IMAGE;
    });
}

/**
 * Extracts attribute-label -> distinct-values map for building swatch UI, e.g.
 * { Color: ['Black', 'White'], Size: ['S', 'M'] }.
 *
 * Prefers real, structured per-variant attribute data if the platform response
 * includes it (variant.attributes = { Color: 'Black', ... }) — this is the
 * correct shape and what every variant match should use going forward. Falls
 * back to parsing the variant SKU against the parent SKU only when structured
 * data isn't present, so sites aren't broken by a sync-pipeline response shape
 * this module doesn't control (the sync pipeline itself is out of scope for
 * this plan) — but the fallback is exactly the legacy heuristic, kept
 * quarantined here rather than duplicated across every screen like before.
 *
 * @param {{sku: string, variants?: Array<Record<string, any>>}} product
 * @returns {Record<string, string[]>}
 */
export function extractVariantAttributeMap(product) {
    const map = {};
    const variants = product.variants || [];

    for (const variant of variants) {
        const attrs = resolveVariantAttributes(variant, product.sku);
        for (const [label, value] of Object.entries(attrs)) {
            if (!value) continue;
            if (!map[label]) map[label] = [];
            if (!map[label].includes(value)) map[label].push(value);
        }
    }

    return map;
}

/**
 * @param {Record<string, any>} variant
 * @param {string} baseSku
 * @returns {Record<string, string>}
 */
function resolveVariantAttributes(variant, baseSku) {
    if (variant.attributes && typeof variant.attributes === 'object') {
        return variant.attributes;
    }
    return parseAttributesFromSku(variant.variant_sku || variant.sku || '', baseSku);
}

/**
 * Legacy fallback only — see extractVariantAttributeMap's docblock. Not the
 * primary path once the platform sends structured variant.attributes.
 * @param {string} variantSku
 * @param {string} baseSku
 * @returns {Record<string, string>}
 */
function parseAttributesFromSku(variantSku, baseSku) {
    if (!variantSku || !baseSku || variantSku.length <= baseSku.length) {
        return {};
    }
    const parts = variantSku.substring(baseSku.length + 1).split('-');
    let sizeIdx = -1;
    parts.forEach((p, i) => {
        if (SIZE_TOKENS.has(p.toUpperCase())) sizeIdx = i;
    });
    if (sizeIdx !== -1) {
        const color = parts.filter((_, i) => i !== sizeIdx).join('-');
        return { Size: parts[sizeIdx], ...(color ? { Color: color } : {}) };
    }
    return parts.join('-') ? { Color: parts.join('-') } : {};
}

/**
 * Finds the specific variant matching a shopper's full selection (e.g.
 * { Color: 'Black', Size: 'M' }), or null if the selection is incomplete or
 * doesn't match any real variant. This drives what the UI shows the shopper
 * (in stock / out of stock / disabled) — the actual cart-add correctness
 * check happens server-side in NativeVariantResolver regardless, this is
 * purely for a responsive options-drawer UI.
 *
 * @param {{sku: string, variants?: Array<Record<string, any>>}} product
 * @param {Record<string, string>} selectedOptions
 * @returns {Record<string, any>|null}
 */
export function matchVariantBySelection(product, selectedOptions) {
    const variants = product.variants || [];
    const attributeMap = extractVariantAttributeMap(product);
    const requiredAttributeCount = Object.keys(attributeMap).length;

    if (Object.keys(selectedOptions).length < requiredAttributeCount) {
        return null; // selection not complete yet
    }

    return variants.find((variant) => {
        const attrs = resolveVariantAttributes(variant, product.sku);
        return Object.entries(selectedOptions).every(([label, value]) => attrs[label] === value);
    }) || null;
}

/**
 * Builds a product card as a real DOM element (not an HTML string) so event
 * binding and image-error handling can attach directly, no re-querying needed.
 *
 * @param {Record<string, any>} product
 * @param {{mediaBaseUrl: string, formatPrice: (n:number)=>string}} ctx
 * @returns {HTMLElement}
 */
export function buildCardElement(product, ctx) {
    const li = document.createElement('li');
    li.className = 'fs-card';
    li.dataset.productId = String(product.product_id ?? product.id ?? '');

    const imgWrap = document.createElement('a');
    imgWrap.className = 'fs-card-img-wrap';
    imgWrap.href = product.url_key ? '/' + product.url_key + '.html' : '#';

    const img = document.createElement('img');
    img.loading = 'lazy';
    img.alt = product.name || '';
    img.src = resolveImageUrl(product.image, ctx.mediaBaseUrl);
    bindImageErrorFallback(img);
    imgWrap.appendChild(img);

    const name = document.createElement('div');
    name.className = 'fs-card-name';
    name.textContent = product.name || '';

    const price = document.createElement('div');
    price.className = 'fs-card-price';
    const hasSpecial = product.special_price && Number(product.special_price) < Number(product.price);
    if (hasSpecial) {
        const special = document.createElement('span');
        special.className = 'fs-price-special';
        special.textContent = ctx.formatPrice(product.special_price);
        const original = document.createElement('span');
        original.className = 'fs-price-original';
        original.textContent = ctx.formatPrice(product.price);
        price.append(special, original);
    } else if (product.price) {
        price.textContent = ctx.formatPrice(product.price);
    }

    li.append(imgWrap, name, price);
    return li;
}
