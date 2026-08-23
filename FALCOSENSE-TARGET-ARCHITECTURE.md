# FalcoSense Target Architecture — Design Patterns for True Decoupling

*A redesign proposal for how FalcoSense integrates with any Magento website — built to survive adversarial review, grounded in the concrete failures documented in the fundamentals guide and the Everest issues audit.*

---

## 0. Definition of done

Before any pattern gets picked, the bar it has to clear, stated precisely — because "decoupled" is a word every engineer nods at and every architecture review disagrees about:

1. **Install-to-live in ~10 minutes**, for the median client: drop the module in, set an API key, done. No layout XML surgery, no theme edits, no coordination with whatever else is installed.
2. **Never the reason the site is down.** If FalcoSense's JS fails to load, times out, or throws — the site looks exactly like it did before FalcoSense existed. Not broken. Not blank. Unchanged.
3. **Never the reason checkout is broken.** Add-to-cart always ends up going through the client's real, existing cart system — including whatever custom logic (marketplace seller assignment, custom pricing rules, loyalty points) already hooks it.
4. **Doesn't care what it's replacing.** Klevu, native Elasticsearch/OpenSearch catalog search, a different vendor's plugin — FalcoSense doesn't need to know it exists, doesn't need to fight it for DOM space, and doesn't need it removed first.
5. **Feels premium regardless of the host site's quality.** A messy, inconsistent, half-broken theme underneath should not visibly leak into how FalcoSense looks or behaves.

Every pattern below exists to satisfy one or more of these five, and each section says which.

---

## 1. The overriding layer — the actual mechanism of decoupling

This is the section that matters most, so it gets the fullest accounting: not "here's a nice pattern," but a complete inventory of every mechanism FalcoSense currently uses to reach into a client's site, cited to the exact file, next to exactly what replaces it and why the replacement is structurally safer — not just tidier.

One honesty check first, since it's a fair challenge to make of this whole document: **the underlying Magento capabilities used below aren't new.** Additive layout XML, `di.xml` preferences, DOM-level event listeners — Magento has always supported all of these, and FalcoSense's own Everest fork already uses a couple of them correctly (the `Magento\CatalogSearch\Controller\Result\Index` preference-bypass, for one — see §9's table). What's actually new is threefold: (a) the Shadow DOM rendering boundary, which has zero precedent in either FalcoSense codebase today — Everest's Alpine.js components run in the light DOM with no isolation at all, which is *why* they needed the CSS wars in the first place (concrete proof below); (b) using the safe mechanisms *consistently*, instead of only in the two or three places they happen to already be used correctly; and (c) **never reaching for the dangerous mechanisms at all** — not "less often," not "more carefully," never. That last part is the actual decoupling claim, and it's worth being able to point at exactly what's being eliminated.

### 1.1 The current override surface, audited

| # | Mechanism | Where it lives | What it requires from the host site | Why it breaks |
|---|---|---|---|---|
| 1 | Custom-handle block removal, triggered from a `layout_load_before` observer | `Observer/AddFrontendLayoutHandle.php` → `view/frontend/layout/ahy_smartsearch_active.xml` | Blocks literally named `top.search`, `category.products`, `div.sidebar.main`, `search_result_list`, `search.result`, `sidebar.main` to exist in the host theme | Those are Luma's own internal names, not a Magento-wide contract. A different theme's equivalent, if one even exists, is very likely named something else — `remove="true"` on a name that doesn't exist is a silent no-op, so the native UI stays on the page, stacked under/beside FalcoSense's own |
| 2 | Direct per-page block removal in FalcoSense's own category/search layout files | `catalog_category_view.xml` / `catalogsearch_result_index.xml`, both codebases | Knowing, by name, every *other vendor's* block that might render into the same content area | Confirmed to have already grown: the original module targets 3 native blocks; the Everest fork's version targets 8, by name, including two unrelated paid extensions (`klevu_content_top`, `mpassign.list` — Webkul Marketplace). Every new module a client installs later is a name nobody added to this list yet |
| 3 | Whole-template replacement via `referenceBlock ... template="..."` | Everest's `default.xml`, targeting the native `header-search` block | The module's own replacement template to exist at exactly the resolved path, and to be kept current | Directly caused the 3-diverging-copies problem in the Everest audit §1 — a path typo doesn't error, it just silently falls back to whatever rendered before, and nobody notices until someone diffs the files by hand |
| 4 | Editing a theme's own copy of a Magento **core** template in place | Everest's `Magento_Theme/templates/html/header/search-form.phtml` | Nothing from Magento — this is editing the exact file the `Vendor_Module::path` resolution convention exists to make unnecessary | The worst case on the list: FalcoSense's logic now lives inside a file that nobody auditing "what does FalcoSense actually do" would think to check |
| 5 | CSS fought against the theme's own selectors, by specificity | `grid-3col.css` (original); the Everest search-form's grid-column overrides | Knowing the exact class names and specificity Luma/Hyvä happen to use for their own grid | **Already caused a real, confirmed regression on an unrelated page.** The Klevu-search-form CSS carries this exact comment: *"styles.css was compiled with repeat(1) at all breakpoints for multi-column layout selectors, breaking the sidebar on pages like /customer/account/."* A CSS rule scoped for FalcoSense's own overlay leaked into the site's global stylesheet and broke the customer account page — not hypothetical, it happened, and needed its own follow-up override to contain |

