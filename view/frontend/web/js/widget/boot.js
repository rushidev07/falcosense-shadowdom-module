import { readConfig } from './shadow-root.js';
import { attachToNativeSearch } from './search-attach.js';
import { mountTakeover } from './takeover.js';
import { mountCategoryEnhancement } from './category-enhancement.js';
import { addToCart, readFormKey } from './cart.js';

/**
 * Entry point. One <falcosense-root> element, mounted once via the additive
 * before.body.end layout block, present on every page. What it does depends on
 * the server-supplied page context in its config — see the implementation
 * plan §4 (Phase 1/2).
 */
class FalcoSenseRoot extends HTMLElement {
    connectedCallback() {
        if (this._booted) return; // Hyvä/Magento partial re-renders can reinsert this node
        this._booted = true;

        const config = readConfig(this);
        if (!config || !config.active) {
            return; // config says FalcoSense isn't active for this site/store — do nothing at all
        }

        this._searchAttachment = attachToNativeSearch({
            onQuery: (query) => this.openOverlay(query, config),
            onClose: () => this.closeOverlay(),
        });

        // Generic close signal, independent of Escape/empty-input: closes the
        // live-typing overlay on any click outside it. This is what makes
        // closing actually work regardless of how a given theme's own search
        // UI gets dismissed — e.g. a theme that clears the input via jQuery's
        // .val() (which fires no real 'input' event, so onClose above would
        // never otherwise run) still gets caught here, since the click that
        // dismissed it necessarily lands outside our own shell.
        this._outsideClickHandler = (e) => {
            if (!this._overlayHandle) return;
            const path = e.composedPath();
            if (this._overlayHost && path.includes(this._overlayHost)) return;
            if (this._searchAttachment.input && path.includes(this._searchAttachment.input)) return;
            this.closeOverlay();
        };
        document.addEventListener('click', this._outsideClickHandler);

        // Search and category deliberately accept opposite trade-offs. Search has
        // no native full-text-search equivalent to fall back to, so it takes over
        // the full page with no fallback (takeover.js). Category always has a
        // working native grid underneath, so it only ever enhances in place once
        // real data has arrived, and never touches the page at all on failure
        // (category-enhancement.js) — see Helper\Data::isCategoryEnhancementEnabled.
        // Header search (attachToNativeSearch, above) runs on every page type
        // regardless of either branch below.
        if (config.pageType === 'search') {
            this._takeoverHandle = mountTakeover(this, {
                productsApiUrl: config.productsApiUrl,
                cartAddUrl: config.cartAddUrl,
                mediaBaseUrl: config.mediaBaseUrl,
                searchToken: config.searchToken,
                pageType: config.pageType,
                categoryName: config.categoryName,
                searchQuery: config.searchQuery,
                perPage: config.perPage || 24,
                themeAccentColor: config.themeAccentColor,
            });
        } else if (config.pageType === 'category' && config.categoryEnhancementActive) {
            mountCategoryEnhancement(this, {
                productsApiUrl: config.productsApiUrl,
                cartAddUrl: config.cartAddUrl,
                mediaBaseUrl: config.mediaBaseUrl,
                searchToken: config.searchToken,
                categoryName: config.categoryName,
                perPage: config.perPage || 24,
                nativeGridSelector: config.nativeGridSelector,
                themeAccentColor: config.themeAccentColor,
            });
        }
    }

    disconnectedCallback() {
        if (this._searchAttachment) {
            this._searchAttachment.destroy();
        }
        if (this._outsideClickHandler) {
            document.removeEventListener('click', this._outsideClickHandler);
        }
        this._booted = false;
    }

    /**
     * The live "type 2+ letters -> real SPA-style results" experience,
     * per the target architecture doc (§3: "Typing 3+ letters triggers the
     * exact same live SPA-style overlay experience"). Reuses takeover.js's
     * real product-grid/filters/pagination rendering rather than a
     * simplified, overlay-only text-link view, so the header preview and the
     * committed search-results page always look and behave identically —
     * one rendering engine, not two that can drift apart. Mounted once on
     * the first query, then updated in place via setQuery() on every
     * keystroke rather than remounting (avoids re-attaching the add-to-cart
     * listener and re-running the SSR-shell-adoption check on every
     * keystroke).
     */
    openOverlay(query, config) {
        if (this._takeoverHandle) {
            // Already on the committed search-results page — its own takeover
            // instance is the results the shopper is looking at, not a preview
            // to be replaced. Reusing it avoids mounting a second, overlapping
            // .fs-shell at the same fixed screen position, which is the most
            // likely cause of "overlay not displaying properly": two shells
            // independently mounting/fetching on top of each other.
            this._takeoverHandle.show();
            this._takeoverHandle.setQuery(query);
            return;
        }

        if (!this._overlayHandle) {
            if (!this._overlayHost) {
                this._overlayHost = document.createElement('div');
                document.body.appendChild(this._overlayHost);
            }
            this._overlayHandle = mountTakeover(this._overlayHost, {
                productsApiUrl: config.productsApiUrl,
                cartAddUrl: config.cartAddUrl,
                mediaBaseUrl: config.mediaBaseUrl,
                searchToken: config.searchToken,
                pageType: 'search',
                searchQuery: query,
                perPage: config.perPage || 24,
                themeAccentColor: config.themeAccentColor,
                anchorEl: this._searchAttachment.input,
            });
            return;
        }
        this._overlayHandle.show();
        this._overlayHandle.setQuery(query);
    }

    closeOverlay() {
        if (this._takeoverHandle) {
            return; // the committed results page itself — never hide it
        }
        if (this._overlayHandle) {
            this._overlayHandle.hide();
        }
    }
}

if (!customElements.get('falcosense-root')) {
    customElements.define('falcosense-root', FalcoSenseRoot);
}

// Exposed only so cart.js's shared logic can be triggered from markup this
// module doesn't directly own (e.g. a slider's own Add to Cart button, per
// the implementation plan §1's third decision) without duplicating the call.
window.FalcoSenseCart = { addToCart, readFormKey };
