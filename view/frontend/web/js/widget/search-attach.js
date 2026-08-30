/**
 * Progressive enhancement on the header search box. Attaches listeners to
 * Magento's own native search input — id="search_mini_form", input name="q" —
 * a framework-level HTML contract, not a theme convention (confirmed intact
 * even on a heavily customized Hyvä theme in the source engagement). Never
 * moves, wraps, or replaces the input.
 *
 * Responsive themes (Hyvä especially) render the header search form MORE THAN
 * ONCE — a mobile copy and a desktop copy, one hidden by CSS at any given
 * breakpoint, both with id="search_mini_form". So this binds every matching
 * input, not just the first: the hidden copies never receive focus/input
 * events, and whichever one the shopper actually uses drives the overlay and
 * the navigation.
 *
 * This does not need to hide the native input to satisfy "no fallback": typing
 * always opens this overlay instead of native suggestions, and submitting
 * navigates to the search-results page — which the takeover claims immediately
 * on load regardless of how the shopper arrived there.
 *
 * IMPORTANT — Enter/submit is intercepted in the capture phase, not left to
 * the browser's default navigation, so it wins over any bubble-phase handler a
 * theme binds to the same form (confirmed necessary on WeltPixel Pearl, where
 * the theme hijacks Enter into a client-side hash change).
 */

const MIN_QUERY_LENGTH = 3;
const DEBOUNCE_MS = 250;
const INPUT_SELECTOR = '#search_mini_form input[name="q"]';

/**
 * @param {{
 *   onQuery: (query: string) => void,
 *   onClose: () => void,
 * }} handlers
 * @returns {{ destroy: () => void, input: HTMLElement|null }}
 */
export function attachToNativeSearch(handlers) {
    const inputs = Array.from(document.querySelectorAll(INPUT_SELECTOR));
    if (inputs.length === 0) {
        // Documented escape hatch for a genuinely non-standard theme
        // (search_input_selector config override) — nothing to attach to.
        return { destroy() {}, input: null };
    }

    let debounceTimer = null;
    const teardowns = [];

    const navigateToResults = (form, query) => {
        if (!query) return;
        const base = (form && form.getAttribute('action')) || '/catalogsearch/result/';
        const separator = base.includes('?') ? '&' : '?';
        window.location.href = base + separator + 'q=' + encodeURIComponent(query);
    };

    inputs.forEach((input) => {
        const form = input.closest('form') || document.getElementById('search_mini_form');

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

        const onKeydownCapture = (e) => {
            if (e.key === 'Escape') {
                handlers.onClose();
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                e.stopImmediatePropagation();
                navigateToResults(form, input.value.trim());
            }
        };

        const onSubmitCapture = (e) => {
            e.preventDefault();
            e.stopImmediatePropagation();
            navigateToResults(form, input.value.trim());
        };

        input.addEventListener('input', onInput);
        input.addEventListener('focus', onFocus);
        input.addEventListener('keydown', onKeydownCapture, true);
        if (form) {
            form.addEventListener('submit', onSubmitCapture, true);
        }

        teardowns.push(() => {
            input.removeEventListener('input', onInput);
            input.removeEventListener('focus', onFocus);
            input.removeEventListener('keydown', onKeydownCapture, true);
            if (form) {
                form.removeEventListener('submit', onSubmitCapture, true);
            }
        });
    });

    return {
        destroy() {
            clearTimeout(debounceTimer);
            teardowns.forEach((fn) => fn());
        },
        // boot.js positions the live-typing overlay relative to, and detects
        // clicks outside, "the search input". With multiple copies it uses
        // whichever is currently visible (has layout boxes), falling back to
        // the first.
        get input() {
            return inputs.find((el) => el.offsetParent !== null) || inputs[0];
        },
    };
}
