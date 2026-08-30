# FalcoSense PLP / ISR Rebuild — Build Tracker

*Live checklist for the category/search SSR + ISR rebuild in `Ahy_SmartSearchLuma`. Merges the two research passes (see `FALCOSENSE-TARGET-ARCHITECTURE.md` §8 and the drift analysis).*

**Status: all 5 phases built (Phases A–E).** Dormant behind `smart_search/plp/ssr_enabled` (default off). Not yet run against a real Magento — see the verification checklist at the bottom. The one true blocker to going live is a server-callable platform PLP endpoint (C2); until then the module renders `unavailable` and falls back to the native grid everywhere.

### How the pieces fit

```
request → PageContext (is this a listing? build PlpQuery)
        → PlpContextProvider (memoised per request; C1 coverage guard)
            → PlpDataProviderInterface
                → CachedPlpProvider   (fresh snapshot? → serve; else fetch, cache _fresh + _lkg;
                │                       platform down → _lkg marked stale; nothing → unavailable)
                └→ OpenSearchPlpProvider → PlatformHttpClient  (GET, 500ms budget, the only wire-format code)

render:  Block\Plp\Grid  → PlpRenderer   → light-DOM <div class="fs-plp"> + embedded payload + <a> pagination
                         → JsonLdBuilder → ItemList + BreadcrumbList (canonical view only)
         CategoryListPlugin → empties the native collection so only our grid shows products
         PlpSeoObserver     → NOINDEX,FOLLOW on filtered/sorted/paged URLs

client:  boot.js sees .fs-plp → plp-hydrate.js
            → hydrate cards in place (quick add-to-cart), never rebuild
            → plp-chrome.js  = sort/filter/price/chips in a Shadow DOM root (sibling of .fs-plp)
            → on change: plp-fragment.js GET /smsl/plp/grid → swap <div class="fs-plp"> → re-hydrate → pushState

invalidate: ProductSave/StockChange observer → sync to platform → THEN CacheInvalidator
            (per-tag snapshot clean + clean_cache_by_tags for FPC/Varnish)
            Cron\ProductSync → one coalesced purge at end of run
            Cron\PlpCacheWarmer → keeps top-N category snapshots hot (opt-in)
```

---

## Decisions locked

| # | Decision | Rationale |
|---|---|---|
| D1 | **PHP is the only grid renderer.** Filter/sort/page interactions fetch a server-rendered HTML fragment from `/smsl/plp/grid` and swap it. The only JS renderer kept is the ephemeral type-ahead overlay (not SEO, allowed to differ). | Removes the "two renderers must stay byte-identical" tax (C3) instead of trying to share templates across PHP and JS. |
| D2 | **Namespace stays `Ahy\SmartSearchLuma`.** | All existing code, config paths, routes, and the implementation plan are scoped to it. Renaming is a separate call. |
| D3 | **Platform PLP data comes through a Port + Adapter** (`PlpDataProviderInterface` → `OpenSearchPlpProvider`), against a documented assumed response contract. | Unblocks the build now; only the adapter changes when the real endpoint contract is known. Matches the Ports & Adapters spine in `FALCOSENSE-TARGET-ARCHITECTURE.md` §5. |
| D4 | **Single source of truth = the platform.** Magento's catalog DB is a *sync input*, not a *render source*. The one exception is the fail-open fallback path. | The drift is caused by rendering one page from two sources; the fix is one source. |
| D5 | **ISR = FPC/Varnish for the page + a data cache for the payload + last-known-good blob.** Blanket TTL 5–15 min, plus event-driven purge on product change. | "No drift" is really "bounded drift ≤ TTL minus purges" — the standard compromise. |
| D6 | **Light DOM = crawlable content (grid + JSON-LD). Shadow DOM = interactive chrome only.** The grid is never moved into a shadow root. | AI answer-engine crawlers don't run JS or parse shadow DOM. Grid-in-shadow = invisible to AEO. |

---

## Phase A — Single-source server render (the drift fix)

