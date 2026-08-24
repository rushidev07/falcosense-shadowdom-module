/**
 * Narrow, generic theme-token extraction. Deliberately not tied to Tailwind,
 * Hyvä, or any specific theme's internals — reading the browser's own
 * *resolved* computed style off a real element already on the page is
 * correct regardless of what produced that value (a Tailwind utility class,
 * Hyvä's own CSS, Luma's LESS, hand-written rules). getComputedStyle doesn't
 * care about the source, which is exactly why this approach generalizes
 * across clients instead of depending on any one framework's conventions.
 *
 * Exactly two tokens, on purpose: an accent color and a font family.
 * Anything broader starts assuming things about a theme's structure that
 * won't hold across every client.
 */

const ACCENT_CANDIDATE_SELECTORS = [
    '.action.primary',
    'button[type="submit"]',
    'a.action.primary',
];

/**
 * @param {string} value
 * @returns {boolean}
 */
function isUsableColor(value) {
    if (!value) {
        return false;
    }
    const normalized = value.trim().toLowerCase();
    return normalized !== '' && normalized !== 'transparent' && normalized !== 'rgba(0, 0, 0, 0)';
}

/**
 * Tries each candidate selector in order and takes the first real, usable
 * background color found. Order matters: `.action.primary` is Magento's own
 * long-standing convention for the main call-to-action (Add to Cart, place
 * order, etc.) and is kept in many Hyvä themes for backward compatibility
 * with exactly this kind of third-party read — but it isn't guaranteed
 * universal the way #search_mini_form is, so this degrades through more
 * generic candidates rather than trusting one selector blindly.
 *
 * @returns {{accentColor: string|null, fontFamily: string|null}}
 */
function extractFromDom() {
    let accentColor = null;
    for (const selector of ACCENT_CANDIDATE_SELECTORS) {
        const el = document.querySelector(selector);
        if (!el) {
            continue;
        }
        const background = window.getComputedStyle(el).backgroundColor;
        if (isUsableColor(background)) {
            accentColor = background;
            break;
        }
    }

    const bodyFont = window.getComputedStyle(document.body).fontFamily;
    const fontFamily = bodyFont && bodyFont.trim() !== '' ? bodyFont : null;

    return { accentColor, fontFamily };
}

/**
 * Applies extracted (or explicitly configured) theme tokens as CSS custom
 * properties on `hostEl`. Custom properties inherit through the shadow
 * boundary by spec, so anything set here reaches every shadow root the
 * widget creates on `hostEl` or its descendants — the one deliberate,
 * narrow crossing point in an otherwise sealed boundary, never a full style
 * leak in either direction.
 *
 * An explicit merchant-configured override always wins over DOM extraction:
 * more reliable than any heuristic, and gives a merchant a way to fix a
 * mismatch without this module having to special-case their theme's markup.
 *
 * Never throws, and never leaves a partial or invalid value applied — on any
 * failure this simply sets nothing, so the widget's own CSS defaults
 * (`var(--fs-theme-accent, <our default>)`) apply unchanged. A styling
 * nicety must never be capable of affecting whether the widget boots.
 *
 * @param {HTMLElement} hostEl
 * @param {{accentColor?: string|null, fontFamily?: string|null}} [explicitOverride]
 */
export function applyThemeTokens(hostEl, explicitOverride = {}) {
    try {
        const detected = extractFromDom();

        const accentColor = explicitOverride.accentColor || detected.accentColor;
        if (accentColor && isUsableColor(accentColor)) {
            hostEl.style.setProperty('--fs-theme-accent', accentColor);
        }

        const fontFamily = explicitOverride.fontFamily || detected.fontFamily;
        if (fontFamily) {
            hostEl.style.setProperty('--fs-theme-font', fontFamily);
        }
    } catch (e) {
        // Deliberately swallowed — see docblock above.
    }
}
