# FalcoSense Architecture Review — Presenter's Guide

*One section per slide in `FALCOSENSE-PITCH-DECK.md`, in the same order, same numbering. If you cut a slide from the deck, just skip its section here — nothing in this guide depends on a slide you might remove. Each section has three parts: what to actually say, a delivery note on pacing/tone, and the questions that slide is most likely to draw, with a ready answer for each.*

---

## Slide 1 — Title

**What to say:** Open cold, no throat-clearing. State the headline as your own claim, not a quote from the slide: *"This is the new architecture for FalcoSense — and the design goal is right there in the title: a search module that structurally cannot break the site it's installed on."*

**Delivery note:** Say it slowly. This is the one sentence you want remembered if nothing else lands. Let it sit for a second before moving on.

**Likely questions:** None yet — this slide's job is to set the frame, not invite scrutiny. If someone jumps straight to "prove it," that's fine — say *"that's exactly where the next few slides go"* and move to Slide 2.

---

## Slide 2 — The Ambition

**What to say:** *"Here's the actual bar we're designing to. Not 'better than before' — a specific number. DecorPrice, Pearl theme, Elasticsearch already installed — that integration took 10 to 12 hours, with errors the whole way through. The target for the new architecture is 10 minutes: install the module, enter an API key, done. No layout edits, no theme work."*

**Delivery note:** Let the stat callout do the work — don't over-explain it, just state it and let the number sit on screen for a moment.

**Likely questions:**
- *"10 minutes for every client, really? What about hard cases?"* → Answer: 10 minutes is the default path for the ~95% case. There are three documented escape hatches (non-standard search box, custom cart, SSR-Shell toggle) for the rest — each isolated, each one config line, never gating the default path. That's covered later in the deck.
- *"How do we know 10-12 hours was even a fair test?"* → Answer: it's not a hypothetical — it's a real integration attempt on a real client site, with a different theme and a different search backend than Everest. It's the second data point, not the first.

---

## Slide 3 — What Went Wrong (and What We Keep)

**What to say:** *"Three things broke Everest, specifically: we removed native theme blocks by name, we rewrote a copy of Magento's own core search template in place, and we fought the theme's CSS with `!important`. I want to be clear this isn't 'everything before was bad' — there's real, well-built code in there already. A cache-aware token endpoint that correctly handles page caching. Real data-integrity logic for disabled products and duplicate SKUs. We're keeping and promoting that, not starting from zero."*

**Delivery note:** Keep this factual, not defensive. No blame language, no dwelling — state what broke, state what's being kept, move on. This slide's job is credibility, not apology.

**Likely questions:**
- *"Whose fault was this?"* → Don't answer this the way it's asked. Redirect: *"The root cause was architectural, not a specific decision by a specific person — the module was built assuming one theme's internals, and Everest runs a completely different rendering stack. That's what the rest of this deck fixes."*
- *"Why should we trust the same approach won't happen again?"* → Answer: because this isn't "be more careful this time" — it's a structural rule (next slide) that makes the old failure mode impossible by construction, not just less likely.

---

## Slide 4 — The One Rule

**What to say:** *"Everything from here follows from one rule: FalcoSense only ever adds. It never removes, replaces, or overrides anything that belongs to someone else — not a theme's block, not another extension's template, not a core Magento file. Old approach: five different ways of reaching into a site, all of them dangerous, all of them theme-or-module-name-dependent. New approach: two mechanisms, both safe by construction."*

**Delivery note:** This is the thesis slide — the punch-mode background should carry some visual weight here. Pause after stating the rule before walking through the diagram.

