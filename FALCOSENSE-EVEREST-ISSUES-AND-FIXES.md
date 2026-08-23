# FalcoSense × Everest — Issues, Root Causes, and the Right Approach

*A grounded diagnosis of what actually went wrong when FalcoSense was integrated into Everest.com, drawn from reading both codebases directly: the original `Ahy_SmartSearchLuma` module, its evolved `FalcoSense_Search` fork living inside the Everest codebase (`app/code/FalcoSense/Search`), and the Everest theme's own overrides (`app/design/frontend/Ahy/Everest2`). Every finding below is anchored to a real file. Use this to decide, item by item, what belongs back in the FalcoSense module, what's legitimate Everest-specific customization, and what's just cleanup.*

---

## 0. The one fact that reframes everything else

Before any individual issue: **check the theme's parentage.**

```xml
<!-- app/design/frontend/Ahy/Everest2/theme.xml -->
<parent>Hyva/default</parent>
```

Everest's theme inherits from **Hyvä**, not Luma. Hyvä is not "Luma with different colors" — it's a different rendering paradigm entirely: Tailwind CSS instead of Luma's LESS/BEM classes, Alpine.js instead of jQuery/Knockout/RequireJS widgets, and (in a standard Hyvä setup) **no jQuery loaded on the frontend at all** unless a theme deliberately adds it back.

The original module is named `Ahy_SmartSearchLuma` and is built entirely on Luma-era assumptions: `wishlist-top.js` is a RequireJS/jQuery widget (`define(['jquery'], ...)`), `grid-3col.css` overrides Luma-specific selectors like `.page-layout-1column .products-grid .product-item`, and `results.phtml`'s ~700 lines of inline JS use classic DOM/jQuery-adjacent patterns. None of that has a natural home in a theme where those CSS classes don't exist and jQuery isn't guaranteed to be present.

**This is the root cause underneath almost every issue in this document.** It's not that FalcoSense has one bug in a search box. It's that the module was designed for one rendering paradigm and deployed into a fundamentally different one, with no abstraction layer between "FalcoSense's logic" and "the DOM/CSS/JS conventions of whatever theme is running." Everything that follows is a consequence of that gap being closed, ad hoc, file by file, mostly on the Everest side.

Your instinct — "we needed to make changes in Everest files because FalcoSense itself wasn't decoupled" — is correct, with one important refinement that changes what you fix: **needing theme-side work at all was legitimate and expected** (per your own stated plan: FalcoSense ships a base experience, clients restyle it into their theme). **Which specific files got touched, and how, is where it went wrong.** Section 1 shows exactly that distinction with the search form.

---

## 1. The `search-form.phtml` saga — the flagship example

This is worth walking through in full because it contains almost every failure mode in this document at once, and it's the exact file you flagged.

### 1.1 What exists on disk right now

Three near-duplicate copies of the same ~1,600–2,000 line file (full search UI: Alpine.js modal, filters, pagination, cart/wishlist actions, ~150 lines of CSS):

| File | Lines | What it is |
|---|---|---|
| `app/design/frontend/Ahy/Everest2/Magento_Theme/templates/html/header/search-form.phtml` | 2,011 | Theme's override of **Magento core's own** search-form template |
| `app/code/FalcoSense/Search/view/frontend/templates/html/header/search-form.phtml` | 1,603 | FalcoSense's own, correctly-namespaced copy |
| `app/design/frontend/Ahy/Everest2/Klevu_Search/templates/hyva/html/header/search-form.phtml` | 1,583 | Leftover from the old Klevu integration |

### 1.2 What the code itself admits

`FalcoSense/Search/view/frontend/layout/default.xml` carries this comment, verbatim:

> *"Override Hyvä's own header-search block (Magento_Theme::html/header/search-form.phtml) with our own copy of that same template… This previously pointed at FalcoSense_Search::search/search-form.phtml, a file that was never created, so the override silently never applied and the theme's original template kept rendering instead."*

Read that carefully: **for some period, the layout XML tried to point the header-search block at a FalcoSense-namespaced template that literally did not exist on disk.** Magento doesn't error loudly when a referenced template file is missing in a way that's easy to notice in this context — the block instance still exists, and depending on how the miss is triggered, this silently degrades. The team's own comment confirms that's exactly what happened: **the "wrong file was edited" instinct you had is correct, and it's independently confirmed by the person who wrote this comment.**

