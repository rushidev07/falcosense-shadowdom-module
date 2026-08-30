# dev2 Search — Diagnosis & Fixes (2026-08-30)

## What was wrong

dev2 had **three search implementations**, two active and fighting:

| # | What | Where | Was active |
|---|---|---|---|
| 1 | Theme's own FalcoSense search (`initMiniSearch` + `ahyModalSearch` + `ahyCfgModal` + full-screen `#ahyModal`) | `design/frontend/Ahy/Everest2/Magento_Theme/templates/html/header/search-form.phtml` — **2,008 lines**, wired to the **disabled** `FalcoSense_Search` module | yes |
| 2 | Module's legacy Luma search — floating magnifier + slide-down panel | `Ahy_SmartSearchLuma` `search/mini.phtml` + `search-panel.js` | yes, because `widget_enabled = 0` |
| 3 | Module's Shadow-DOM widget + SSR/ISR (`boot.js`, `plp-*`, `Block\Plp\Grid`) | `Ahy_SmartSearchLuma` | **no** — `widget_enabled = 0`, `plp/ssr_enabled = 0` |

Symptoms this produced:

- **Second search bar behind the header** → implementation #2. Its icon is `float:right` (lands in the announcement bar); its panel is `z-index:600` under a `z-index:601` header and only `padding-top:110px` on a ~250px-tall header.
- **`/fs/search` empty** → implementation #2's legacy `results.phtml` bakes the platform token into the HTML with no client refresh; the FPC-cached page serves a stale token → platform 401 → empty grid. (Worked briefly right after a cache flush.)
- **Two overlays on one input** would also have happened the moment `widget_enabled` was turned on, because #1 and #3 both bind `#search_mini_form input[name="q"]`.

Also found:
- The theme renders `#search_mini_form` **three times** (Hyvä responsive mobile/desktop copies). `search-attach.js` only bound the first — the *hidden* mobile copy on desktop.
- `catalogsearch` route override is commented out in `etc/frontend/routes.xml` — re-enabling it hits a pre-existing "Undefined array key 1" during layout generation.
- Theme's `search-form.phtml` imports `FalcoSense\Search\Helper\Data` and calls the dead route `smartsearch/search/token` (that module is disabled; its route is `smsl/search/token` now).

---

## Code fixes applied (in this repo copy)

