# FalcoSense Shadow DOM Implementation Plan

*A reviewable, editable build plan for `FalcoSense-module-main` (`Ahy_SmartSearchLuma`) only — not Everest, not DecorPrice. Add, remove, or reorder anything below; this is meant to be marked up.*

---

## 0. Scope and ground rules

- **In scope:** the base module's three real screens — the site-wide header search bar, the category page, the search results page — plus the add-to-cart action they trigger.
- **Explicitly excluded from this plan:** sliders (placed by merchants anywhere, not owned screens), and the Product Detail Page (currently untouched by the module — **open question, see §7**, not silently assumed either way).
- **Governing rule:** every change here only adds to the module. Nothing in this plan involves removing or rewriting anything belonging to a theme. The module's own old code is what gets removed, on our own schedule, once its replacement is proven — never a client's.
- **Definition of done for this whole effort:** the module can be installed on any Magento theme without layout edits, never leaves the site broken if it fails, never breaks an existing add-to-cart flow, and doesn't need to know what search engine or theme it's replacing.

---

## 1. Current state — exactly what exists today and what happens to each piece

| File / class | What it does today | What happens to it |
|---|---|---|
| `Observer/AddFrontendLayoutHandle.php` | Turns on the layout handle that deletes native blocks | Retired in Phase 2 |
| `view/frontend/layout/ahy_smartsearch_active.xml` | Contains the actual `remove="true"` rules (`top.search`, `category.products`, `sidebar.main`, `search_result_list`, `search.result`) | Retired in Phase 2 |
| `view/frontend/layout/default.xml` | Adds the header search block + geo-consent script | Edited in Phase 1 (additive addition, not a rewrite) |
| `view/frontend/layout/catalog_category_view.xml`, `catalogsearch_result_index.xml` | Insert the `Category` block + remove native grid/sidebar via the handle above | Replaced in Phase 2 with additive-only versions |
| `view/frontend/templates/category/results.phtml` | The 1,366-line file: markup, ~300 lines of CSS, ~700 lines of JS (fetch, filter, sort, paginate, cart, wishlist) all in one place | Retired in Phase 2, replaced by the widget bundle |
| `view/frontend/templates/category/product_card.phtml`, `product_options.phtml` | Child templates for the card/options markup used by `results.phtml` | Retired in Phase 2 (rendering moves inside the widget) |
| `view/frontend/templates/search/mini.phtml`, `view/frontend/web/js/search-panel.js` | Current header mini-search overlay | Retired in Phase 1 |
| `view/frontend/web/css/grid-3col.css` | CSS fighting Luma's own grid classes with `!important` | Retired in Phase 2 (Shadow DOM makes this file's entire purpose unnecessary) |
| `Block/Category.php`, `Block/Search.php` | Supply individual getter methods (token, URLs, config) consumed by inline PHP in the templates above | Reworked in Phase 1/2 to emit one config JSON payload instead |
| Current add-to-cart path (inline JS in `results.phtml`, SKU-string variant guessing) | Resolves a shopper's color/size pick by parsing the SKU string, then POSTs the resolved child product ID directly to `checkout/cart/add` | Retired in Phase 3, replaced by the adapter/resolver pair below |
| `Controller/Product/VariantImages.php` | Fetches variant images for the current guess-based variant logic | Reviewed in Phase 3 — likely reusable once the resolver is real, not guessed |
| `Block/NoResultsModal.php`, `view/frontend/templates/no-results-modal.phtml` | Already dead code today — not wired into any layout | Deleted outright, independent of this plan (pure cleanup) |
| `view/frontend/templates/product/list.phtml`, `product/list/item.phtml` | Already dead code today — not referenced by any layout | Deleted outright, independent of this plan (pure cleanup) |
| Everything in `Model/`, `Service/`, `Cron/`, `Console/`, `Observer/` related to sync (`ProductSyncService`, `FullSyncService`, `WebhookPublisher/Consumer`, etc.) | The sync pipeline — pushing catalog data out to the platform | **Untouched by this plan.** Not a rendering concern. Out of scope here. |

---

## 2. New structure being added

All new code lives alongside the old code until each phase's cutover point — nothing below replaces anything until its "exit criteria" in §3 are met.

```
Api/
  CartAdapterInterface.php          (new — the cart Port)
  VariantResolverInterface.php      (new — the variant-resolution Port)

Model/
  Cart/
    NativeCartAdapter.php           (new — default CartAdapterInterface implementation,
                                      calls Magento's real cart/quote APIs)
    NativeVariantResolver.php       (new — default VariantResolverInterface implementation,
                                      uses Magento's real configurable-attribute API)

Controller/
  Cart/
    Add.php                        (new — the one endpoint the widget calls:
                                      POST /smsl/cart/add)
  Config/
    Get.php                        (new — the config-bootstrap endpoint, if a full page
                                      reload isn't sufficient for token refresh)

view/frontend/
  web/js/widget/                   (new — the actual Shadow DOM widget source, organized
                                     as small, single-job files, not one script:
                                     - boot.js            (reads config, decides to mount)
                                     - shadow-root.js      (creates/attaches the boundary)
                                     - search-attach.js    (listens to the native search input)
                                     - fetch-results.js    (talks to the platform/search API)
                                     - render-cards.js     (product data -> card markup)
                                     - filters-sort.js      (filter/sort state + UI)
                                     - cart.js             (calls Controller/Cart/Add.php,
                                                             dispatches the "added to cart" event)
                                     - fail-open.js        (the readiness gate — everything
                                                             above only runs if this passes)
  layout/
    default.xml                    (edited, additive only — mount point + config bootstrap
                                     added to before.body.end, header search left as-is
                                     until Phase 1 cuts it over)
```