### 1.2 What replaces all five — two mechanisms, not five

1. **One additive layout XML block per area**, inserted into `before.body.end` — a container `Magento_Theme` itself owns at the framework level, not Luma's, not Hyvä's, not any specific theme's. It contains exactly a mount `<div>`, a `<script>` tag, and the config bootstrap (§2). Never `remove`. Never a `template=` override of anything that isn't FalcoSense's own block.
2. **Runtime DOM attachment** to a Magento-framework-level HTML contract (`#search_mini_form`, `input[name="q"]` — §3). This is the important structural difference from mechanism #1 above: it doesn't go through Magento's layout system *at all*, so it is immune to layout-merge order, module load-sequence, and theme-specific layout files as a category of problem, not just resistant to them in practice.

Neither mechanism requires FalcoSense to know the name of a single block belonging to Klevu, native Elasticsearch/OpenSearch search, Webkul, or any module that doesn't exist yet. That's the actual, mechanical answer to "doesn't care what's already there": it's not that FalcoSense is careful about what it touches — it's that the touch surface has been reduced to two contracts that are either Magento-core-owned or Magento-framework-level, and nothing else.

### 1.3 The rule this generates

> **FalcoSense's Magento-side footprint never removes, replaces, or overrides anything that belongs to another module or the theme. It only adds.**

This single constraint is what makes "doesn't care what's already there" true by construction rather than by hope: if FalcoSense never touches another module's declared block, it cannot conflict with it, cannot break when that module updates, and cannot need updating when a *new* module shows up next to it. It also means uninstalling FalcoSense is always safe — nothing else was ever depending on FalcoSense having removed something, because it never removed anything.

Everything from here is "how do you deliver the Everest-quality search/PLP experience while obeying that constraint."

---

## 2. Pattern: Micro-frontend via Web Component + Shadow DOM

**Satisfies: #1 (fast install), #2 (never the reason the site breaks), #5 (premium regardless of host quality).**

The rendering layer — the live-search-as-you-type overlay, the PLP grid, filters, sort, pagination, the add-to-cart UI — ships as a single self-contained JS bundle that mounts into a **Shadow DOM boundary**. This is the same isolation technique embeddable products like Stripe Elements or Intercom's chat widget rely on, for exactly the same reason: it's the only web-platform mechanism that gives you a *bidirectional* guarantee — the host page's CSS/JS cannot reach in and break FalcoSense, and FalcoSense's CSS/JS cannot reach out and break the host page. No `!important` wars (guide #1, §6), no accidental collision with a class name the theme also happens to use, no dependency on jQuery/RequireJS being present (Hyvä doesn't load them by default) or on Alpine.js being present (a Luma or custom-stack client won't have it at all).

Practically, this resolves the confusion from your question directly: **PHP doesn't go away.** Magento's job shrinks to exactly three things:

1. **Mount point** — one `<div id="falcosense-root">` and one `<script>` tag, inserted via one additive layout XML block (§6).
2. **Config bridge** — a small, versioned JSON blob (store ID, a short-lived search token, feature flags, mount-point overrides) that boots the widget. Magento computes this server-side (where it has real access to store/customer context) and hands it to the widget as inert data — the widget never calls back into Magento-specific APIs to figure out what store it's on.
3. **The revenue-critical boundary** — add-to-cart, which is deliberately *not* rendered inside the Shadow DOM's responsibility; it's a request to a PHP-owned endpoint (§5). The widget requests; Magento decides.

Everything about how the search box behaves, how results render, what the "type 3 letters → live SPA-style PLP" experience looks and feels like — that's now **one codebase, one bundle, versioned and tested once**, not re-derived per theme family the way it currently is (a Luma-era vanilla-JS implementation in the original module, and an entirely separate Alpine.js reimplementation forked into Everest's theme — two real implementations of the same feature, already diverging, per the Everest audit §1).

