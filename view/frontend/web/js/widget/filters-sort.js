/**
 * Filter/sort state management for the category/search-results takeover. State
 * transitions are pure functions (easy to test in isolation, and to reuse
 * identically between the header's quick overlay and the full-page takeover),
 * DOM rendering is kept separate.
 */

/**
 * @typedef {{ key: string, label: string, value: string }} ActiveFilter
 * @typedef {{ min: number|null, max: number|null }} PriceRange
 */

export function createInitialFilterState() {
    return {
        activeFilters: /** @type {ActiveFilter[]} */ ([]),
        activePriceMin: '',
        activePriceMax: '',
        sort: 'relevance',
        page: 1,
    };
}

/**
 * Toggles one filter value on/off, returning a new state object (never mutates
 * the input) so callers can diff old vs. new state simply.
 * @param {ReturnType<typeof createInitialFilterState>} state
 * @param {string} key
 * @param {string} label
 * @param {string} value
 */
export function toggleFilter(state, key, label, value) {
    const exists = state.activeFilters.some((f) => f.key === key && f.value === value);
    const activeFilters = exists
        ? state.activeFilters.filter((f) => !(f.key === key && f.value === value))
        : [...state.activeFilters, { key, label, value }];
    return { ...state, activeFilters, page: 1 };
}

/**
 * @param {ReturnType<typeof createInitialFilterState>} state
 * @param {string|number} min
 * @param {string|number} max
 */
export function setPriceRange(state, min, max) {
    return { ...state, activePriceMin: String(min ?? ''), activePriceMax: String(max ?? ''), page: 1 };
}

export function clearPriceRange(state) {
    return { ...state, activePriceMin: '', activePriceMax: '', page: 1 };
}

/**
 * @param {ReturnType<typeof createInitialFilterState>} state
 * @param {'relevance'|'price_asc'|'price_desc'} sort
 */
export function setSort(state, sort) {
    return { ...state, sort, page: 1 };
}

export function setPage(state, page) {
    return { ...state, page };
}

export function clearAllFilters(state) {
    return { ...state, activeFilters: [], activePriceMin: '', activePriceMax: '', page: 1 };
}

/**
 * Builds the query string params to send to the platform's search/products API
 * for the current state.
 * @param {ReturnType<typeof createInitialFilterState>} state
 * @returns {Record<string, string>}
 */
export function buildApiParams(state) {
    /** @type {Record<string, string>} */
    const params = {};

    const grouped = {};
    for (const f of state.activeFilters) {
        if (!grouped[f.key]) grouped[f.key] = [];
        grouped[f.key].push(f.value);
    }
    for (const [key, values] of Object.entries(grouped)) {
        params[key] = values.join('\x1F'); // matches the platform's existing multi-value separator
    }

    if (state.activePriceMin !== '') params.price_min = state.activePriceMin;
    if (state.activePriceMax !== '') params.price_max = state.activePriceMax;
    if (state.sort !== 'relevance') params.sort = state.sort;
    params.page = String(state.page);

    return params;
}

/**
 * Normalizes the platform's raw facet response into the shape the filter UI
 * renders from, excluding facets rendered by dedicated UI (price, category).
 * @param {Array<Record<string, any>>} facets
 */
export function buildFilterGroupsFromFacets(facets) {
    return (facets || [])
        .filter((f) => f.key !== 'price' && f.key !== 'category')
        .map((f) => ({
            key: f.key,
            label: f.key === 'brand' ? 'Brand' : (f.label || f.key),
            options: (f.options || []).map((o) => ({ value: o.value, count: o.count })),
        }));
}

/**
 * @param {Array<Record<string, any>>} facets
 * @returns {PriceRange}
 */
export function extractPriceRange(facets) {
    const priceFacet = (facets || []).find((f) => f.key === 'price');
    return priceFacet ? { min: priceFacet.min ?? null, max: priceFacet.max ?? null } : { min: null, max: null };
}
