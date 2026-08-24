import { getOrCreateShadowRoot } from './shadow-root.js';
import { fetchProducts } from './fetch-results.js';
import { createInitialFilterState, buildApiParams } from './filters-sort.js';
import { renderProductGrid, attachAddToCartHandler } from './product-grid.js';
import { BASE_CSS } from './styles.js';

const CATEGORY_CSS = `
${BASE_CSS}
:host { display: block; width: 100%; }
`;

/**
 * Fail-open category-page enhancement — the deliberate opposite of the
 * search-results takeover in takeover.js. Fetches FalcoSense's ranked
 * results and facets silently in the background; the native grid Magento
 * already rendered (never removed at the layout level while the widget is
 * active — see Observer\AddFrontendLayoutHandle's guard) is only ever hidden
 * once real, usable data has actually arrived. On any failure — network
 * error, platform down, an empty result set, or simply no known place to
 * mount into on this theme — this does nothing at all: no error state, no
 * loading flicker, no partial UI. Category/PLP browsing must never become
 * dependent on FalcoSense's uptime; only the search-results page accepts
 * that trade-off.
 *
 * @param {HTMLElement} hostEl
 * @param {{
 *   productsApiUrl: string,
 *   cartAddUrl: string,
 *   mediaBaseUrl: string,
 *   searchToken: string,
 *   categoryName: string,
 *   perPage: number,
 *   nativeGridSelector: string,
 *   themeAccentColor?: string,
 * }} config
 */
export function mountCategoryEnhancement(hostEl, config) {
    let filterState = createInitialFilterState();
    let shell = null;

    /**
     * @param {{data: Array, facets?: Array, pagination?: {total: number}}} data
     */
    function activate(data) {
        if (!shell) {
            const nativeGrid = document.querySelector(config.nativeGridSelector);
            if (!nativeGrid) {
                // No known place to enhance on this theme — leave the page
                // exactly as it is rather than guessing at a different
                // insertion point. See Helper\Data::getCategoryGridSelector's
                // docblock: unlike the search input, there is no single
                // selector guaranteed across every theme.
                return;
            }
            // Additive at runtime, not at the layout level: a plain inline
            // style toggle, trivially reversible (and never applied at all
            // if FalcoSense never has anything real to show), never a
            // layout XML removal.
            nativeGrid.style.display = 'none';

            const root = getOrCreateShadowRoot(hostEl, CATEGORY_CSS, { accentColor: config.themeAccentColor });
            shell = document.createElement('div');
            shell.className = 'fs-category-shell';
            root.appendChild(shell);
            attachAddToCartHandler(shell, config);
        }

        renderProductGrid(shell, data, config, filterState, (nextState) => {
            filterState = nextState;
            load();
        });
    }

    async function load() {
        try {
            const base = {
                searchToken: config.searchToken,
                page: filterState.page,
                perPage: config.perPage,
                category: config.categoryName,
            };
            const data = await fetchProducts(config.productsApiUrl, base, buildApiParams(filterState));
            if (!data || !(data.data || []).length) {
                return; // nothing usable — the untouched native grid remains the result
            }
            activate(data);
        } catch (err) {
            // Deliberately silent — see the module docblock above. Category
            // browsing keeps working exactly as it did before this ever ran.
        }
    }

    load();
}
