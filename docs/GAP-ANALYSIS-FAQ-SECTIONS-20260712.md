# GAP ANALYSIS — SECTION-AWARE EDITOR + FAQ BLOCK (2026-07-12) — AWAITING GO

**Owner order:** every edit should visibly live inside an existing section
(keep the site's real formatting); page freedom (delete/insert/wipe) stays;
plus an FAQ block that looks decent on ANY site without builder access.
Every fact below verified in code this day.

**Board context:** pair `[add-images]` (fix specced in HANDOVER-20260712 §7)
and pair `[slug-redirects]` (GAP-ANALYSIS-IMAGES-REDIRECTS-20260712.md) are
approved and queued ahead of these two. Owner-approved order: images →
redirects → sections → FAQ.

---

## UPDATE C — SECTION-AWARE EDITOR (frames · you-are-here · native-vs-new · dead zone)

### Facts

| # | Fact | Evidence |
|---|------|----------|
| C1 | **Section grouping already exists client-side** and mirrors the server's: `splitDocSections(html)` returns `{orphanHtml, sections[]}`, splitting on h1–h6 exactly like the compiler's `section_runs`. One source of truth, already shipped (powers AI review). | word-diff.ts:149 |
| C2 | **Ownership attribution exists server-side but is thrown away.** `assemble_content_html` knows, per heading row, whether the section is rule-owned (`section`/`sectionInsert` attribution, `$att`) — then emits bare tags with no marker. The editor cannot currently tell original sections from owned/inserted ones. | service.php:3169-3183 |
| C3 | **The dead zone is real and server-enforced.** Content before the first heading is editable in the editor today but the compiler REFUSES it after the fact ("Content before the first heading can't be edited yet… weren't saved."). User learns after saving — backwards. | service.php:4496-4498 |
| C4 | **The editor has an established custom-extension pattern** (LockedImage node, DiffAdded/DiffRemoved marks) but NO ProseMirror decoration plugin yet — section frames need one (decorations = visuals that are not content, exactly what frames must be so they can never leak into a save). | SectionModal.tsx:151-159, 296-301; grep: no `Decoration` usage |
| C5 | **House law: no instructional UI text, ever.** The solution must be structural/visual only. | memory feedback_no_instructional_ui_chrome |
| C6 | Page freedom (sectionRemove, insert, wipe, restore) is engine-complete and live-proven — nothing here may restrict it. Guidance by clarity, not prohibition. | v2.4/v2.4.1 arcs, harness 70/70 |

### Gaps → changes

| # | Change |
|---|--------|
| CG1 | **Server: carry ownership into the payload.** `assemble_content_html` emits `data-pcm-origin="original\|owned\|insert"` on each section's heading tag (attribution it already computes at 3169; today discarded). Survives kses (data-* wildcard verified in this core, kses.php:904+2920). Zero new queries. The save compiler ignores/strips the attribute on the way back in (it is context, not content — same class as `data-pcm-locked`). |
| CG2 | **Editor: section frames.** One ProseMirror decoration plugin: group blocks via the C1 splitter, draw a quiet left rail/soft boundary per section. Decorations only — impossible to save by construction. |
| CG3 | **Editor: "you are here".** Same plugin highlights the section containing the cursor (selection-driven decoration class). |
| CG4 | **Editor: native-vs-new by color.** Rail color keyed on origin: original (neutral) · owned/edited (accent) · new-in-this-session or insert (distinct accent). New = section whose heading key is absent from the load-time snapshot (client-side) OR `data-pcm-origin="insert"` (server-side). No words, no hints — color states the fact (C5-compliant). |
| CG5 | **Editor: close the dead zone.** Block edits above the first heading at the editor level (filterTransaction / handleTextInput guard on the orphan range) so the C3 server refusal can never be reached. Locked orphan images stay visible (they are context, already non-editable). |

### Regression surface (named)
Decorations never serialize → saves byte-identical to today (CG2/CG3/CG4 are
render-only) · CG1 attribute must be stripped by the save path — covered by the
same strip class as data-pcm-locked + a compiler check · CG5 must not block
LEGAL first-heading edits (guard scopes to the orphan range only) · section
mode (non-page) untouched — plugin mounts in page mode only.

---

## UPDATE D — FAQ BLOCK (native accordion, decent on any site)

### Facts

| # | Fact | Evidence |
|---|------|----------|
| D1 | **`<details>`/`<summary>` survive sanitization end-to-end**: both tags are in this core's kses allowlist; the connector sanitizes rule replacements with the same `wp_kses_post`. Native browser accordion — zero JS shipped to client sites. | kses.php:149,302; connector service.php:2261 |
| D2 | **Inline styles survive too**: `style=""` passes kses with border/border-radius/margin/padding/font-size/display/gap/cursor all whitelisted — enough for spacing + dividers while FONT/COLOR INHERIT from the site (the "decent anywhere" mechanism). | kses.php:2585-2757 |
| D3 | **The engine needs zero change**: an FAQ is a section insert (heading + blocks); inserts store arbitrary kses'd HTML and serve it opaquely; non-img raw units (the `<details>` blocks) are KEPT by the compiler (F9 note: engine-legal). Rule algebra stays closed. | service.php:4020 (save_section_insert), 4391-4398 (raw units kept) |
| D4 | **Change detection works on FAQ content**: `$rawtext` counts visible text of raw units — Q&A text lives in raw `<details>` units, so edits register. (Image-blindness does not repeat here: text IS visible.) | service.php:4540-4548 |
| D5 | **The editor cannot represent `<details>` yet**: extensions are StarterKit + Image + custom marks; StarterKit has no details node. Without a node, TipTap would flatten the FAQ on round-trip. | SectionModal.tsx:296-301 |
| D6 | **JSON-LD capability exists in the connector**: it already owns `wp_head` output (three hooks) — an FAQPage schema emitter has a proven injection point. Data channel (hub-pushed, per-post) is new. | connector service.php:1416,1424,1609 |
| D7 | Client-site WP version dependency: details/summary entered core kses in WP 5.9 (2022). Fleet (powerleads) is current. Live proof must confirm on the real connector, not assume. | kses history; fleet state |

### Gaps → changes

| # | Change |
|---|--------|
| DG1 | **Editor: FAQ node.** Custom TipTap node pair (`faqItem` = details+summary) with schema `summary text + rich content`, style attrs preserved, Enter/Backspace ergonomics (new item, exit block). Same pattern class as LockedImage. |
| DG2 | **Editor: "Add FAQ" action** (page mode toolbar): inserts heading + N editable Q&A items at cursor with the D2 inline-style skeleton (structure + spacing only; fonts/colors inherit). Renders in-editor exactly as it will serve (native details element). |
| DG3 | **Skeleton = one exported template function in the editor module.** LEAN RULING (2nd pass): the no-hardcoding law governs the CONNECTOR (hub must control client-side data); the editor IS the hub — an endpoint + fetch + loading state to deliver a static skeleton the hub itself renders is excess code. One constant, zero routes. |
| DG4 | **Phase 2 (separate GO): FAQPage JSON-LD.** Hub compiles Q&A pairs from the insert rule content → pushes per-post schema JSON to the connector (rides the existing rules payload, schema bump) → connector `wp_head` emits `<script type="application/ld+json">`. Named now, not built now. |

### Regression surface (named)
Round-trip: FAQ insert → serve → editor reload → save unchanged must be
`skipped` (D4 text identity; harness-style proof) · kses must not reorder/nest
details (live proof) · AI review on a section containing details: review
operates on p/h blocks; details blocks pass through untouched (verify in
splitDocSections path) · section mode untouched.

---

## THE CHECKLIST (two pairs, full ritual each, owner GO per pair)

**Pair 3 `[section-frames]`** — server marker + editor decorations:
- [ ] BEFORE commit
- [ ] CG1 origin attribute in assembly + strip/ignore in save path
- [ ] CG2 decoration plugin (frames) + CG3 you-are-here + CG4 origin colors
- [ ] CG5 dead-zone guard (orphan range only)
- [ ] Verify: php -l · harness stays green (engine untouched) · tsc 59 · build ·
      LIVE: load owner page → frames match served sections → cursor highlight →
      edited section shows accent after save → pasted section shows "new" rail →
      typing above first heading impossible → save byte-path unchanged (skipped
      counts identical on an untouched round-trip)
- [ ] Changelog + AFTER commit

**Pair 4 `[faq-block]`** — editor node + hub template (phase 1, no connector change):
- [ ] BEFORE commit
- [ ] DG1 faq node + DG2 Add FAQ action + DG3 template constant (no endpoint)
- [ ] Verify: php -l · harness green · tsc 59 · build ·
      LIVE (disposable page): insert FAQ → save (inserted:1) → full render
      serves native details block styled sanely → toggle open/close works with
      site CSS only → edit a question → save registers (saved:1) → reload
      editor → FAQ round-trips intact → delete section → stops serving
- [ ] Changelog + AFTER commit

**Phase 2 `[faq-jsonld]`** (DG4): separate gap + GO after pair 4 ships.

---

## MASTER CHECKLIST — THE FULL BOARD (owner-approved order 1 → 2 → 3 → 4)

Execution law: full ritual per pair (BEFORE commit → implement → verify:
php -l · harness ALL GREEN · tsc 59 baseline · build · LIVE proof on
disposable pages, owner data untouched → changelog line → AFTER commit).
No pair starts before the previous pair's ritual closes.

**Pair 1 `[add-images]`** — finish (code committed in 9c16d71; spec: HANDOVER-20260712 §7)
- [ ] Fix the IMAGE-BLIND unchanged-skip: kept-image (`data-pcm-added`) src
      identity joins the pair comparison per side (~15 lines, save_page_edits;
      identity spaces — norm/fp/keys/rename/restore — stay image-blind BY DESIGN)
- [ ] Rewrite probe-addimage.php in pcm-probes (stable dir); assert sane
      snapshot before saving; warm-retry pattern
- [ ] LIVE 5-step proof: deliver → place-save `saved:1` → serves on full
      render (client-host src) → remove+save stops serving → attachment persists
- [ ] Changelog + AFTER commit

**Pair 2 `[slug-redirects]`** — build (spec: GAP-ANALYSIS-IMAGES-REDIRECTS-20260712.md BG1–BG5)
- [ ] BEFORE commit
- [ ] BG1 `seo_redirects` table (DB 1.38.0 → 1.39.0, additive, idempotent) +
      service save/list/delete with push-fail rollback
- [ ] BG2 connector 3.0.5 redirects store + early template_redirect handler
      (harness-extracted match fn) + BG3 `/url-usage` route
- [ ] Harness fixtures: exact match · query passthrough · code whitelist ·
      no-match passthrough · empty-store zero-cost · url-usage
- [ ] BG4 popup on real slug change (prefilled editable From/To/301-dropdown +
      N-links checkbox) + BG5 settings-panel list/delete + trpc routes
- [ ] LIVE proof: disposable published post → slug change → popup → Yes →
      curl -I 301 → seeded internal link rewritten → delete redirect in
      settings stops it → restore slug, remove post
- [ ] Changelog + AFTER commit

**Pair 3 `[section-frames]`** — build (spec: this doc, CG1–CG5)
- [ ] BEFORE commit
- [ ] CG1 `data-pcm-origin` on section headings in assembly (attribution
      already computed at service.php:3169) + strip in the save path
- [ ] CG2–CG4 ONE decoration plugin: frames · cursor-section highlight ·
      origin colors (decorations never serialize — save path byte-identical)
- [ ] CG5 dead-zone guard (orphan range only; legal first-heading edits unblocked)
- [ ] LIVE proof: frames match served sections · you-are-here follows cursor ·
      pasted section shows "new" rail · typing above first heading impossible ·
      untouched round-trip saves with identical skipped counts
- [ ] Changelog + AFTER commit

**Pair 4 `[faq-block]`** — build (spec: this doc, DG1–DG3; DG3 = constant, no endpoint)
- [ ] BEFORE commit
- [ ] DG1 faq node (details/summary, style attrs preserved, Enter/Backspace
      ergonomics) + DG2 Add FAQ toolbar action + DG3 template constant
- [ ] LIVE proof (disposable page): insert FAQ → `inserted:1` → full render
      serves native accordion, site typography inherited → toggle works with
      zero shipped JS → edit question → `saved:1` → editor reload round-trips
      intact → delete section stops serving
- [ ] Changelog + AFTER commit

**Parked (named, not started):** `[faq-jsonld]` phase 2 · builder-wrapper
cloning · AI-generated FAQs · editable theme furniture · fleet convergence.

## OUT OF SCOPE (named)
Copying builder wrapper markup ("replicate this styled section") — real
architecture step, parked by owner · editable theme-furniture texts (parked) ·
FAQ import/generation by AI (needs its own spec) · per-site FAQ theming UI.
