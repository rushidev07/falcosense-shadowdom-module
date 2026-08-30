/**
 * Full-page type-ahead search.
 *
 * Typing 3+ letters in the header box (no click, no Enter) turns the current
 * page into a full search-results page: the site header stays, the page's own
 * main content is hidden, and PlpRenderer's grid — fetched from /smsl/plp/grid,
 * the exact same renderer the committed /fs/search page and every category page
 * use — fills the space. The address bar becomes /fs/search?q=... (a real
 * history entry), so Back returns to where the shopper was and a reload is a
 * real server-rendered page.
 *
 * It is NOT a modal/overlay: no fixed positioning, no backdrop, no z-index
 * stack. It is the page, minus its old body, plus the results — one look across
 * search and category, because it is literally the same markup and CSS.
 */

import { mountPlpHydration } from './plp-hydrate.js';

const MAIN_SELECTORS = ['#maincontent', 'main[id]', 'main', '[role="main"]', '.page-main', '.page-wrapper'];
const MIN_LEN = 3;

export function mountPlpSearch(config) {
    let active = false;
    let pushed = false;
    let mainEl = null;
    let container = null;
    let hydration = null;
    let originalUrl = null;
    let currentQuery = '';
    let reqSeq = 0;

    function findMain() {
        for (const sel of MAIN_SELECTORS) {
            const el = document.querySelector(sel);
            if (el && el !== document.body) {
                return el;
            }
        }
        return document.body;
    }

    function enter() {
        active = true;
        originalUrl = window.location.href;
        mainEl = findMain();

        Array.from(mainEl.children).forEach((child) => {
            if (child !== container) {
                child.setAttribute('data-fs-hidden-by-search', '');
            }
        });

        container = document.createElement('div');
        container.className = 'fs-plp-fullpage';
        container.innerHTML = '<div class="fs-plp-loading">' + esc('Searching…') + '</div>';
        mainEl.appendChild(container);
        document.body.classList.add('fs-plp-search-active');
        window.scrollTo({ top: 0, behavior: 'auto' });
    }

    function exit(skipHistory) {
        if (!active) {
            return;
        }
        active = false;
        currentQuery = '';
        reqSeq++;

        if (hydration && typeof hydration.destroy === 'function') {
            hydration.destroy();
        }
        hydration = null;

        if (container && container.parentNode) {
            container.parentNode.removeChild(container);
        }
        container = null;

        if (mainEl) {
            mainEl.querySelectorAll('[data-fs-hidden-by-search]').forEach((el) => {
                el.removeAttribute('data-fs-hidden-by-search');
            });
        }
        document.body.classList.remove('fs-plp-search-active');

        if (pushed && !skipHistory) {
            pushed = false;
            try {
                if (/\/fs\/search/.test(window.location.pathname)) {
                    window.history.back();
                } else if (originalUrl) {
                    window.history.replaceState({}, '', originalUrl);
                }
            } catch (e) { /* nicety */ }
        }
        pushed = false;
    }

    async function setQuery(query) {
        query = (query || '').trim();

        if (query.length < MIN_LEN) {
            exit();
            return;
        }
        if (!active) {
            enter();
        }
        if (query === currentQuery) {
            return;
        }
        currentQuery = query;
        const seq = ++reqSeq;

        try {
            const u = new URL('/fs/search', window.location.origin);
            u.searchParams.set('q', query);
            if (!pushed) {
                window.history.pushState({ fsSearch: true }, '', u.toString());
                pushed = true;
            } else {
                window.history.replaceState({ fsSearch: true }, '', u.toString());
            }
        } catch (e) { /* ignore */ }

        let html;
        try {
            html = await fetchGrid(config.plpGridUrl, query);
        } catch (e) {
            if (seq === reqSeq && container) {
                container.innerHTML = '<div class="fs-plp-loading">' + esc('Search is unavailable right now.') + '</div>';
            }
            return;
        }
        if (seq !== reqSeq || !container) {
            return; // a newer keystroke (or an exit) superseded this response
        }

        const holder = document.createElement('div');
        holder.innerHTML = html;
        const fresh = holder.querySelector('.fs-plp');

        if (hydration && typeof hydration.destroy === 'function') {
            hydration.destroy();
            hydration = null;
        }

        if (!fresh || fresh.getAttribute('data-fs-source') === 'unavailable') {
            container.innerHTML = '<h1 class="fs-plp-fullpage-head">' + heading(query) + '</h1>'
                + '<div class="fs-plp-loading">' + esc('No results found for that search.') + '</div>';
            return;
        }

        container.innerHTML = '<h1 class="fs-plp-fullpage-head">' + heading(query) + '</h1>';
        container.appendChild(fresh);
        hydration = mountPlpHydration(null, config, container);
    }

    function heading(query) {
        return esc("Search results for: '" + query + "'");
    }

    function esc(text) {
        const span = document.createElement('span');
        span.textContent = text;
        return span.innerHTML;
    }

    window.addEventListener('popstate', () => {
        if (active && !/\/fs\/search/.test(window.location.pathname)) {
            exit(true);
        }
    });

    return {
        setQuery,
        close: () => exit(false),
        isActive: () => active,
    };
}

async function fetchGrid(gridUrl, query) {
    const u = new URL(gridUrl, window.location.origin);
    u.searchParams.set('context', 'search');
    u.searchParams.set('q', query);
    u.searchParams.set('p', '1');
    u.searchParams.set('sort', 'position');
    u.searchParams.set('base_url', window.location.origin + '/fs/search');

    const res = await fetch(u.toString(), { credentials: 'omit' });
    if (!res.ok) {
        throw new Error('PLP grid fragment HTTP ' + res.status);
    }
    return res.text();
}
