# PLP CSS Isolation — Implementation Handoff

*Written 2026-09-01. Read this whole file before touching anything — it's the
full record of a long design discussion, condensed into one concrete task.*

---

## 0. Read this first — current repo state

Checked immediately before writing this doc (`git status`, `git diff`):

- **`view/frontend/web/css/plp.css` has ZERO uncommitted changes right now —
  it is byte-identical to `HEAD` (commit `1d8d2ad`).** Earlier in the session
  that produced this doc, several CSS edits were made to this file (a
  padding/max-width fix, `!important` hardening) — but by the time this
  handoff was written, the file had reverted to its original committed state
  (cause unclear — possibly a `git checkout` run outside that conversation).
  **Treat the CSS work below as not-yet-started, from a clean baseline.**
  Don't assume any prior fix is already present — verify with `git diff`
  before relying on anything in older notes/chat history.
- **`etc/di.xml` DOES have an uncommitted change**: `PlpDataProviderInterface`
  is temporarily bound directly to `Service\Plp\OpenSearchPlpProvider`
  instead of the caching decorator `Service\Plp\CachedPlpProvider`, as a
  deliberate, separate experiment (testing the module with no local cache /
  no last-known-good fallback). **This is UNRELATED to the CSS task below —
  do not revert it, "fix" it, or touch it as part of this work.** If you need
  to know why it's there, the comment left in the file explains it.
- Module enable/disable state and caching decisions are tracked in Magento
  config (`core_config_data` on dev2), not in this repo — this doc doesn't
  change any of that.

---

## 1. The problem this solves

The PLP grid (search results + category pages) renders via
`Model/Plp/PlpRenderer.php` directly into the page's **light DOM** — plain
HTML, not shadow DOM. That choice is deliberate and final (see §2), but it
means there is **no browser-enforced isolation boundary** between
FalcoSense's own CSS (`view/frontend/web/css/plp.css`) and the host Everest2
theme's CSS. They share one cascade. Two concrete symptoms already happened:

1. The grid/sidebar/heading rendered edge-to-edge on the page (missing
   side padding/margin) because the host page's layout rules won a silent
   cascade tie against `plp.css`.
2. More generally: *any* property `plp.css` doesn't explicitly and robustly
   assert can be silently overridden by the theme, or can silently leak
   theme styling *into* FalcoSense's own elements (buttons, lists, links
   inheriting the theme's resets/typography without us asking for it).

---

## 2. The decision (final — do not revisit without a real reason)

**Keep light DOM + server-side rendering exactly as-is.** This was
extensively discussed and is not up for debate as part of this task:

- Shadow DOM would give automatic CSS isolation, but shadow-DOM content is
  either invisible or unreliably visible to non-JS crawlers (GPTBot,
  ClaudeBot, PerplexityBot) — even with Declarative Shadow DOM (`<template
  shadowrootmode="open">`), which real browsers and Googlebot's renderer
  support, but which most simple/non-JS crawler parsers do not implement.
  Since crawlability (SEO/AEO) is the whole point of the SSR rebuild, light
  DOM is the only reliable choice for the primary render path.
- **Tailwind CSS was considered and rejected** for this module specifically
  because Everest2 is already a Hyvä+Tailwind theme — using stock Tailwind
  utility classes for FalcoSense's own markup would mean using the *same*
  class names the theme already uses (worse collision risk, not better),
  and Tailwind's own Preflight reset has the same type-selector-leak problem
  plain CSS does, so it doesn't remove the need for the isolation strategy
  below — it would just add a new build-toolchain dependency on top of it.

**To compensate for light DOM's lack of automatic isolation, use two
complementary CSS techniques together** (not either/or):

1. **`all: revert`** on every FalcoSense element — wipes out anything the
   host theme's CSS would otherwise contribute (resets, type selectors like
   `button {}`/`ul {}`, inheritance) before any of `plp.css`'s own rules
   apply. This is what protects against *properties we never explicitly
   declared* leaking in unnoticed.
2. **`!important`** on the box-model properties that control page layout
   (`box-sizing`, `width`, `max-width`, `margin`, `padding`) — guarantees
   those specific, layout-critical properties can never be silently
   overridden by the theme, regardless of specificity or stylesheet load
   order. This is what protects against *properties we did explicitly
   declare* losing a cascade tie (this is what actually broke last time).

These solve different problems and are both needed — `!important` alone
doesn't stop inherited/reset leakage; `all: revert` alone doesn't guarantee
our own explicit layout values win a specificity fight.

---

## 3. Known gaps to close in the same pass (found during design review)

