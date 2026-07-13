# GAP ANALYSIS — SECTION REMOVAL IN THE PAGE EDITOR (2026-07-11) — AWAITING DECISION

**Owner report:** deleting a lot of content, or heavy rewrites, hit
"Removing whole sections isn't supported yet — nothing was saved." This must
become possible. Owner floated: drop per-paragraph edits since the page
editor covers them. Facts + implementable options below, decision is the
owner's.

## PART 1 — WHY REMOVAL IS BLOCKED TODAY (facts, all code-verified)

| # | Fact | Evidence |
|---|------|----------|
| R1 | Every optimization is a RULE anchored to content that EXISTS on the original page: `section` (replace, matched by heading + fingerprint), `sectionInsert` (add, anchored to an existing heading), `paragraph`, `heading`, `image`. **There is no rule target that means "this section does not serve".** Removal isn't a bug in the save path — the ENGINE has no word for it. | rule targets; connector apply passes |
| R2 | The engine already expresses PARTIAL deletion: a replacement with fewer units than the original section removes the surplus blocks whole (harness: "section merge removes surplus"); an empty-bodied section (heading only) is legal ("section empty body"). What it cannot express is removing the WHOLE section incl. its heading — every save path rejects empty replacements (`pcm_seo_rule_empty`, 4 places; the slice path deletes only `sectionInsert` rules on empty). | run.php:147,174; service.php:3650,3843,4105,4398 |
| R3 | `sectionInsert` sections (content ADDED by us) are already fully removable — an empty save deletes the rule. Only ORIGINAL-page sections can't be removed. | save_section_insert / save_section_slice |
| R4 | The removal guard in `save_page_edits` is also a SAFETY device, proven the same day it mattered: on 2026-07-11 a degraded snapshot (site's loopback failing → bare-content fallback) put a GUTTED document into the editor; a save of it would have looked exactly like "the user deleted the comment area and menus". The guard refused, zero data lost. **Any removal support must distinguish intent from truncation.** | incident, changelog [modal-polish-2] |
| R5 | The "rewriting too much" variant of the error: when a heavy rewrite RENAMES headings AND changes the section count in one save, alignment falls back to LCS on heading keys — a renamed section matches nothing and is classified as removed+added. With counts EQUAL, renames align positionally and save fine (proven live). | save_page_edits alignment; V1 live pass |
| R6 | Removing a section's TEXT does not remove its between-content: images/forms/widgets in that span are not blocks and stay on the page (the same law that keeps images safe on every save — F9). True for replaces today and for any remove mechanism built on the same mapping. | connector apply mapping; image law |

## PART 2 — THE OPTIONS

### Option A — additive `sectionRemove` target (engine v2.4, connector 3.0.3) — RECOMMENDED

One new rule target, same laws as everything else:

- **Rule:** `sectionRemove` — identity = the section's existing identity
  (normalized heading + level + occurrence + paragraph fingerprint).
  Serving: locate exactly like a replace (verify-first — heading AND
  fingerprint must match, else the rule is inert and the ORIGINAL serves),
  then remove the section's blocks (heading + paragraphs). Between-content
  (images/forms) stays — R6, honest and F9-safe.
- **Pass order:** heading pass → section replaces → **removes** → inserts →
  paragraphs → images (removes before inserts so an insert anchored on a
  neighbor still lands; anchored-on-the-REMOVED-heading inserts go inert +
  stale, honest).
- **save_page_edits:** FEWER sections stops being an error. Per missing
  baseline section: rule-born insert → delete its rule (exists today, R3);
  whole-rule-owned → delete the owning rule AND write `sectionRemove` on the
  ORIGINAL identity from the rule's stored context; original section →
  `sectionRemove` on the row identity. One-owner holds (a remove absorbs the
  section's other rules, same absorb law as replaces).
- **The R4 safety gate:** a save with removals returns them as a PENDING
  list; the editor shows "You're removing N sections: … " with explicit
  confirm; the confirmed save carries `confirmRemovals`. A truncated
  document can never confirm itself — today's incident stays impossible.
- **Undo:** versions dropdown — restore any earlier version (the sections
  reappear in the doc) → save → each `sectionRemove` rule clean-reverts
  (deletes) because its section is present again. Symmetrical with every
  other revert law. Nothing is ever rewritten on the client site.
- **R5 refinement in the same pair:** after LCS, leftover baseline/edited
  sections pair up positionally (in order) as RENAMES before anything is
  classified removed/added — heavy rewrites stop degrading to
  remove+insert.
- Footprint: connector remove pass + harness fixtures + schemaVersion 5
  gating (additive — v1–v4 payloads byte-identical), hub routing + confirm
  flag, editor confirm dialog. Fleet: powerleads (self/manual update),
  powerstock ritual. **Effort: one full pair, same size as the image pair.**

### Option B — whole-page rule (owner's floated model: one rule serves the whole page)

Facts against, each concrete:

- **Blast radius vs the core law.** Rules verify-first against the original.
  One page-level rule means ONE fingerprint for the whole page: the client
  fixing a typo in any paragraph makes the ENTIRE page's optimization go
  stale at once (all-or-nothing honesty), or we weaken verification —
  against the "never a wrong swap" law the product is built on.
- **Between-content destruction.** A whole-page swap must replace the entire
  content region — including comment forms, widgets, builder wrappers,
  images (R6 at page scale). That's the F9 landmine as a feature. The
  per-section model exists precisely because these must survive.
- **Granularity losses.** Per-section versions, AI accept/reject per
  section, per-section stale honesty, the absorb/one-owner ecosystem — all
  become page-global or die.
- **Not additive.** This is an engine REWRITE (new serving model, migration
  for every existing rule on the fleet), not a v2.x extension.
- What it buys: deletion becomes trivial and the mental model is simpler.
  **Verdict as senior developer: the costs are structural, the benefit is
  Option A's feature. Not recommended.**

### Option C — retire per-paragraph editing (owner's second point) — COMPATIBLE ADD-ON

Fact: `paragraph` rules are a pre-section-era mechanism; the section/page
editors already absorb them on save (interaction law). Retiring means:
stop CREATING them in the UI (the per-paragraph edit affordances), keep
serving existing ones until each is absorbed by a section save, then the
target dies with the pre-3.0 fallbacks in the owner-gated fleet-convergence
cleanup. **This simplifies the UI but does NOT enable removal** — it is
orthogonal to the error. Can ride along as a small extra pair if ordered.

## PART 3 — RECOMMENDATION

**Option A + the R5 rename refinement**, with the confirm gate (R4). Option
C optionally after, as UI cleanup. Option B rejected on the facts above.

Owner test after A: delete two sections in the page editor → confirm dialog
names them → save → live page serves without them (images/forms in that area
untouched) → restore via the versions dropdown → sections serve again, the
remove rules are gone.
