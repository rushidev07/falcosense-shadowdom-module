/**
 * The one add-to-cart call every surface shares — the search overlay, the category
 * page, the search results page, and the sliders (implementation plan §1's third
 * decision). This deliberately knows nothing about Magento internals: it sends an
 * opaque product id, the shopper's selected option labels, and a qty, to
 * POST /smsl/cart/add — everything else (resolving the real Magento entity,
 * building super_attribute, the actual cart write) happens server-side behind
 * VariantResolverInterface/CartAdapterInterface.
 */

/**
 * @param {string} cartAddUrl
 * @param {{productId: number|string, selectedOptions?: Record<string,string>, qty?: number, formKey: string}} params
 * @returns {Promise<{success: boolean, message: string}>}
 */
export async function addToCart(cartAddUrl, params) {
    const body = new URLSearchParams();
    body.set('platform_product_id', String(params.productId));
    body.set('qty', String(params.qty ?? 1));
    body.set('form_key', params.formKey);
    if (params.selectedOptions && Object.keys(params.selectedOptions).length) {
        body.set('selected_options', JSON.stringify(params.selectedOptions));
    }

    const response = await fetch(cartAddUrl, {
        method: 'POST',
        body,
        credentials: 'same-origin', // form_key is validated against the session — needs the session cookie
    });

    let data = null;
    try {
        data = await response.json();
    } catch (e) {
        // Fall through — data stays null, handled below.
    }

    if (!data) {
        return { success: false, message: 'Unable to add this item to your cart right now.' };
    }

    if (data.success) {
        announceCartUpdated();
    }

    return { success: !!data.success, message: data.message || '' };
}

/**
 * Announces a cart change to the rest of the page via a plain, explicit signal —
 * never by reaching into the theme's own cart-badge code directly. Covers both
 * conventions already present across Magento themes: legacy RequireJS-based
 * customer-data reload, and the plain CustomEvent modern themes (including Hyvä)
 * listen for.
 */
function announceCartUpdated() {
    if (typeof window.require === 'function') {
        window.require(['Magento_Customer/js/customer-data'], (customerData) => {
            customerData.reload(['cart'], true);
        });
    }
    window.dispatchEvent(new CustomEvent('reload-customer-section-data'));
}

/**
 * Reads the Magento form_key cookie every page already sets — the same value
 * native add-to-cart already relies on, read the same way the legacy code did.
 * @returns {string}
 */
export function readFormKey() {
    const match = document.cookie.match(/(?:^|; )form_key=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