---

## 3. Pattern: Progressive Enhancement, Not Replacement (the search box)

**Satisfies: #1, #4 (doesn't care what it's replacing).**

The Everest fix rewrote Magento's own `Magento_Theme::html/header/search-form.phtml` in place to get "typing in the existing search box triggers FalcoSense" — the wrong file, for the right instinct. The right mechanism already exists in every Magento install without editing anything: **Magento's own native search form has a stable, framework-level HTML contract** — `id="search_mini_form"`, an `<input name="q">` (the literal query param name `Magento\Search\Helper\Data::getQueryParamName()` returns). This isn't a Luma convention or a Hyvä convention — it's how `Magento_Search`/`Magento_CatalogSearch` themselves define the native mini-search form, and it's exactly what's still present, under the same `id`/`name`, inside Everest's own heavily-customized Hyvä search-form.phtml (confirmed directly reading that file). Themes restyle it; essentially none of them rename it, because too much of the ecosystem (analytics, tag managers, browser autofill) already depends on that convention holding.

So the widget **attaches to the existing input via that selector** — adds its own `input`/`focus` listeners, without removing, replacing, or even needing to know about the theme's own search-form template at all. Typing 3+ letters triggers the exact same live SPA-style overlay experience you built for Everest, using the theme's own, unmodified search box as the entry point.

For the rare theme that doesn't follow the convention: a documented, one-line config override (`search_input_selector`) — an explicit escape hatch you reach for deliberately during onboarding if the 10-minute path doesn't just work, not a maintenance burden carried by default.

---

## 4. Pattern: Fail-Open Mounting ("Progressive Takeover") for category/search-result pages

**Satisfies: #2, #4.**

