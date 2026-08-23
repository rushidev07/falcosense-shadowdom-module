# Magento Fundamentals, Through FalcoSense

*An onboarding guide for someone who knows MERN/Next/Nest/RN cold, and needs to actually understand Magento — not memorize it — well enough to architect FalcoSense properly.*

---

## 0. Why this document exists, and how to read it

You've inherited two facts at the same time: FalcoSense broke badly on the Everest.com integration, and you're now the person responsible for making sure it doesn't happen again — in a stack you didn't grow up in. Your CEO, who does know Magento, pointed at specific things: how layouts get overridden, what XML files actually do, how to keep the module decoupled from "the website behind it."

This document teaches you the Magento concepts you need, and — instead of using generic textbook examples — uses **FalcoSense's own code** as the worked example for almost every concept. You will see the actual file, the actual line, and in several places you'll see exactly where FalcoSense does the *opposite* of what the concept recommends. That's intentional. The fastest way to actually internalize "why layout XML should never hardcode a theme's internal block names" is to see the line in `ahy_smartsearch_active.xml` that does exactly that, and understand why it will break.

**What this document is not:** it is not a redesign proposal. It doesn't tell you what to build instead. That's the system-design conversation you're planning next — this is the prerequisite knowledge for having that conversation as an equal, not as someone nodding along.

**Scope note on your stated goal:** you told me the plan is *not* to make FalcoSense theme-adaptive for every possible Magento theme. The plan is: FalcoSense ships one solid, self-contained base experience; clients are free to re-skin or override it into their own theme *using Magento's supported extension points*. Keep that in your head through this whole document — it reframes almost every "gap" below. The problem was never "we didn't support Hyvä." The problem is FalcoSense doesn't currently expose a stable, documented seam for a client to override it *at all* — it performs surgery directly on Luma's internals, so there is no clean interface for a client theme to plug into, regardless of which theme they use.

---

## 1. The 60-second mental model

Forget "Magento is an e-commerce platform" — that's true but useless for orientation. Here's the translation you actually need:

| What you know | Magento's version | Notes |
|---|---|---|
| Express/Nest app | A **module** (`app/code/Vendor/Module`) | A module is a self-contained plugin, not the whole app. A Magento site is dozens of modules (core + third-party) composed together. |
| Nest's DI container / providers | **Object Manager + `di.xml`** | Constructor injection by type-hint, exactly like Nest. `di.xml` is where you configure it — think of it as `providers: []` in a Nest module, but XML. |
| Nest middleware / interceptors, or a monkey-patch you wish were sanctioned | **Plugins (interceptors)** | Wrap a public method on *any* class from *any* module, without touching its source. This is Magento's official "extend without forking" mechanism. |
| Event emitters (`EventEmitter2`, webhooks) | **Events & Observers** | `events.xml` binds an observer class to a named event. Loosely coupled, fire-and-forget by default. |
| Next.js `app/` routing + layouts | **Layout XML + Blocks + Containers** | This is the single most important section below. A "page" is assembled from named containers/blocks the way a Next layout composes nested `layout.tsx` files — except the composition is declared in XML and merged from *every module and the theme*, not just one file tree. |
| React component (JSX + logic) | **Block (PHP class) + Template (`.phtml`)** | The Block is the "controller" for one chunk of UI; the `.phtml` is the view. Similar split to a container/presentational component pair. |
| CSS Modules / Tailwind scoping | **Nothing automatic** — Magento CSS is global by default | This is a real difference, not just unfamiliar syntax. There's no build-time scoping. Namespacing discipline is 100% on you. |
| A themeable design system (e.g. shadcn "new-york" vs "default") | **Magento Themes**, with **fallback inheritance** | A theme can be a from-scratch UI or a thin set of overrides on a parent theme (Luma, Blank, Hyvä, a custom one). |
| Postgres row + ORM entity | **EAV entity** (Product, Category, Customer) | Products aren't a flat table. Attributes live in per-type tables (varchar/int/decimal/text) keyed by attribute + store + entity. This explains a lot of "why is this so indirect" moments later. |
| BullMQ / SQS worker | **Message Queue consumers** (`queue_consumer.xml`) + **Cron jobs** (`crontab.xml`) | Two different async primitives Magento ships natively. FalcoSense uses both — plus a third, non-native one. More on that in §10. |
| `docker exec` a bespoke bash script for one-off ops | **Console Commands** (`bin/magento vendor:module:command`) | The formal way to run indexers, custom sync jobs, etc. |

Two words you'll hear constantly and should nail down immediately:

- **PLP** — Product Listing Page (category page / search results grid). Where FalcoSense's `Category` block and `results.phtml` live.
- **PDP** — Product Detail Page (single product page). FalcoSense does not currently touch this — worth noting, since "search → PLP → cart" is the whole loop today, and PDP is a gap in itself.

---

## 2. Anatomy of a module

Every Magento module is identified by a `Vendor_Module` name — here, `Ahy_SmartSearchLuma`. Two files make a folder into a real module:

**`registration.php`** — tells Magento's component registrar "a module lives here":
```php
ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Ahy_SmartSearchLuma', __DIR__);
```

**`etc/module.xml`** — declares the module and its load-order dependencies:
```xml
<module name="Ahy_SmartSearchLuma" setup_version="1.0.0">
    <sequence>
        <module name="Magento_MessageQueue"/>
        <module name="Magento_CatalogInventory"/>
    </sequence>
</module>
```
`<sequence>` doesn't create a hard dependency (Magento won't refuse to run without those modules present) — it only guarantees *load order* relative to them, which matters because layout XML, `di.xml`, and `events.xml` from all modules get **merged**, and merge order can matter (e.g., a later-loaded layout file can remove/override something an earlier one declared).

