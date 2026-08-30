import { readConfig } from './shadow-root.js';
import { attachToNativeSearch } from './search-attach.js';
import { mountTakeover } from './takeover.js';
import { mountCategoryEnhancement } from './category-enhancement.js';
import { mountPlpHydration } from './plp-hydrate.js';
import { mountPlpSearch } from './plp-search.js';
import { addToCart, readFormKey } from './cart.js';

/**
 * Entry point. One <falcosense-root> element, mounted once via the additive
 * before.body.end layout block, present on every page.
 *
 * Rendering is unified on PlpRenderer:
 *   - a category or /fs/search page the server already rendered (Block\Plp\Grid)
 *     -> hydrate it in place (plp-hydrate.js);
 *   - typing 3+ letters in the header anywhere -> a full-page search takeover
 *     rendered from the SAME PlpRenderer fragment (plp-search.js), so the
 *     type-ahead result and the committed page look identical;
 *   - stores not yet on the SSR pipeline fall back to the legacy takeover /
 *     category-enhancement paths.
 */
class FalcoSenseRoot extends HTMLElement {
    connectedCallback() {
        if (this._booted) return; // Hyvä/Magento partial re-renders can reinsert this node
        this._booted = true;

        const config = readConfig(this);
        if (!config || !config.active) {
            return; // config says FalcoSense isn't active for this site/store
        }

        const hasSsrGrid = !!document.querySelector('.fs-plp .fs-plp-payload');
        const ssrListing = hasSsrGrid && (config.pageType === 'category' || config.pageType === 'search');

        // 1. Full-page type-ahead. Available whenever the SSR pipeline is on
        //    (plpGridUrl serves fragments). Falls back to the legacy overlay
        //    only when that fragment endpoint isn't there.
        if (config.plpGridUrl) {
            this._plpSearch = mountPlpSearch(config);
            this._searchAttachment = attachToNativeSearch({
                onQuery: (query) => this._plpSearch.setQuery(query),
                onClose: () => this._plpSearch.close(),
            });
        } else {
            this._searchAttachment = attachToNativeSearch({
                onQuery: (query) => this.openLegacyOverlay(query, config),
                onClose: () => this.closeLegacyOverlay(),
            });
        }

        // 2. This page is itself a server-rendered listing -> hydrate in place.
        if (ssrListing) {
            this._plpHandle = mountPlpHydration(this, config);
            if (this._plpHandle) {
                return;
            }
        }

        // 3. Legacy fallback for stores without the SSR grid.
        if (!hasSsrGrid && config.pageType === 'search') {
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
        } else if (!hasSsrGrid && config.pageType === 'category' && config.categoryEnhancementActive) {
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
        if (this._plpHandle && typeof this._plpHandle.destroy === 'function') {
            this._plpHandle.destroy();
        }
        if (this._plpSearch) {
            this._plpSearch.close();
        }
        this._booted = false;
    }

    // ── Legacy overlay (only when plpGridUrl is absent — SSR pipeline off) ──

    openLegacyOverlay(query, config) {
        if (this._takeoverHandle) {
            this._takeoverHandle.show();
            this._takeoverHandle.setQuery(query);
            return;
        }
        if (!this._overlayHandle) {
            if (!this._overlayHost) {
                this._overlayHost = document.createElement('div');
                document.body.appendChild(this._overlayHost);
            }
            if (!this._outsideClickHandler) {
                this._outsideClickHandler = (e) => {
                    if (!this._overlayHandle) return;
                    const path = e.composedPath();
                    if (this._overlayHost && path.includes(this._overlayHost)) return;
                    if (this._searchAttachment.input && path.includes(this._searchAttachment.input)) return;
                    this.closeLegacyOverlay();
                };
                document.addEventListener('click', this._outsideClickHandler);
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

    closeLegacyOverlay() {
        if (this._takeoverHandle) return;
        if (this._overlayHandle) this._overlayHandle.hide();
    }
}

if (!customElements.get('falcosense-root')) {
    customElements.define('falcosense-root', FalcoSenseRoot);
}

// Exposed so a slider's own Add to Cart button can reuse cart.js without
// duplicating the call.
window.FalcoSenseCart = { addToCart, readFormKey };
