/**
 * Shared Shadow DOM boundary utility. Every piece of the widget's own UI lives
 * inside the root this returns — the browser-enforced boundary that makes the
 * whole "can't break the host page, can't be broken by it" guarantee real,
 * not a convention.
 *
 * "detect-then-attach": if the server already sent a Declarative Shadow Root
 * (SSR-Shell mode — <template shadowrootmode="open"> parsed as original page
 * HTML), `el.shadowRoot` is already populated by the browser's own HTML parser
 * and this reuses it. Otherwise this creates one. Same widget code either way
 * — no fork between the plain-CSR path and the SSR-Shell path. Reusing an
 * existing root still needs this module's own CSS applied on top of whatever
 * the server already put there (see applyWidgetCss) — the server-rendered
 * shell only carries its own minimal card styling, not the full widget CSS.
 */

const styleSheetCache = new Map();

/**
 * @param {string} cssText
 * @returns {CSSStyleSheet|null} A constructable stylesheet, cached per distinct
 *   cssText (the widget uses more than one — e.g. the search overlay vs. the
 *   category/search takeover — so a single shared sheet would let whichever
 *   loads first silently win for both), or null if the browser doesn't
 *   support constructable stylesheets (falls back to a plain <style> tag).
 */
function getStyleSheetFor(cssText) {
    if (typeof CSSStyleSheet === 'undefined' || !('replaceSync' in CSSStyleSheet.prototype)) {
        return null;
    }
    let sheet = styleSheetCache.get(cssText);
    if (!sheet) {
        sheet = new CSSStyleSheet();
        sheet.replaceSync(cssText);
        styleSheetCache.set(cssText, sheet);
    }
    return sheet;
}

/**
 * @param {ShadowRoot} root
 * @param {string} cssText
 */
function applyWidgetCss(root, cssText) {
    if (root.querySelector('style[data-fs-widget-css]')) {
        return; // already applied — e.g. a shadow root reused across a re-render
    }

    const sheet = getStyleSheetFor(cssText);
    if (sheet) {
        root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
    } else {
        const styleEl = document.createElement('style');
        styleEl.setAttribute('data-fs-widget-css', '');
        styleEl.textContent = cssText;
        root.appendChild(styleEl);
    }
}

/**
 * @param {HTMLElement} hostEl - element to attach/reuse a shadow root on
 * @param {string} cssText - the widget's own CSS, sealed inside the boundary
 * @returns {ShadowRoot}
 */
export function getOrCreateShadowRoot(hostEl, cssText) {
    if (hostEl.shadowRoot) {
        // A server-rendered Declarative Shadow Root already exists (SSR-Shell) —
        // reuse it. Calling attachShadow() on a node that already has one throws,
        // so this check is load-bearing, not defensive paranoia.
        applyWidgetCss(hostEl.shadowRoot, cssText);
        return hostEl.shadowRoot;
    }

    // mode: 'open', not 'closed' — the isolation is structural (the browser
    // boundary), not secretive. Keeping it open means devtools/monitoring can
    // still inspect it, which matters given there's no fallback safety net once
    // this widget is active (see the implementation plan §6 on reliability).
    const root = hostEl.attachShadow({ mode: 'open' });
    applyWidgetCss(root, cssText);
    return root;
}

/**
 * Reads the server-rendered config JSON off a mount element's data-config
 * attribute. This is the entire PHP-to-JS handoff — one inert JSON blob, no
 * extra network round trip required before the widget can boot.
 * @param {HTMLElement} el
 * @returns {Record<string, any>}
 */
export function readConfig(el) {
    const raw = el.getAttribute('data-config');
    if (!raw) {
        return {};
    }
    try {
        return JSON.parse(raw);
    } catch (e) {
        return {};
    }
}