- **`all: revert` also wipes any focus-visible outline the theme provides**
  for keyboard navigation. `plp.css` currently has zero `:focus`/
  `:focus-visible` rules, so without adding our own, tabbing through filter
  checkboxes / Add to Cart / pagination would fall back to the browser's
  bare default outline instead of anything theme-consistent. Add explicit
  `:focus-visible` styles as part of this change (included in §4 below).
- **SVG icons are already safe** — checked `PlpRenderer.php`: the chevron
  and wishlist SVGs use `fill="currentColor"`/`stroke="currentColor"` as
  HTML attributes, not CSS rules, so `all: revert` doesn't affect them
  (they resolve from the `color` property, which is correctly re-declared
  on `.fs-plp` after the revert). No action needed here — just don't
  "fix" this, it isn't broken.
- Every non-default `display` (`.fs-plp-layout { display: flex }`,
  `.fs-plp-grid { display: grid }`, the mobile drawer's `position: fixed`)
  needs to still resolve correctly after the revert. It checks out on
  cascade-order math (see §5 for why), but **must be visually verified**,
  not just trusted from the diff — see the testing checklist in §6.

---

## 4. Exact implementation

**File to change**: `view/frontend/web/css/plp.css` — this is the only file
this task touches.

### 4a. Add the isolation boundary as the very first rule in the file

Insert this immediately after the top doc-comment block (before the current
first rule, `.fs-plp { ... }`):

```css
/* ── Isolation boundary — MUST stay the first rule in this file ─────────── */
.fs-plp, .fs-plp *, .fs-plp *::before, .fs-plp *::after,
.fs-plp-fullpage, .fs-plp-fullpage-head {
    all: revert;
}
```

`.fs-plp-fullpage`/`.fs-plp-fullpage-head` are included because they're the
full-page type-ahead takeover's wrapper and heading — they sit as *siblings*
of `.fs-plp` in that mode (see `plp-search.js`), not descendants, so they
need to be listed explicitly or the revert won't reach them.

**Why this must be the first rule**: `all: revert` only protects properties
declared *after* it in the cascade (same-specificity ties resolve by source
order). Every other rule in this file must come after it, which they
already do as long as this block is inserted at the top.

### 4b. Update `.fs-plp`'s own rule to harden the box-model + keep the
    existing padding/max-width fix

Replace:

```css
.fs-plp {
    --fs-ink: #0d2f47;
    --fs-ink-soft: #6b7a84;
    --fs-line: #e3ddcf;
    --fs-accent: #e63232;
    --fs-surface: #f4efe4;
    --fs-card-radius: 4px;
    margin: 0 0 48px;
    color: var(--fs-ink);
    font-family: inherit;
    font-size: 15px;
}
```

with:

```css
.fs-plp {
    --fs-ink: #0d2f47;
    --fs-ink-soft: #6b7a84;
    --fs-line: #e3ddcf;
    --fs-accent: #e63232;
    --fs-surface: #f4efe4;
    --fs-card-radius: 4px;
    /* Self-contained page width, deliberately not left to whatever container
       the theme happens to render this into — the grid otherwise runs edge
       to edge on themes/pages whose content area isn't itself constrained.
       !important on the box-model properties: this stylesheet's own position
       in <head> (relative to the theme's) isn't guaranteed, so these must win
       on specificity alone, not on load order — the layout must hold no
       matter what the host page's CSS does. */
    --fs-page-max-width: 1600px;
    --fs-page-padding: 40px;
    box-sizing: border-box !important;
    width: 100% !important;
    max-width: var(--fs-page-max-width) !important;
    margin: 0 auto 48px !important;
    padding: 0 var(--fs-page-padding) !important;
    color: var(--fs-ink);
    font-family: inherit;
    font-size: 15px;
}
```

(`font-family: inherit` here is also what restores the one thing we
deliberately want back after `all: revert` wiped it — the site's brand
font. Everything else in this block is our own explicit choice, same as
before.)

### 4c. Add focus-visible styles (new — closes the gap from §3)

Add this block somewhere reasonable near the top of the file (e.g. right
after the `.fs-plp button { font: inherit; cursor: pointer; }` /
`.fs-plp [hidden]` lines):

```css
/* ── Keyboard accessibility — `all: revert` above also reverts any focus
   ring the host theme provides, so it needs restating explicitly here. ── */
.fs-plp a:focus-visible,
.fs-plp button:focus-visible,
.fs-plp select:focus-visible,
.fs-plp input:focus-visible {
    outline: 2px solid var(--fs-ink);
    outline-offset: 2px;
}
```

### 4d. Fix the full-page takeover heading + wrapper (the original
    bug — heading is a sibling of `.fs-plp`, doesn't inherit its padding)

Replace:

```css
/* ── Full-page type-ahead takeover ───────────────────────── */
body.fs-plp-search-active [data-fs-hidden-by-search] { display: none !important; }
.fs-plp-fullpage { display: block; padding: 8px 0 24px; }
.fs-plp-fullpage-head {
    margin: 0 0 24px;
    font-size: clamp(28px, 5vw, 56px); font-weight: 800; line-height: 1.05;
    text-transform: uppercase; color: #0d2f47;
}
.fs-plp-fullpage .fs-plp-loading {
    display: flex; align-items: center; justify-content: center;
    min-height: 40vh; color: #6b7a84; font-size: 15px;
}
```

with:

```css
/* ── Full-page type-ahead takeover ───────────────────────── */
/* Same --fs-page-max-width/--fs-page-padding values as .fs-plp, but defined
   here too: the heading is a sibling of the nested .fs-plp, not a descendant,
   so it can't read custom properties scoped only to that selector. The
   nested .fs-plp itself is reset to full-width — its own centering would
   otherwise double the padding and misalign it against the heading. Every
   box-model value below is !important for the same reason as .fs-plp: this
   must hold regardless of the host page's own CSS or its load order. */
body.fs-plp-search-active [data-fs-hidden-by-search] { display: none !important; }
.fs-plp-fullpage {
    --fs-page-max-width: 1600px;
    --fs-page-padding: 40px;
    box-sizing: border-box !important;
    display: block !important;
    width: 100% !important;
    padding: 8px 0 24px !important;
}
.fs-plp-fullpage-head {
    box-sizing: border-box !important;
    max-width: var(--fs-page-max-width) !important;
    margin: 0 auto 24px !important;
    padding: 0 var(--fs-page-padding) !important;
    font-size: clamp(28px, 5vw, 56px); font-weight: 800; line-height: 1.05;
    text-transform: uppercase; color: #0d2f47;
}
.fs-plp-fullpage .fs-plp {
    width: 100% !important;
    max-width: none !important;
    margin: 0 0 48px !important;
    padding: 0 var(--fs-page-padding) !important;
}
.fs-plp-fullpage .fs-plp-loading {
    display: flex; align-items: center; justify-content: center;
    min-height: 40vh; color: #6b7a84; font-size: 15px;
}
```

### 4e. Add responsive padding overrides

Add this new block right after the block in 4d (before the existing
`/* ── Mobile ── */` section):

```css
/* ── Responsive page padding ─────────────────────────────── */
@media (max-width: 1024px) {
    .fs-plp, .fs-plp-fullpage { --fs-page-padding: 24px; }
}
@media (max-width: 767px) {
    .fs-plp, .fs-plp-fullpage { --fs-page-padding: 16px; }
}
```

### What NOT to change

- The existing `.fs-plp *, .fs-plp *::before, .fs-plp *::after { box-sizing:
  border-box; }` rule (currently line 25) — leave it exactly where it is.
  It's technically redundant with `box-sizing: border-box` now also being
  set inside the `all: revert` selector list, but it's harmless (same
  value, just re-asserted) and removing it isn't part of this task.