---

## 3. Phased build plan

Each phase has a single flag: `smart_search/general/frontend_enabled` already exists and can gate all of this the same way it gates the current UI — no new flag needed unless we want the old and new systems addressable independently during the transition (see §7).

### Phase 0 — Scaffolding, nothing user-facing changes
- [ ] Create `Api/CartAdapterInterface.php` and `Api/VariantResolverInterface.php`
- [ ] Create `Model/Cart/NativeCartAdapter.php` and `Model/Cart/NativeVariantResolver.php` with real implementations, wired via `di.xml` preferences, but not called from anywhere yet
- [ ] Scaffold `view/frontend/web/js/widget/` with the file breakdown above, each file a stub
- [ ] **Exit criteria:** module installs and runs exactly as it does today; nothing new is reachable yet

### Phase 1 — Header search bar
- [ ] Build `search-attach.js`: listen to `#search_mini_form input[name=q]` without touching the existing template
- [ ] Build `shadow-root.js`, `fetch-results.js`, `render-cards.js`, `fail-open.js` for the search overlay specifically
- [ ] Add the additive mount point + config bootstrap to `view/frontend/layout/default.xml`
- [ ] Retire `view/frontend/templates/search/mini.phtml` and `view/frontend/web/js/search-panel.js` once the new overlay is confirmed equivalent
- [ ] **Exit criteria:** with JS disabled, the native search box still submits and works; with JS enabled, typing opens the new overlay; no CSS/JS from the theme can be shown to reach the widget (verified, not assumed) and vice versa

### Phase 2 — Category page + search results page
- [ ] Extend `render-cards.js`/`filters-sort.js` to cover the full grid: filters, sort, pagination
- [ ] Replace `ahy_smartsearch_active.xml`'s removal rules and `Observer/AddFrontendLayoutHandle.php` with the additive, fail-open mounting pattern (native grid stays untouched until the widget proves it has data)
- [ ] Retire `view/frontend/templates/category/results.phtml`, `product_card.phtml`, `product_options.phtml`, and `view/frontend/web/css/grid-3col.css`
- [ ] **Exit criteria:** disabling the module (or the widget failing to load) leaves the native category/search page fully intact and functional; no block is ever deleted at layout-compile time

### Phase 3 — Add-to-cart correctness
- [ ] Wire `cart.js` to `POST /smsl/cart/add` with only `{platform_product_id, selected_options, qty}` — no Magento internals in the request
- [ ] Implement `NativeVariantResolver` against Magento's real configurable-attribute API (`getConfigurableAttributesAsArray` or equivalent) — delete the SKU-string parsing logic entirely
- [ ] Implement `NativeCartAdapter` calling Magento's real `addProduct`/cart-repository flow
- [ ] **Exit criteria:** a configurable product added via the widget shows correct color/size on the cart line item; an existing plugin on the native add-to-cart event (test with any sample observer) still fires

### Phase 4 — Retirement + optional SSR-Shell
- [ ] Delete every file marked "retired" in §1 that hasn't been deleted yet
- [ ] Delete the now-dead `frontend_enabled`-gated old code paths entirely — one implementation, not two
- [ ] Add SSR-Shell as an explicit, separate config option (per-store), using Declarative Shadow DOM, populated from already-synced local data — not built by default, only if §7's SEO question resolves toward "yes, needed"
- [ ] **Exit criteria:** module has exactly one rendering path for each screen; old path no longer exists to compare against

---

## 4. Validation checklist (run at the end of every phase, not just once at the end)

- [ ] Disable JavaScript entirely — confirm the native page still works
- [ ] Simulate the platform being unreachable — confirm the widget fails open, page unaffected
- [ ] Inspect via view-source that no native block was removed by layout XML in the new version
- [ ] Confirm a broad/generic theme CSS rule cannot visually affect the widget, and the widget's CSS cannot leak out
- [ ] Confirm an existing cart-related plugin/observer still fires after an add-to-cart through the widget
- [ ] Confirm the module can be fully disabled via config and the site reverts to 100% native behavior

---

## 5. Explicitly out of scope for this plan

- The sync pipeline (cron, message queue, `ProductSyncService`, etc.) — untouched
- Everest's specific fork and its entanglement with Klevu/Webkul/ThemeCustomization — separate effort, separate document
- DecorPrice's benchmark work — already done, informs this plan, not part of it
- Sliders — placed by merchants, not a fixed screen this plan touches

---

## 6. Open questions for review before or during build

1. **Is the Product Detail Page in scope at all, now or later?** Currently untouched by the module. Not assumed either way.
2. **Does the old and new system need to run side by side under two independent flags**, or is reusing `frontend_enabled` as a single on/off switch enough? Affects how safely Phase 1-3 can be tested in isolation.
3. **Old code deletion timing** — deleted immediately at the end of each phase once proven, or kept one release cycle longer as a rollback safety net?
4. **SSR-Shell** — build it as part of this plan's Phase 4, or treat it as a fully separate follow-up plan once the core widget is live?
5. **Naming** — new classes above assume the existing `Ahy\SmartSearchLuma` namespace. Confirm before Phase 0, since a rename later means touching every new file again.
