# Section Editor — Factual Implementation Plan (2026-07-09) — AWAITING GO

Owner-locked decisions (2026-07-09 discussion):
1. **Every header owns its own section** — a section = one heading + every
   paragraph after it until the NEXT heading (any level). Headers keep their
   one-click fixes (H1–H6 switch, text optimize) exactly as today.
2. **Table rows stay unchanged** — quick per-row edits keep the shipped paths.
3. **Modal = presentation + section work**: click a paragraph → floating,
   draggable, NON-blocking modal shows the whole section as readable formatted
   text; rewrite freely (merge/delete/add paragraphs, headings, lists), AI
   rewrite, accept/undo.
4. **New sections** (e.g. FAQ) are created in the tool and anchored
   before/after an existing header. Pages with zero headings can't take inserts.
5. Safety law unchanged: client edits their original → we serve THEIR version
   and flag stale. Never a silent overwrite, never a wrong swap.

Supersedes the earlier "paragraphGroup" sketch (HANDOVER-20260709-0115 §IN
DISCUSSION). Companion: `docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md` (contracts
v1 — v2 additions frozen in sub-pair S1 below). Every fact below is
code-verified (file:line on HEAD `2607ed8`).

---

## 0. Fact base — what exists and is reused unchanged

| Fact | Where |
|---|---|
| Paragraph rules: UPSERT + clean revert + push-fail rollback + capability check | seo/service.php:2773–2878 |
| Rules push (schema v1, replaces set) + capability check | seo/service.php:2668–2709 |
| Hub rule store `seo_dynamic_rules` — `target` varchar(20), `replacement` longtext, `anchorContext` text, `changesetId` nullable | class-pcm-schema.php:706–726 (DB 1.37.0; **no bump needed** — columns fit) |
| Connector serving: `template_redirect`+`ob_start` prio 2, try/catch fail-to-original, kill switch, `?pcm_cscan` excluded | seohub/service.php:2032–2047 |
| Boundary matcher on non-nestable tags (`<p>`), occurrence-aware, visible-text compare | seohub/service.php:2003–2026 |
| Connector POST /rules: rejects `schemaVersion !== 1` honestly; drops unknown targets; `wp_kses_post` on replacement; purge + scan-cache bust | seohub/service.php:2066–2096 |
| Connector GET /rules returns `schemaVersion` (the 2.8.0 capability handle) | seohub/service.php:2057 |
| scan-content: cached, single-flight loopback, 3 honest tiers; paragraph nodes carry `html`, `occurrence`, `anchor{level,text}` (anchor has NO occurrence) | seohub/service.php:1930–1960, 2102–2158 |
| Heading rows: remote heading scan + edit endpoints untouched by paragraph work | seo/controller.php:340–350, 418+ |
| Normalization spec v1, byte-identical hub/connector | PCM_Text_Matcher::normalize ↔ seohub/service.php:1918–1924 |
| Staged AI accept/reject UI + rules overlay in the outline | HeadingsPanel.tsx:243–293, 340–374, 441–456 |
| TipTap full stack (bold/italic/underline/link/headings/lists) shared; selection-only bubble toolbar pattern | app/src/components/shared/editorExtensions.ts; Writer/components/WriterBubbleMenu.tsx (`selectionOnly`) |
| Shared Dialog is BLOCKING by construction (overlay + centered hardcoded) — unusable for the floating modal | app/src/components/ui/dialog.tsx:122–127 |
| No floating-panel/drag lib in the bundle (`@hello-pangea/dnd` is list DnD) — the panel is a small custom component, **no new dependency** | app/package.json |
| Prompt sections auto-seed as user-editable Templates (module=seo, userId=0 system set, idempotent) | seo/service.php:3136–3177 |
| `run_prompt_section` multiline mode (paragraph pair 3) | seo/service.php:684–688 |
| trpc route-map pattern for the 4 paragraph endpoints | app/src/lib/trpc-routes.ts:394–412 |
| `wp_kses_post` allows h1–h6/ul/ol/li/a/strong/em — section HTML passes both sanitizers | WP core (hub :2780, connector :2085) |

## 1. Contracts to freeze FIRST (sub-pair S1, docs before code)

### 1a. Section identity contract v1
`section = { headingText (normalized spec v1), headingLevel, headingOccurrence,
fingerprint }`
- `headingOccurrence` = position among same-normalized-text headings, counted
  in scan order (hub computes from the existing heading scan; connector counts
  the same way over the buffered HTML).
- `fingerprint` = normalization-spec-v1 result of the section's paragraph
  visible texts joined with `"\n"` (computed from EXISTING scan-content nodes —
  **no connector scan change needed**).
- The fingerprint is the real guard: a candidate heading only qualifies if the
  whole section body also matches. Chrome-duplicate headings (site title as H1
  in the page header) therefore can't cause a wrong swap — worst case is an
  honest miss + stale flag.

