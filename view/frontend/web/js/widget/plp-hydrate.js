/**
 * Hydration controller for an SSR-rendered listing (category or search).
 *
 * The whole listing — product grid AND the left filter rail — is already in the
 * light DOM, server-rendered by PlpRenderer from the one platform response, and
 * crawlable. This does NOT rebuild any of it. It:
 *   1. binds behaviour onto the server-rendered controls (facet checkboxes,
 *      price bands, chips, "See More", group collapse, sort, pagination, the
 *      per-card Add/Options + wishlist buttons);
 *   2. on any sort/filter/page change, fetches a fresh server-rendered fragment
 *      from /smsl/plp/grid and swaps <div class="fs-plp"> in place, then re-binds.
 *
 * No shadow DOM here — the filters are a normal persistent sidebar, exactly like
 * the previous production build. Shadow DOM is only used by the header type-ahead
 * overlay now.
 *
 * If anything here throws, the page is exactly the server-rendered page: real
 * products, a working no-JS sort form, working <a> pagination, working filter
 * links would 404 without JS — so the sidebar checkboxes are enhancement only.
 * Fail-open.
 */

import { fetchFragment, readPlpPayload } from './plp-fragment.js';
import { addToCart, readFormKey } from './cart.js';

const URL_PARAMS_OWNED = ['p', 'product_list_order', 'price_min', 'price_max'];

