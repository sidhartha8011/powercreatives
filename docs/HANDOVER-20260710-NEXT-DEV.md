# HANDOVER — 2026-07-10 (evening) — full background, plan, and checklist for the next developer

You are taking over the SEO suite of the Power Creatives hub plugin. Read this
top to bottom before touching anything. Companion docs are referenced inline;
they are authoritative where they go deeper.

---

## 1. PURPOSE — what this product is

The hub (this plugin, on powercreatives.local) manages SEO for a fleet of
CLIENT WordPress sites (powerleads.local, powerstock.local, …), each running a
single-file **connector** plugin that the hub generates and serves updates to.

**The core product idea:** content optimizations are DYNAMIC. Nothing rewrites
the client's stored content or builder data. Every edit (headings, sections,
paragraphs — soon image metadata) is stored as a **rule** on the client site
and applied at render time, verify-first: if the page no longer matches what
was scanned, the rule refuses and the ORIGINAL serves. Never a guess, never a
wrong swap. Edits are reversible (edit back to original = rule deletes),
versioned (sections), and survive connector updates/reinstalls (they live in
the client site's own DB). The hub is only needed to CREATE edits; client
sites serve them autonomously.

## 2. ARCHITECTURE — the one-brain law

**Everything intelligent lives in the hub. The connector has exactly four dumb
jobs** (contracts in `docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md`, sections
"Cleanup contracts v3" + "Instruction stream v2.2" — FROZEN, do not re-litigate):

1. **Page snapshot** — `GET /pcm-conn/v1/snapshot?post_id=N[&mode=served]`.
   Two views: `input` = the page as RULES-INPUT (rule serving excluded — the
   identity space every rule matches against); `served` = what a visitor sees.
   Explicit `view` marker in the response; tiered fallbacks for WAF-blocked
   sites; cached (input per-post, served version-stamped via
   `pcm_conn_view_ver`, bumped on every rules push + save_post).
2. **Storage writers** — links/meta only (builder-aware). Headings NEVER
   write storage anymore.
3. **Guarded apply** — the serving pass (`pcm_conn_apply_rules`, template in
   `includes/modules/seohub/service.php`): heading rules FIRST (occurrence-
   aware; `allOccurrences` = compiled-override semantics; `frameOnly` = only
   inside chrome spans), then section replaces → section inserts → paragraph
   rules. All-or-nothing, fail-to-original.
4. **Hub-pushed config** — every tunable (TTLs, timeouts, chrome region list,
   caps) is hub data (`pcm_seo_connector_config` option, auto-pushed on site
   save + `POST /seo/sites/{id}/push-config`). Code keeps defaults.

**Hub brain:** `includes/modules/seo/service.php` (~5800 lines) +
`class-pcm-text-matcher.php` (parsing/identity primitives ONLY — the apply
engine exists solely in the connector; the primitives are enforced
behavior-identical by the committed harness, see §6).

**Key hub flows:**
- `served_inventory()` — fetches the served view, parses it
  (`parse_page_snapshot`), and **ATTRIBUTES every row to the rule that
  produced it** (whole rule, or a SLICE `{ruleId, unitFrom, unitTo}` of a
  bigger replacement, incl. sections that exist only inside a rule). The
  outline (HeadingsPanel) renders served truth.
- Heading edits: `remote_update_heading_apply()` routes by row → section-owned
  → `update_owned_heading_unit()` (one-owner law: never stack a second rule on
  an element); else `save_heading_rule()` (post-scope: page+text+level+
  occurrence-among-twins; chrome rows: site-scope + allOccurrences +
  frameOnly).
- Section edits: `save_section_rule` (replace, absorb laws, clean revert,
  versions) / `save_section_insert` / `save_section_slice` (edit one unit
  range of an owning rule) — all with capability-check-first + push-fail
  rollback (`push_current_rules_or_rollback`).

**Identity spaces (critical to understand):** rules match the **rules-input**
text (original page + prior transforms in serving order); the UI shows the
**served/display** text. `parse_page_snapshot` computes both
(`matchText/matchLevel/matchOccurrence` = input space; `text/occurrence` =
display space). Confusing these spaces caused a real production bug (see §5).

## 3. HISTORY — how we got here (this week)

- **2026-07-09 (day):** section editor built (rules v2, fingerprints, section
  modal, versions). Three root causes found live (chrome parity, buffer
  order, scan loopback exclusion) → bred by duplicated connector logic.
- **2026-07-09 (night), THE CLEANUP C1–C5** (`docs/CLEANUP-GAP-ANALYSIS-20260709.md`):
  one brain in the hub; connector 3.0.0 = four dumb jobs; BOTH scanners
  deleted (hub parses snapshots); mirrored apply logic deleted hub-side;
  hand "sync contract" replaced by the committed extraction harness; fossil
  v1.0.1 tenant template deleted; config push; override→instruction migration
  (`migrate_site_overrides`, Migrate button in RemoteSiteSettingsPanel).
- **2026-07-10, DYNAMIC ALIGNMENT P1–P3**
  (`docs/GAP-ANALYSIS-DYNAMIC-ALIGNMENT-20260710.md`): heading edits went
  FULLY dynamic (source-write path deleted, connector 3.0.1); smart per-page
  identity (chrome ⇒ site-wide, content ⇒ this page + this twin; content
  twins never deduped); one-owner law (rule chains impossible to create);
  served-truth editor (dual snapshot views + attribution + slice editing).
- **2026-07-10 hotfixes (all live-verified on powerleads):** a 2-arg call
  fatal in the snapshot parser; **the re-key law corrupting rule identities**
  (re-key now fires ONLY on legacy source writes — a rule-mediated edit
  changes display only); a data repair after the owner's title edit broke the
  last pre-P2 test chain; `frameOnly` tightening (site-wide edits can never
  touch page content with identical text).
- **UX:** global `Pill` primitive (`components/ui/pill.tsx`, cva variants —
  ALL pill colors/sizes live there, zero inline styling anywhere);
  the heading-level dropdown IS a Pill via `SelectTrigger asChild`
  (composition — no style-override warfare); status dots (sky = site-wide
  element, primary = optimized here, tooltips carry the full text).

## 4. CURRENT STATE

- Branch `feat/seo-suite-port`, **~40 local commits ahead of origin — NEVER
  PUSHED** (owner law #1: never push; another dev shares the repo).
- Working tree clean at the commit adding this handover. Every pair has
  BEFORE/AFTER commits (rollback points) + a line in
  `docs/CHANGELOG-20260709-2200.md` (the running changelog for this arc).
- Fleet: powerleads runs connector **3.0.1** (it self-updated from the hub's
  manifest — the self-update system is proven). powerstock is OLD (needs the
  manual reinstall ritual; its self-update is broken).
- Verification: harness **41/41** (`php tests/standalone/run.php`) · php -l on
  everything incl. the extracted generated template · tsc **59 = baseline**
  (pre-existing errors, ZERO in SEO/touched files) · builds clean · multiple
  live proofs on powerleads (probe scripts pattern, see §6).
- Live test data on powerleads: rule 5 (comment-area section, post 1), rule 6
  (section, post 2 "Sample" — owner's real test), rules 8/10 (site heading +
  insert anchored on chrome — stale test junk the owner clears via UI).

## 5. LAWS (owner rules — hard, non-negotiable)

1. **NEVER `git push`.** Local commits only until the owner explicitly says
   push, per instance.
2. **No coding without explicit GO.** Reiterate the plan → owner confirms →
   build exactly that. Gap analysis BEFORE building anything sizable.
3. **Zero dead code, zero tech debt.** Delete, don't deprecate-and-keep.
   No mirrored logic — the harness pins parity instead.
4. **No hardcoded tunables in the connector** — hub-pushed config.
5. **No silent fallbacks** — fail honest (original serves, stale counted).
6. **Facts with evidence.** Instrument live before theorizing (debug.log,
   the probe scripts, direct DB reads). Guessing gets called out.
7. **Owner communication: product language, SHORT.** He will not read walls
   of text. No code talk unless he asks "as senior developer".
8. **Full ritual per pair:** BEFORE commit (rollback point) → implement →
   verify (harness + php -l + extracted-template lint + tsc + build) →
   changelog line → AFTER commit.
9. **This box:** never splice UTF-8 files with PowerShell Get/Set-Content
   (double-encodes — bit us once, recovery documented in the changelog).
   Use the Edit tooling or byte-safe sed/iconv via Git Bash.

## 6. TOOLCHAIN (this machine — LocalWP, no dev tools on PATH)

- PHP CLI: `C:\Users\dataadmin546\AppData\Roaming\Local\lightning-services\php-8.2.29+0\bin\win64\php.exe`
  (no php.ini → no mysqli/mbstring by default; add
  `-d extension_dir=<phpdir>\ext -d extension=mysqli -d extension=mbstring …`
  when a script needs them; normalize() falls back to ASCII folding without
  mbstring — spec'd safe, harness accounts for it).
- **Harness (the safety net):** `php tests/standalone/run.php` — no
  vendor/PHPUnit needed (vendor is intentionally absent on this box; the
  PHPUnit corpus in tests/unit runs on the other dev's machine/CI). It EVALS
  the REAL connector template extracted from seohub/service.php and runs 41
  fixtures: hub↔connector primitive parity + the full apply corpus + heading
  pass (occurrence, allOccurrences, frameOnly) + the ordering law. Extend it
  with every engine change.
- Frontend: `cd app && npm run check` (tsc; baseline 59 pre-existing errors —
  the bar is ZERO NEW) `&& npm run build`.
- Live instrumentation patterns (proven this week): bootstrap the hub in CLI
  via `wp-load.php` + `-d mysqli.default_port=10017` and call services
  directly; read client-site state via LocalWP MySQL (powercreatives :10017,
  powerleads :10041, root/root, db `local`); hub fatals land in
  `app/public/wp-content/debug.log`; fetch client pages directly
  (`?pcm_snap=1` = rules-input view).

## 7. THE NEXT ASSIGNMENT (owner-confirmed GO): full-page editor

`docs/GAP-ANALYSIS-FULLPAGE-EDITOR-20260710.md` — read it in full; 11
code-verified facts, 8 gaps, plan V1→V3. Owner-confirmed decisions: reuse and
enhance the EXISTING SectionModal (never a parallel editor); repurpose the
table row's SquarePen button (today: "Edit on site (WP editor)" link,
`index.tsx:~1056`) into the page editor; inline red/green word-diff (NOT
side-by-side); images visible/safe always, metadata editing in V3.

**⚠ THE LANDMINE (F9, verified in the apply code):** section replacement
units map 1:1 onto [heading, p…] blocks — a RAW unit (image/list) in a
replacement whole-swaps a text block while the page's original between-content
stays → duplication + destroyed paragraphs. Any page-level save MUST strip
raw units from replacements (V1) — images then persist naturally as untouched
between-content.

### V1 — page editor, manual edits (hub + frontend only; NO connector change)
- [ ] BEFORE commit.
- [ ] Hub: `served_inventory()` additionally returns `contentHtml` — a CLEAN
      block-level document assembled from the served content blocks (h/p with
      inner HTML) + `<img>` tags extracted from inter-block gaps, marked
      locked (e.g. `data-pcm-locked`). Do NOT feed raw builder soup to the
      editor.
- [ ] Hub: `save_page_edits(user, site, post, html)` — parse the editor's
      HTML into unit-sections (the `split_unit_sections` logic exists inside
      `attribute_inventory` — refactor it out for reuse, don't duplicate);
      align edited ⇄ baseline sections (1:1 by order when counts match; LCS
      on `level|norm(heading)` keys as fallback); per pair: STRIP raw units;
      unchanged → skip; changed + rule-attributed → `save_section_slice`;
      changed + original → `save_section_rule` with the row's INPUT identity
      (`matchText/matchLevel/matchOccurrence`) + its paragraphs (input==served
      for unruled sections); EXTRA edited sections → `save_section_insert`
      anchored to the preceding section's served heading; FEWER sections →
      honest error ("removing whole sections isn't supported yet"). Leading
      no-heading (orphan) zone: skip with honest note in the response.
- [ ] Endpoint `POST /seo/sites/{id}/content/{post}/page-edits` + trpc route
      (`seo.remoteSavePageEdits`).
- [ ] Frontend: SectionModal gains `mode:'page'` — maximized (~90vw/85vh),
      self-fetches `contentHtml`, TipTap with the ALREADY-INSTALLED image +
      list extensions (images atomic/locked; header note "images are context —
      editable in a later version"); versions dropdown + Ask AI HIDDEN in page
      mode (V2); save → page-edits; keep section mode byte-identical.
- [ ] Frontend: SquarePen on connected v3 rows opens page mode; the WP-editor
      link demotes into the editor header ("Open in WP editor"); local rows
      keep the old link (no engine locally — boundary).
- [ ] Verify: harness 41/41 (no engine change → must stay green) · php -l ·
      tsc 59 · build · LIVE on powerleads (edit two paragraphs + one heading
      in page mode → save → exactly N section rules; images intact; revert by
      editing back).
- [ ] Changelog line + AFTER commit.

### V2 — AI Optimize + inline diff (after owner tests V1)
- Batched per-section calls to the EXISTING `remote_optimize_section`
  (bounded prompts, parallel, per-section failure honest).
- In-house LCS word-diff util (~80 lines, NO new dependency) + two TipTap
  marks (`diffAdded` green / `diffRemoved` red strikethrough); Accept /
  Reject per section + Accept All; accepted sections ride the V1 save path.

### V3 — image metadata (engine v2.3, connector 3.0.2)
- New ADDITIVE rule target `image`: match by normalized `src` (+ occurrence),
  replacement = attribute set (alt/title), serving rewrites ONLY attributes —
  never position/existence. Connector pass + harness fixtures + contract note
  + editor image side-panel. Never image add/move/delete (F9 + builder
  wrappers).

## 8. OTHER OPEN / GATED ITEMS (unchanged)

- **Fleet convergence (owner-gated):** powerstock → 3.0.1 (manual ritual),
  run the Migrate button per site; once the WHOLE fleet reads 3.0.x, ONE
  follow-up commit deletes the legacy override layer (`/override-heading`,
  the prio-1 buffer, `remote_apply_heading_override`) + every pre-3.0 hub
  fallback (scan-headings/scan-content paths, schema v1/v2 push shapes) —
  pre-scoped in the architecture doc's deletions ledger #4.
- Known sharp edge (designed, documented): renaming a page's TITLE strands
  rules keyed on it — they fail safe (original serves) until re-saved. The
  owner hit this once; a "carry edits across renames" feature was floated,
  never ordered.
- Draft→Publish revival (spec on file, paused) · outline de-clutter proposal
  · heading version history (ask the owner if wanted — rides sections).
- Old-arc handovers for deeper history: `docs/HANDOVER-20260709-1300.md`,
  `docs/HANDOVER-20260709-2320.md`, `docs/HANDOVER-20260710-DYNAMIC-ALIGNMENT.md`.

## 9. OWNER TEST CHECKLIST (what he verifies after V1)

1. Table row → pen icon → the page opens in the editor, matching the live page.
2. Edit a paragraph + a heading → Save → live page serves both (Ctrl+F5).
3. Images: visible in the editor, unchanged on the live page after save.
4. Outline agrees with the editor and the live page (served truth everywhere).
5. Edit a heading back to its original → its edit disappears (clean revert).
6. Site-wide (footer/menu) headings: still badge sky, still frame-only.