| File | Change |
|---|---|
| `design/.../header/search-form.phtml` | **Replaced** the 2,008-line file with a minimal Everest-styled input. No Alpine modal, no `FalcoSense\Search` imports. `<form id="search_mini_form" action="{fs/search}" method="get">` — works with JS off; `search-attach.js` owns the type-ahead. Kept the temporary global `<style>` patch for the miscompiled `styles.css` grid columns (move that into real theme CSS later). **Rollback:** `search-form.phtml.ROLLBACK_full_20260813` (in the same folder) — the Aug-13 version (1912 lines), the closest available. The exact pre-edit version was not under git. |
| `view/frontend/web/js/widget/search-attach.js` | Binds **every** `#search_mini_form input[name="q"]`, not just the first — fixes the hidden-mobile-copy problem on responsive themes. `input` getter now returns the currently-visible copy. |
| `Model/Plp/PlpRenderer.php` | **Rebuilt** to reproduce the old production listing layout server-side: persistent **light-DOM** left filter rail (price bands + facet groups with counts + "See More" + active-filter chips) beside the grid, and rich cards (wishlist bookmark, "Sold By …", From/Deal pricing, FREE Shipping, Add/Options). Still `.fs-plp*`-namespaced. |
| `view/frontend/templates/plp/grid.phtml` | Full scoped CSS for the new two-column layout in the Everest palette (`#0d2f47` ink, `#e63232` accent, `#f4efe4` surface, rounded pills). Mobile: sidebar becomes a right drawer via the "Filters" toggle. |
| `view/frontend/web/js/widget/plp-hydrate.js` | **Reworked**: binds the light-DOM sidebar (facet checkboxes, price bands, chips, clear-all, collapse, See More, mobile drawer), sort, pagination and per-card Add/Options/wishlist — all driving the `/smsl/plp/grid` fragment loop. **No more shadow-DOM filter panel** — `plp-chrome.js` is now unused (left in place, imported by nothing). Shadow DOM is only the header type-ahead overlay now. |
| `view/frontend/web/js/widget/styles.js` | Everest re-skin for the header type-ahead overlay surface. |
| `Plugin/CatalogSearchRedirectPlugin.php` (new) + `etc/frontend/di.xml` | `/catalogsearch/result/?q=...` → **301** → `/fs/search?q=...`. Avoids the stock controller's eager Elasticsearch load **and** the layout fatal — no need to re-enable the route override. Only fires when `q` is set and `frontend_enabled` is on. |
| `view/frontend/web/js/widget/search-attach.js` | Binds **every** `#search_mini_form input[name="q"]`, not just the first — fixes the hidden-mobile-copy problem on responsive themes. |
| `view/frontend/layout/catalog_category_view.xml` | Removed the legacy `<move element="div.sidebar.additional" …>` (Luma-only block name; the SSR grid doesn't use it). |

**Theme fix (TL applies):**
| `design/.../Magento_Theme/templates/html/header/search-form.phtml` | **Replaced** the 2,008-line file with a minimal Everest-styled input. Rollback: `search-form.phtml.ROLLBACK_full_20260813`. |
| `design/.../Magento_Catalog/layout/catalog_category_view.xml` | **Delete the line** `<referenceBlock name="category.products" remove="true"/>`. The theme permanently deleting the native grid is why category pages go blank when FalcoSense isn't rendering. `CategoryListPlugin` already suppresses it *conditionally*. |

**Sync note:** all `Ahy_SmartSearchLuma` module changes are now in the canonical repo (`falcosense-shadowdom-module`). The two theme files above are Everest-specific and stay in the theme.

---

## Config changes still needed (not code — done in Admin or `config.php`)

```
smart_search/general/widget_enabled = 1     # kills legacy magnifier panel + legacy results.phtml
smart_search/plp/ssr_enabled        = 1     # /fs/search + category pages render server-side
```
Then:
```bash
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Verify the DB has **real** platform URLs (the `config.xml` defaults are `host.docker.internal` placeholders):
```bash
bin/magento config:show smart_search/general/search_url
bin/magento config:show smart_search/general/endpoint_url
bin/magento config:show smart_search/general/api_key
```

**Platform dependency (C2):** the SSR grid needs the platform's search endpoint to return, for a server-side call: products **with** name/price/image/stock **+ facets + total**. If it only returns `product_id`s, `plp/ssr_enabled=1` gets `unavailable` and falls back. Confirm with the platform team.

---

## Verify

1. **One search box.** Homepage → only the rounded header input. No floating magnifier in the announcement bar.
2. **Type-ahead.** Type 3+ letters in the header box (desktop *and* mobile width) → the FalcoSense overlay opens, styled in Everest blue/red.
3. **Results page.** Submit, or "See all results" → `/fs/search?q=…` shows a real product grid. `View Source` (not Inspect) → product names/prices/links + `<script type="application/ld+json">` present before any JS.
4. **Old URL.** `/catalogsearch/result/?q=tshirt` → 301 → `/fs/search?q=tshirt`.
5. **Fail-safe.** Set `plp/ssr_enabled = 0`, flush, reload → native Magento search page, intact.

---

## Left for later (not blocking)

- The "Undefined array key 1" on the `catalogsearch` route override — mooted by the redirect; only matters if someone wants to re-enable the override. It's a malformed element in the merged `catalogsearch_result_index` handle (candidates: `Webkul_MpAssignProduct`'s `mpassign.list`, theme `Magento_Wishlist` override, or a Hyvä `search_result_list` removal leaving orphaned children).
- `Ahy_ThemeCustomization/view/frontend/layout/search_index_index.xml` — dead Klevu blocks (`class="Klevu\FrontendJs\Block\Template"` — class won't exist with Klevu disabled). Harmless only because the `/search` route is gone with Klevu; delete the file.
- ~18 `search-form.phtml.bak*` files and `Magento_Theme_bkp.donotopen/` in the theme — clean up.
- Move the `styles.css` grid-column patch out of `search-form.phtml` into real theme CSS and rebuild `styles.css`.
