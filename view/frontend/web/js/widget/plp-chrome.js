/**
 * The interactive chrome for a hydrated SSR listing — sort control, filter
 * groups, price range, active-filter chips — rendered INSIDE a Shadow DOM root
 * so a host theme's CSS can't disturb it and vice versa. This is the only part
 * of the listing that lives in shadow DOM; the product grid itself stays in
 * light DOM (crawlable) and is only ever swapped, never rebuilt here.
 *
 * It owns no data of its own: it renders from the facet list handed to it and
 * reports every change back through onChange, letting plp-hydrate.js run the
 * fragment fetch + swap loop.
 */

import { getOrCreateShadowRoot } from './shadow-root.js';

const CHROME_CSS = `
:host { display: block; }
.fs-chrome { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; font-size: 14px; color: #1a1a1a; margin: 0 0 20px; }
.fs-chrome-bar { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.fs-chrome select { padding: 6px 8px; border: 1px solid #ccc; border-radius: 4px; background: #fff; font: inherit; }
.fs-chrome-toggle { padding: 6px 12px; border: 1px solid #ccc; border-radius: 4px; background: #fff; cursor: pointer; font: inherit; }
.fs-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
.fs-chip { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px; border-radius: 999px; background: #eef1f4; font-size: 12px; }
.fs-chip button { border: 0; background: transparent; cursor: pointer; font-size: 13px; line-height: 1; padding: 0; }
.fs-panel { margin-top: 14px; border: 1px solid #e6e6e6; border-radius: 6px; padding: 14px; display: none; }
.fs-panel[data-open="1"] { display: block; }
.fs-group { margin-bottom: 14px; }
.fs-group:last-child { margin-bottom: 0; }
.fs-group h4 { margin: 0 0 6px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #666; }
.fs-opt { display: flex; align-items: center; gap: 8px; padding: 2px 0; }
.fs-opt .count { margin-left: auto; color: #999; font-size: 12px; }
.fs-price-row { display: flex; gap: 8px; align-items: center; }
.fs-price-row input { width: 90px; padding: 6px; border: 1px solid #ccc; border-radius: 4px; font: inherit; }
.fs-price-row button, .fs-clear { padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; background: #fff; cursor: pointer; font: inherit; }
@media (prefers-color-scheme: dark) {
  .fs-chrome { color: #ececec; }
  .fs-chrome select, .fs-chrome-toggle, .fs-price-row input, .fs-price-row button, .fs-clear { background: #1c1c1c; border-color: #444; color: inherit; }
  .fs-panel { border-color: #333; }
  .fs-chip { background: #2a2a2a; }
}
`;

const SORTS = [
    { value: 'position', label: 'Relevance' },
    { value: 'price_asc', label: 'Price: Low to High' },
    { value: 'price_desc', label: 'Price: High to Low' },
    { value: 'name', label: 'Name' },
];

/**
 * @param {HTMLElement} hostEl
 * @param {{
 *   facets: Array<Record<string, any>>,
 *   state: Record<string, any>,
 *   accentColor?: string,
 *   onChange: (patch: Record<string, any>) => void,
 * }} opts
 */