### 1b. Rule schema v2 (ADDITIVE to v1)
Two new targets, everything else identical:
- **`section`** (replace): `match:{text: <heading normalized>, occurrence:
  <headingOccurrence>}`, `section:{level, fingerprint}`, `replacement` = the
  ENTIRE new section as block HTML (heading first, then body blocks).
- **`sectionInsert`**: `match:{text: <ANCHOR heading normalized>, occurrence}`,
  `section:{position:'before'|'after'}`, `replacement` = a complete new
  section (heading + body). Identity = hub rule id (several inserts may share
  an anchor — UPSERT by id, never by matchText).
- Push carries `schemaVersion: 2`. Old connectors (≤2.7.1) reject it honestly
  (`unsupported_schema`, seohub/service.php:2068) — **zero silent-drop risk**.
  Hub pushes v1 exactly as today whenever a post's set contains no section
  targets (zero regression for un-updated sites).

### 1c. Serving mechanism v2 (connector 2.8.0) — the engineering core
**All-or-nothing section application** (wrapper-safe by construction — builder
pages keep their wrapper divs; we never swap a raw heading→heading span, which
would rip out Elementor/Brizy column markup):
1. Find candidate `<hN>` whose visible text matches `match.text`
   (occurrence-aware over matching texts).
2. From after it, collect the following `<p>` blocks up to the next `<h1-6>`
   (same regex family as the shipped scan, seohub/service.php:1933).
3. Verify: normalized join of collected texts === `section.fingerprint`.
   Mismatch → apply NOTHING for this rule, count a miss (stale). Next rule.
4. Verified → parse `replacement` into top-level blocks with the SAME parser
   rules (heading block + body blocks) and apply as block ops:
   - heading block ⇢ swaps the matched `<hN>` block;
   - body blocks map 1:1 onto the original `<p>` blocks while counts allow
     (the common "polish text" case keeps per-widget distribution);
   - surplus NEW blocks ride as siblings replacing/following the LAST original
     block; surplus ORIGINAL `<p>` blocks are removed whole (wrappers stay).
5. `sectionInsert`: locate the anchor section the same way (steps 1–2, **no
   fingerprint requirement** — inserts anchor to the heading only); insert the
   parsed blocks before the anchor heading block or after the section's last
   block. Anchor not found → nothing inserted + stale (honest).
6. Order inside `pcm_conn_apply_rules`: section replaces → section inserts →
   paragraph rules (hub prevents overlap — §2c). Entire callback stays inside
   the existing try/catch fail-to-original (:2040–2046). Kill switch, purge,
   stats counters, `?pcm_cscan` exclusion: same pipeline, zero change.

**Documented limitations (honest, never silent):** removed `<p>`s can leave
empty builder wrappers (possible spacing); count-changing rewrites on
multi-column builder sections re-flow into the surviving blocks; inserted
sections inherit the container they land in. The preview modal (authenticated
fetch, shows served rules) is the immediate truth-check for all three.

## 2. Build plan — sub-pairs, each with the full commit ritual

Every sub-pair: `git pull --ff-only` → BEFORE commit → build → verify (php -l
each touched file; **template-extract lint of the GENERATED connector
source**; `cd app && npm run check` tsc baseline **59, zero new**; `npm run
build`) → AFTER `- UNVERIFIED` → session log (+ changelog) → push → owner
verifies before the next sub-pair.

### S1 — Contracts (DOCS only)
- [ ] Write §1a–1c into `DYNAMIC-OPTIMIZATION-ARCHITECTURE.md` as frozen v2
      additions (node/normalization v1 untouched).
- [ ] Record the two owner decisions (section boundary, insert anchoring).

### S2 — Hub engine (matcher + rules service + endpoints)
- [ ] `PCM_Text_Matcher::fingerprint(array $texts)` (pure; normalize + join)
      + fixture tests beside the existing corpus (tests/unit/TextMatcherTest.php).
- [ ] `save_section_rule()` in PCM_SEO_Service modeled line-for-line on
      `save_paragraph_rule` (:2773): capability first (now reads GET /rules
      `schemaVersion` ≥ 2 → else honest "connector needs v2.8.0" error),
      UPSERT (replace: siteId+postId+target+matchText+occurrence; insert: by
      rule id), CLEAN REVERT (replacement re-fingerprints to the original AND
      heading unchanged → delete), push v2, push-fail ROLLBACK.
- [ ] **Absorb rule**: saving a section rule DELETES the hub's paragraph rules
      whose matchText+occurrence fall inside that section (their replacements
      are folded into the modal's initial state frontend-side first — nothing
      lost, no overlap state possible).
- [ ] **Re-key on heading edit**: a successful remote heading source-write
      (controller.php:418+) updates matchText/anchor of any section/insert
      rules keyed to that heading and re-pushes — a heading fix must never
      strand its section rule stale.