- Everything from `.fs-plp-layout { display: flex; ... }` down through the
  grid/card/pagination rules, and the existing `@media (max-width: 767px)`
  mobile block at the bottom — none of that needs to change. `all: revert`
  being placed first in the file means these later, more specific rules
  still win normally; don't add `!important` to them, that's not part of
  this task and adds risk for no benefit.
- Do not touch `etc/di.xml` (see §0).
- Do not touch any JS file (`plp-hydrate.js`, `plp-search.js`,
  `plp-fragment.js`, etc.) — this is a CSS-only task. `all: revert`
  automatically covers content those scripts inject later (filter/sort/
  pagination fragment swaps), since it's a stylesheet rule, not something
  applied imperatively — no JS changes needed for that to work.

---

## 5. Why this is expected to work (cascade math, for context)

- `all: revert` and `.fs-plp`'s own rules both use the selector `.fs-plp`
  with identical specificity (0,1,0). Same-specificity ties resolve by
  *source order* — since the revert block is placed first in the file and
  `.fs-plp`'s real rule comes second, the real rule correctly wins for
  every property it declares.
- Properties `.fs-plp`'s own rule marks `!important` win regardless of
  order or specificity — full stop, that's what `!important` means.
- Every other rule further down the file (`.fs-plp-layout`, `.fs-plp-grid`,
  etc.) has specificity ≥ (0,1,0) and comes later in source order than the
  revert block, so they also naturally win their own properties without
  needing `!important` themselves.

---

## 6. Testing checklist — MUST be done on an actual rendered page, not
    just a code read

CSS cascade correctness on paper is not the same as CSS cascade correctness
in a browser — verify all of the following on dev2 (or wherever this gets
deployed) after making the change:

- [ ] `/fs/search?q=tshirt` (or any search query) — grid has proper side
      padding/margins at desktop width, matches the heading's left/right
      edges exactly (this was the original bug)
- [ ] Same check at the `1024px` and `767px` breakpoints — padding should
      shrink per §4e, not disappear or double up
- [ ] Filter sidebar layout (`.fs-plp-layout { display: flex }`) still
      renders as a proper two-column layout, not stacked/broken
- [ ] Product grid (`.fs-plp-grid { display: grid }`) still renders as a
      grid, correct column count at ≥1280px width
- [ ] Mobile filter drawer (`<767px`, tap the filter toggle) still slides
      in from the right correctly, backdrop still shows
- [ ] Tab through the page with a real keyboard (no mouse) — filter
      checkboxes, sort `<select>`, Add to Cart buttons, and pagination
      links all show a visible focus ring
- [ ] Colors/fonts/button styling still match the intended design (spot
      check a few cards, the "Add to Cart" button, the sort dropdown)
- [ ] Apply a filter or change sort/page — confirm the swapped-in fragment
      (via `/smsl/plp/grid`) is styled identically to the initial page load
- [ ] Type 3+ letters in the header search box (the full-page takeover
      path) — heading and grid both padded correctly, matches the direct
      `/fs/search` page
- [ ] `View Source` on a search/category page still shows the real product
      HTML in the initial response (confirms this change didn't
      accidentally break SSR itself — it shouldn't, this is CSS-only, but
      worth the 10-second sanity check)

---

## 7. Deploy steps — and a known gotcha from last time

Standard steps for a CSS-only change on dev2:

```bash
cd /home2/dev2/www
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

**Known gotcha, hit during this exact task last time**: after running
those commands, the browser can still show the *old* compiled CSS (verified
via DevTools showing a stale line number that matched the pre-fix file,
even after a redeploy). If the fix doesn't visually appear after deploying:

```bash
cd /home2/dev2/www
echo "--- source file ---"
grep -n "all: revert" app/code/Ahy/SmartSearchLuma/view/frontend/web/css/plp.css
echo "--- version Magento thinks is current ---"
cat pub/static/deployed_version.txt
echo "--- compiled copy for that version ---"
grep -n "all: revert" pub/static/version*/frontend/Ahy/Ahy_Everest2/en_US/Ahy_SmartSearchLuma/css/plp.css 2>/dev/null
```

If the compiled copy doesn't have the change even though the source file
does, the nuclear (but safe — only deletes regenerable build output) fix
is:

```bash
rm -rf pub/static/frontend pub/static/version*
rm -rf var/view_preprocessed/*
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

Then check in a **new** incognito window (not just a refresh of an existing
tab) — DevTools should show `plp.css` at a line number in the 190s–210s
range (the new file is longer than the original 171 lines), not matching
any line number from the original committed version.

---

## 8. Explicitly out of scope for this task (discussed, not decided —
    don't act on these without asking first)

- **Whether to retire the legacy shadow-DOM CSR fallback**
  (`takeover.js`, `category-enhancement.js`, `plp-chrome.js`) — extensively
  discussed, genuinely unresolved. Current known facts, for whoever picks
  this up later: it's inconsistently gated (search falls back to it
  automatically when SSR renders nothing; category needs
  `category_enhancement_enabled`, which defaults `0` in `etc/config.xml`
  and isn't set on dev2, so category currently gets *no* CSR fallback at
  all). Do not delete or "fix" any of this as a side effect of the CSS task.
- **The Declarative Shadow DOM shell in `bootstrap.phtml`/
  `Block/Widget/Bootstrap.php`** — confirmed dead code
  (`isSsrShellActive()` hardcoded `return false`), and confirmed to pull
  from Magento's *native* category product collection rather than the
  FalcoSense platform if it were ever turned on — i.e. turning it on as-is
  would reintroduce a two-sources-of-truth problem. Leave it alone; this
  is a separate future decision (likely: delete it), not part of this task.
- **The PLP caching layer** (`etc/di.xml`'s current state) — see §0.
  Separate experiment, separate decision, don't touch.

---

## 9. Other docs in this repo for background (read if more context is
    needed, not required for this specific task)

- `DEV2-SEARCH-FIXES.md` — the original dev2 diagnosis + fixes
- `FALCOSENSE-PLP-ISR-BUILD.md` — the SSR/ISR build plan + concern register
- `FALCOSENSE-TARGET-ARCHITECTURE.md`, `MAGENTO-FALCOSENSE-GUIDE.md`,
  `FALCOSENSE-SHADOWDOM-IMPLEMENTATION-PLAN.md` — prior architecture
  research