**Naming convention worth internalizing now:** `Ahy_SmartSearchLuma`. That "Luma" in the module name isn't cosmetic — it's the first data point that this module was conceived *as a Luma extension*, not as a platform-agnostic Magento module that happens to ship a Luma-based default look. That distinction sounds pedantic until you get to §6.

**Folder layout, mapped to concerns you already know:**

| Folder | Rough equivalent |
|---|---|
| `Api/` | Public interfaces (contracts) — like an interface file you'd hand to a consumer package |
| `Block/` | "Controller" half of each UI component |
| `Controller/` | HTTP route handlers (per area — `Controller/Adminhtml/...` vs `Controller/Ajax/...` vs `Controller/Product/...`) |
| `Model/`, `Service/` | Domain logic, persistence, outbound integrations |
| `Observer/` | Event listeners |
| `Plugin/` | Interceptors (method-level monkey-patches, sanctioned) |
| `Helper/` | Grab-bag utility/config-reader class (an older Magento 1 convention that's still common; `Helper/Data.php` here is basically a typed config accessor) |
| `Cron/` | Scheduled job classes |
| `Console/Command/` | CLI commands |
| `Logger/` | Custom Monolog channel wiring |
| `etc/` | All XML configuration — the module's "wiring diagram" |
| `view/frontend/`, `view/adminhtml/` | Per-area templates, layout XML, static assets (`web/js`, `web/css`) |

That `etc/` folder is worth pausing on, because almost every "how does X get connected to Y" question in this codebase is answered by one file in there. Quick map of what you already read in this module:

| File | What it wires |
|---|---|
| `etc/di.xml` | DI overrides, virtual types, constructor argument injection |
| `etc/events.xml` | Event name → Observer class |
| `etc/config.xml` | Default values for system-config fields |
| `etc/crontab.xml` | Cron job → class/method + schedule |
| `etc/communication.xml`, `queue_publisher.xml`, `queue_consumer.xml`, `queue_topology.xml` | Message queue topic/queue/exchange wiring |
| `etc/acl.xml` | Admin permission tree |
| `etc/adminhtml/routes.xml`, `etc/frontend/routes.xml` | URL front-name → module, per area |
| `etc/adminhtml/system.xml` | Admin config UI (Stores → Configuration) field definitions |

---

## 3. Dependency Injection & the Object Manager

Magento's DI will feel *almost* like Nest's, with one crucial difference: Nest makes it very hard to bypass the container. Magento does not — and that gap is where a lot of technical debt hides.

**Constructor injection** works exactly like you'd expect:
```php
public function __construct(
    Context $context,
    private Data $helper,
    private LayerResolver $layerResolver,
    private SearchTokenService $tokenService,
    array $data = []
) { parent::__construct($context, $data); }
```
Type-hint an interface or class, Magento's Object Manager resolves and injects it (with constructor property promotion, PHP 8 style — the module's already using modern PHP here, that part's fine).

**`di.xml` is your composition root**, per area (global `etc/di.xml`, or `etc/frontend/di.xml`, `etc/adminhtml/di.xml`). Three things you'll see in it:

- **`<preference>`** — "when anyone asks for `InterfaceX`, give them `ClassY`" (global, app-wide swap — like binding a token to an implementation in Nest).
- **`<type>` with `<arguments>`** — inject a *specific* value/object into a *specific* class's constructor, without that class knowing. FalcoSense uses this to hand a dedicated logger channel to specific classes:
  ```xml
  <type name="Ahy\SmartSearchLuma\Observer\ProductSaveObserver">
      <arguments>
          <argument name="logger" xsi:type="object">Ahy\SmartSearchLuma\Logger\RealtimeSyncLoggerVirtual</argument>
      </arguments>
  </type>
  ```
- **`<virtualType>`** — a "class" that doesn't need its own PHP file; it's an existing class reconfigured with different constructor args. FalcoSense defines `RealtimeSyncLoggerVirtual` this way to get a Monolog logger pointed at its own log file/channel, without subclassing `Logger`.

**Plugins (interceptors)** are Magento's sanctioned "extend without forking" tool, and FalcoSense uses one correctly — `Plugin/CategoryListPlugin.php` wraps `ListProduct::getLoadedProductCollection()`:
```php
public function aroundGetLoadedProductCollection(ListProduct $subject, callable $proceed)
```
`around` means: intercept the call, decide whether to run the original (`$proceed()`) at all, and return whatever you want. (The other flavors are `before` — mutate arguments before the real method runs — and `after` — mutate the return value.) Plugins only work on **public, non-final methods of classes fetched through the Object Manager** — never on objects you `new` up yourself, and never on `final` classes. This is *why* Magento discourages `final` on public APIs.

**The anti-pattern you need to be able to spot immediately: bypassing DI with `ObjectManager::getInstance()`.** Magento's own coding standard calls this out as the #1 thing not to do, and this codebase does it — right inside a `.phtml` template:
```php
// view/frontend/templates/product/list.phtml
$_om = \Magento\Framework\App\ObjectManager::getInstance();
if (!$_om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class)->isSetFlag(...))
```
Why this matters beyond "style": code that pulls dependencies from the global singleton instead of its constructor is **untestable** (you can't inject a fake config in a unit test), **hides its real dependencies** (you can't tell what a class needs just by reading its constructor), and **breaks compiled/preference-based overrides** in subtle ways. If you remember one DI rule from this whole document: *if a class or template needs a service, it should be injected, never fetched.*

---

## 4. Request lifecycle & routing

Two "areas" matter for FalcoSense: **frontend** (the storefront, what shoppers see) and **adminhtml** (the admin panel). A third, **webapi_rest**, is Magento's REST layer (not used here — FalcoSense's Magento-side controllers are plain HTTP endpoints, and all platform communication happens the *other* direction, via outbound cURL. More on that boundary in §12).

