/**
 * Renders one page of results — toolbar/sort, filter sidebar, product grid,
 * pagination — and wires the shared add-to-cart contract. This is the one
 * place that UI gets built, shared between the search-results takeover
 * (fail-closed, full-viewport) and the category-page enhancement (fail-open,
 * in-place): both need identical results UI, and only differ in how they got
 * mounted and what happens if a fetch never succeeds — which stays entirely
 * in each caller, on purpose. This module never fetches and never decides
 * what "failure" means; it only builds DOM from data it's handed, and
 * reports shopper intent (a new filter, a page change) back through
 * `onStateChange` so each caller's own load loop stays in charge of its own
 * fail-open/fail-closed behavior.
 */

import { buildCardElement } from './render-cards.js';
import {
    toggleFilter,
    setSort,
    setPage,
    clearAllFilters,
    setPriceRange,
    buildFilterGroupsFromFacets,
    extractPriceRange,
} from './filters-sort.js';
import { addToCart, readFormKey } from './cart.js';

const formatPrice = (n) => new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(n);

/**
 * @param {HTMLElement} container
 * @param {{data: Array<Record<string, any>>, facets?: Array, pagination?: {total: number}}} responseData
 * @param {{mediaBaseUrl: string, perPage: number}} config
 * @param {ReturnType<typeof import('./filters-sort.js').createInitialFilterState>} filterState
 * @param {(next: typeof filterState) => void} onStateChange
 */
export function renderProductGrid(container, responseData, config, filterState, onStateChange) {
    container.innerHTML = '';

    const toolbar = document.createElement('div');
    toolbar.className = 'fs-toolbar';
    const count = document.createElement('span');
    count.textContent = `${(responseData.pagination && responseData.pagination.total) || (responseData.data || []).length} items`;
    toolbar.appendChild(count);
    toolbar.appendChild(buildSortSelect(filterState, onStateChange));
    container.appendChild(toolbar);

    const layout = document.createElement('div');
    layout.className = 'fs-layout';

    layout.appendChild(buildFilterPanel(
        buildFilterGroupsFromFacets(responseData.facets),
        extractPriceRange(responseData.facets),
        filterState,
        onStateChange
    ));

    const main = document.createElement('div');
    main.className = 'fs-main';

    const list = document.createElement('ol');
    list.className = 'fs-grid';
    for (const product of responseData.data || []) {
        list.appendChild(buildCardElement(product, { mediaBaseUrl: config.mediaBaseUrl, formatPrice }));
    }
    main.appendChild(list);
    main.appendChild(buildPagination(responseData.pagination, config.perPage, filterState, onStateChange));
    layout.appendChild(main);

    container.appendChild(layout);
}

function buildSortSelect(filterState, onStateChange) {
    const select = document.createElement('select');
    select.className = 'fs-sort-select';
    [
        { value: 'relevance', label: 'Relevance' },
        { value: 'price_asc', label: 'Price: Low to High' },
        { value: 'price_desc', label: 'Price: High to Low' },
    ].forEach(({ value, label }) => {
        const opt = document.createElement('option');
        opt.value = value;
        opt.textContent = label;
        opt.selected = filterState.sort === value;
        select.appendChild(opt);
    });
    select.addEventListener('change', () => onStateChange(setSort(filterState, select.value)));
    return select;
}

