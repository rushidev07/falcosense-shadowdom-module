/**
 * Fetches a fresh platform search token from /smsl/search/token. Used by the
 * type-ahead overlay when a platform call comes back 401/403 — the token baked
 * into the (FPC-cached) page has expired. Everything else on the listing page
 * (fragment endpoint, add-to-cart) authenticates server-side and never needs
 * this.
 *
 * @param {string} url  config.tokenRefreshUrl
 * @returns {Promise<string>} a token, or '' on any failure
 */
export async function refreshSearchToken(url) {
    if (!url) {
        return '';
    }
    try {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) {
            return '';
        }
        const data = await response.json();
        return (data && data.token) || '';
    } catch (e) {
        return '';
    }
}
