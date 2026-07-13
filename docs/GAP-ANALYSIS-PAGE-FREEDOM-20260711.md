# GAP ANALYSIS — FULL PAGE FREEDOM: removal + image hiding + paragraph consolidation (2026-07-11) — AWAITING GO

**Owner order:** the user must be able to do essentially anything on the
page — including deleting sections AND their images (hidden from serving,
never touched on the site) · deleting an image alone must work · consolidate
the paragraph-rule relic IF it significantly improves things or prevents
weird errors · no dead code, no relics. Every fact verified in code or live
this day. Supersedes GAP-ANALYSIS-SECTION-REMOVAL-20260711 (Option A chosen,
extended with images).

## PART 1 — CURRENT STATE (facts)

| # | Fact | Evidence |
|---|------|----------|
| P1 | **Deleting an image in the editor today is a SILENT NO-OP.** LockedImage blocks dragging only — Backspace deletes the node from the document; the save then strips every image from every path, so nothing changes on the live page. The user sees it "deleted", the site keeps serving it. A dishonesty, not a safety. | SectionModal LockedImage (draggable:false, no delete guard); save strip law |
| P2 | Section removal has no engine word (R1–R6 of the superseded analysis hold: replace/insert exist, merge removes surplus blocks, empty replacement rejected in 4 paths, inserted sections already deletable, the removal guard proved its safety value live 2026-07-11). | run.php:139-152; service.php:3650/3843/4105/4398 |
| P3 | Between-content SURVIVES every block operation by design: the merge fixture asserts wrappers and the between-img stay when surplus blocks are removed. So removing a section's blocks does NOT remove its images — image hiding must be its own mechanism. | run.php "merge removes surplus" (`<div class="col"></div>` survives) |
| P4 | Image rules (v2.3) already carry the perfect identity for hiding: normalized src + occurrence, verify-first, editor set == connector counting set BY CONSTRUCTION (proven offline 2026-07-11), originals stored at creation. Only the ACTION is missing (attrs-only today). | v2.3 contract; [review-findings] proof |
| P5 | **Paragraph-rule creation is DEAD CODE**: `seo.remoteSaveParagraphRule` and `seo.remoteOptimizeParagraph` trpc routes have ZERO frontend callers; the REST endpoints + controller handlers + `save_paragraph_rule` exist only for them. Read-side support (display folding, connector Pass 2 serving, absorb) is alive because legacy rows exist (rule #2 on powerleads post 3). | grep app/src: only trpc-routes.ts defines them |
| P6 | **The absorb law MISSES served paragraph rules — observed live**: absorb deletes paragraph rules matching the section's paragraph TEXTS, but the page editor passes SERVED texts while a paragraph rule is keyed on its ORIGINAL text. When the rule serves (text differs), absorb misses → a section rule and a paragraph rule coexist on the same section — the one-owner law broken in practice. Live proof: restoring post 3's Original created section rule #21 while paragraph rule #2 survived beside it (2026-07-11, later cleaned). | service.php absorb loop (matchText = passed texts); live incident |
| P7 | Heavy rewrites that RENAME headings while changing the section count degrade to remove+add under LCS key alignment (rename detection gap R5). | save_page_edits alignment |
| P8 | Capability gating is one handle (`connector_rules_schema_version`, wire schema 1–4 today); additive bumps are the established pattern (v4 shipped this day; posts without new-target rules keep pushing old shapes byte-identical). | push_rules; v2.3 rollout |

## PART 2 — TARGET

The page editor's save can express ANY text-level transformation (replace /
insert / **remove** — a closed operation set) plus image visibility:
deleting an image in the editor (alone or with its section) HIDES it at
render time — never touched in storage or media. Every destructive save is
listed and explicitly confirmed (the truncation safety stays). One rule
class per concern, zero dead creation paths, one-owner holds everywhere.

## PART 3 — THE GAPS