function buildFilterPanel(filterGroups, priceRange, filterState, onStateChange) {
    const panel = document.createElement('div');
    panel.className = 'fs-filters';

    const hasActiveFilters = filterState.activeFilters.length > 0
        || filterState.activePriceMin !== ''
        || filterState.activePriceMax !== '';
    if (hasActiveFilters) {
        const clearBtn = document.createElement('button');
        clearBtn.type = 'button';
        clearBtn.className = 'fs-clear-filters';
        clearBtn.textContent = 'Clear all filters';
        clearBtn.addEventListener('click', () => onStateChange(clearAllFilters(filterState)));
        panel.appendChild(clearBtn);
    }

    const hasPriceData = priceRange.min !== null || priceRange.max !== null;
    if (hasPriceData || filterState.activePriceMin !== '' || filterState.activePriceMax !== '') {
        const priceGroup = document.createElement('fieldset');
        priceGroup.className = 'fs-filter-group';
        const legend = document.createElement('legend');
        legend.textContent = 'Price';
        priceGroup.appendChild(legend);

        const row = document.createElement('div');
        row.className = 'fs-price-row';

        const minInput = document.createElement('input');
        minInput.type = 'number';
        minInput.placeholder = priceRange.min !== null ? String(priceRange.min) : 'Min';
        minInput.value = filterState.activePriceMin;

        const maxInput = document.createElement('input');
        maxInput.type = 'number';
        maxInput.placeholder = priceRange.max !== null ? String(priceRange.max) : 'Max';
        maxInput.value = filterState.activePriceMax;

        const applyBtn = document.createElement('button');
        applyBtn.type = 'button';
        applyBtn.textContent = 'Go';
        applyBtn.addEventListener('click', () => onStateChange(setPriceRange(filterState, minInput.value, maxInput.value)));

        row.append(minInput, maxInput, applyBtn);
        priceGroup.appendChild(row);
        panel.appendChild(priceGroup);
    }

    for (const group of filterGroups) {
        const fieldset = document.createElement('fieldset');
        fieldset.className = 'fs-filter-group';
        const legend = document.createElement('legend');
        legend.textContent = group.label;
        fieldset.appendChild(legend);

        for (const option of group.options) {
            const isActive = filterState.activeFilters.some(
                (f) => f.key === group.key && f.value === option.value
            );
            const label = document.createElement('label');
            label.className = 'fs-filter-option';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.checked = isActive;
            checkbox.addEventListener('change', () => onStateChange(toggleFilter(filterState, group.key, group.label, option.value)));

            const text = document.createElement('span');
            text.textContent = option.value;
            const countEl = document.createElement('span');
            countEl.className = 'fs-count';
            countEl.textContent = option.count != null ? String(option.count) : '';

            label.append(checkbox, text, countEl);
            fieldset.appendChild(label);
        }
        panel.appendChild(fieldset);
    }

    return panel;
}

function buildPagination(pagination, perPage, filterState, onStateChange) {
    const nav = document.createElement('div');
    nav.className = 'fs-pagination';
    if (!pagination || !pagination.total) {
        return nav;
    }

    const totalPages = Math.max(1, Math.ceil(pagination.total / perPage));

    const prevBtn = document.createElement('button');
    prevBtn.type = 'button';
    prevBtn.textContent = 'Previous';
    prevBtn.disabled = filterState.page <= 1;
    prevBtn.addEventListener('click', () => onStateChange(setPage(filterState, filterState.page - 1)));

    const label = document.createElement('span');
    label.textContent = `Page ${filterState.page} of ${totalPages}`;

    const nextBtn = document.createElement('button');
    nextBtn.type = 'button';
    nextBtn.textContent = 'Next';
    nextBtn.disabled = filterState.page >= totalPages;
    nextBtn.addEventListener('click', () => onStateChange(setPage(filterState, filterState.page + 1)));

    nav.append(prevBtn, label, nextBtn);
    return nav;
}

/**
 * @param {HTMLElement} container
 * @param {string} [message]
 */
export function renderLoadingState(container, message = 'Loading…') {
    container.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'fs-state';
    el.textContent = message;
    container.appendChild(el);
}

/**
 * @param {HTMLElement} container
 * @param {string} [message]
 */
export function renderErrorState(container, message) {
    container.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'fs-state';
    el.textContent = message || 'We couldn’t load results right now.';
    container.appendChild(el);
}

/**
 * Wires the one 'fs:add-to-cart' event contract product cards dispatch,
 * POSTs to the shared cart endpoint, and reports the result back —
 * identical behavior regardless of which surface the card was rendered
 * into.
 * @param {HTMLElement} container
 * @param {{cartAddUrl: string}} config
 */
export function attachAddToCartHandler(container, config) {
    container.addEventListener('fs:add-to-cart', async (e) => {
        const { productId, selectedOptions, button } = e.detail;
        if (button) button.disabled = true;
        const result = await addToCart(config.cartAddUrl, {
            productId,
            selectedOptions,
            formKey: readFormKey(),
        });
        if (button) button.disabled = false;
        container.dispatchEvent(new CustomEvent('fs:cart-result', { detail: result, bubbles: true }));
    });
}