export function mountPlpHydration(rootEl, config) {
    let plpEl = document.querySelector('.fs-plp');
    if (!plpEl) {
        return null;
    }
    const payload = readPlpPayload(plpEl);
    if (!payload || !payload.result) {
        return null;
    }

    let state = {
        context: payload.context || 'category',
        categoryId: payload.categoryId || null,
        searchQuery: payload.searchQuery || null,
        sort: payload.sort || 'position',
        page: (payload.result && payload.result.page) || 1,
        filters: payload.filters && typeof payload.filters === 'object' ? { ...payload.filters } : {},
        priceMin: payload.priceMin ?? null,
        priceMax: payload.priceMax ?? null,
    };
    applyStateFromUrl(state);

    let busy = false;
    bind(plpEl);

    async function reload() {
        if (busy) {
            return;
        }
        busy = true;
        plpEl.setAttribute('aria-busy', 'true');

        try {
            const html = await fetchFragment(config.plpGridUrl, state);
            const holder = document.createElement('div');
            holder.innerHTML = html;
            const fresh = holder.querySelector('.fs-plp');

            if (!fresh || fresh.getAttribute('data-fs-source') === 'unavailable') {
                plpEl.removeAttribute('aria-busy');
                busy = false;
                return;
            }

            plpEl.replaceWith(fresh);
            plpEl = fresh;
            bind(plpEl);
            pushStateToUrl(state);
            plpEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            plpEl.removeAttribute('aria-busy');
        } finally {
            busy = false;
        }
    }

    /**
     * Bind every control inside one .fs-plp element. Called on first mount and
     * after every fragment swap.
     * @param {Element} el
     */
    function bind(el) {
        el.removeAttribute('aria-busy');

        // ── sidebar: facet checkboxes ──
        el.querySelectorAll('.fs-plp-facet-check').forEach((cb) => {
            cb.addEventListener('change', () => {
                const key = cb.dataset.fsFilterKey;
                const value = cb.dataset.fsFilterValue;
                const set = new Set(state.filters[key] || []);
                cb.checked ? set.add(value) : set.delete(value);
                if (set.size) {
                    state.filters[key] = [...set];
                } else {
                    delete state.filters[key];
                }
                state.page = 1;
                reload();
            });
        });

        // ── sidebar: price bands (checkbox that behaves like a radio) ──
        el.querySelectorAll('.fs-plp-price-check').forEach((cb) => {
            cb.addEventListener('change', () => {
                el.querySelectorAll('.fs-plp-price-check').forEach((other) => {
                    if (other !== cb) {
                        other.checked = false;
                    }
                });
                if (cb.checked) {
                    state.priceMin = cb.dataset.fsPriceMin === '' ? null : Number(cb.dataset.fsPriceMin);
                    state.priceMax = cb.dataset.fsPriceMax === '' ? null : Number(cb.dataset.fsPriceMax);
                } else {
                    state.priceMin = null;
                    state.priceMax = null;
                }
                state.page = 1;
                reload();
            });
        });

        // ── sidebar: active-filter chips ──
        el.querySelectorAll('.fs-plp-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                if (chip.dataset.fsPriceClear) {
                    state.priceMin = null;
                    state.priceMax = null;
                } else {
                    const key = chip.dataset.fsFilterKey;
                    const value = chip.dataset.fsFilterValue;
                    const set = new Set(state.filters[key] || []);
                    set.delete(value);
                    set.size ? (state.filters[key] = [...set]) : delete state.filters[key];
                }
                state.page = 1;
                reload();
            });
        });

        const clearAll = el.querySelector('.fs-plp-clear-all');
        if (clearAll) {
            clearAll.addEventListener('click', () => {
                state.filters = {};
                state.priceMin = null;
                state.priceMax = null;
                state.page = 1;
                reload();
            });
        }

        // ── sidebar: local UI only (no fetch) ──
        el.querySelectorAll('.fs-plp-fhead').forEach((head) => {
            head.addEventListener('click', () => head.closest('.fs-plp-fgroup').classList.toggle('is-collapsed'));
        });
        el.querySelectorAll('.fs-plp-more').forEach((btn) => {
            btn.addEventListener('click', () => {
                const list = btn.closest('.fs-plp-fopts');
                const hidden = list.querySelectorAll('.fs-plp-fopt-li[hidden]');
                if (hidden.length) {
                    hidden.forEach((li) => (li.hidden = false));
                    btn.textContent = btn.textContent.replace('See More +', 'See Less –');
                } else {
                    list.querySelectorAll('.fs-plp-fopt-li').forEach((li, i) => {
                        if (i >= 5) li.hidden = true;
                    });
                    btn.textContent = btn.textContent.replace('See Less –', 'See More +');
                }
            });
        });
        const toggle = el.querySelector('.fs-plp-filter-toggle');
        if (toggle) {
            toggle.addEventListener('click', () => {
                el.dataset.filtersOpen = el.dataset.filtersOpen === '1' ? '0' : '1';
            });
        }

        // ── toolbar: sort ──
        const sortForm = el.querySelector('.fs-plp-sort');
        if (sortForm) {
            sortForm.addEventListener('submit', (e) => e.preventDefault());
            const sel = sortForm.querySelector('select');
            if (sel) {
                sel.addEventListener('change', () => {
                    state.sort = sel.value || 'position';
                    state.page = 1;
                    reload();
                });
            }
        }

        // ── pagination ──
        el.querySelectorAll('.fs-plp-pagination a').forEach((a) => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                try {
                    state.page = parseInt(new URL(a.href, location.origin).searchParams.get('p') || '1', 10) || 1;
                } catch (err) {
                    state.page += a.classList.contains('fs-plp-page--next') ? 1 : -1;
                }
                reload();
            });
        });

        // ── cards: add / options / wishlist ──
        const items = {};
        (readPlpPayload(el)?.result?.items || []).forEach((i) => (items[i.product_id] = i));

        el.querySelectorAll('.fs-plp-card').forEach((card) => {
            const id = parseInt(card.dataset.productId || '0', 10);
            const item = items[id] || {};
            const addBtn = card.querySelector('.fs-plp-card-add');
            const titleLink = card.querySelector('.fs-plp-card-title');

            if (addBtn && !addBtn.disabled) {
                addBtn.addEventListener('click', async () => {
                    if (card.dataset.type === 'configurable') {
                        if (titleLink) location.href = titleLink.href;
                        return;
                    }
                    addBtn.disabled = true;
                    const prev = addBtn.textContent;
                    try {
                        const res = await addToCart(config.cartAddUrl, {
                            productId: id,
                            selectedOptions: {},
                            formKey: readFormKey(),
                        });
                        addBtn.textContent = res && res.success ? 'Added ✓' : 'Try again';
                        if (res && res.success) {
                            window.dispatchEvent(new CustomEvent('fs:cart-updated', { detail: { productId: id } }));
                        }
                    } catch (e) {
                        addBtn.textContent = 'Try again';
                    }
                    setTimeout(() => {
                        addBtn.textContent = prev;
                        addBtn.disabled = false;
                    }, 2000);
                });
            }

            const wish = card.querySelector('.fs-plp-card-wish');
            if (wish) {
                wish.addEventListener('click', () => {
                    const base = config.wishlistAddUrl || '/wishlist/index/add/';
                    location.href = base + (base.includes('?') ? '&' : '?') + 'product=' + id;
                });
            }
        });
    }

    function applyStateFromUrl(target) {
        try {
            const params = new URL(location.href).searchParams;
            const p = parseInt(params.get('p') || '1', 10);
            if (p > 1) target.page = p;
            const sort = params.get('product_list_order');
            if (sort) target.sort = sort;
            if (params.get('price_min')) target.priceMin = Number(params.get('price_min'));
            if (params.get('price_max')) target.priceMax = Number(params.get('price_max'));
            params.forEach((value, key) => {
                const m = key.match(/^filter\[([a-zA-Z0-9_]+)\]\[\]$/);
                if (m) (target.filters[m[1]] = target.filters[m[1]] || []).push(value);
            });
        } catch (e) { /* keep defaults */ }
    }

    function pushStateToUrl(s) {
        try {
            const u = new URL(location.href);
            [...u.searchParams.keys()].forEach((k) => {
                if (URL_PARAMS_OWNED.includes(k) || /^filter\[/.test(k)) u.searchParams.delete(k);
            });
            if (s.page > 1) u.searchParams.set('p', String(s.page));
            if (s.sort && s.sort !== 'position') u.searchParams.set('product_list_order', s.sort);
            if (s.priceMin != null) u.searchParams.set('price_min', String(s.priceMin));
            if (s.priceMax != null) u.searchParams.set('price_max', String(s.priceMax));
            Object.entries(s.filters || {}).forEach(([key, values]) => {
                values.forEach((v) => u.searchParams.append('filter[' + key + '][]', v));
            });
            history.pushState({ fsPlp: true }, '', u.toString());
        } catch (e) { /* URL sync is a nicety */ }
    }

    const initialPath = location.pathname;
    const onPopState = () => {
        if (location.pathname === initialPath) location.reload();
    };
    window.addEventListener('popstate', onPopState);

    return {
        destroy() {
            window.removeEventListener('popstate', onPopState);
        },
    };
}