### 1.3 What actually happened, most likely

1. Original module ships → breaks visibly on Hyvä (wrong classes, no jQuery, layout removals target things that don't fully resolve into working UI on this theme's DOM).
2. Someone starts fixing it live, directly in the theme's copy of Magento's own `Magento_Theme::html/header/search-form.phtml` — because that's the file that's actually rendering, and editing it produces an immediate visible result. This is the fastest way to see the fix work, and also the worst possible place to put it (more on why in 1.4).
3. Separately, someone builds the "correct" version — a properly namespaced `FalcoSense_Search::html/header/search-form.phtml` — and a layout-XML override to point the native block at it. But the override initially points at the wrong path (`search/search-form.phtml` instead of `html/header/search-form.phtml`), so it never took effect. The Magento_Theme copy kept being the one that rendered, so that's the one that kept getting hotfixed.
4. Eventually the path bug is found and fixed (per the comment). The `referenceBlock` now correctly points at `FalcoSense_Search::html/header/search-form.phtml`.
5. **But nobody backported the accumulated hotfixes from the Magento_Theme copy into the FalcoSense copy before flipping the switch.** A diff of the two files confirms this concretely — the Magento_Theme version (2,011 lines) has real functionality the FalcoSense version (1,603 lines) doesn't: a `/customer/account/` grid-layout fix (with its own explanatory comment: *"styles.css was compiled with repeat(1) at all breakpoints for multi-column layout selectors, breaking the sidebar on pages like /customer/account/"*), refined no-results-state styling, and other CSS the module copy is simply missing.

### 1.4 The actual, current state — and why it matters

Because Magento resolves a `Vendor_Module::path` template reference by checking the current theme's `Vendor_Module/` override folder first (there is no `app/design/frontend/Ahy/Everest2/FalcoSense_Search/` folder — it doesn't exist), and falling back to the module's own copy, the block `header-search` — once the path bug was fixed — should now resolve to **`app/code/FalcoSense/Search/view/frontend/templates/html/header/search-form.phtml`, the 1,603-line, less-developed copy.**

That means the 2,011-line copy sitting in `Magento_Theme/` — which has more fixes and was, for some period, the one actually serving traffic — is now **very likely dead code**, and the version that's live is a **regression** relative to what was previously rendering. This is a strong, structurally-grounded inference from the file evidence (there is no other override folder that would make the theme copy win); confirm it by comparing the rendered header search markup on staging against both files (the `/customer/account/` grid-fix CSS block is a fast, distinctive tell — if it's not in the rendered `<style>` output, the module's stale copy is the one live).

**This is the cleanest illustration in the whole codebase of what "not decoupled" costs in practice**: once there's no single, unambiguous place FalcoSense's UI is allowed to live, "fix the bug" and "fix it in the right file" become two different tasks, and under deadline pressure the second one loses — repeatedly, evidently, since this happened at least twice (the original path-bug, and now this stale-copy problem left in its wake).

### 1.5 What the right approach actually looks like — and it already exists, in this same codebase

Compare against `app/design/frontend/Ahy/Everest2/Klevu_Search/templates/hyva/html/header/search-form.phtml`. Setting aside that it's now dead (Klevu modules are disabled — see §3.3), *structurally* it's the correct pattern: a Hyvä-flavored template living under its own module's namespaced `hyva/` override path, never touching a Magento core file. FalcoSense's own `view/frontend/templates/html/header/search-form.phtml` is *also* structurally correct — it's just stale content in the right place, rather than fresh content in the wrong place.

**The fix is mechanical, not architectural**: diff the Magento_Theme copy against the FalcoSense module copy, port the missing fixes into the module's own file (the one that's supposed to be the source of truth), and delete the Magento_Theme override entirely so there is exactly one file this UI can live in. Going forward, any hotfix has exactly one legitimate destination, which removes the entire failure mode.

---

## 2. Cross-module layout coupling — FalcoSense's layout XML now depends on knowing what *other* modules do

This is a different, more insidious version of the "hardcoded theme internals" problem from the original module (see the fundamentals guide, §5.3) — except now it's not just Luma's block names, it's a growing list of **specific third-party modules' block names**, and the comments in the code say so directly.

