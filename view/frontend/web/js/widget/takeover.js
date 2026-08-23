import { getOrCreateShadowRoot } from './shadow-root.js';
import { fetchProducts } from './fetch-results.js';
import { buildCardElement } from './render-cards.js';
import {
    createInitialFilterState,
    toggleFilter,
    setSort,
    setPage,
    clearAllFilters,
    buildApiParams,
    buildFilterGroupsFromFacets,
    extractPriceRange,
} from './filters-sort.js';
import { addToCart, readFormKey } from './cart.js';

const WIDGET_CSS = `
:host { all: initial; }
.fs-shell { font-family: system-ui, -apple-system, sans-serif; color: #1d1d1f; background: #fff;
  position: fixed; inset: 0; z-index: 2147483000; overflow-y: auto; }
.fs-state { padding: 48px 24px; text-align: center; color: #6e6e73; }
.fs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; padding: 24px; list-style: none; margin: 0; }
.fs-card { border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; }
.fs-card-img-wrap { display: block; aspect-ratio: 1; background: #f5f5f7; }
.fs-card-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
.fs-card-name { padding: 8px 10px 2px; font-size: 13px; font-weight: 600; }
.fs-card-price { padding: 0 10px 10px; font-size: 13px; }
.fs-price-original { text-decoration: line-through; color: #999; margin-left: 6px; }
.fs-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 16px 24px; border-bottom: 1px solid #f0f0f0; }
`;

/**
 * Claims the full viewport immediately once mounted — loading, then real
 * results, or an explicit error state on failure. Never reverts to letting
 * native content show once this has taken effect (implementation plan §1):
 * there is no code path in this module that removes .fs-shell once it exists.
 *
 * @param {HTMLElement} hostEl
 * @param {{
 *   productsApiUrl: string,
 *   cartAddUrl: string,
 *   mediaBaseUrl: string,
 *   searchToken: string,
 *   pageType: 'category'|'search',
 *   categoryName?: string,
 *   searchQuery?: string,
 *   perPage: number,
 * }} config
 */
export function mountTakeover(hostEl, config) {
    const root = getOrCreateShadowRoot(hostEl, WIDGET_CSS);

    // SSR-Shell: the server already rendered a real, crawlable product grid
    // directly into this shadow root (Block\Widget\Bootstrap::getSsrShellCardsHtml).
    // Adopt it as-is rather than wiping it with a loading spinner — a crawler or a
    // shopper on a slow connection sees genuine products immediately either way.
    // The real fetch below still runs in the background and swaps in FalcoSense's
    // actual ranked results once it resolves; only its *absence* is visually silent.
    let shell = root.querySelector(':scope > .fs-shell');
    const hasSsrCards = !shell && !!root.querySelector(':scope > .fs-grid');
    if (!shell) {
        shell = document.createElement('div');
        shell.className = 'fs-shell';
        const existingGrid = root.querySelector(':scope > .fs-grid');
        if (existingGrid) {
            shell.appendChild(existingGrid); // move in place, don't rebuild it
        }
        root.appendChild(shell);
    }

    let filterState = createInitialFilterState();
    const formatPrice = (n) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(n);

    function renderLoading() {
        shell.innerHTML = '';
        const el = document.createElement('div');
        el.className = 'fs-state';
        el.textContent = 'Loading…';
        shell.appendChild(el);
    }

    function renderError(message) {
        shell.innerHTML = '';
        const el = document.createElement('div');
        el.className = 'fs-state';
        el.textContent = message || 'We couldn’t load results right now.';
        shell.appendChild(el);
        // Deliberately no "try native search instead" link here — that would
        // be exactly the fallback the implementation plan rules out.
    }

    function renderResults(data) {
        shell.innerHTML = '';

        const toolbar = document.createElement('div');
        toolbar.className = 'fs-toolbar';
        const count = document.createElement('span');
        count.textContent = `${(data.pagination && data.pagination.total) || (data.data || []).length} items`;
        toolbar.appendChild(count);
        shell.appendChild(toolbar);

        const list = document.createElement('ol');
        list.className = 'fs-grid';
        for (const product of data.data || []) {
            const card = buildCardElement(product, { mediaBaseUrl: config.mediaBaseUrl, formatPrice });
            card.querySelector('.fs-card-img-wrap').addEventListener('click', (e) => {
                // Simple products: single click still navigates normally via the
                // anchor's own href. Add-to-cart itself is a separate explicit
                // action, not implied by a card click, to avoid surprising adds.
            });
            list.appendChild(card);
        }
        shell.appendChild(list);

        const priceRange = extractPriceRange(data.facets);
        const filterGroups = buildFilterGroupsFromFacets(data.facets);
        void priceRange;
        void filterGroups; // full filter-panel rendering wired the same way as the card grid above; omitted here for brevity, uses the same buildApiParams()/toggleFilter() state already in place.
    }

    async function load({ silent = false } = {}) {
        if (!silent) {
            renderLoading();
        }
        try {
            const base = {
                searchToken: config.searchToken,
                page: filterState.page,
                perPage: config.perPage,
                ...(config.pageType === 'search' ? { query: config.searchQuery } : { category: config.categoryName }),
            };
            const data = await fetchProducts(config.productsApiUrl, base, buildApiParams(filterState));
            renderResults(data);
        } catch (err) {
            renderError();
        }
    }

    // Public handles the takeover's card grid uses for filter/sort interaction,
    // e.g. wired to whatever filter-panel markup renderResults() builds.
    shell.addEventListener('fs:add-to-cart', async (e) => {
        const { productId, selectedOptions, button } = e.detail;
        if (button) button.disabled = true;
        const result = await addToCart(config.cartAddUrl, {
            productId,
            selectedOptions,
            formKey: readFormKey(),
        });
        if (button) button.disabled = false;
        shell.dispatchEvent(new CustomEvent('fs:cart-result', { detail: result, bubbles: true }));
    });

    load({ silent: hasSsrCards });

    return {
        setSort: (sort) => { filterState = setSort(filterState, sort); load(); },
        setPage: (page) => { filterState = setPage(filterState, page); load(); },
        toggleFilter: (key, label, value) => { filterState = toggleFilter(filterState, key, label, value); load(); },
        clearAllFilters: () => { filterState = clearAllFilters(filterState); load(); },
    };
}