Routing starts with a **front name** registered per area:
```xml
<!-- etc/adminhtml/routes.xml -->
<route id="smartsearchluma" frontName="smartsearchluma">
    <module name="Ahy_SmartSearchLuma"/>
</route>

<!-- etc/frontend/routes.xml -->
<route id="smsl" frontName="smsl">
    <module name="Ahy_SmartSearchLuma"/>
</route>
```
A URL like `/admin/smartsearchluma/sync/fullsync` maps front-name → module → `Controller/Adminhtml/Sync/Fullsync.php` → `execute()`, by **folder path convention**: `Controller/<Path>/<Action>.php` corresponds to `<frontName>/<path>/<action>`. No route table to maintain by hand, unlike Express — it's filesystem convention, closer to Next's `app/` router.

**Admin controllers must declare `ADMIN_RESOURCE`**, which ties the controller to a node in `etc/acl.xml`'s permission tree — this is Magento's RBAC. FalcoSense's admin controllers correctly gate on `Ahy_SmartSearchLuma::config`. This part of the module is done the right way and is a good reference for how ACL should look elsewhere.

**One deployment-risk item worth flagging while we're in Controllers:** `Controller/Adminhtml/Sync/Fullsync.php` triggers a full sync by literally shelling out:
```php
$cmd = sprintf('env -i HOME=%s PATH=%s %s %s smartsearchluma:sync:full --force < /dev/null >> %s 2>&1 &', ...);
@shell_exec($cmd);
```
This is a PHP web request spawning a detached background OS process. It works on a classic single-box LAMP-style host with `shell_exec` enabled. It silently does nothing (or throws, or is blocked entirely) on a huge fraction of real hosting: Magento Cloud/Adobe Commerce Cloud, most containerized/Kubernetes deployments (`shell_exec` frequently disabled, and a spawned background process inside a request-handling container is not guaranteed to outlive the request), and any host with `disable_functions` including `shell_exec`. Magento already ships two supported ways to run something "in the background from a web trigger": queue a message (which FalcoSense *also* has infrastructure for — see §10) or rely on cron. Reaching for `shell_exec` when the module already has a message-queue publisher sitting right next to it is the kind of inconsistency that's worth noticing as a pattern, not just a one-off bug.

---

## 5. The layout system — this is the one that broke Everest.com

This is the concept your CEO was pointing at. Take your time here.

### 5.1 The model

A rendered Magento page is a **tree of Blocks**, described declaratively by **layout XML**, and merged from *every module that has something to say about that page* **plus the active theme**. Two node types matter:

- **Container** — a named slot with no rendering logic of its own (e.g. `content`, `header-wrapper`, `sidebar.main`). Purely structural, like a named `<Slot>`.
- **Block** — an actual PHP class + template pair that renders into a container (or into another block, as a child).

A given page load resolves a **stack of "handles"** — think of each handle as one layout-XML-file-namespace that gets merged in. Every page always gets `default`. Then, based on the current controller action, a specific handle is added — e.g. `catalog_category_view` for a category page, `catalogsearch_result_index` for search results. **Every module's `view/frontend/layout/<handle>.xml`, plus the active theme's own copy of the same filename, all get merged together**, in module-sequence order, theme last. That "theme last" part is exactly what makes theming work: a theme's layout file processes after modules', so it can add, move, or remove what modules declared.

### 5.2 What you can do in a layout XML file

- `<referenceContainer name="content">...</referenceContainer>` / `<referenceBlock name="...">` — "reach into this already-declared slot/block and do something to it."
- Add a new block: `<block class="..." name="..." template="..."/>`, optionally with `before="-"` / `after="minicart"` to position it, or nested `<block>` children.
- `remove="true"` — delete a block/container that an earlier-merged file declared.
- `<move element="..." destination="..."/>` — relocate a block from one container to another.
- `ifconfig="path/to/config/flag"` — only include this block if the config flag is truthy. (Nice, and FalcoSense uses this correctly on its own blocks.)

### 5.3 FalcoSense's actual layout, read the way Magento reads it

```xml
<!-- default.xml — applies to EVERY page -->
<referenceContainer name="header-wrapper">
    <block class="Ahy\SmartSearchLuma\Block\Search" name="ahy_search_bar"
           template="Ahy_SmartSearchLuma::search/mini.phtml"
           ifconfig="smart_search/general/frontend_enabled" after="minicart"/>
</referenceContainer>
```
This is the *good* pattern: it adds a new block into an existing container, positioned relative to a native block, gated behind config. It doesn't touch anything Luma already owns. If a client's theme has a completely different header, this still degrades gracefully — worst case, it renders in a slightly odd position, but it doesn't *break* anything.