`app/code/FalcoSense/Search/view/frontend/layout/catalogsearch_result_index.xml`:
```xml
<!--
    Mirrors the theme's own Magento_CatalogSearch::layout/catalogsearch_result_index.xml
    cleanup exactly, so this route renders identically to the existing search
    results page instead of falling back to the default 2-column layout...
-->
<referenceBlock name="search.result"          remove="true"/>
<referenceBlock name="klevu_content_top"       remove="true"/>
<referenceBlock name="catalogsearch.leftnav"   remove="true"/>
<referenceBlock name="catalog.compare.sidebar" remove="true"/>
<referenceContainer name="sidebar.main"       remove="true"/>
<!-- Webkul MpAssignProduct injects a full product ListProduct block — remove it -->
<referenceBlock name="mpassign.list"           remove="true"/>
<!-- Remove search result list blocks added by Hyva/core that may still load collections -->
<referenceBlock name="search_result_list"      remove="true"/>
<referenceBlock name="search.search_terms_log" remove="true"/>
```

`klevu_content_top` is Klevu's block name. `mpassign.list` is Webkul Marketplace's block name. The comment literally says this file was written by copying another module's cleanup list ("mirrors... exactly"). **FalcoSense's layout configuration is now coupled not to one theme, but to the full roster of every other extension installed on this specific site**, and it knows about them by name. Add a new merchandising module that injects a block into `content` on the search results page, and FalcoSense's layout file has no way of knowing it needs updating — the new module's block just sits there, unremoved, next to FalcoSense's own results grid, until someone notices the duplicate UI and manually adds one more line to this list.

`app/code/FalcoSense/Search/view/frontend/layout/search_index_index.xml` does the same thing for Klevu specifically:
```xml
<referenceBlock name="search_index_klevutemplate" remove="true"/>
<referenceBlock name="search_index_klevutemplate.themev2" remove="true"/>
```
— removal targets for a module (`Klevu_Search`) that is **already disabled** in `app/etc/config.php` (confirmed directly — see §3.3). Since a disabled module's own layout XML never gets merged in the first place, these blocks don't exist to be removed; this is a no-op left over from the Klevu-to-FalcoSense transition period, not currently causing harm, but it's exactly the kind of stale coupling that accumulates when the removal-list-copying pattern above continues.

**Why this is the wrong approach, independent of Hyvä vs. Luma:** this isn't a theme-portability problem anymore, it's a *module-composition* problem. The fix isn't "make FalcoSense theme-agnostic" (your stated goal already accepts it won't be); it's that **FalcoSense's layout XML shouldn't need to enumerate every other vendor's blocks by name at all.** The reason the original module needed `remove="true"` on native blocks was to replace Magento's own default search/category rendering. Once you're also removing *other paid extensions'* blocks to prevent them stacking under FalcoSense's own UI, that's a sign the actual containment problem — "make sure only FalcoSense's UI renders in this content area, regardless of what else is registered to render there" — was solved by chasing every offender individually instead of by owning the container.

---

## 3. Dead, orphaned, and competing code

Both codebases have accumulated code that looks load-bearing but structurally cannot be running, or is actively fighting another piece of code for the same responsibility. These are exactly the kind of findings worth triaging explicitly, because "is this safe to delete" is a different question from "is this an architecture problem," and conflating them wastes the time you're trying to spend wisely.

### 3.1 Two competing preferences for the same cart line-item class

`app/code/FalcoSense/Search/etc/di.xml`:
```xml
<preference for="Magento\Checkout\CustomerData\DefaultItem" type="Ahy\ThemeCustomization\CustomerData\DefaultItem"/>
```
But `app/code/FalcoSense/Search/CustomerData/DefaultItem.php` **also exists**, also extends `\Magento\Checkout\CustomerData\DefaultItem`, and also overrides `doGetItemData()` to resolve the product image through `FalcoSense\Search\Helper\ProductImage`. It is a real, complete, purpose-built class.

