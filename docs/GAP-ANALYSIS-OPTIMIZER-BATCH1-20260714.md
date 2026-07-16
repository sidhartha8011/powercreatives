# GAP ANALYSIS — OPTIMIZER BATCH 1 (S1 phrasing · S2 compiler · S4 subtopics · S3 keywords+drawer) — 2026-07-14 — AWAITING GO

Every implementation point below stands on a VERIFIED fact. Four steps,
built back-to-back, each its own verified commit; one owner browser pass
at the end covers the whole in-editor experience.

## Verified facts (the ground)

| # | Fact |
|---|---|
| B1 | Rail rows lead with check label + finding (OptimizerRail.tsx found-items block); the action text already exists on every item (`instruction`) — S1 is a display flip + a wording pass over the seed in optimizer/service.php `default_checklists()` |
| B2 | The basket compiles VERBATIM in `buildDirectives` (useOptimizer.ts) and hands one topic string to `startAiReview(topic, scope?)` (SectionModal.tsx:975) — the compiler slots between these two points, nothing else moves |
| B3 | The review rail prints the model name at SectionModal.tsx:1744-1745 (`s.genModel`) — the purpose display replaces that line (model moves into the tooltip; genModel data stays consumed, zero dead code) |
| B4 | `PCM_LLM::invoke_json` + the Anthropic law (schema must live IN the prompt, class-pcm-llm.php:126) are proven live — the compiler uses the same call shape with the JSON contract in the prompt, like the fixed search teacher |
| B5 | `PCM_GSC::sa_rows` is PRIVATE static (class-pcm-gsc.php:327) — the drawer's per-query fetch is a new public `query_stats()` INSIDE PCM_GSC (same class, reuses sa_rows; dimensions `[query]` + page filter), never a copy |
| B6 | `get_provider_api_key` lives on the shared PCM_REST_Base (base-controller.php:592) — the optimizer controller inherits GSC key resolution; the integrations module stays untouched |
| B7 | Primary/supporting keywords save through the EXISTING `seo.remoteSaveCell` route (trpc-routes.ts:586: `seo/sites/{id}/content/{post}/cell` with field/value) — fields `primaryKeyword`/`metaKeywords` map at seo/service.php:852-853. READ: `remote_get_inventory` (the editor's own page fetch) gains the two fields additively — a small seo-module payload addition, the module still owns its data |
| B8 | THE shared `DataTable<T>` (components/ui/data-table.tsx:155) ships sorting, per-column `filterDefs`, `defaultSortKey/Dir` — the drawer's table is a compact configuration of it, zero new table code |
| B9 | The editor already holds `page.permalink` (header Open link) — the drawer's "this page" GSC filter uses it; the page-switcher dropdown stays DEFERRED per the MVP cut |
| B10 | The modal's content row starts at SectionModal.tsx:1609 (`flex min-h-0 flex-1`) — the LEFT drawer is an `<aside>` FIRST inside that row, mirror of the right rail; one seam |
| B11 | The subtopics teacher is ONE server file on the proven teacher registry (I1 live) — zero frontend work, by the add-on law |

## The factual plan

**S1 — Positive phrasing.**
Rail found-rows: `instruction` becomes the row text, label+evidence become
the small subtext/tooltip (OptimizerRail.tsx found-items block, B1).
Seed wording pass so every instruction is a clean imperative (service.php).

**S2 — THE COMPILER + purpose verification + pills.**
- Server: `POST /optimizer/compile {items:[{instruction, teacherId, label}]}`
  (controller + service). ONE invoke_json (JSON contract in the prompt, B4):
  merge overlaps, resolve collisions, output
  `{directives:[{text, purposes: string[], sources: int[]}]}`.
  CERTAINTY CHECK server-side: the union of `sources` must cover every
  input index — a dropped intent is an HONEST ERROR, never a quiet loss.
- Frontend: "Optimize selected" → compile → the compiled directives become
  the run topic; `startAiReview` gains an optional `runDirectives` carry
  (B2); the review rail row replaces the model line (B3) with the run's
  purpose bullets + small purpose pills (SEO/AI…, teacher labels as pill
  text source); plain Optimize runs (no basket) show no pills — honest.

**S4 — Subtopics teacher (`class-pcm-teacher-subtopics.php`).**
ONE invoke_json: from the page's heading/topic + content → the expected
core subtopics, each judged covered (evidence quote) or missing; missing →
found-items whose instruction is "Add a coverage section about X …" (B11).
Registers itself; the rail gains the section with zero frontend change.

**S3 — Keywords teacher + the LEFT GSC drawer.**
- Server: `PCM_GSC::query_stats($json, $property, $days, $pageUrl)` (B5) ·
  optimizer endpoints: `POST /optimizer/keywords/stats {siteId, postId,
  pageUrl, days}` (GSC key via B6, property match like the proven
  integrations flow) and `GET|POST /optimizer/keywords {siteId, postId}`
  — the bucket, an option map keyed `site:post` (the page-type-map
  pattern) · seo `remote_get_inventory` additively returns
  `primaryKeyword` + `metaKeywords` (B7).
- Frontend: left `<aside>` first in the content row (B10), toggled by a
  ghost "Keywords" button on the workbench row's left; inside: primary +
  supporting inputs (read B7, save via `seo.remoteSaveCell`), the
  additional-keywords bucket as pills, days-back (default 30),
  related-only checkbox (v1 heuristic: shares a non-stopword token with
  the primary — named, transparent), and the compact `DataTable` (B8:
  keyword | clicks | impressions | position, default sort impressions
  desc, default filter impressions ≥ 100 removable, + per row → bucket).
- AUTO-RIDE: `startAiReview` appends the bucket (shared trpc query cache)
  to EVERY run's topic — one-click, instruction and basket runs alike.

## Regression surface (named)
Review pipeline untouched except the topic string + the display line
(B2/B3) · plain Optimize byte-identical when the basket/bucket are empty ·
integrations module read-only (B6) · seo module: one additive payload
field pair (B7) · GSC calls read-only · section mode untouched.

## CHECKLIST
- [ ] BEFORE = this doc committed, tree clean
- [ ] S1 phrasing flip + seed wording (own pair)
- [ ] S2 compiler endpoint + certainty check + purpose display + pills (own pair)
- [ ] S4 subtopics teacher (own pair)
- [ ] S3 keywords teacher + drawer + auto-ride (own pair)
- [ ] Each pair: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
- [ ] Owner browser pass over the whole batch
