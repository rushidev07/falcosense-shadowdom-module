/**
 * Fetches a re-rendered product grid from /smsl/plp/grid. The endpoint runs the
 * exact same PHP renderer (PlpRenderer) the initial page did, so there is no
 * second grid renderer in JS — this returns HTML to swap in place, not data to
 * rebuild from.
 */

/**
 * @param {string} gridUrl  config.plpGridUrl
 * @param {{
 *   context: 'category'|'search',
 *   categoryId?: number|null,
 *   searchQuery?: string|null,
 *   page?: number,
 *   sort?: string,
 *   filters?: Record<string, string[]>,
 *   priceMin?: number|null,
 *   priceMax?: number|null,
 * }} state
 * @returns {Promise<string>} raw HTML for one <div class="fs-plp">
 */
export async function fetchFragment(gridUrl, state) {
    const url = new URL(gridUrl, window.location.origin);
    url.searchParams.set('context', state.context || 'category');
    if (state.categoryId) {
        url.searchParams.set('category_id', String(state.categoryId));
    }
    if (state.searchQuery) {
        url.searchParams.set('q', state.searchQuery);
    }
    url.searchParams.set('p', String(state.page || 1));
    url.searchParams.set('sort', state.sort || 'position');
    url.searchParams.set('base_url', window.location.origin + window.location.pathname);

    if (state.priceMin != null) {
        url.searchParams.set('price_min', String(state.priceMin));
    }
    if (state.priceMax != null) {
        url.searchParams.set('price_max', String(state.priceMax));
    }
    for (const [key, values] of Object.entries(state.filters || {})) {
        for (const value of values) {
            url.searchParams.append('filter[' + key + '][]', value);
        }
    }

    const response = await fetch(url.toString(), { credentials: 'omit' });
    if (!response.ok) {
        throw new Error('PLP fragment failed (HTTP ' + response.status + ')');
    }
    return response.text();
}

/**
 * Reads the <script class="fs-plp-payload"> JSON embedded in a .fs-plp element.
 * @param {Element} plpEl
 * @returns {Record<string, any>|null}
 */
export function readPlpPayload(plpEl) {
    const node = plpEl && plpEl.querySelector(':scope > .fs-plp-payload, .fs-plp-payload');
    if (!node) {
        return null;
    }
    try {
        return JSON.parse(node.textContent || '{}');
    } catch (e) {
        return null;
    }
}