The problem: the actual `<preference>` binding points at `Ahy\ThemeCustomization\CustomerData\DefaultItem` — a **different module's** class, which independently extends the same native class and does its own (also complete, unrelated) image-resolution override via `Ahy\ThemeCustomization\Helper\ProductImage`. Since Magento allows exactly one `<preference>` per type, only one of these can win — and it's the `ThemeCustomization` one. **`FalcoSense\Search\CustomerData\DefaultItem` is, as configured, dead code**, sitting in the FalcoSense module, doing nothing, while a general-purpose theme-customization module quietly owns a responsibility ("how do FalcoSense-synced cart images resolve") that should arguably belong to FalcoSense.

This is worth resolving deliberately, not just picking a winner: figure out whether `Ahy\ThemeCustomization\CustomerData\DefaultItem`'s image logic is actually correct for FalcoSense-synced products (its own `ProductImage` helper is a separate implementation from FalcoSense's), or whether it's coincidentally close enough that nobody's noticed the FalcoSense-specific version never runs.

### 3.2 A plugin bound to a class that no longer gets built

`app/code/Ahy/Redirect/etc/di.xml` registers a plugin on `Klevu\Search\Controller\Index\Index` (confirmed): a redirect rule that sends a specific search query straight to a merchandising collection page —
```php
private const REDIRECTS = ['fix myself first' => 'The-Everest-Collection'];
```
But `FalcoSense/Search/etc/di.xml` also declares:
```xml
<preference for="Klevu\Search\Controller\Index\Index" type="FalcoSense\Search\Controller\Search\Index"/>
```
`FalcoSense\Search\Controller\Search\Index` does not extend Klevu's controller — it's a from-scratch class. Magento's interceptor/plugin system resolves which plugins apply by walking the **class hierarchy of the concrete class actually instantiated**, not the originally-requested type name, when the preference target is unrelated to the original. Since the resolved class shares no ancestry with `Klevu\Search\Controller\Index\Index`, this plugin has almost certainly stopped firing since the day the preference was introduced.

Practically: a business rule (a specific gag/vanity search term redirecting to a specific collection — plausibly a marketing or SEO decision someone asked for deliberately) silently stopped working the moment the search controller was swapped out, and nothing in the deployment would have surfaced that as an error — the redirect just quietly doesn't happen anymore, the search falls through to a normal (probably empty) results page instead. **Confirm with `bin/magento dev:di:info 'FalcoSense\Search\Controller\Search\Index'`** (lists all plugins Magento will actually apply to that class) before deciding whether to port the rule forward or confirm it's genuinely no longer needed — don't assume either way from this document alone.

### 3.3 A fully decommissioned competitor still sitting in the theme

All ten `Klevu_*` modules are disabled in `app/etc/config.php` (`'Klevu_Search' => 0`, etc. — confirmed directly), while `FalcoSense_Search` is enabled. That's the right end-state. But the theme still carries five full override folders for the dead integration: `Klevu_Search/`, `Klevu_Addtocart/`, `Klevu_Categorynavigation/`, `Klevu_FrontendJs/`, `Hyva_KlevuSearch/`, plus an entire `Ahy_ThemeCustomization` subtree of `klevu_*.phtml` templates (`klevu_landing_template.phtml`, `quick_auto_suggestions.phtml`, `trending_products_page.phtml`, and a dozen more). None of it executes — disabled modules' layout XML never merges — but all of it sits there, at full size, indistinguishable at a glance from live code, actively misleading anyone (including a future you, six months from now) trying to understand what actually renders this site's search experience. This is pure hygiene debt, zero runtime risk, and the single cheapest item in this whole document to clear: delete the disabled-module override folders once you've confirmed (per §3.1–3.2) nothing was quietly still depending on a file inside them.

### 3.4 Backup files committed as files, not commits

```
Service/ProductSyncService.php.bak.20260618_105345
view/frontend/templates/search/autocomplete.phtml.bak.20260617
view/frontend/templates/search/results.phtml.bak.2026-06-17
```
Three timestamped `.bak` copies of files that already have "live" versions sitting right next to them, in the module's actual source tree. This is what version history looks like when there isn't confidence in — or access to — real version control at the moment a risky change is being made: rather than trust `git` to let you get back to a known-good state, someone manually copied the file first. It's a process signal more than an architecture one, but it's worth naming plainly: if hotfixes are being applied this way, that's a strong indicator changes are landing directly on files that matter without a review/rollback safety net around them — which is exactly the kind of gap that produces incidents like the original Everest launch.

