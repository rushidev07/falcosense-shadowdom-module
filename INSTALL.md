# FalcoSense Smart Search — Install Guide (`Ahy_SmartSearchLuma`)

For whoever installs this on the **dev2** instance. Every command is run from the
Magento root (the folder that contains `bin/magento`), over SSH / terminal on the
machine that runs dev2.

> **Time:** ~15 minutes. **Risk:** low — everything new is off by default, and it
> fails back to the site's normal search/category pages if anything isn't ready.

---

## 0. Before you start — have these ready

- Magento **2.4.x**, PHP **8.1+** (this is what dev2 already runs).
- Terminal access to the dev2 server, able to run `bin/magento`.
- From the FalcoSense platform team: the **platform base URL**, the **search API
  URL**, and the **API key** for this store.
- 10 minutes when the site can be in maintenance mode (dev2, so fine any time).

---

## 1. Put the module in place

The folder you were sent **is** the module. Copy it so its path becomes exactly:

```
<magento-root>/app/code/Ahy/SmartSearchLuma/
```

So `app/code/Ahy/SmartSearchLuma/registration.php` must exist after copying.

```bash
# from the magento root, assuming the folder was uploaded to /tmp/SmartSearchLuma
mkdir -p app/code/Ahy
cp -r /tmp/SmartSearchLuma app/code/Ahy/SmartSearchLuma
```

---

## 2. Deal with the **old** FalcoSense module first  ⚠️ important for dev2

dev2 already has the older FalcoSense search integration (`FalcoSense_Search`,
plus its theme overrides). **Running both at once is not supported** — they both
try to own the search route and the header search box, and you'll get duplicate
or broken UI.

Check what's there:

```bash
bin/magento module:status | grep -i -E 'falco|smartsearch'
```

Disable the old one (keep the new `Ahy_SmartSearchLuma` — that's this module):

```bash
bin/magento module:disable FalcoSense_Search
```

If that command lists other old FalcoSense/Klevu search modules as enabled,
disable those too (ask before disabling anything named `Ahy_ThemeCustomization`
— that one may carry unrelated theme code).

> Rolling back later = re-enable `FalcoSense_Search`, disable `Ahy_SmartSearchLuma`,
> and re-run step 3. Nothing is deleted.

---

## 3. Enable and build

```bash
bin/magento module:enable Ahy_SmartSearchLuma
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

If dev2 runs in developer mode you can skip `di:compile` and
`static-content:deploy`, but running them does no harm.

Confirm it's on:

```bash
bin/magento module:status Ahy_SmartSearchLuma      # -> "Module is enabled"
```

---

## 4. Configure it (Magento Admin)

**Stores → Configuration → Ahy → Smart Search → General Settings**

| Field | Value |
|---|---|
| Enable Frontend UI | **Yes** |
| Enable Sync | **Yes** |
| Enable Real-Time Sync | **Yes** |
| Enable Shadow DOM Widget (Beta) | **Yes** |
| Platform Endpoint URL | *(from the platform team, e.g. `https://platform.example/api/v1/ingest/products`)* |
| API Key | *(from the platform team)* |
| Search API URL | *(from the platform team)* |
| Platform Store / Tenant ID | *(the platform-side store id for this store view — ask the platform team; leave 0 only if they don't have one yet)* |
| Products Per Page | 12 (or 24) |

Leave **Category & Search SSR (ISR)** → *Enable Platform SSR for Listings* on
**No** for now. Turn it on in step 6.

Click **Save Config**, then:

```bash
bin/magento cache:flush
```

---

## 5. Sync the catalog to the platform

```bash
bin/magento smartsearchluma:sync:full --force
```

Watch it:

```bash
tail -f var/log/smartsearch-full-sync.log
```

Wait until it reports done. **Check the count** — if it synced far fewer products
than the catalog has (e.g. 4,000 of 100,000), the platform account is
quota-limited; sort that with the platform team before relying on category pages.

Make sure Magento cron is running (it usually is on dev2) — the module keeps the
platform in sync every minute after this.

---

## 6. Turn on the pieces — one at a time

Test after each one. All of these are in the same admin section.

**6a. Header search + search results page** — already on from step 4.
Load the storefront, type 3+ letters in the search box → the FalcoSense overlay
should appear. Submit → the FalcoSense search-results page.

**6b. Category pages (SSR + ISR).**
Set **Enable Platform SSR for Listings = Yes**, Save, `bin/magento cache:flush`.
Open a category page. You should see the FalcoSense product grid.

Keep the other SSR fields at their defaults to start:
- Snapshot TTL: `600`
- Platform Render Timeout: `500`
- Minimum Sync Coverage Ratio: `0` (turn this to `0.5` once the catalog is fully
  synced — it makes half-synced categories fall back to the native grid instead
  of showing a thin grid)
- Pre-warm Popular Categories: `No` for now

---

## 7. Verify it's actually working

**A. Real content is server-rendered (SEO):** open a category page, then
`View Source` (Ctrl-U, *not* "Inspect"). Search the source for `fs-plp`. You
should see real product names, prices, and links, plus a
`<script type="application/ld+json">` block — **before any JavaScript runs**.

**B. It fails safe:** in admin set *Enable Platform SSR for Listings* back to
**No**, flush cache, reload the category page → it's the normal Magento category
page, completely intact. Turn SSR back on.

**C. JavaScript off:** disable JS in the browser, reload a category page → the
grid is still there and the pagination links still work.

**D. Cache invalidation:** edit a product's price in admin and save. Reload its
category page (hard refresh) → the new price shows within a few seconds.

---

## 8. If something looks wrong

| Symptom | Fix |
|---|---|
| Two search boxes / two product grids | The old `FalcoSense_Search` module is still enabled — go back to step 2. |
| Category page shows the **native** grid, not FalcoSense | Platform not reachable, not configured, or catalog not synced. Check `var/log/system.log` and `var/log/exception.log` for `[SmartSearchLuma][PLP]`. This is the safe fallback — the site isn't broken. |
| Search-as-you-type doesn't trigger | The theme's search box isn't the standard one. Note the theme name and its search input's `id` — a one-line config override handles it. |
| Empty toolbar / "0 items" above the FalcoSense grid (no-JS view) | Set **Native Category Grid Selector** to match this theme's product-grid wrapper class. Products are already correct; this is cosmetic. |
| `di:compile` fails | Copy the full error and send it back — almost always a leftover file from the old module or a half-copied folder. |

**Full technical detail:** see `FALCOSENSE-PLP-ISR-BUILD.md` in the module root.

---

## 9. Rollback (one command sequence)

```bash
bin/magento module:disable Ahy_SmartSearchLuma
bin/magento module:enable FalcoSense_Search
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The dev2 site is back to exactly how it was.