**Likely questions:**
- *"Isn't 'never remove anything' going to cause duplicate UI — the old search box AND ours both showing?"* → Answer: no — covered in Slide 6 and 7. We attach to the native search box instead of replacing it (so there's only ever one search box, just with FalcoSense listening to it), and for pages like category grids, we only visually take over after confirming we have real data — the native grid isn't removed, it's just covered once we're sure we have something better to show.
- *"What if a client's theme genuinely doesn't have a container to add to?"* → Answer: the container we use (`before.body.end`) is owned by Magento's core framework, not any theme — removing it would also break other common things themes rely on it for, like cookie banners and chat widgets, so in practice it's present on virtually every Magento install regardless of theme.

---

## Slide 5 — Shadow DOM

**What to say:** *"The isolation isn't a discipline we practice — it's a browser-enforced boundary. `attachShadow()` creates a genuinely separate DOM subtree. A sloppy, broad CSS rule in a four-year-old theme — even something like `button { background: red }` — literally cannot see inside that boundary. And the reverse is true too: nothing we write can leak out and repaint anything on the client's page. One deliberate exception: we let a brand color and font cross the boundary on purpose, the same way Stripe and other embeddable widgets let you theme them without breaking the isolation."*

**Delivery note:** If you have the live Mermaid/diagram rendered, point at the one arrow crossing the boundary specifically — that's the moment to slow down, since it preempts the obvious follow-up question before it's asked.

**Likely questions:**
- *"Doesn't Shadow DOM mean we lose control over how it looks per client?"* → Answer: no — we control which specific things are themeable (brand color, font) and everything else — layout, spacing, interaction — is protected regardless of what a client tries to change. It's a curated set of knobs, not an open door.
- *"Is Shadow DOM actually supported everywhere, or are we assuming modern browsers?"* → Answer: it's been supported in every major browser since roughly 2020 — this isn't cutting-edge technology, it's the same mechanism Stripe Elements and Salesforce's own component library already run in production at scale.
- *"What about CSS inheritance — doesn't font/color leak through anyway?"* → Answer: yes, a small, well-known, spec'd set of inherited properties (font, color, line-height) does cross by default — and the widget explicitly resets those at its own root, the same defensive move any professional embeddable widget makes. It's a known behavior, not an oversight.

---

## Slide 6 — Attach, Don't Replace

**What to say:** *"This is the direct fix for the actual search-form.phtml bug at Everest — the team rewrote Magento's own core search template in place to make typing in the box trigger FalcoSense. The right mechanism was already available without touching that file at all: Magento's native search input has one stable, framework-level contract — `search_mini_form`, `input name=q` — that's not a theme convention, it's baked into Magento_Search itself. We just add a listener to it. We never move it, wrap it, or claim ownership of it."*

**Delivery note:** This is a good moment to name the specific bug from the Everest audit directly — it's concrete, it's real, and naming it shows you're not being vague about what actually happened.

**Likely questions:**
- *"What if a theme renamed that input?"* → Answer: extremely rare in practice — we confirmed directly that even Everest's own heavily-customized theme kept this exact convention after years of custom work, because too much of the ecosystem (analytics, autofill, tag managers) already depends on it. For the rare exception, it's one documented config override, not a redesign.

---

## Slide 7 — Fail-Open, Not Fail-Broken

**What to say:** *"Today, if the replacement UI doesn't work for any reason, the native UI it deleted is already gone — that's the failure mode that broke Everest. Under this design, native rendering is never deleted. FalcoSense only visually takes over after it's already confirmed it has real data to show. If the fetch fails, times out, or the JS has a bug — nothing happens. Not 'something breaks' — nothing happens. The site behaves exactly as if FalcoSense weren't installed for that one moment."*

**Delivery note:** The phrase to land clearly here: *"fails toward inertness, never toward damage."* That's the sentence worth repeating if someone asks for the summary later.

**Likely questions:**
- *"How would we even know if it's silently failing, then?"* → Answer: that's a monitoring/alerting question, separate from the architecture question — the point here is that *the shopper* never sees a broken site either way; our own team would still want visibility into failure rates, which is a standard operational dashboard concern, not a structural gap.

---

## Slide 8 — The Cart Never Breaks

**What to say:** *"This is the one that has to be bulletproof, because it's revenue. Here's the actual flow: the widget only ever knows an opaque platform product ID — never a Magento entity ID. That gets resolved server-side, using Magento's real configurable-product API, not a SKU-string guessing heuristic like the old code used. The resolved result goes through the same native `addProduct` call the theme's own Add to Cart button already triggers. Because it's the real path, not a bypass, every plugin a client already has — loyalty points, custom pricing, marketplace seller assignment — keeps firing automatically. We never had to know those plugins exist."*

**Delivery note:** Slow down on "because it's the real path, not a bypass" — that's the sentence that answers the whole slide in one breath if someone only remembers one line.

**Likely questions:**
- *"What's the SKU-guessing bug you mentioned — did that actually cause a real bug?"* → Answer: the original module resolved a shopper's color/size selection by parsing the SKU string for patterns — which breaks the moment a client's SKU convention doesn't match what was assumed. It also posted the resolved child product ID directly to the cart, skipping Magento's real `super_attribute` mechanism entirely — meaning cart line items could show up without the color/size metadata a merchant's own order system expects.
- *"What does a custom cart adapter actually involve building?"* → Answer: one small PHP class implementing our interface, plus one line in the client's own `di.xml` pointing at it. It's the same override mechanism Everest's fork already uses correctly today to swap out Klevu's search controller — not a new pattern, a proven one.

---

## Slide 9 — Reframing "Doesn't JS Fail?"

**What to say:** *"This is worth addressing directly because it sounds like a weakness at first. Live search needs a network call — full stop, no way around that, in any language. What changes is where that call happens. We do it in the browser, after the page is already fully sent to the shopper — so if it fails, only the extra feature doesn't activate. If we did that same call in PHP during page render instead, the entire page would be waiting on our platform before Magento could even finish generating the HTML — meaning a slow or down platform would make every page load on the client's site slow or hang. JavaScript isn't the risk here — it's what contains the risk to just the widget instead of the whole page."*

**Delivery note:** This slide directly answers the sharpest technical objection in the whole deck — deliver it with confidence, not defensiveness. You've already thought about this more than the room has.

**Likely questions:**
- *"So PHP has zero risk in this design?"* → Answer: no, but its job is kept deliberately tiny — output one div and one script tag, no external network call in the critical path — so there's almost nothing left in it that *can* fail in a way that affects the page.

---

## Slide 10 — Why Not What Algolia/Klevu Do?

**What to say:** *"We looked at this directly rather than assuming — Algolia's own Magento docs confirm they inject results straight into the theme's real DOM, and require layout XML changes for anything other than the default theme. Klevu does the same, per-theme, template by template. Neither of them uses this kind of isolation. That's not an oversight on their part — they're optimizing for deep, native-looking integration and SEO, and they can absorb the coupling risk because they typically sell with paid implementation services. We're optimizing for the opposite: zero-touch reliability with no bespoke integration budget per client. So we borrowed the isolation pattern from a different category entirely — Stripe, Intercom — products whose top priority is literally 'never be the reason the host page breaks,' which is exactly our priority too."*

**Delivery note:** This is the slide most likely to draw a sharp "why do you think you know better than Algolia" question — you should feel ready for it, because you already worked through the honest answer. Don't get defensive; the honest answer is genuinely strong.

**Likely questions:**
- *"If the market leaders don't do this, isn't that a signal we're wrong?"* → Answer: it's a signal they're solving a different problem than we are. Their business model assumes competent, funded, per-client integration work. Ours explicitly doesn't — that's the whole differentiation. Different constraints justify a different architecture; it's not that they're wrong or we're smarter, it's that "10-minute install on a site nobody understands" was never their design target.
- *"Does this hurt us on SEO compared to them, then?"* → Answer: that's specifically what SSR-Shell mode (Slide 12) exists to close — real, crawlable HTML for the pages where it matters, same outcome as their approach, without giving up the isolation everywhere else.

---

## Slide 11 — Any Theme, Any Mess

**What to say:** *"Heavy theme customization isn't an edge case for this design — it's the case it's built around. The mount point is a container Magento's core framework owns, not the theme, so it survives essentially any level of customization. The search input contract is the same story — we confirmed it directly in Everest's own theme, which has years of custom Hyvä work on top of the base, and the underlying convention was still intact. And add-to-cart drives Magento's actual backend cart system, which has nothing to do with how the theme looks. Visual customization and this architecture's stability are on two completely separate axes."*

**Delivery note:** Say "we confirmed it directly" with real confidence — this isn't a claim, it's something you personally verified by reading the actual file.

**Likely questions:**
- *"What about themes that aren't Hyvä-based at all — older Luma sites?"* → Answer: the same conventions (`before.body.end`, `search_mini_form`) predate Hyvä — they're core Magento, present since Magento 2's earliest versions, so this works the same way on Luma-based sites too, not just Hyvä.

---

## Slide 12 — SEO Without Compromise

**What to say:** *"One server response serves two audiences. The response includes real, already-synced product data rendered as real HTML — that's what Googlebot sees and indexes, immediately, no JavaScript required. The shopper's browser gets the exact same HTML, and then the widget loads on top of it and turns it into the full live, interactive experience — filters, sort, instant search. Nobody has to choose between 'crawlable' and 'great UX' — they get both, from the same request."*

**Delivery note:** Keep this tight — it's a satisfying, clean answer to an objection, so don't over-explain it into sounding uncertain.

**Likely questions:**
- *"Is this mode on for every client by default?"* → Answer: it's a per-store configuration decision, not a global default — some clients' organic PLP traffic genuinely matters, others don't need the extra rendering work. It's isolated either way, so turning it on for one client has zero effect on any other client's setup.
- *"Does the crawlable HTML come from FalcoSense's live platform, or from Magento?"* → Answer: from FalcoSense's already-synced local data, not a live round-trip to the platform at render time — so a page render never has to wait on our platform being reachable just to produce the crawlable shell.

---

## Slide 13 — Headless Magento

**What to say:** *"I want to be upfront that this is the one place the current design has a real, acknowledged gap, not a solved problem — because it's better that we surface it ourselves than have it found in review. In a headless setup, Magento only serves an API — there's no Magento-rendered page for our current mount mechanism to attach to at all. But the two hardest parts of this whole architecture don't need to change: the Shadow DOM widget is a browser-standard Web Component, so it mounts identically in a Next.js app as it does in a PHP-rendered page. And the cart logic doesn't change either, because there's still exactly one real Magento cart, headless or not. What's actually new is delivery — an npm package instead of automatic layout XML injection, one small config endpoint instead of an embedded PHP config blob, and standard CORS configuration, which every headless Magento integration already has to handle regardless of us."*

**Delivery note:** Say the words "real, acknowledged gap" out loud, deliberately. Naming your own limitation before someone else does is what actually builds credibility in a room like this — don't try to talk around it or make it sound smaller than it is.

**Likely questions:**
- *"Why wasn't this designed from day one?"* → Answer: because it's a genuinely separate integration path, not a variant of the PHP-based one, and it deserves its own dedicated design pass rather than being bolted onto this architecture as an afterthought — that's exactly why it's flagged here rather than glossed over.
- *"How much work is this, realistically?"* → Answer: three concrete, bounded pieces — package the existing widget for npm distribution, add one config endpoint, configure CORS. Not a redesign of anything already built.

---

## Slide 14 — Built to Scale

**What to say:** *"Every client runs the same shared codebase by default — the widget, the core module, the default adapters are identical across every install. That's the opposite of what's happening today, where Everest's fork has already diverged into something semi-custom. When a client does need something different — a custom cart adapter, an SSR-Shell config — that change is isolated to that one client and never touches anyone else's setup, or the shared core. And because failures are low-severity by design — quietly inactive, not site-down — support triage gets easier as the client count grows, not harder."*

**Delivery note:** This slide is good for a confident, slightly faster pace — it's the "and here's why this isn't just safe, it's efficient to run" moment.

**Likely questions:**
- *"What doesn't scale automatically, then?"* → Answer, said plainly: SSR-Shell is a deliberate per-client decision, not automatic. Headless clients need the separate integration path from the previous slide, which doesn't exist yet. And onboarding still needs a human to walk through API key setup — this architecture shrinks that from hours to minutes, it doesn't remove the human step entirely.
- *"Does this cover how FalcoSense's own platform scales — the ranking/search backend?"* → Answer: no — that's a separate system with its own separate scalability story. This is specifically about the Magento-side integration.

---

## Slide 15 — Stress-Tested

**What to say:** *"Rather than assert this is safe, here's the actual scenario table we worked through before this review — the same questions I'd expect to get asked, answered in advance. In every single row, the failure mode is the same shape: FalcoSense quietly doesn't do anything extra. It's never 'the client's site breaks because we're there.' That asymmetry — fail toward inertness, never toward damage — isn't a promise layered on top of the architecture. It's a direct, mechanical result of the rule from Slide 4."*

**Delivery note:** This slide is your strongest defensive position in the whole deck — if the room has been pushing hard, this is where you can visibly relax the pace, because you're showing your homework rather than making a new claim.

**Likely questions:** This slide exists specifically to preempt questions — if one comes up that isn't on the table, that's a genuinely new scenario worth taking seriously and following up on, not deflecting. Say so directly: *"that's a good one, let me think it through properly rather than guess an answer right now."*

---

## Slide 16 — Close

**What to say:** *"10 to 12 hours of firefighting on a real client integration becomes a 10-minute setup. A module that structurally cannot be the reason a client's site goes down. That's what makes 'we just installed FalcoSense' feel like an upgrade — every single time, on every site, no matter how messy the codebase underneath it is. That's the pitch."*

**Delivery note:** End on the stat and the last line, in that order, and stop talking. Don't add a summary after this — let it be the last thing said before questions open up.

**Likely questions:** By this point, most of the sharp questions should already have surfaced earlier in the deck. If the room opens with something broad like *"so what's actually left to build?"* — answer honestly: the widget bundle itself, the `CartAdapterInterface`/`VariantResolverInterface` concrete implementations, the SSR-Shell rendering path, and the headless delivery adapter. The architecture is decided; the build is the next phase.