---

## 4. What the Everest team got right — patterns worth keeping and reusing

You explicitly asked for this half too: not everything here is a mistake, and it's important to name the good instincts clearly so they don't get accidentally undone while you're cleaning up the rest.

**Bypassing Magento's native search-result collection query via `<preference>`, not by hacking the controller.**
```xml
<!-- Bypass Magento's CatalogSearch result controller — it runs a full product
     collection query (150k+ rows) even though we render results via our own API.
     Our replacement just renders the page layout with no catalog queries. -->
<preference for="Magento\CatalogSearch\Controller\Result\Index"
            type="FalcoSense\Search\Controller\Result\Index"/>
```
This is exactly the idiomatic Magento mechanism for "replace a whole controller's behavior" (see the fundamentals guide, §3) — a real performance fix (avoiding a 150k-row query that was running on every search-results page load regardless of whether its output was even used), done through the sanctioned extension point instead of editing core. Keep this pattern as the template for future "replace, don't patch" decisions.

**A live-token endpoint that's actually FPC/Varnish-aware.**
`Controller/Search/Token.php` exists specifically because a token baked into server-rendered HTML goes stale the moment that HTML is served from a full-page cache — the endpoint is small, correctly scoped, sets `Cache-Control: no-store, no-cache, must-revalidate` explicitly, and the client-side `ahyTokenRefresh` helper in the search form correctly treats it as "fetch once, cache in memory, refresh on 401." This is a real fix to a real architectural gap in the *original* module (which baked the token straight into server-rendered `.phtml` output with no caching-awareness at all) — and it's implemented as a clean, single-purpose controller, not folded into an existing one. Good shape for future work: **when you find a caching-correctness gap like this, this is what "fix it properly" looks like.**