- [x] `Api/PlpDataProviderInterface.php` — the Port
- [x] `Model/Plp/PlpQuery.php` — request value object + deterministic cache key
- [x] `Model/Plp/PlpItem.php` — one card's data
- [x] `Model/Plp/PlpFacet.php` — one filter group
- [x] `Model/Plp/PlpResult.php` — full payload VO (`toArray()`/`fromArray()`, `source`, `fetchedAt`)
- [x] `Service/Plp/PlatformRequestException.php`
- [x] `Service/Plp/PlatformHttpClient.php` — one shared cURL GET helper (ms timeouts), replaces ad-hoc call sites for this path
- [x] `Service/Plp/OpenSearchPlpProvider.php` — the Adapter (assumed contract, documented)
- [x] `Model/Cache/Type/Plp.php` + `etc/cache.xml` — dedicated cache type
- [x] `Service/Plp/CachedPlpProvider.php` — TTL cache + last-known-good decorator
- [x] `Model/Plp/PageContext.php` — is-this-a-PLP + build the `PlpQuery`
- [x] `Model/Plp/PlpContextProvider.php` — request-memoized resolve, shared by all blocks
- [x] `Helper/Data.php` — config accessors (ssr enabled, ttl, timeout, C1 fallback ratio)
- [x] `etc/config.xml` + `etc/adminhtml/system.xml` — new `smart_search/plp/*` group
- [x] `etc/di.xml` — Port preference + adapter wiring
- [x] `etc/module.xml` — sequence Catalog / CatalogSearch / PageCache
- [x] Unit tests: `PlpQueryTest`, `PlpResultTest`, `OpenSearchPlpProviderTest`, `CachedPlpProviderTest`

**Phase A status:** complete and DI-wired. The whole pipeline is dormant until `smart_search/plp/ssr_enabled` is turned on per store (default `0`).

## Phase B — Caching / ISR delivery  ✅

- [x] `Block\Plp\Grid` implements `IdentityInterface` — `getIdentities()` = `cat_c_<id>` + every `cat_p_<id>` in the payload; stale render drops to a 120s cache lifetime
- [x] `Model/Plp/CacheInvalidator.php` — per-tag clean of the snapshot cache + `clean_cache_by_tags` event for FPC/Varnish; auto-falls back to a full flush past 300 tags
- [x] `Model/Plp/TagCarrier.php` (`IdentityInterface` carrier), `Model/Plp/AffectedCategoryResolver.php` (product IDs → product + category + ancestor tags, one raw query)
- [x] Purge is **synchronous, after the sync push** — `ProductSaveObserver` / `StockChangeObserver` invalidate only once `ProductSyncService::sync()` has returned (no MQ topic added — the module already has too many async mechanisms; `CacheInvalidator` is the seam if async is wanted later)
- [x] Bulk coalescing — `Cron/ProductSync` collects changed IDs across all batches and does **one** purge at the end (`invalidateAll()` for a full sync or >2000 changes)
- [x] `AttributeChangeDetector::WATCHED` += `image`, `small_image`, `thumbnail` (C5)
- [x] `Cron/PlpCacheWarmer.php` + `*/5` crontab entry — warms the top N in-menu categories per store (off by default)

## Phase C — Fragment endpoint + widget rework  ✅

- [x] `Model/Plp/PlpRenderer.php` — the ONE grid renderer: wrapper + embedded `fs-plp-payload` JSON + toolbar (real GET sort form) + cards + real `<a rel=prev/next>` pagination
- [x] `Block/Plp/Grid.php` + `view/frontend/templates/plp/grid.phtml` — light-DOM grid, scoped reset, hides the emptied native grid via the admin selector
- [x] `Controller/Plp/Grid.php` → `GET /smsl/plp/grid` — same `PlpRenderer`, params sanitised, `Cache-Control: public` when fresh
- [x] `CategoryListPlugin` rewritten to one job: empty the native collection when `PlpContextProvider->isActive()`, else `proceed()`. **Registered** in new `etc/frontend/di.xml` (it was never actually wired before). `Service/OpenSearchService.php` deleted (only that dead plugin used it).
- [x] `Block\Category::isEnabled()` + `AddFrontendLayoutHandle` now stand down when SSR is on — legacy `results.phtml` and the Luma-name removals never fire alongside the SSR grid
- [x] Widget: `boot.js` detects `.fs-plp` and runs `plp-hydrate.js` (hydrate-in-place, no rebuild) → `plp-chrome.js` (filter/sort/price/chips in a shadow root) → `plp-fragment.js` (fetch + swap on interaction, `pushState`). Legacy `takeover.js` / `category-enhancement.js` stay as the pre-SSR fallback.
- [x] `Block\Widget\Bootstrap` emits `plpGridUrl` + `tokenRefreshUrl`
- [x] FPC-safe token: `Controller/Search/Token.php` (`GET /smsl/search/token`, `no-store`) + `takeover.js` refreshes once on a 401/403 from the overlay's platform call

## Phase D — SEO / AEO  ✅

