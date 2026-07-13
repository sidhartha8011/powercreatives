# GAP ANALYSIS — FULL-PAGE EDITOR + AI DIFF (2026-07-10) — AWAITING GO

**Owner order:** repurpose the row's edit button into a full-page editor ·
REUSE and enhance the existing small editor (never a parallel new one) · AI
Optimize produces an inline red/green word-diff (code-review style) with
accept per change · images editable incl. dynamic metadata · senior grade,
zero duplication. Every fact below verified in code this day.

## PART 1 — CURRENT STATE (facts)

| # | Fact | Evidence |
|---|------|----------|
| F1 | The button to repurpose exists: each SEO row shows (on hover) an Eye (preview modal) + a **SquarePen "Edit on site (WP editor)"** external link (`row.editUrl`) | `index.tsx:1056` |
| F2 | That button today sends users to the WP editor — i.e. to SOURCE editing, the mechanism we deliberately killed for headings this week. Repurposing it into the dynamic full-page editor is philosophically consistent, not just convenient | routing law / v2.2 |
| F3 | The preview modal is display-only (authenticated served HTML in an iframe; open-in-new-tab + close) | `index.tsx:1698-1739` |
| F4 | The small editor (SectionModal) is a TipTap instance: always-editable, select-text toolbar (B/I/U/Link/H1/H2/bullets), Ask-AI instruction row + Re-write, per-section version history with delete, Acceptera/Ångra, click-outside saves, and THREE save paths that all exist server-side: whole-section `replace`, `insert`, and `slice` (one unit range of an owning rule) | SectionModal.tsx; controller `remote_save_section_rule` |
| F5 | **TipTap image + highlight + table extensions are ALREADY installed** (`@tiptap/extension-image`, `-highlight`, `-table`, `-typography`…) — the full-page editor needs no new dependency for content richness | app/package.json |
| F6 | **No diff library exists** in the app | package.json grep |
| F7 | The hub already fetches the FULL SERVED page and the FULL RULES-INPUT page (dual snapshot views, cached, version-stamped) — the complete page content the editor needs is already flowing; only its delivery to the frontend is row-shaped (headings+nodes), not region-shaped (one HTML block) | `served_inventory()` |
| F8 | Per-section persistence is complete and battle-tested: identity keys, fingerprints, absorb + one-owner laws, versions, rollback, clean revert, honest stale | this week's P1–P3 + harness 41/41 |
| F9 | **ENGINE HAZARD (verified in the apply code):** a section replacement's units map 1:1 onto the original [heading, p…] blocks; a RAW unit (image, list) that lands on a mapped position **whole-swaps a text block**, while the page's original between-content (images) stays in place — a naive full-page save that includes a section's images in its replacement would duplicate the image AND destroy a paragraph | connector `pcm_conn_apply_section_rule` mapping loop |
| F10 | AI per-section rewrite exists (`remote_optimize_section`, staged, `{{topic}}` instruction channel); there is NO whole-page AI path | service.php |
| F11 | The engine has NO image target — images are untouched-by-construction at serve time; `<img>` alt/title cannot be edited by any current mechanism | rule targets: paragraph/heading/section/sectionInsert/anchorText/href(reserved) |

## PART 2 — TARGET

One editor component (the enhanced SectionModal) with two entry modes:
- **Section mode** — exactly today's behavior (unchanged).
- **Page mode** — opened by the repurposed row button: near-full-screen, the
  whole served CONTENT region editable (headings, paragraphs, lists; images
  visible), **AI Optimize** renders an inline word-diff (red strikethrough =
  removed, green = added) with **Accept / Reject per section + Accept all**;
  every accept persists through the EXISTING per-section rules (replace /
  slice / insert) — page-level editing, section-level storage, engine
  untouched. Images: metadata (alt/title) editable dynamically via a new,
  narrow engine target; image position/deletion out of scope (F9 + builder
  wrappers).

## PART 3 — THE GAPS

| # | Gap | The change |
|---|-----|-----------|
| G1 | Editor is section-sized (F4) | SectionModal gains `mode:'page'`: maximized layout, StarterKit + already-installed image/list/typography extensions; images rendered read-only in V1 (selectable, not movable/deletable) |
| G2 | No page-shaped content delivery (F7) | Inventory response gains `contentHtml` (served content region, chrome-stripped) + per-section unit ranges so the editor can map DOM regions ⇄ section identities client-side. No new fetches — same cached snapshot |
| G3 | No page-shaped SAVE; naive save is DESTRUCTIVE (F9) | New hub path `save_page_edits`: slices the edited HTML back into sections with the SAME parser that defines identity, diffs each against the served baseline, and routes each changed section through the EXISTING save paths (replace / slice / insert; deletions = section rule with removed blocks). **Images/raw units are STRIPPED from replacements in V1** — they persist naturally as between-content, which defuses F9 without touching the engine. Unchanged sections produce no writes. One push, one rollback snapshot |
| G4 | No page AI (F10) | Page mode's AI Optimize = batched per-section calls to the EXISTING `remote_optimize_section` (bounded prompts, parallel, per-section failures honest) — no new AI plumbing |
| G5 | No diff (F6) | Small in-house word-level LCS diff util (~80 lines, zero new dependencies) + two TipTap marks (`diffAdded`/`diffRemoved`, green / red-strikethrough). Accept per section = strip marks and keep green; Reject = restore baseline for that section's range. Accepted sections save via G3 |
| G6 | Button points at source editing (F1/F2) | SquarePen repurposed: opens page mode (connected sites, v3 connector). `editUrl` link demoted into the editor's header ("Open in WP editor") so the escape hatch survives |
| G7 | No image metadata mechanism (F11) | **V3, engine v2.3 (additive):** new rule target `image` — match by normalized `src` (+ occurrence), replacement = attribute set (alt/title), serving rewrites ONLY attributes on the matched `<img>` (never position/existence). Connector pass + harness fixtures + editor's image-click side panel |
| G8 | Version history is per-section | UNCHANGED by design — page saves record versions per touched section (rides G3 through the existing paths). A "page version" concept is explicitly out of scope |

## PART 4 — PLAN (pairs, full ritual, local commits only)

- **V1 — page editor, manual:** G1 + G2 + G3 + G6. Ships: open page → edit
  text anywhere → save → per-section rules land; images visible/read-only.
- **V2 — AI + inline diff:** G4 + G5. Ships: AI Optimize → red/green marks →
  Accept per section / Accept all → rules land. (The headline feature.)
- **V3 — image metadata:** G7 (connector 3.0.2 + fixtures).
- Verification per pair: harness ALL GREEN (V3 adds image-target fixtures) ·
  php -l + extracted template lint · tsc 59 baseline · build · live pass on
  powerleads.

## PART 5 — RISKS (named)

- **F9 is the landmine** — defused in V1 by stripping raw units from
  replacements (images can't be edited, so nothing is lost), and only ever
  revisited if image REPOSITIONING is someday ordered (engine mapping change,
  not planned).
- Whole-page AI cost/latency: per-section batching bounds each prompt; a slow
  section fails alone, honestly.
- Diff quality on heavy rewrites degrades to "everything changed" for that
  section — accept/reject granularity (per section) keeps that usable.
- Very large pages in one TipTap document: bounded — content region only
  (chrome stripped), and served pages of this fleet are far below the size
  where ProseMirror struggles.

## OUT OF SCOPE

Image add/move/delete (F9 + builder wrappers) · page-level version history
(G8) · local hub posts (no engine — boundary) · side-by-side view (owner
chose inline diff; storage model agrees) · meta fields (already editable in
the table).