- [ ] `push_rules` gains the v1/v2 decision (v2 only when section targets
      present, :2684–2709 otherwise byte-identical behavior).
- [ ] Routes (seo/controller.php, `manage_options`, same guards as :375–416):
      `POST …/section-rule`, `POST …/section-optimize` (+ list_dynamic_rules
      already returns ALL targets unfiltered, :2712–2734 — overlay free).
- [ ] trpc-routes.ts entries (pattern :394–412).
- [ ] `section` prompt section in prompts.php (generate + optimize; block-HTML
      output modeled on `content`, prompts.php:80–91; same-language law) —
      auto-seeded as a user-editable Template by the existing seeder
      (service.php:3136 — additive to `get_default_prompts`, zero seeding code).

### S3 — Connector 2.8.0 (template in seohub/service.php)
- [ ] GET /rules answers `schemaVersion: 2`; POST accepts v1 AND v2 (v2
      validates the two new targets + `section` object; everything else
      rejected honestly).
- [ ] `pcm_conn_apply_rules` v2 per §1c (verify-then-apply, all-or-nothing per
      rule, block mapping, inserts) — pure additions; the v1 paragraph path
      stays byte-identical.
- [ ] Version bump 2.7.1 → 2.8.0 (template header, seohub/service.php:448).
- [ ] MANDATORY: extract generated connector source → `php -l` it.

### S4 — Frontend: the modal + wiring
- [ ] `SEO/SectionModal.tsx` (new): floating panel — portal, fixed position,
      pointer-drag on header, NO overlay (table behind stays interactive),
      Esc/X close. Custom, dependency-free (shared Dialog is blocking by
      construction, dialog.tsx:122–127).
- [ ] Read view: section as sanitized formatted HTML (served state from rules
      overlay; original from node `html` — both already fetched,
      HeadingsPanel.tsx:243–249 + scan nodes).
- [ ] Edit mode: TipTap (editorExtensions subset: inline marks + link +
      headings + lists — no images/tables v1) emitting HTML; selection bubble
      toolbar (WriterBubbleMenu pattern, `selectionOnly`).
- [ ] AI: header ✦ section rewrite → `section-optimize`, staged Accept/Undo
      (same contract as HeadingsPanel :340–374). Per-paragraph ✦ inside the
      modal keeps the shipped paragraph endpoint.
- [ ] HeadingsPanel wiring: paragraph TEXT click keeps inline quick-edit
      (owner: rows = quick edits); the P chip + a hover expand icon open the
      modal (the raw-HTML popup becomes a "view HTML" toggle INSIDE the modal).
      Rows inside a section that has an ACTIVE section rule route their edit
      into the modal (a row-level paragraph rule would honestly miss — so we
      don't offer it).
- [ ] "+ Add section": outline affordance → modal in create mode (anchor
      section + before/after picker; AI generate via the `section` prompt or
      write manually) → saves a `sectionInsert` rule.
- [ ] Overlay marks: section-ruled rows show served text + the primary dot
      (same affordance as paragraph rules today); inserted sections render as
      rows tagged "new section (served dynamically)".
- [ ] LOCAL tab: modal opens read-only formatted (routing law — no engine on
      the hub's own site; honest tooltip as today).

### S5 — Owner verification script (per sub-pair + end-to-end on powerstock)
- [ ] Connector column reads 2.8.0 after reinstall/update; old connector →
      section save fails with the honest 2.8.0 message, paragraph editing
      still works (v1 push path untouched).
- [ ] Click paragraph → modal shows whole section formatted; drag it; table
      behind stays usable.
- [ ] AI rewrite section → accept → live page serves the whole new section
      (Ctrl+F5); revert to original text → rule gone, original serves.
- [ ] Merge 4 paragraphs → 2 → serves; add bullets/H3 inside the section →
      serves legally (block HTML).
- [ ] Add FAQ section after the last section → serves; toggle/kill switch off
      → gone; delete → gone.
- [ ] Edit the ORIGINAL text on the site (client simulation) → section goes
      stale → original serves + flagged.
- [ ] Deliberately-wrong rule (tampered fingerprint) → original serves.
- [ ] Heading one-click edit on a section-ruled header → still works AND the
      section rule follows (re-key), never strands stale.

## 3. Explicitly OUT of this build (unchanged parkings)
Approvals rails/changesets (pair 5 — `changesetId` columns already wait,
schema:718) · switches UI beyond the shipped kill switch (pair 4) · AI
optimizer · SERP · licensing · local-site rule engine.

## 4. Effort & risk (senior read)
S2+S3 are the build's core (~same weight as pair 2 was); S4 is the largest
frontend piece since the Deliveries table; S1/S5 are thin. Highest-risk item:
§1c block mapping on heavily-designed builder pages — mitigated by
all-or-nothing verify, fail-to-original try/catch, stale flags, and the
preview modal as immediate truth. No DB bump, no new dependency, one connector
bump.