- [x] `Model/Plp/JsonLdBuilder.php` — `ItemList` (+ per-item `Product`/`Offer`/`AggregateRating`) and `BreadcrumbList`, from the same `PlpResult` as the grid
- [x] `Model/Plp/BreadcrumbResolver.php` — category trail for the breadcrumb JSON-LD
- [x] Real `<a href="?p=N">` pagination in `PlpRenderer`
- [x] `Observer/PlpSeoObserver.php` — `NOINDEX,FOLLOW` on any non-canonical (filtered/sorted/paged) SSR listing; canonical is left to Magento's native `catalog/seo/category_canonical_tag` to avoid a double tag
- [x] JSON-LD only on the canonical view; noindex views carry none

## Phase E — Hardening  ✅

- [x] Every new flag defaults OFF (`plp/ssr_enabled=0`, `plp/warm_enabled=0`, `plp/fallback_min_ratio=0`)
- [x] Fail-open chain verified by construction: platform slow → last-known-good blob → native grid; JS off → server grid + real `<a>` pagination + GET sort form; unknown theme → `CategoryListPlugin` targets a core method, not a block name
- [x] Additive layout only — the SSR grid is added; the native grid is *emptied at the collection level*, never `remove=`d
- [x] C9 fixed — `getPlatformStoreId()` now reads an explicit per-store-view `smart_search/general/platform_store_id` mapping, position-derivation only as fallback
- [x] Namespace-clean; no assumption of being the only module (routes/blocks all `ahy_*` / `smsl` / `Ahy\SmartSearchLuma`). Coexistence with the Everest `FalcoSense\Search` fork is an integration-phase concern (both claim the `catalogsearch` route override + `fs` frontname — must be reconciled at cutover).

### Recommended config for a new install
`frontend_enabled=1`, `widget_enabled=1`, `plp/ssr_enabled=1`. `ssr_enabled=1` alone also works (theme-agnostic — the destructive Luma handle stands down), `widget_enabled=1` adds the shadow-DOM hydration/chrome.

---

## Open / deferred concerns

| Ref | Concern | Status |
|---|---|---|
| C1 | Sync completeness — DecorPrice ~4.6k/100k synced. Rendering from a 5%-populated platform = broken pages. | **Still blocking for go-live.** The `plp/fallback_min_ratio` guard makes it degrade to native per-category instead of shipping a half-empty grid, but the real fix is a platform quota bump. |
| C2 | **Platform must expose a server-callable PLP endpoint** returning full display data + facets + total. `OpenSearchPlpProvider` is written against a documented assumed contract (its class header). | **Confirm the real contract, then adjust only that adapter.** Until then SSR renders `unavailable` → native fallback everywhere. |
| C7 | TTL vs staleness dial — tune per client; say so in the CEO deck. | `smart_search/plp/cache_ttl` exposed (default 600s). Stale renders self-limit to a 120s page cache. |
| C8 | Per-request platform dependency on cache-miss. | Mitigated: FPC + `PlpCacheWarmer` + 500ms timeout + last-known-good blob. Not eliminated. |
| C10 | `SearchTokenService` hardcodes `/tmp` (breaks token cache across load-balanced nodes). | Not touched. The new `Controller/Search/Token.php` re-fetches per node, so it's a redundant-fetch inefficiency, not a correctness bug, for now. |
| — | `Controller/Plp/Grid` `Cache-Control` may be overridden by Magento's response pipeline on some setups. | The data-layer cache underneath makes repeat fragment loads cheap regardless; CDN-caching the fragment is a bonus, not load-bearing. |
| — | Fragment interactions re-hydrate but `popstate` (browser Back) does a full `location.reload()`, not an SPA restore. | Acceptable v1 — correct, just not seamless. |

## Post-build verification checklist (needs a real Magento)

- [ ] `bin/magento setup:upgrade && setup:di:compile` clean
- [ ] `vendor/bin/phpunit -c dev/tests/unit` — the 8 new `Test/Unit/**/Plp` suites
- [ ] With `plp/ssr_enabled=0`: site behaves exactly as before (nothing new renders)
- [ ] With it on + a real endpoint: category page has a crawlable `<div class="fs-plp">` + `ItemList` JSON-LD in view-source, before any JS
- [ ] JS disabled → grid still there, `<a>` pagination + GET sort form work
- [ ] Platform stopped → page still renders (last-known-good, `data-fs-source="platform_stale"`), then native once the blob is gone
- [ ] Save a product → its category pages drop from FPC within one request; the snapshot cache entry is gone
- [ ] `?p=2` / `?product_list_order=` → `<meta name="robots" content="NOINDEX,FOLLOW">` present