This is the direct fix for the single most repeated failure across both prior documents: `remove="true"` on native blocks, hardcoded to a specific theme's or module's internal block names (guide #1 §5.3; guide #2 §2 — the `klevu_content_top`, `mpassign.list`, `catalogsearch.leftnav` whack-a-mole).

Under Additive Only, FalcoSense's layout XML never removes the native grid/search-results block, from any module, ever. Instead:

1. The native page renders exactly as it always would — Klevu's results, Elasticsearch's native results, whatever's there. FalcoSense's widget mounts *alongside* it via the same `before.body.end`-style additive container used everywhere else (a Magento-core container, not theme-owned — present regardless of theme).
2. On page load, if page context indicates this is a category or search-results page (passed in the config bridge, §2, computed server-side from real Magento context — current category name, current query — not guessed from JS-side URL parsing), the widget **auto-opens its own overlay, pre-scoped to that context**, and fetches real results from FalcoSense's platform.
3. **Only once FalcoSense's own component has confirmed it has real data to show** does it visually take over (the overlay covers the native content — the native DOM is still there, untouched, just no longer visible). If the fetch fails, times out, or the widget errors out entirely, it simply never takes over — **the native search/category experience the client already had keeps working, unmodified**, exactly as if FalcoSense weren't installed.

This is the opposite failure mode from what's in production today. Today: native rendering is deleted at layout-compile time, unconditionally, and FalcoSense's replacement had better work. Under this design: native rendering is untouched by default, and FalcoSense only *earns* the visible slot by successfully proving it has something better to show. That ordering is what makes "never the reason the site is down" true even in genuinely broken/messy client codebases — the worst case is FalcoSense silently doesn't activate, not that the client's fallback got deleted out from under them.

---

## 5. Pattern: Ports & Adapters (Hexagonal Architecture) — the Anti-Corruption Layer at the Magento boundary

**Satisfies: #3, #4, and is the architectural spine the cart adapter (§ below) is one instance of.**

This is the pattern that formally answers "doesn't care how messy or customized the rest of the codebase is." FalcoSense's core PHP logic (sync, token issuance, request handling) is written against a **small, stable set of interfaces it owns** — never against Magento's concrete classes directly, and never against another module's concrete classes at all. Every place FalcoSense needs something from "the rest of this specific Magento install" — how to add to cart, how to resolve a configurable variant, how to read customer/geo context — goes through a named **Port** (an interface), and the messy, install-specific reality lives entirely inside a swappable **Adapter** behind it.

```php
interface CartAdapterInterface {
    public function addToCart(CartAddRequest $request): CartAddResult;
}

interface VariantResolverInterface {
    public function resolve(string $platformProductId, array $selectedOptions): ResolvedVariant;
}
```

This is a real, named pattern (Ports & Adapters / Hexagonal Architecture, and specifically an Anti-Corruption Layer at the boundary to "whatever this client's Magento actually looks like") — worth naming explicitly in review, because it's exactly the right vocabulary for "our core logic has zero knowledge of your specific site's mess; your mess lives entirely in a 40-line adapter class we don't have to touch." A client's Magento being on an ancient version, having a heavily customized checkout, running a marketplace extension — none of that is a risk to FalcoSense's core, because the core was never written against any of it. It's written against the interface, and the interface doesn't change.

---

## 6. Pattern: Cart Integration — Strategy/Adapter, native Magento by default

**Satisfies: #3 directly — this is the one that must never break checkout.**

Per your call: a pluggable adapter from day one, not a hardcoded assumption.

**The contract the widget knows about is exactly one thing**: `POST /falcosense/cart/add` with `{ platform_product_id, variant_id?, qty, selected_options? }`. The widget never knows about Magento entity IDs, `super_attribute` arrays, quote items, or anything else Magento-internal — that asymmetry (rich Magento knowledge stays server-side, only opaque platform IDs cross to JS) is deliberate and important: it's what keeps the widget bundle themeable/portable/dumb-on-purpose, and it's what makes swapping the adapter a zero-JS-change operation.

Server-side, that endpoint resolves through the two Ports from §5:

- **`VariantResolverInterface`** turns "platform product X, selected Color=Black/Size=M" into a real Magento entity ID and the real `super_attribute` option IDs, using Magento's actual configurable-product attribute data (`getConfigurableAttributesAsArray()`/the real super-attribute API) — **not** the SKU-string-splitting heuristic the original module's front-end JS relies on today (guide #1 §8.2), which silently mis-resolves the moment a client's SKU convention doesn't match the assumed pattern.
- **`CartAdapterInterface`**'s **default implementation calls Magento's own real cart/quote APIs** — the same code path a theme's native "Add to Cart" button would trigger. This is the detail that actually delivers "never breaks the client's existing add-to-cart": because the default adapter drives the *real* native flow rather than reimplementing it, **any plugin, observer, or customization that's already hooked onto Magento's native add-to-cart (marketplace seller assignment, custom pricing, loyalty points, whatever a specific client already has) keeps firing, unmodified**, because FalcoSense is a caller of that flow, not a bypass of it. This is the direct fix for the original module's actual cart bug (guide #1 §8.3 — posting a resolved child product ID straight to `checkout/cart/add` with no `super_attribute`, skipping the parent-configurable cart contract entirely, and silently dropping any product's real custom-option requirements).

For a client with a genuinely non-standard cart (a marketplace platform like Webkul on Everest, a fully custom checkout) — someone implements a small class against `CartAdapterInterface`, and it's wired in with **one `di.xml` `<preference>` line**, Magento's own sanctioned "swap an implementation" mechanism (guide #1 §3). No core file touched, no theme file touched, no FalcoSense core code touched. That preference is the entire integration effort for that edge case.

---

## 7. Pattern: Config-Driven Bootstrap — what "10 minutes" actually looks like

**Satisfies: #1, directly.**

Walking the actual onboarding sequence end to end, to make the time-budget claim concrete rather than aspirational:

1. **Install the module.** `composer require falcosense/module-search` (or drop into `app/code`), `bin/magento module:enable`, `setup:upgrade`. Standard for any Magento module — no different from today.
2. **Enter the API key** in Stores → Configuration (the `system.xml` UI already exists and is well-built per guide #1 §11 — nothing needs to change here).
3. **That's it.** No layout XML edits by the integrator — the module's own additive-only layout XML (mount point in `before.body.end`, `ifconfig`-gated) is already shipped *inside the module*, the same way any other extension's layout XML ships. There is nothing for the client's developer to author.
4. First page load: the widget boots off the config blob, attaches to the native search input (§3), and is live. Category/search pages get the fail-open takeover (§4) automatically, on the same trigger.
5. **Escape hatches, used only if step 3 doesn't just work**, each independently documented and each a single config value or a single `di.xml` line: non-standard search input selector (§3), custom cart adapter (§6), SSR-shell mode toggle (§8).

The 95% case — a Magento site with a reasonably standard search-form contract and a native or lightly-customized cart — never touches an escape hatch. The 5% case (Everest's marketplace cart, a heavily bespoke search form) touches exactly one, in isolation, without the other four needing to be understood at all. That's the actual mechanism behind "10 minutes, plug and play": it's not that integration is never hard, it's that the module is designed so the hard cases are opt-in, isolated, and don't gate the default path.

---

## 8. Pattern: Configurable Rendering Mode — Pure-Widget vs. SSR-Shell

**Satisfies: #1 for clients who don't need it; a real, honest answer for clients who do.**

Per your call — configurable per client, not a single global choice:

- **Pure-Widget mode** (the default, and what's effectively running at Everest today, confirmed): the fastest to integrate, zero server-side rendering work, category/search pages have no crawlable product content until JS executes. Correct choice for clients where organic PLP/search-page SEO isn't the acquisition channel that matters.
- **SSR-Shell mode**: an additive (never `remove`-based, per §1) server-rendered block, populated from FalcoSense's own already-synced local product data (the `product_info`/sync tables from the architecture PDF — not a live platform round-trip, so it doesn't reintroduce a hard dependency on the search platform being reachable at page-render time), producing a real, crawlable minimal grid on first response. The widget then progressively enhances that same DOM into the full interactive experience for real visitors — the crawler sees real HTML; the shopper still gets the SPA-style overlay. This is genuinely more work to build once, but it's built **once, in the module**, and becomes a config toggle per store view from then on — not a decision anyone has to re-litigate per client.

Worth surfacing plainly, since you asked me to check: Everest is currently running (accidentally, not by deliberate choice) in a mode equivalent to Pure-Widget for *every* PLP/search surface, with no SSR fallback anywhere in either fork. Whether that's actually fine for Everest's SEO needs is a real product question worth a real answer, not an artifact of what happened to get built first — worth putting on the list for the next conversation rather than assuming either way here.

---

## 9. What this does to the issues already on record

Mapping this design directly against the two prior audits, so it's clear this isn't a parallel proposal but a resolution of specific, already-documented failures:

| Documented issue | Resolved by |
|---|---|
| Hardcoded Luma block names removed at layout-compile time (guide #1 §5.3) | Additive Only (§1) — nothing is ever removed |
| CSS `!important` wars against theme selectors (guide #1 §6) | Shadow DOM isolation (§2) — no shared CSS scope to fight over |
| `results.phtml` God template — markup/CSS/JS/logic fused, unforkable per-piece (guide #1 §7) | Single versioned widget bundle (§2) — one implementation, not a per-theme fork |
| SKU-string variant guessing instead of real configurable-attribute data (guide #1 §8.2) | `VariantResolverInterface` using real Magento configurable-attribute APIs (§6) |
| Add-to-cart bypasses the parent-configurable cart contract (guide #1 §8.3) | Default `CartAdapterInterface` implementation drives the real native cart flow (§6) |
| `search-form.phtml` rewritten in place on a Magento core template; 3 diverging copies (Everest audit §1) | Progressive Enhancement (§3) — the native template is never touched, module ships one owned bundle |
| Layout XML coupled to other vendors' block names — Klevu, Webkul (Everest audit §2) | Fail-Open Mounting (§4) + Additive Only (§1) — no removal, so no dependency on knowing what else is there |
| Competing `di.xml` preferences for the same class, from two different modules (Everest audit §3.1) | Ports & Adapters (§5) — one Port, one configured Adapter, explicit and singular by construction |
| `Controller/Search/Token.php`'s cache-aware live-token pattern (Everest audit §4 — a *good* pattern, currently Everest-only) | Promote into the core module as the standard token mechanism for every client, not a one-off fork |
| `DisabledParentResolver` / `DuplicateSkuResolver` (Everest audit §4 — also genuinely good, currently Everest-only) | Promote into the core module's sync layer — every client benefits from data-integrity handling that's currently only protecting Everest |

---

## 10. Surviving adversarial review — a threat model

You said your CEO, director, and team will actively try to find the gaps. Here's the scenario table worth walking through with them directly, since "what happens when X" answered in advance is more convincing than "trust the architecture."

| Scenario | What happens | Why |
|---|---|---|
| FalcoSense's JS bundle fails to load (CDN hiccup, ad-blocker, whatever) | Site looks and behaves exactly as it did before FalcoSense was installed | Nothing was ever removed (§1); the widget only *adds* a takeover, never subtracts the fallback |
| Client's theme doesn't use the standard `search_mini_form`/`name=q` convention | FalcoSense's search-as-you-type simply doesn't attach; native search still works | Progressive Enhancement (§3) fails closed by design; fixed via one documented config value once noticed |
| Client is mid-migration off Klevu/Elasticsearch, old search engine still fully installed | Both can coexist indefinitely; no conflict, no removal collision | FalcoSense never declares or removes any block either of them owns (§1, §4) |
| A completely different, unrelated new extension gets installed later and adds its own block to the category page | Zero effect on FalcoSense | FalcoSense was never coupled to what else renders there — it takes over visually only after confirming its own data, and never depended on knowing the full roster of what else exists (§4) |
| Client has a fully custom, non-Magento-native cart (marketplace, bespoke checkout) | Documented as an explicit, isolated integration step: implement `CartAdapterInterface`, one `di.xml` line | Not a silent workaround (contrast: the original module's SKU-guessing hack) — a named, reviewable extension point (§5, §6) |
| FalcoSense's sync data is stale or incomplete at go-live | Widget shows whatever it has; SSR-shell mode (if enabled) shows the same locally-synced snapshot a crawler would see — no page ever renders "half-broken," it renders "not yet fully synced" | Sync completeness is a data-freshness concern, isolated from rendering correctness by construction |
| API key is wrong, expired, or platform is unreachable | Token endpoint (§7, promoted from Everest's `Token.php`) fails cleanly with a `503`; widget fails open exactly as in the JS-load-failure case above | Same fail-open contract applies uniformly, regardless of *why* the widget couldn't get live data |
| Two environments (staging + prod) both fire the mount script on the same page by accident | Contained to a single, namespaced Shadow DOM root; no global CSS/JS collision even in the worst case | Shadow DOM boundary (§2) makes even a genuine integration mistake non-catastrophic |

The pattern across every row: the failure mode is always **"FalcoSense quietly doesn't do anything extra,"** never **"the client's existing site breaks because FalcoSense is there."** That asymmetry — fail toward inertness, never fail toward damage — is the actual, provable claim to bring into that review, and it's a direct, mechanical consequence of Additive Only (§1), not a promise layered on top of an architecture that doesn't structurally guarantee it.

---

## 11. Open questions for the next conversation

This document is the target shape, not a finished spec. Worth carrying forward, explicitly, rather than quietly deciding inside implementation:

- **SSR-Shell mode's actual trigger mechanism** — per-store config flag is the obvious default, but is there a case for auto-detecting (e.g., based on whether the platform's sync data confirms a catalog size where organic PLP traffic is likely to matter)?
- **The `VariantResolverInterface`/`CartAdapterInterface` exact method signatures** — sketched here at the concept level; real signatures need a pass against Magento's actual configurable-product and quote APIs before this is buildable.
- **Migration plan for Everest specifically** — it's currently running the *old*, tightly-coupled architecture with real, working (if fragile) fixes layered on top (the Everest audit's §4 "already right" list). Moving Everest onto this target architecture is a distinct project from designing it, with its own sequencing questions (can it be done incrementally, block by block, or does it need a cutover).
- **What "SSR-shell" needs from the sync pipeline** — guide #1 §10 already flagged the sync pipeline itself (cron/MQ/shell_exec overlap) as needing attention; SSR-shell mode adds a new consumer of that data (server-side render at request time) that the current sync architecture wasn't designed around.