export function mountChrome(hostEl, opts) {
    const root = getOrCreateShadowRoot(hostEl, CHROME_CSS, { accentColor: opts.accentColor });
    let facets = Array.isArray(opts.facets) ? opts.facets : [];
    let state = opts.state;

    const wrap = document.createElement('div');
    wrap.className = 'fs-chrome';
    root.appendChild(wrap);

    function fire(patch) {
        opts.onChange(patch);
    }

    function activeFilterList() {
        const list = [];
        for (const [key, values] of Object.entries(state.filters || {})) {
            for (const value of values) {
                list.push({ key, value });
            }
        }
        return list;
    }

    function toggleFilter(key, value) {
        const filters = { ...(state.filters || {}) };
        const set = new Set(filters[key] || []);
        if (set.has(value)) {
            set.delete(value);
        } else {
            set.add(value);
        }
        if (set.size) {
            filters[key] = [...set];
        } else {
            delete filters[key];
        }
        state = { ...state, filters, page: 1 };
        fire({ filters, page: 1 });
    }

    function render() {
        wrap.innerHTML = '';

        const panel = document.createElement('div');
        panel.className = 'fs-panel';
        panel.setAttribute('data-open', '0');

        const bar = document.createElement('div');
        bar.className = 'fs-chrome-bar';

        const sortLabel = document.createElement('label');
        sortLabel.textContent = 'Sort ';
        const sortSel = document.createElement('select');
        SORTS.forEach(({ value, label }) => {
            const o = document.createElement('option');
            o.value = value;
            o.textContent = label;
            o.selected = (state.sort || 'position') === value;
            sortSel.appendChild(o);
        });
        sortSel.addEventListener('change', () => {
            state = { ...state, sort: sortSel.value, page: 1 };
            fire({ sort: sortSel.value, page: 1 });
        });
        sortLabel.appendChild(sortSel);
        bar.appendChild(sortLabel);

        if (facets.length) {
            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'fs-chrome-toggle';
            toggle.textContent = 'Filters';
            toggle.addEventListener('click', () => {
                const open = panel.getAttribute('data-open') === '1';
                panel.setAttribute('data-open', open ? '0' : '1');
            });
            bar.appendChild(toggle);
        }

        wrap.appendChild(bar);

        const active = activeFilterList();
        if (active.length || state.priceMin != null || state.priceMax != null) {
            const chips = document.createElement('div');
            chips.className = 'fs-chips';
            active.forEach(({ key, value }) => {
                chips.appendChild(chip(value, () => toggleFilter(key, value)));
            });
            if (state.priceMin != null || state.priceMax != null) {
                const label = 'Price ' + (state.priceMin ?? '0') + '–' + (state.priceMax ?? '∞');
                chips.appendChild(chip(label, () => {
                    state = { ...state, priceMin: null, priceMax: null, page: 1 };
                    fire({ priceMin: null, priceMax: null, page: 1 });
                }));
            }
            const clear = document.createElement('button');
            clear.className = 'fs-clear';
            clear.type = 'button';
            clear.textContent = 'Clear all';
            clear.addEventListener('click', () => {
                state = { ...state, filters: {}, priceMin: null, priceMax: null, page: 1 };
                fire({ filters: {}, priceMin: null, priceMax: null, page: 1 });
            });
            chips.appendChild(clear);
            wrap.appendChild(chips);
        }

        // price range
        const priceGroup = document.createElement('div');
        priceGroup.className = 'fs-group';
        priceGroup.innerHTML = '<h4>Price</h4>';
        const row = document.createElement('div');
        row.className = 'fs-price-row';
        const min = document.createElement('input');
        min.type = 'number';
        min.placeholder = 'Min';
        min.value = state.priceMin ?? '';
        const max = document.createElement('input');
        max.type = 'number';
        max.placeholder = 'Max';
        max.value = state.priceMax ?? '';
        const go = document.createElement('button');
        go.type = 'button';
        go.textContent = 'Apply';
        go.addEventListener('click', () => {
            const pMin = min.value === '' ? null : Number(min.value);
            const pMax = max.value === '' ? null : Number(max.value);
            state = { ...state, priceMin: pMin, priceMax: pMax, page: 1 };
            fire({ priceMin: pMin, priceMax: pMax, page: 1 });
        });
        row.append(min, max, go);
        priceGroup.appendChild(row);
        panel.appendChild(priceGroup);

        facets.forEach((facet) => {
            if (!facet || !Array.isArray(facet.options) || !facet.options.length) {
                return;
            }
            const group = document.createElement('div');
            group.className = 'fs-group';
            const h = document.createElement('h4');
            h.textContent = facet.label || facet.key;
            group.appendChild(h);

            const selected = new Set((state.filters || {})[facet.key] || []);
            facet.options.forEach((opt) => {
                const line = document.createElement('label');
                line.className = 'fs-opt';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.checked = selected.has(opt.value);
                cb.addEventListener('change', () => toggleFilter(facet.key, opt.value));
                const text = document.createElement('span');
                text.textContent = opt.label || opt.value;
                const count = document.createElement('span');
                count.className = 'count';
                count.textContent = opt.count != null ? String(opt.count) : '';
                line.append(cb, text, count);
                group.appendChild(line);
            });
            panel.appendChild(group);
        });

        wrap.appendChild(panel);
    }

    function chip(label, onRemove) {
        const el = document.createElement('span');
        el.className = 'fs-chip';
        const text = document.createElement('span');
        text.textContent = label;
        const x = document.createElement('button');
        x.type = 'button';
        x.setAttribute('aria-label', 'Remove ' + label);
        x.textContent = '×';
        x.addEventListener('click', onRemove);
        el.append(text, x);
        return el;
    }

    render();

    return {
        update(nextFacets, nextState) {
            if (Array.isArray(nextFacets)) {
                facets = nextFacets;
            }
            if (nextState) {
                state = nextState;
            }
            render();
        },
    };
}