**Real parent-child catalog relationships instead of SKU-pattern guessing.**
`DisabledParentResolver` and `DuplicateSkuResolver` (§Model/Service, full read above) are the most carefully-reasoned files in either codebase. Both walk Magento's actual `catalog_product_super_link` table for configurable parent/child relationships rather than inferring structure from SKU string patterns — the exact opposite of the fragile `parseVariantAttrs()` SKU-splitting hack the original module's front-end JS relies on (fundamentals guide §8.2). The doc comments on both classes are genuinely excellent: they explain *why* each edge case matters (a disabled parent's still-enabled child, a configurable's own price masking one overpriced variant, a configurable parent's own stock-item row always reading in-stock regardless of its children) in a way that would let someone unfamiliar with the bug reports that motivated them still understand the reasoning years later. **This is the standard the rest of the sync logic should be held to**, and a strong signal that whoever wrote these two files understood Magento's catalog data model correctly — worth having them review the parts of this document that touch data-sync correctness.

**Explicit, reasoned use of `cacheable="false"`.**
```xml
<!-- cacheable="false" makes Magento mark this entire page response as not
     cacheable... This page is fully dynamic (live product results, live
     search token) — earlier work made it *safe* to cache by fetching the
     token live client-side, but this opts it out of caching entirely rather
     than relying on that. -->
<block ... cacheable="false" .../>
```
This is a deliberate, documented trade-off (not caching a page that's fully dynamic anyway) made with the *other* fix (the live-token endpoint) explicitly in mind — the comment shows the author knew both approaches existed and chose the simpler one for this specific page. That's exactly the kind of reasoning that should be captured in comments more often across both codebases (see §3.4 above for what happens when it isn't).

---

## 5. A structural product/UX decision made inside a template comment, not a design conversation

One more pattern worth surfacing on its own, because it's not a bug — it's a real design change that happened to land in the wrong place to be a deliberate decision.

The **original** module's search box (`Block/Search.php` + `search/mini.phtml`) opens a small overlay: a handful of quick-suggestion links and ~6 popular products. Typing more, or submitting, sends the shopper to a real page (`/catalogsearch/result/`), rendered by `category/results.phtml` — full filters, sort, pagination.

The **Everest** version collapses that entire second step into the header component itself: `#ahyModal` is a full-screen overlay with its own filter sidebar, sort dropdown, pagination, and mobile drawer — everything `category/results.phtml` used to do — driven by `history.pushState` so the URL bar tracks it and Back/Forward work, with deliberate handling for bfcache staleness (forcing a reload on real Back-button presses, but not on the modal's own internal "close" call). This is a *substantial*, well-thought-through UX change — "search never leaves this page" instead of "search is a page" — and the implementation quality (the scroll-position race-condition handling, the history-entry bookkeeping) shows real engineering care went into it.

But it exists **only** as ~2,000 lines inside a header template override, with no trace of it as a decision anywhere else in either codebase — no config flag, no mention in the module's own layout, nothing that would tell a new client (or a future you) "FalcoSense can render as a page (default) or as an in-page modal (Everest's choice)." If this modal-search pattern is genuinely better — and skimming the implementation, it plausibly is, for a catalog this size — **it deserves to exist as a real, named option in FalcoSense itself**, not as an undocumented fork that only Everest happens to run. If it's Everest-specific (matches this brand's UX, not necessarily every future client's), that's a legitimate reason to keep it theme-side — but it should be a clean, intentional theme override of a FalcoSense-provided extension point, not a rewrite of Magento's own header template.

Either way, this is the kind of call worth making explicitly in the system-design conversation, not leaving implicit in the fact that nobody's touched it since it landed.

---

## 6. Decision framework — what to fix where

Sorted by where the fix actually belongs, since that's what you said this document needs to support.

### Fix in FalcoSense (the module — benefits every future client, not just Everest)
- Consolidate the three `search-form.phtml` copies into one source of truth inside the module, with the Magento_Theme fixes ported in (§1.5).
- Stop enumerating other vendors' block names in FalcoSense's own layout XML (§2) — solve "only our UI renders here" at the container-ownership level, not the block-name-blacklist level.
- Resolve the `DefaultItem` preference collision (§3.1) — decide, deliberately, whether FalcoSense or ThemeCustomization owns cart-image resolution for FalcoSense-synced products, and delete the loser.
- Remove `LogApiKey.php` (§ — confirmed unwired, but logging a raw API key in plaintext is the wrong pattern to leave lying around for someone to wire up later without noticing).
- If the modal-search UX (§5) is worth keeping generally, promote it into FalcoSense as a real, documented option — don't leave it as an undocumented theme fork.

### Legitimate Everest-side customization (keep here, but done as a clean override — not core-file surgery)
- Everest-specific styling, the GIF loader, brand colors, the `/customer/account/` grid-fix CSS — all fine to live in the theme, *as long as* it's layered on through a FalcoSense-provided extension point rather than replacing a Magento core template in place.
- The modal-vs-page search UX choice, if it turns out to be Everest-specific rather than a general FalcoSense capability (§5).

### Cleanup — no architecture decision needed, just needs doing
- Delete the five dead `Klevu_*` theme override folders and the `Ahy/ThemeCustomization` Klevu template subtree, once confirmed nothing still resolves through them (§3.3).
- Delete the three `.bak.*` files once their content is confirmed merged or irrelevant (§3.4).
- Resolve or explicitly retire the `SearchQueryRedirect` plugin (§3.2) — confirm via `dev:di:info` whether it fires, then either port the redirect rule into the live search controller or remove the dead plugin.
- Remove the no-op Klevu block-removal rules in `search_index_index.xml` once the folders above are gone.

### Needs a decision, not just a fix
- Whether Everest keeps the in-page search modal permanently, and if so, whether that becomes a first-class FalcoSense capability (§5).
- Whether the cross-module layout coupling (§2) gets solved by a real containment mechanism now, or accepted as ongoing maintenance given the current client roster — worth pricing both options before choosing.

---

## 7. What this document deliberately doesn't cover

This stays in the same lane as the fundamentals guide: diagnosis, not redesign. It doesn't propose the actual container/extension-point mechanism that would solve §2 for good, doesn't spec what a first-class "modal vs. page" search mode would look like in FalcoSense's config, and doesn't touch the ~70 files in either codebase not discussed above (most of it — image compression internals, the fuller observer set for wishlist/login/logout event tracking, the marketplace-seller-specific layout hook — is unremarkable and not obviously implicated in what broke). Bring this into the system-design conversation as the evidence base; that's where "here's the mechanism that prevents §1 and §2 from recurring" actually gets decided.
