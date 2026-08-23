/**
 * Talks to the platform's search/products API. Kept deliberately dumb: builds a
 * URL from already-resolved params, fetches, returns parsed JSON or throws — no
 * retry/fallback logic here. Per the implementation plan §1, there is no
 * fallback state to degrade into; a failure here is surfaced as-is to whatever
 * called this (takeover.js / search-attach.js), which show their own explicit
 * error state rather than silently retrying forever or reverting to nothing.
 */

/**
 * @param {string} productsApiUrl - base URL, e.g. "https://platform.example/api/v1/products"
 * @param {{searchToken: string, query?: string, category?: string, page?: number, perPage?: number}} base
 * @param {Record<string, string>} extraParams - from filters-sort.js's buildApiParams()
 * @returns {Promise<Record<string, any>>}
 */
export async function fetchProducts(productsApiUrl, base, extraParams = {}) {
    const url = new URL(productsApiUrl);
    url.searchParams.set('search_token', base.searchToken);
    if (base.query) url.searchParams.set('q', base.query);
    if (base.category) url.searchParams.set('category', base.category);
    url.searchParams.set('page', String(base.page ?? 1));
    url.searchParams.set('per_page', String(base.perPage ?? 24));

    for (const [key, value] of Object.entries(extraParams)) {
        url.searchParams.set(key, value);
    }

    const response = await fetch(url.toString(), { credentials: 'omit' });

    if (!response.ok) {
        throw new Error(`Search request failed (HTTP ${response.status})`);
    }

    const data = await response.json();

    if (!data || data.success === false) {
        throw new Error((data && data.error) || 'Search request returned no data.');
    }

    return data;
}

/**
 * @param {string} suggestUrl
 * @param {string} searchToken
 * @param {string} query
 * @returns {Promise<Record<string, any>>}
 */
export async function fetchSuggestions(suggestUrl, searchToken, query) {
    const url = new URL(suggestUrl);
    url.searchParams.set('search_token', searchToken);
    url.searchParams.set('q', query || '');

    const response = await fetch(url.toString(), { credentials: 'omit' });
    if (!response.ok) {
        throw new Error(`Suggest request failed (HTTP ${response.status})`);
    }
    return response.json();
}
