/**
 * Progressive enhancement on the header search box. Attaches listeners to
 * Magento's own native search input — id="search_mini_form", input name="q" —
 * a framework-level HTML contract, not a theme convention (confirmed intact
 * even on a heavily customized Hyvä theme in the source engagement). Never
 * moves, wraps, or replaces that input.
 *
 * This does not need to hide the native input to satisfy "no fallback": typing
 * always opens this overlay instead of native suggestions, and submitting
 * navigates to the search-results page — which the takeover in takeover.js
 * claims immediately on load regardless of how the shopper arrived there. So
 * the native input's mere visual presence never actually surfaces a working
 * native search path.
 *
 * IMPORTANT — Enter/submit is intercepted in the capture phase, not left to
 * the browser's default navigation. Confirmed on a WeltPixel Pearl deployment:
 * that theme's own header JS has its own bubble-phase handler on this same
 * form that hijacks Enter into a client-side hash change (its own unrelated,
 * unwired native search modal) instead of letting the browser actually
 * navigate to the search-results page. When that happens, the real
 * catalogsearch_result_index controller action never runs, so
 * Block\Widget\Bootstrap never sees pageType 'search' and takeover.js's real
 * product grid never mounts — the shopper is left staring at two unrelated,
 * unfinished UI fragments instead of results, even though the platform
 * returned real data. Capturing here and calling stopImmediatePropagation()
 * wins that race regardless of what a given theme's own JS does on the same
 * input, so "submitting navigates to the search-results page" is something
 * this file *makes true* rather than assumes.
 */

const MIN_QUERY_LENGTH = 3;
const DEBOUNCE_MS = 250;

/**
 * @param {{
 *   onQuery: (query: string) => void,
 *   onClose: () => void,
 * }} handlers
 * @returns {{ destroy: () => void }} teardown, since Hyvä/Magento partial
 *   re-renders can remove and reinsert the mount node this attaches near.
 */
export function attachToNativeSearch(handlers) {
    const input = document.querySelector('#search_mini_form input[name="q"]');
    if (!input) {
        // No native search input found at all — nothing to attach to. This is
        // the documented escape hatch for a genuinely non-standard theme
        // (search_input_selector config override), not handled here directly.
        return { destroy() {} };
    }

    const form = input.closest('form') || document.getElementById('search_mini_form');

    function navigateToResults(query) {
        if (!query) return;
        const base = (form && form.getAttribute('action')) || '/catalogsearch/result/';
        const separator = base.includes('?') ? '&' : '?';
        window.location.href = base + separator + 'q=' + encodeURIComponent(query);
    }

    let debounceTimer = null;

    const onInput = () => {
        clearTimeout(debounceTimer);
        const value = input.value.trim();
        if (value.length < MIN_QUERY_LENGTH) {
            handlers.onClose();
            return;
        }
        debounceTimer = setTimeout(() => handlers.onQuery(value), DEBOUNCE_MS);
    };

    const onFocus = () => {
        const value = input.value.trim();
        if (value.length >= MIN_QUERY_LENGTH) {
            handlers.onQuery(value);
        }
    };

    // Capture phase + stopImmediatePropagation: must run and win before any
    // theme-native handler bound (typically bubble-phase) to this same input
    // or form. See the file-level note above for why this can't just rely on
    // default browser form-submit behavior.
    const onKeydownCapture = (e) => {
        if (e.key === 'Escape') {
            handlers.onClose();
            return;
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            e.stopImmediatePropagation();
            navigateToResults(input.value.trim());
        }
    };

    const onSubmitCapture = (e) => {
        e.preventDefault();
        e.stopImmediatePropagation();
        navigateToResults(input.value.trim());
    };

    input.addEventListener('input', onInput);
    input.addEventListener('focus', onFocus);
    input.addEventListener('keydown', onKeydownCapture, true);
    if (form) {
        form.addEventListener('submit', onSubmitCapture, true);
    }

    return {
        destroy() {
            clearTimeout(debounceTimer);
            input.removeEventListener('input', onInput);
            input.removeEventListener('focus', onFocus);
            input.removeEventListener('keydown', onKeydownCapture, true);
            if (form) {
                form.removeEventListener('submit', onSubmitCapture, true);
            }
        },
        // Exposed so boot.js can position the live-typing overlay relative to
        // where this input actually is, and detect clicks outside it — needed
        // because the input's real on-screen position depends entirely on
        // whatever container currently holds it (a plain header on one theme,
        // a full-height native search modal on another), not on any fixed
        // assumption this file could make about page structure.
        input,
    };
}