Now the one that matters:
```xml
<!-- ahy_smartsearch_active.xml -->
<referenceBlock name="top.search" remove="true"/>
<referenceBlock name="category.products" remove="true"/>
<referenceContainer name="div.sidebar.main" remove="true"/>
<referenceBlock name="search_result_list" remove="true"/>
<referenceBlock name="search.result" remove="true"/>
<referenceContainer name="sidebar.main" remove="true"/>
```
`top.search`, `category.products`, `div.sidebar.main`, `search_result_list`, `search.result`, `sidebar.main` are **block/container names that belong to Luma** (and Luma's parent, Blank). They are not part of Magento core in any theme-independent sense — they are *that specific theme's* internal naming for its own layout structure. A different theme is free to name its equivalent container anything it wants, split it into multiple containers, or not have a directly-equivalent block at all. This is precisely why the module is named `SmartSearchLuma`: it was written *assuming* Luma's internal block names as a stable contract. They are not a stable contract — they're an implementation detail of one theme.

Concretely, here's what happens on a non-Luma (or heavily customized Luma) theme:
- If the theme renamed/restructured these blocks, `remove="true"` silently **no-ops** (Magento doesn't error on removing a block that doesn't exist by that name) — so the native search bar / native product grid **stays on the page, underneath or alongside FalcoSense's own UI**. That's very plausibly the "layout broke" symptom you saw on Everest — two competing product grids, native filters next to custom filters, duplicated search boxes.
- The custom handle itself (`ahy_smartsearch_active`) is only even *added* by an Observer on `layout_load_before` (`Observer/AddFrontendLayoutHandle.php`) — which is a legitimate technique (adding a handle conditionally from PHP, since layout XML alone can't express "add this handle only if this config flag is on"). That part's fine. It's what the handle's own layout file assumes about block names that's fragile.

**The direct evidence this already happened once:** `view/frontend/templates/product/list.phtml` and `product/list/item.phtml` exist in this module, fully written, clearly intended to *override* Magento's native product-grid rendering template-for-template — the theme-fallback-friendly way to customize a grid (see §5.4). But **neither file is referenced by any layout XML in this module** — I checked. They're dead. The team apparently started down the "override the native template" path, hit its limits (probably because the actual UI needed to be driven by an external API + client-side JS state machine that Magento's server-rendered toolbar/pager doesn't easily support), and pivoted to "just remove Luma's blocks entirely and render our own container with `results.phtml`." That pivot is understandable — but the removal step kept Luma-specific names as its removal targets, which is where the coupling actually lives. Same story with `Block/NoResultsModal.php` and `no-results-modal.phtml` (the latter is literally an empty file with a comment redirecting you to inline logic in `results.phtml`) — another abandoned "do it the Magento component way" attempt.

### 5.4 What "doing it the Magento way" looks like, for comparison

You don't need to design the fix now, but you should be able to recognize the shape of the correct pattern when you see it, because it already exists in the *same file* as the counter-example:
```xml
<block class="Magento\Framework\View\Element\Template"
       name="ahy_card_template"
       template="Ahy_SmartSearchLuma::category/product_card.phtml"/>
```
This declares a block with a **module-namespaced template path** (`Ahy_SmartSearchLuma::category/product_card.phtml`). Magento resolves that path by checking, **in order**: the active theme's override folder (`app/design/frontend/<Vendor>/<Theme>/Ahy_SmartSearchLuma/templates/category/product_card.phtml`), then that theme's parent theme's equivalent path (theme inheritance — Luma inherits from Blank, and a client's custom theme can inherit from either), and only if nobody overrode it, the module's own copy under `view/frontend/templates/`. **This is the entire theme-override contract in Magento**, and it's why a client *can* restyle a component you ship without you doing anything, as long as you namespace your template path and they know to drop a same-named file in their theme folder. The moment you instead reach into another theme's block by its Luma-internal name and delete it, you've stepped outside that contract entirely — there's no equivalent "override" story for a client, they can only re-fight your removal logic.

**Static assets (CSS/JS/images) resolve the same way.** `view/frontend/web/css/grid-3col.css` is reachable at a namespaced static URL and could, in principle, be overridden per-theme the same way templates are. But its actual content works against that principle — see next section.

---

## 6. Themes, and why fighting a theme's CSS with `!important` is structurally fragile

A **Magento theme** is a package under `app/design/frontend/<Vendor>/<Theme>/` — it can be a from-scratch design system, or (far more common) a thin set of overrides on a parent (`theme.xml` declares `<parent>Magento/blank</parent>`, and Luma itself is exactly that: Blank + overrides). Fallback for **every resolvable asset type** — templates, `.less`/CSS, JS, email templates, translations — walks the same chain: **current theme → its parent chain → the owning module's own defaults**.

This matters for FalcoSense because `grid-3col.css` doesn't add new, namespaced styling — it **overrides Luma's own grid rules by re-targeting Luma's own selectors**, stacking `!important` to win the specificity fight:
```css
.page-products .products-grid .product-item,
.page-layout-1column .products-grid .product-item {
    margin-left: 2% !important;
    width: calc((100% - 4%) / 3) !important;
}
```
Two independent problems, worth separating:

1. **It's a CSS-specificity war against a theme it doesn't own**, not a themed override *of* something FalcoSense itself owns. If Luma's CSS changes on a Magento core upgrade (selector renamed, specificity changed, a wrapping class added), this rule can silently stop applying — with no error, just a layout that quietly regresses. If the client is on a different theme entirely, `.products-grid`/`.page-layout-1column` may not exist as classes at all, and the override does nothing.
2. It duplicates work `grid-3col.css` is fighting for anyway — most of the actual product grid in `results.phtml` is FalcoSense's *own* markup (`#ahy-product-grid .product-items`, already grid-templated to 3 columns in the inline `<style>` block). So there are, in the same feature, two independent CSS systems trying to enforce "3 columns" — one namespaced and self-owned (good), one fighting a theme's own grid via specificity overrides (fragile) — and it's not obvious from reading the code which one is actually in effect on a given page, which is its own maintainability problem regardless of theme-portability.

**The broader lesson, independent of any specific fix:** the safe, portable way to style your own UI is to own a CSS namespace entirely (FalcoSense mostly does this well — `#ahy-category-wrap`, `.ahy-*` classes throughout `results.phtml` are genuinely namespaced and would survive a theme change fine) and never assume you know, or need to fight, the host theme's internal class names or specificity. The unsafe way is reaching into the host theme's selectors — whether to delete a block by name (§5.3) or override its CSS by name (this section) — because both assume implementation details of one specific theme that a client is explicitly allowed to not be running.

---

## 7. Blocks, templates, and where business logic should actually live

A **Block** (PHP, extends `\Magento\Framework\View\Element\Template` here) is the data/logic layer for one piece of UI. A **template** (`.phtml`) is the view — it receives `$block` (the Block instance) and typically `$escaper` (Magento's output-escaping helper — `escapeHtml`, `escapeUrl`, `escapeJs`, `escapeHtmlAttr`; **always use these**, they're Magento's XSS defense layer, and this codebase does use them consistently, which is good). The relationship is close to a container/presentational component pair, or a Next.js Server Component handing props to a leaf render.

Most of FalcoSense's Block classes are appropriately thin — `Category.php`, `Search.php` are basically "read config, build a URL, return a token," which is exactly the right amount of logic for a Block. That's the right instinct.

**Where it breaks down is `results.phtml`.** It's 1,366 lines: inline `<style>` (roughly 300 lines of CSS), inline `<script>` (roughly 700 lines of vanilla JS implementing an entire client-side single-page app — filtering, sorting, pagination, cart, wishlist/compare posting, variant resolution, analytics tracking), and PHP just to bootstrap the initial config into that JS. This is the textbook "God template" — template, styling, and application logic, all fused into one file with no unit-testable seam anywhere in it. Two concrete costs, not abstract ones:

- **You cannot test the JS logic** (variant resolution, filter-state merging, price-bucket math) without a browser and a fully rendered Magento page, because it's not a module — it's a `<script>` tag with closures.
- **A client cannot override just the styling, or just one behavior**, without copying the entire 1,366-line file into their theme and hand-merging future updates forever. Compare that to `product_card.phtml`/`product_options.phtml` being separate child blocks in the same layout node (`ahy_card_template`, `ahy_options_template`) — that split exists and is the right idea, but the actual interactive logic isn't split the same way.

**The Magento-idiomatic escape hatch for "I need business/computed data in a template without polluting the Block class or duplicating logic across native blocks" is a `ViewModel`.** You haven't seen one in this codebase — this module doesn't use the pattern at all — but it's worth knowing it exists, because it's usually the tool for exactly the kind of "inject FalcoSense data into a page without subclassing or forking a native block" problem this module keeps solving by more invasive means (full block replacement, or raw `ObjectManager::getInstance()` calls from inside a template, per §3).

---

## 8. EAV, configurable products, and what the add-to-cart flow is actually doing

### 8.1 Why products aren't a normal row

Magento's catalog is **EAV (Entity–Attribute–Value)**: instead of one wide `products` table, each attribute (color, brand, material, whatever a merchant defines) is stored in a per-datatype table (varchar/int/decimal/text/datetime), keyed by entity + attribute + store. This is *why* code like this exists in `ProductSyncService::normalize()`:
```php
foreach ($product->getAttributes() as $attribute) {
    ...
    if (in_array($input, ['select', 'multiselect', 'swatch_visual', 'swatch_text'], true)) {
        $text = $product->getAttributeText($code);
```
`getAttributeText()` exists because a `select`/`swatch` attribute's *stored* value is an internal option ID, not the human label — EAV again. This part of the sync code is written correctly and is a decent reference for "how do you actually read a product's attributes in Magento."

### 8.2 Configurable products (the "T-shirt in 4 colors × 3 sizes" pattern)

A **configurable product** is a parent SKU with no stock of its own, linked to real, sellable **simple products** (the actual color/size combinations) via **super attributes** (the specific attribute(s) — e.g. `color`, `size` — chosen to define the variation axis). Magento's API for this is exactly what `ProductSyncService` already calls: `$product->getTypeInstance()->getUsedProducts($product)` returns the real child simple products, each with its own real SKU, price, stock, and — critically — **its real attribute *values*, retrievable the same EAV way as any other product.**

**This is what makes the front-end JS's approach a workaround rather than a solution.** In `results.phtml` / `smart-slider.phtml`, variant attributes (Color, Size) are recovered client-side by **parsing the child SKU string**:
```js
function parseVariantAttrs(variantSku, baseSku) {
    var parts = variantSku.substring(baseSku.length + 1).split('-');
    var sizeIdx = -1;
    parts.forEach(function (p, i) { if (SIZES.indexOf(p.toUpperCase()) !== -1) sizeIdx = i; });
    ...
}
```
This assumes every variant SKU is literally `{baseSku}-{maybe-size}-{color}`, that "size" is always a token from a hardcoded `SIZES` list, and that whatever's left over is "Color." It works for however Everest's SKUs happened to be formatted, and will silently mis-parse (wrong attribute, swapped color/size, or a crash on `undefined`) for **any merchant whose SKU convention doesn't match** — which is most merchants, since SKU format is a per-merchant convention, not a Magento standard. The real attribute code/label/value triples are already available from `getUsedProducts()` server-side (and are partially captured — `ProductSyncService` does send a flat `variants` array to the platform) but the platform's API response apparently doesn't carry structured attribute data back down to the browser, so the front-end re-derives it by guessing from the SKU string instead. This is the kind of thing that looks like it works in a demo (one merchant, one SKU convention) and becomes a support fire the moment SKU conventions vary — which, for a service company onboarding different Magento clients, they always will.

### 8.3 Add to Cart — the native flow vs. what FalcoSense does

Magento's canonical add-to-cart is a POST to `checkout/cart/add` with, at minimum: `product` (ID), `qty`, `form_key` (Magento's CSRF token, tied to the session), and — **for a configurable product specifically** — `super_attribute[<attributeId>]=<optionId>` for each selected axis, so Magento can resolve which simple product to actually sell *and* keep the cart-item's "selected options" metadata attached to the **parent** product for display (product name/image/URL stay the parent's; the child is referenced internally). You can see the fully correct, native version of this in the module's own **unused-in-layout** `product/list/item.phtml`:
```php
<?php $options = $block->getData('viewModel')->getOptionsData($_product); ?>
<?php foreach ($options as $optionItem): ?>
    <input type="hidden" name="<?= $escaper->escapeHtml($optionItem['name']) ?>"
           value="<?= $escaper->escapeHtml($optionItem['value']) ?>">
<?php endforeach; ?>
```