| # | Gap | Change |
|---|-----|--------|
| G1 | No removal target (P2) | **Engine v2.4, connector 3.0.3, wire schema 5:** target `sectionRemove` — identity = the section identity (heading + level + occurrence + fingerprint), verify-first locate exactly like a replace, then remove the section's blocks. Pass order: heading → replaces → **removes** → inserts → paragraphs → images. Miss = inert + stale, original serves. |
| G2 | No image hiding (P1, P3) | Same release: image-rule replacement JSON gains `"hidden": true` — the image pass REMOVES the matched tag from the buffer (media/storage untouched; un-hide = rule deletes, originals already stored per P4). Connector sanitizer whitelist gains the flag (schema 5 only — a 3.0.2 site never receives it, honest gate). Contract note: v2.3's "never existence" is AMENDED by v2.4 to "existence only by explicit hide rule, never in storage". |
| G3 | Deleting images in the editor is a lie (P1) | `save_page_edits` compares the baseline image set against the edited doc BEFORE stripping: missing images → hide rules; images inside REMOVED sections → hide rules automatically (owner law: goes with the section). The image side panel also gains an explicit **Hide image** button. Restoring a version that contains the image → the hide rule clean-reverts (deletes). |
| G4 | Removal must never be an accident (P2/R4) | Confirm protocol: a save that would remove sections or hide images saves NOTHING and returns the pending list (`needsConfirm`, section names + image srcs); the editor shows the list, the confirmed save carries `confirm: true`. A truncated/degraded document can never confirm itself. |
| G5 | Rename degradation (P7) | After LCS, leftover baseline/edited sections pair positionally IN ORDER as renames (replace path) before anything is classified removed/added — only the true count difference becomes removals/inserts. |
| G6 | Dead creation paths (P5) | DELETE: both trpc routes, both REST routes + controller handlers, `save_paragraph_rule` + `remote_optimize_paragraph` service methods. Read-side stays (legacy rows must serve + display) until fleet convergence — already ledgered. |
| G7 | One-owner hole (P6) | Absorb matches paragraph rules by original text **OR by served replacement output** — a section save then always consumes the paragraph rules it covers. Legacy paragraph rules die naturally on the next touch of their section; the connector's Pass 2 dies with the pre-3.0 fallbacks in the owner-gated convergence cleanup (no migration event needed — this is the consolidation, and it prevents exactly the P6 error class). |

## PART 4 — PLAN (3 pairs, full ritual each, local commits only)

- **Pair 1 `[para-consolidation]`** — G6 + G7 (hub only, no connector, no
  schema change). Ships: dead endpoints gone, absorb airtight, paragraph
  relics on a documented path to extinction. Harness stays 51/51.
- **Pair 2 `[engine-v24]`** — G1 + G2 (connector 3.0.3, schema 5, both new
  behaviors + sanitizers) + harness fixtures (remove: verify-first hit/miss,
  occurrence, between-content survival, ordering; hide: tag removal,
  occurrence, chrome isolation, un-hide absence) + the v2.4 contract note in
  the architecture doc.
- **Pair 3 `[page-freedom-ux]`** — G3 + G4 + G5 (hub save paths + capability
  gates + confirm protocol; editor confirm dialog, panel Hide button,
  delete-detection). LIVE pass on powerleads after its 3.0.3 update: delete
  a section with an image → confirm lists both → live page serves without
  them, image still in the media library → restore the version → both back,
  rules gone.

Fleet: powerleads updates to 3.0.3 (hub package, sha-verified); powerstock
stays on the manual ritual (owner-gated, unchanged).

## RISKS (named)

- A hidden image's occurrence shifts if the client inserts ANOTHER copy of
  the same src BEFORE it → verify-first makes the rule target the wrong twin?
  No: occurrence matching is deterministic (n-th same-src content copy) —
  the rule hides the n-th copy, which after such a client edit may be a
  different physical img. Same accepted semantics as every occurrence-keyed
  rule since v1 (documented, not new).
- Removing MANY sections in one save = many rules, one push each (existing
  per-save push law) — bounded by page size; acceptable, and the confirm
  gate batches user intent into one deliberate action.
- The confirm dialog adds one click to destructive saves — the price of the
  R4 safety, owner-visible.

## OUT OF SCOPE (unchanged)

Image add/move/resize (storage writers — never) · deleting media from the
site (never — hiding only) · whole-page rule model (rejected on facts,
prior analysis) · removing the connector's paragraph pass before fleet
convergence (ledgered, owner-gated).
