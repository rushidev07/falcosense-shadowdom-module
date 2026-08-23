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

    const onKeydown = (e) => {
        if (e.key === 'Escape') {
            handlers.onClose();
        }
    };

    input.addEventListener('input', onInput);
    input.addEventListener('focus', onFocus);
    input.addEventListener('keydown', onKeydown);

    return {
        destroy() {
            clearTimeout(debounceTimer);
            input.removeEventListener('input', onInput);
            input.removeEventListener('focus', onFocus);
            input.removeEventListener('keydown', onKeydown);
        },
    };
}