What the *active* code path does instead (`results.phtml`'s `resolveVariant()` + `addToCart()`): once all swatches are picked, it looks up the matching **child's own product ID** (via the SKU-guessing above) and POSTs *that child ID directly* as `product`, with no `super_attribute` at all:
```js
fd.append('product', productId); // this is the resolved simple/child product ID
fd.append('qty', 1);
fd.append('form_key', getFormKey());
fetch(_state.addToCartUrl, { method: 'POST', body: fd })
```
This can genuinely work — Magento will add that simple product to the cart. But it's now in the cart **as a bare simple product**, not as "the configurable parent, configured to this variant." Practical consequences worth knowing about (not necessarily all currently visible as bugs, but the kind of thing that surfaces later): cart/checkout line items may not show the "Color: Black, Size: M"-style options block a merchant's order emails and admin order view normally display for configurable purchases; anything that keys off the parent product for merchandising/reporting on the storefront can miss these line items; and if a product ever has genuine **required custom options** (not just configurable swatches — Magento's separate "custom options" system, e.g. "add engraving text"), this direct-child-ID path skips that validation entirely, which native `checkout/cart/add` would have enforced.

You don't need to fix this today. You need to be able to say, in the system-design conversation: *"we're bypassing Magento's own configurable-cart contract, here's what that costs us, here's what going through it properly would look like."*

---

## 9. Extension mechanisms: Events/Observers vs. Plugins — and where FalcoSense mixes concerns

**Events (`events.xml`) → Observers** are Magento's pub/sub. A module (core or third-party) calls `$this->eventManager->dispatch('some_event', [...])` at some point in its own logic; anyone can bind an Observer to that name without the dispatcher knowing or caring. FalcoSense listens to a sensible, minimal set:

| Event | Observer | Why it fires |
|---|---|---|
| `catalog_product_save_after` | `ProductSaveObserver` | Admin (or API) saved a product |
| `cataloginventory_stock_item_save_after` | `StockChangeObserver` | Stock qty/in-stock flag changed |
| `sales_order_place_after` | `PurchaseObserver` | Order placed — pushed as a customer/analytics event, not a catalog sync |
| `customer_register_success` | `CustomerRegisterObserver` | New account |
| `controller_action_postdispatch_catalogsearch_result_index` | `SearchQueryObserver` | Server-side fallback search-analytics ping |
| `layout_load_before` | `AddFrontendLayoutHandle` | Injects the `ahy_smartsearch_active` handle (§5.3) |

**Plugins (interceptors)** wrap an existing public method. `CategoryListPlugin::aroundGetLoadedProductCollection` is the one real example here, and it's a legitimate, idiomatic use: rather than forking or replacing `Magento\Catalog\Block\Product\ListProduct`, it intercepts its collection-loading method and substitutes an OpenSearch-ranked collection when conditions are met, falling back to `$proceed()` (the real Magento behavior) otherwise. That fallback discipline — always have a path back to native behavior — is worth calling out as *correct*, because it's the difference between a plugin that degrades gracefully and one that becomes a single point of failure.

**The mixed concern worth flagging:** several Observers and Services reach directly for `curl_init()` to talk to the external platform — `ProductSyncService::post()`, `CustomerEventService::send()`, `SearchQueryObserver::send()`, `SearchTokenService::fetchFromPlatform()`, `Block\Slider\Products::getSliderProducts()` — **five independent, hand-rolled cURL call sites**, each re-implementing timeout handling, header building, and error interpretation slightly differently (some set `X-Signature` HMAC headers, some don't; timeouts range from 2s to 120s; some catch `\Throwable`, some just check `curl_errno`). None of them go through Magento's own HTTP client abstraction (`Magento\Framework\HTTP\Client\Curl`, or a `GuzzleHttp\ClientInterface` binding), which is what DI-friendly, testable, mockable outbound HTTP looks like in this framework. This isn't a Magento-specific concept so much as a general "you have one integration, don't write the client five times" observation — but it's very visible once you're looking at the codebase through a "what's the seam for testing/replacing this" lens, which is exactly the lens the system-design conversation will need.

---

## 10. Background processing: three different strategies doing overlapping work

Magento gives you three native async primitives, and FalcoSense's sync logic ended up spread across **all three, plus a fourth non-native one**, without a clear division of responsibility between them:

| Mechanism | Native? | Used for, in FalcoSense | File |
|---|---|---|---|
| **Cron** (`crontab.xml`) | Yes | Delta sync, every minute, cursor-based (`updated_at` watermark) | `Cron/ProductSync.php` |
| **Message Queue** (`queue_consumer.xml` / `_publisher.xml` / `_topology.xml`, DB-backed) | Yes | Two topics: per-product webhook sync, and full-sync batches | `Model/WebhookConsumer.php`, `Model/FullSyncConsumer.php`, `Model/WebhookPublisher.php`, `Model/FullSyncPublisher.php` |
| **Console Command** (`bin/magento`) | Yes | Manually/admin-triggered full sync | `Console/Command/FullSyncCommand.php` |
| **`shell_exec` detached process** | **No** — not a Magento primitive at all | Admin clicks "Sync All Products Now" → controller shells out to run the Console Command in the background (§4) | `Controller/Adminhtml/Sync/Fullsync.php` |

Notice: `WebhookPublisher`/`WebhookConsumer` (real-time, per-product, MQ-based) exist and are wired up in `etc/queue_*.xml` — but grep the Observers for who actually calls `WebhookPublisher::publish()`, and nobody does. `ProductSaveObserver` and `StockChangeObserver` both call `ProductSyncService::sync()` **directly, synchronously, inline in the observer** (which itself fires inline in the request that saved the product/stock item) — meaning an admin saving a product in the backend, or an order decrementing stock, currently **blocks on an outbound HTTP call to the external platform** (with a rate limiter to protect against burst, at least — `ProductSaveObserver`'s 120/min cache-based limiter is a reasonable guard) before the request can finish. The message-queue plumbing that would make this properly async — publish an event, return immediately, let a consumer do the HTTP call off the request thread — is fully built and wired, and simply isn't being used for this path. That's not "MQ is the wrong tool here" — the tool is already installed, it's just not plugged in where it would help most.

Meanwhile the **lock file** coordinating "don't run two full-syncs at once" (`SyncLockManager`, using a plain file in `var/`) has to arbitrate between three different triggers that can all start a full sync (admin button → shell_exec → command; cron; and the command run directly by hand) — which is a reasonable amount of defensive code (stale-lock detection, PID liveness check via `posix_kill`, an "admin pre-lock" handoff protocol) for a coordination problem that exists *because* there isn't one clear owner of "how does a full sync get triggered." Worth noticing as a pattern: the amount of defensive coordination code is often a signal that points back at a design question, not just an implementation bug.

**One more file-storage inconsistency worth knowing, because it'll matter for horizontal scaling:** `SyncLockManager` correctly uses Magento's `Filesystem`/`DirectoryList::VAR_DIR` abstraction (so it always resolves to the right `var/` path regardless of install layout). `SearchTokenService`, right next to it conceptually, instead hardcodes `/tmp/smartsearchluma_token_{storeId}.json` directly. On a single-box deployment this is invisible. On any deployment with more than one web/app node behind a load balancer (extremely common for a client the size of Everest), `/tmp` is **local to each node** — so token caching effectively doesn't work as a shared cache, each node re-fetches its own copy, and worse, there's no cross-node invalidation story at all. This is a good concrete example of why "works on my machine / works on the demo box" and "works in production at a real client's infra" are different bars, and why reaching for Magento's own cache/filesystem abstractions (`Magento\Framework\App\CacheInterface`, or the `Filesystem` service used correctly two files over) rather than raw PHP filesystem calls isn't pedantry — it's what makes a feature actually portable across hosting setups you don't control.

---

## 11. System configuration & scope — and a subtle correctness bug worth knowing about

`etc/adminhtml/system.xml` defines the **Stores → Configuration** UI (tabs → sections → groups → fields); `etc/acl.xml` gates who can see it; `etc/config.xml` seeds default values. FalcoSense's `system.xml` is genuinely well-built — sensible `<comment>` text, `<depends>` for conditional field visibility, source models for yes/no dropdowns, validation classes on numeric fields. This part is a good reference, not a cautionary one.

**Scope** is the concept to make sure you actually have solid, because it's easy to think you understand it and be subtly wrong: Magento config exists at three levels — **Default** → **Website** → **Store View**, each able to override the level above it, resolved most-specific-wins at read time. `system.xml`'s `showInWebsite="1" showInStore="1"` on FalcoSense's fields means a multi-store Magento install (which Everest, or any client with multiple brands/locales on one Magento instance, is very likely to be) can legitimately have **different API keys / endpoint URLs / feature flags per store view.** `Helper\Data` reads config correctly with `ScopeInterface::SCOPE_STORE` throughout — that part's right.

**Where it goes wrong is mapping a Magento store to "the platform's" store ID**, in `getPlatformStoreId()`:
```php
public function getPlatformStoreId(int|string|null $storeId = null): int
{
    $stores = $this->storeManager->getStores(false);
    $storeIds = array_keys($stores);
    sort($storeIds);
    ...
    $position = array_search((int) $storeId, $storeIds);
    return $position !== false ? (int) $position + 1 : 1;
}
```
This derives the platform's store ID from **the current sorted *position* of this store among all of Magento's stores** — not from any stored, stable mapping. That means: add a new store view to Magento (even unrelated to FalcoSense, even one that will never sync), or remove one, and **every existing store's computed "platform store id" can shift**, silently repointing already-synced data, tokens, and search traffic at the wrong tenant in the external platform. This is exactly the kind of bug that stays invisible for months (nobody's reconfiguring stores every day) and then causes a very confusing incident the one time someone does. The fix-shape (not fixing it now, just naming the concept) is: a real stored mapping — either its own config field per store view, or a lookup table — never a computed position.

---

## 12. Where Magento's job ends and the platform's job begins

Your architecture PDF describes a full SaaS backend: multi-tenant client/store/API-key model, a query-composition engine, boost rules/synonyms, OpenSearch indices per client, an AI layer for typo correction and query understanding, analytics/cohort computation, workers. **None of that lives in this repository.** This repo's entire job, matched against that diagram, is exactly two roles:

1. **Push data out** — normalize Magento's product/customer/order data into the platform's expected JSON shape, and get it there via sync (cron delta, MQ webhook, full-sync command) — the "Magento Admin → Observer/Plugin → Product Update Controller" and "Cron job" boxes on the left of your diagram.
2. **Render what comes back** — call the platform's search/suggest/category endpoints from the browser and PHP, and turn the JSON response into storefront HTML/interactions — the "Storefront/frontend" and "Product listing templates code" boxes.

Everything about ranking quality, query understanding, typo correction, boosts, and synonyms — the things that make "the search algorithm failed" a real complaint — is **not a Magento problem and not something this guide covers**, because it isn't in this codebase; it's the other system your PDF describes. It matters that you can draw this line cleanly, though, for two reasons: first, so that when something breaks at a client, you can tell in minutes whether it's a Magento-integration bug (wrong data shape sent, wrong theme coupling, wrong DOM state) or a platform-ranking bug (right data, wrong results) — those are different teams' problems and different fixes. Second, because it directly shapes the architecture conversation ahead of you: this module should be a thin, well-behaved **client** of that platform — normalize data faithfully out, render responses faithfully in, and otherwise get out of the way — not a place where platform-side concerns (ranking heuristics, variant-attribute parsing, trending-product logic) leak into the Magento side because it was easier to hack around in JS than to fix in the platform's API contract. Several of the fragility points in this document (§8.2's SKU-string variant parsing is the clearest example) are exactly that kind of leak.

---

## 13. The design philosophy this points toward (a north star, not a plan)

You told me the actual goal isn't "support every Magento theme automatically" — it's: FalcoSense ships one solid, self-contained default experience, and a client is free to restyle or reposition it into their own theme, on their own time, using supported Magento mechanisms. Everything in this document adds up to what that actually requires, structurally:

- **A stable, minimal seam into the native page** — new containers/blocks added via `ifconfig`-gated, position-relative layout XML (§5.3's `default.xml` search-bar example is already this pattern) — never block-name surgery on another theme's internals.
- **A fully owned, non-collided visual namespace** — which FalcoSense's `#ahy-*`/`.ahy-*` convention already mostly achieves — with zero dependency on knowing or fighting a host theme's own class names or specificity.
- **A real component boundary between "FalcoSense's rendering logic" and "the page it's embedded in"** — not five duplicated cURL call sites, not 1,366-line templates mixing markup/CSS/app-logic, not client-side guesswork standing in for data the platform could just send correctly the first time.
- **Native Magento contracts honored at the edges** — add-to-cart through the real configurable-product contract, config through real scope resolution, background work through one deliberately-chosen async mechanism per job, not three redundant ones.

None of that requires Hyvä support, or LESS/Tailwind parity, or theme-detection logic. It requires FalcoSense to stop assuming it *is* Luma, and start behaving like a well-mannered guest in *any* theme — which, not coincidentally, is also what makes it trivial for a client to override on purpose.

---

## 14. Quick diagnostic table — symptom → mechanism → concept

A cheat-sheet for the "what actually broke on Everest" conversation, cross-referenced to sections above.

| Symptom you likely saw | Magento mechanism responsible | Section |
|---|---|---|
| Native search box and/or native product grid still visible, duplicated alongside FalcoSense's UI | Layout `remove="true"` targeting Luma-only block names that don't exist (or exist under different names) in the client's actual theme | §5.3 |
| Grid column count / spacing looks right on one theme, wrong or fought-over on another | CSS overriding the host theme's own selectors via `!important`, instead of owning a namespace | §6 |
| Product options (color/size) show wrong values, or fail to resolve, on some catalogs | Client-side SKU-string parsing standing in for real configurable-attribute data | §8.2 |
| Cart shows a bare product with no visible "Color/Size" selection after using swatches | Add-to-cart posts the resolved child product ID directly, skipping `super_attribute`/the parent-configurable cart contract | §8.3 |
| Admin "save product" feels slow, or times out under load | Realtime sync calls the external platform synchronously inline in the save request, instead of via the already-built MQ path | §10 |
| "Sync All Products" button does nothing / errors on certain hosting | `shell_exec`-spawned background process, not a Magento-native async mechanism | §4, §10 |
| Data quietly starts routing to the wrong tenant after adding/removing a store view | Platform store ID computed from store-list *position* instead of a stored mapping | §11 |
| A template a client tried to override in their own theme "did nothing" | The relevant UI isn't actually driven by that template — it's hardcoded inline in a different, undocumented file (see the dead `product/list.phtml` / `no-results-modal.phtml` example) | §5.3, §7 |

---

## 15. Glossary — fast lookup

- **Area** — Magento's top-level execution context: `frontend` (storefront), `adminhtml` (admin panel), `webapi_rest`/`webapi_soap`, `crontab`, `graphql`. Config (`di.xml`, routes, layout) can be area-specific.
- **ACL** — Access Control List; Magento's admin permission tree, declared in `acl.xml`, enforced via `ADMIN_RESOURCE` on controllers.
- **Block** — a PHP class rendering one piece of page UI; pairs with a `.phtml` template.
- **Container** — a named, non-rendering layout slot that blocks render into.
- **EAV** — Entity-Attribute-Value; Magento's storage model for Products, Categories, Customers — attributes live in per-type tables, not flat columns.
- **Front name** — the URL segment (`smartsearchluma`, `smsl`) that routes to a module.
- **Handle** — a named layout-XML "bucket" (`default`, `catalog_category_view`, ...) that gets merged from every module + the active theme for a given page.
- **Interceptor / Plugin** — Magento's method-level "wrap without forking" extension mechanism (`before`/`around`/`after`).
- **Object Manager** — Magento's DI container. Should almost never be called directly (`ObjectManager::getInstance()`) outside bootstrap code — constructor injection instead.
- **PLP / PDP** — Product Listing Page / Product Detail Page.
- **Preference** — a `di.xml` binding of an interface (or class) to a concrete implementation, app-wide.
- **Scope (config)** — Default → Website → Store View; most-specific override wins.
- **Simple / Configurable product** — a sellable single SKU vs. a parent SKU representing a set of simple-product variants (via super attributes).
- **Super attribute** — the attribute(s) chosen to define a configurable product's variation axis (e.g. Color, Size).
- **Theme** — a design package under `app/design/frontend/<Vendor>/<Theme>`, optionally inheriting from a parent theme; resolves templates/CSS/JS via fallback.
- **ViewModel** — the idiomatic pattern for injecting extra data/logic into a template without subclassing or forking the owning Block.
- **Virtual type** — a `di.xml`-only "class" (reconfigured constructor args on an existing class), no new PHP file needed.

---

## 16. What comes next

This document deliberately stops at "understand the terrain." The next conversation — actual system design for FalcoSense's Magento-side architecture — should start from §13's north star and §14's diagnostic table, and go somewhere this document intentionally didn't: what the new extension-point contract looks like, what stays in this module vs. moves to the platform, how sync/async responsibilities get cleanly split across cron/MQ, and what a client's "override the base theme" workflow should actually look like end to end. Come back to that once this has settled — you'll be able to make those calls yourself instead of taking them on faith.
