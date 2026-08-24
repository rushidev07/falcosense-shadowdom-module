import { getOrCreateShadowRoot } from './shadow-root.js';
import { fetchProducts } from './fetch-results.js';
import { renderProductGrid, renderLoadingState, renderErrorState, attachAddToCartHandler } from './product-grid.js';
import {
    createInitialFilterState,
    toggleFilter,
    setSort,
    setPage,
    clearAllFilters,
    setPriceRange,
    clearPriceRange,
    buildApiParams,
} from './filters-sort.js';
import { BASE_CSS } from './styles.js';

const SHELL_CSS = `
${BASE_CSS}
.fs-shell { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; overflow-y: auto; z-index: 2147483000; }
`;

/**
 * Finds where .fs-shell should start so it doesn't cover whatever real
 * search UI is currently above it. Deliberately does NOT special-case any
 * theme's own search modal (e.g. a WeltPixel-style full-height overlay) —
 * that would only fix one theme's structure. Instead:
 *
 * - If `config.anchorEl` is given (the actual native search input boot.js
 *   attached to), measure *its* current bottom edge. Wherever that input
 *   actually lives on screen — a plain header, a full-height native search
 *   modal, anything else — is, by construction, exactly what needs to sit
 *   above the results. This is what the live-typing overlay uses, since it
 *   opens directly on top of whatever currently holds that input.
 * - Otherwise, fall back to the site's own <header> landmark, for the
 *   committed full-page results view, which has no such input context.
 *
 * Falls back to 0 (full-viewport, the old behavior) if neither is found,
 * rather than guessing at a theme-specific selector.
 * @param {{anchorEl?: HTMLElement}} config
 * @returns {number}
 */
function getOverlayTopOffset(config) {
    try {
        if (config.anchorEl) {
            const rect = config.anchorEl.getBoundingClientRect();
            if (rect.bottom > 0) {
                return Math.round(rect.bottom);
            }
        }
        const header = document.querySelector('header, .page-header');
        if (header) {
            const rect = header.getBoundingClientRect();
            if (rect.bottom > 0) {
                return Math.round(rect.bottom);
            }
        }
    } catch (e) {
        // Fall through to 0 below — a styling nicety must never throw.
    }
    return 0;
}

/**
 * Claims the viewport below the site's own header immediately once mounted —
 * loading, then real results, or an explicit error state on failure. Never
 * reverts to letting native content show once this has taken effect
 * (implementation plan §1): there is no code path in this module that
 * removes .fs-shell once it exists, short of the caller's own hide()/show()
 * toggle (used by the header search overlay, which mounts the same shell on
 * demand rather than on every page load).
 *
 * Shares its actual rendering (toolbar, filters, grid, pagination) with
 * category-enhancement.js via product-grid.js — this file only owns mounting,
 * positioning, and the fetch/filter-state loop, not UI construction.
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
 *   themeAccentColor?: string,
 *   anchorEl?: HTMLElement,
 * }} config
 */
export function mountTakeover(hostEl, config) {
    const root = getOrCreateShadowRoot(hostEl, SHELL_CSS, { accentColor: config.themeAccentColor });

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
        attachAddToCartHandler(shell, config);
    }

    function positionShell() {
        shell.style.top = getOverlayTopOffset(config) + 'px';
    }
    positionShell();
    window.addEventListener('resize', positionShell);

    let filterState = createInitialFilterState();

    function handleStateChange(nextState) {
        filterState = nextState;
        load();
    }

    async function load({ silent = false } = {}) {
        if (!silent) {
            renderLoadingState(shell);
        }
        try {
            const base = {
                searchToken: config.searchToken,
                page: filterState.page,
                perPage: config.perPage,
                ...(config.pageType === 'search' ? { query: config.searchQuery } : { category: config.categoryName }),
            };
            const data = await fetchProducts(config.productsApiUrl, base, buildApiParams(filterState));
            renderProductGrid(shell, data, config, filterState, handleStateChange);
        } catch (err) {
            renderErrorState(shell);
            // Deliberately no "try native search instead" link here — that would
            // be exactly the fallback the implementation plan rules out.
        }
    }

    load({ silent: hasSsrCards });

    return {
        setSort: (sort) => handleStateChange(setSort(filterState, sort)),
        setPage: (page) => handleStateChange(setPage(filterState, page)),
        toggleFilter: (key, label, value) => handleStateChange(toggleFilter(filterState, key, label, value)),
        clearAllFilters: () => handleStateChange(clearAllFilters(filterState)),
        setPriceRange: (min, max) => handleStateChange(setPriceRange(filterState, min, max)),
        clearPriceRange: () => handleStateChange(clearPriceRange(filterState)),
        // Live-typing support: reuses this same mounted shell/fetch loop
        // instead of remounting per keystroke — see boot.js's header overlay.
        setQuery: (query) => {
            config.searchQuery = query;
            filterState = createInitialFilterState();
            positionShell(); // the anchor (e.g. a native search modal) may have just opened/resized
            load();
        },
        show: () => { shell.style.display = ''; },
        hide: () => { shell.style.display = 'none'; },
    };
}
