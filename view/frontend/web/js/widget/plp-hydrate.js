/**
 * Hydration controller for an SSR-rendered listing (category or search).
 *
 * The product grid is already in the light DOM, server-rendered from the one
 * platform response and crawlable. This does NOT rebuild it. It:
 *   1. binds behaviour (quick add-to-cart) onto the existing cards;
 *   2. mounts the filter/sort chrome in a shadow root as a sibling of .fs-plp,
 *      so a grid swap never disturbs it;
 *   3. on any sort/filter/page change, fetches a fresh server-rendered fragment
 *      and swaps <div class="fs-plp"> in place, then re-hydrates it.
 *
 * If anything here fails, the page is exactly the server-rendered page — real
 * products, working <a> pagination, a working no-JS sort form. Fail-open.
 */

import { fetchFragment, readPlpPayload } from './plp-fragment.js';
import { mountChrome } from './plp-chrome.js';
import { addToCart, readFormKey } from './cart.js';

const NEUTRAL_URL_PARAMS = new Set(['p', 'product_list_order', 'product_list_dir', 'price_min', 'price_max']);

/**
 * @param {HTMLElement} rootEl  the <falcosense-root> element (unused hook point)
 * @param {Record<string, any>} config  the bootstrap config blob
 * @returns {{ destroy: () => void } | null}
 */
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
        filters: {},
        priceMin: null,
        priceMax: null,
    };

    applyStateFromUrl(state);

    const chromeHost = document.createElement('div');
    chromeHost.className = 'fs-plp-chrome-host';
    plpEl.parentNode.insertBefore(chromeHost, plpEl);

    const chrome = mountChrome(chromeHost, {
        facets: (payload.result.facets) || [],
        state,
        accentColor: config.themeAccentColor,
        onChange: (patch) => {
            state = { ...state, ...patch };
            if (patch.page === undefined) {
                state.page = 1;
            }
            reload();
        },
    });

    hydrateCards(plpEl);
    wireGridControls(plpEl);

    let busy = false;

    async function reload() {
        if (busy) {
            return;
        }
        busy = true;
        plpEl.style.opacity = '0.45';
        plpEl.setAttribute('aria-busy', 'true');

        try {
            const html = await fetchFragment(config.plpGridUrl, state);
            const holder = document.createElement('div');
            holder.innerHTML = html;
            const fresh = holder.querySelector('.fs-plp');

            if (!fresh || fresh.getAttribute('data-fs-source') === 'unavailable') {
                restore();
                return;
            }

            plpEl.replaceWith(fresh);
            plpEl = fresh;

            const freshPayload = readPlpPayload(plpEl);
            chrome.update(freshPayload && freshPayload.result ? freshPayload.result.facets : null, state);

            hydrateCards(plpEl);
            wireGridControls(plpEl);
            pushStateToUrl(state);

            plpEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            restore();
        } finally {
            busy = false;
        }
    }

    function restore() {
        plpEl.style.opacity = '';
        plpEl.removeAttribute('aria-busy');
        busy = false;
    }

    /**
     * Quick add-to-cart on the existing server-rendered cards. Products with
     * variants link to the PDP instead — swatch resolution is the PDP's job,
     * not the grid's.
     * @param {Element} container
     */
    function hydrateCards(container) {
        const items = {};
        (payloadFor(container).items || []).forEach((item) => {
            items[item.product_id] = item;
        });

        container.querySelectorAll('.fs-plp-card').forEach((card) => {
            if (card.dataset.fsHydrated) {
                return;
            }
            card.dataset.fsHydrated = '1';

            const id = parseInt(card.dataset.productId || '0', 10);
            const item = items[id];
            if (!item || item.in_stock === false) {
                return;
            }

            const hasVariants = Array.isArray(item.swatches) && item.swatches.length > 0;
            const titleLink = card.querySelector('.fs-plp-card-title');

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'fs-plp-card-add';
            btn.textContent = hasVariants ? 'Choose options' : 'Add to cart';

            btn.addEventListener('click', async () => {
                if (hasVariants) {
                    if (titleLink) {
                        window.location.href = titleLink.href;
                    }
                    return;
                }
                btn.disabled = true;
                const prev = btn.textContent;
                try {
                    const result = await addToCart(config.cartAddUrl, {
                        productId: id,
                        selectedOptions: {},
                        formKey: readFormKey(),
                    });
                    btn.textContent = result && result.success ? 'Added ✓' : 'Try again';
                    if (result && result.success) {
                        window.dispatchEvent(new CustomEvent('fs:cart-updated', { detail: { productId: id } }));
                    }
                } catch (e) {
                    btn.textContent = 'Try again';
                }
                setTimeout(() => {
                    btn.textContent = prev;
                    btn.disabled = false;
                }, 2000);
            });

            card.appendChild(btn);
        });
    }

    function payloadFor(container) {
        const p = readPlpPayload(container);
        return (p && p.result) || { items: [] };
    }

    /**
     * Intercept the server-rendered <a> pagination and the no-JS sort <select>
     * so they drive the fragment loop instead of a full navigation.
     * @param {Element} container
     */
    function wireGridControls(container) {
        container.querySelectorAll('.fs-plp-pagination a').forEach((a) => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                try {
                    const u = new URL(a.href, window.location.origin);
                    state.page = parseInt(u.searchParams.get('p') || '1', 10) || 1;
                } catch (err) {
                    state.page = state.page + (a.classList.contains('fs-plp-page--next') ? 1 : -1);
                }
                reload();
            });
        });

        const sortForm = container.querySelector('.fs-plp-sort');
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
    }

    function applyStateFromUrl(target) {
        try {
            const params = new URL(window.location.href).searchParams;
            const p = parseInt(params.get('p') || '1', 10);
            if (p > 1) {
                target.page = p;
            }
            const sort = params.get('product_list_order');
            if (sort) {
                target.sort = sort;
            }
            if (params.get('price_min')) {
                target.priceMin = Number(params.get('price_min'));
            }
            if (params.get('price_max')) {
                target.priceMax = Number(params.get('price_max'));
            }
            params.forEach((value, key) => {
                const m = key.match(/^filter\[([a-zA-Z0-9_]+)\]\[\]$/);
                if (m) {
                    (target.filters[m[1]] = target.filters[m[1]] || []).push(value);
                }
            });
        } catch (e) {
            // leave defaults
        }
    }

    function pushStateToUrl(s) {
        try {
            const u = new URL(window.location.href);
            [...u.searchParams.keys()].forEach((k) => {
                if (NEUTRAL_URL_PARAMS.has(k) || /^filter\[/.test(k)) {
                    u.searchParams.delete(k);
                }
            });
            if (s.page > 1) {
                u.searchParams.set('p', String(s.page));
            }
            if (s.sort && s.sort !== 'position') {
                u.searchParams.set('product_list_order', s.sort);
            }
            if (s.priceMin != null) {
                u.searchParams.set('price_min', String(s.priceMin));
            }
            if (s.priceMax != null) {
                u.searchParams.set('price_max', String(s.priceMax));
            }
            Object.entries(s.filters || {}).forEach(([key, values]) => {
                values.forEach((v) => u.searchParams.append('filter[' + key + '][]', v));
            });
            history.pushState({ fsPlp: true }, '', u.toString());
        } catch (e) {
            // URL sync is a nicety, never fatal
        }
    }

    // Back/forward within this listing: reload so the server re-renders the
    // correct SSR view for the restored URL. A path change is a real navigation
    // — let the browser handle it.
    const initialPath = window.location.pathname;
    const onPopState = () => {
        if (window.location.pathname === initialPath) {
            window.location.reload();
        }
    };
    window.addEventListener('popstate', onPopState);

    return {
        destroy() {
            window.removeEventListener('popstate', onPopState);
        },
    };
}
