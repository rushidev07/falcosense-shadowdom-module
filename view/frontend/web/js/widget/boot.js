import { getOrCreateShadowRoot, readConfig } from './shadow-root.js';
import { attachToNativeSearch } from './search-attach.js';
import { fetchSuggestions } from './fetch-results.js';
import { mountTakeover } from './takeover.js';
import { addToCart, readFormKey } from './cart.js';

const OVERLAY_CSS = `
:host { all: initial; }
.fs-overlay { font-family: system-ui, -apple-system, sans-serif; position: fixed; top: 0; left: 0; right: 0;
  max-height: 70vh; overflow-y: auto; background: #fff; box-shadow: 0 8px 24px rgba(0,0,0,.15);
  z-index: 2147483000; padding: 16px 24px; }
.fs-overlay-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px; margin-top: 12px; }
.fs-overlay .fs-card { border: 1px solid #f0f0f0; border-radius: 8px; overflow: hidden; }
`;

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

        if (config.pageType === 'category' || config.pageType === 'search') {
            mountTakeover(this, {
                productsApiUrl: config.productsApiUrl,
                cartAddUrl: config.cartAddUrl,
                mediaBaseUrl: config.mediaBaseUrl,
                searchToken: config.searchToken,
                pageType: config.pageType,
                categoryName: config.categoryName,
                searchQuery: config.searchQuery,
                perPage: config.perPage || 24,
            });
        }
    }

    disconnectedCallback() {
        if (this._searchAttachment) {
            this._searchAttachment.destroy();
        }
        this._booted = false;
    }

    openOverlay(query, config) {
        if (!this._overlayHost) {
            this._overlayHost = document.createElement('div');
            document.body.appendChild(this._overlayHost);
        }
        const root = getOrCreateShadowRoot(this._overlayHost, OVERLAY_CSS);
        root.innerHTML = '<div class="fs-overlay"><div class="fs-overlay-status">Searching…</div></div>';

        fetchSuggestions(config.suggestUrl, config.searchToken, query)
            .then((data) => this.renderOverlay(root, data, config))
            .catch(() => {
                root.innerHTML = ''; // no fallback UI — quietly nothing, per implementation plan §1
            });
    }

    renderOverlay(root, data, config) {
        const overlay = root.querySelector('.fs-overlay');
        if (!overlay) return;

        overlay.innerHTML = '';
        const status = document.createElement('div');
        status.className = 'fs-overlay-status';
        status.textContent = `${(data.products || []).length} results`;
        overlay.appendChild(status);

        const grid = document.createElement('div');
        grid.className = 'fs-overlay-grid';
        for (const product of data.products || []) {
            const link = document.createElement('a');
            link.href = product.url_key ? '/' + product.url_key + '.html' : '#';
            link.className = 'fs-card';
            link.textContent = product.name || '';
            grid.appendChild(link);
        }
        overlay.appendChild(grid);
        void config;
    }

    closeOverlay() {
        if (this._overlayHost && this._overlayHost.shadowRoot) {
            this._overlayHost.shadowRoot.innerHTML = '';
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
