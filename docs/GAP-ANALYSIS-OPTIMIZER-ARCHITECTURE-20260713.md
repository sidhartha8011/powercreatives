# GAP ANALYSIS — THE OPTIMIZER SYSTEM (architecture) — 2026-07-13 — AWAITING GO

**Owner order:** a separate system with its own files and its own coding —
add-on per feature, never reading through everything to fix one thing.
Mental model (spec'd in backlog): each purpose = a separate TEACHER;
teachers only add items to the CATALOG; the user's BASKET goes into ONE
optimization order.

## Facts the architecture stands on (verified)

| # | Fact |
|---|---|
| F1 | The platform is a modular monolith: modules auto-discovered from `includes/modules/*/config.php` (21 modules exist); the LAW is config+controller+service per module — never hand-registered routes |
| F2 | The add-on pattern ALREADY EXISTS and is proven: `power-creatives.php:84` glob-loads `includes/modules/*/automations.php` — registries populated by discovery, zero central edits per addition. The teacher registry copies this exactly |
| F3 | SectionModal.tsx is ~97KB TODAY — the owner's "millions of lines" pain is real in this exact file. The optimizer may touch it at TWO seams only (a button + a topic handoff); everything else lives in its own directory |
| F4 | The optimize→red/green pipeline the basket feeds ALREADY EXISTS: `startAiReview(topic)` — the basket's directives ride the `topic` mechanism the instruction field and Revise already use. Zero new write paths; the review law holds by construction |
| F5 | GSC machinery EXISTS: `POST /integrations/gsc/stats`, OAuth flow, `PCM_GSC` (integrations/controller.php:46-51) — the keywords teacher consumes it, never rebuilds it |
| F6 | A `keywords` module already exists as its own domain — the optimizer references keyword data across the boundary (FK-style), never absorbs it (modular-boundary law) |
| F7 | Tunables/checklists must be hub-controlled DATA (standing law; precedents: tier thresholds option, prompt registry) — teacher checklists ship as seeded, editable options, never hardcoded logic |

## The architecture

### Server — NEW module `includes/modules/optimizer/`
```
includes/modules/optimizer/
  config.php                              ← module registration (auto-discovered, F1)
  controller.php                          ← thin REST: GET /optimizer/teachers ·
                                            POST /optimizer/analyze {teacherId,…} ·
                                            GET|POST /optimizer/keywords {siteId,postId}
  service.php                             ← THE ENGINE ONLY: teacher registry, shared
                                            context assembly (content, brand, page type,
                                            GSC via F5, site inventory), checklist DATA
                                            (seeded option), dispatch — NO teacher logic
  teachers/
    interface-pcm-optimizer-teacher.php   ← id() · label() · analyze(ctx): item[]
    class-pcm-teacher-search.php          ← feature 1 (P1 checklists)
    class-pcm-teacher-keywords.php        ← feature 3 (GSC bucket)
    class-pcm-teacher-subtopics.php       ← feature 4 (knowledge graph)
    class-pcm-teacher-interlinks.php      ← feature 5
    class-pcm-teacher-ai-visibility.php   ← feature 2 (web-search runs)
```
- Teachers glob-load exactly like automations (F2). **Adding a feature =
  adding ONE file.** Removing one = deleting one file. No central edits.
- `POST /optimizer/analyze` takes ONE teacherId — the rail's per-purpose
  RE-ANALYZE button is the same endpoint by construction, not a feature.
- **The catalog item — ONE contract for every teacher, forever:**
  `{id, teacherId, found: bool, label, evidence, instruction}` —
  `instruction` is the directive text that rides the basket; `found:false`
  items render in the quiet "nothing to fix" half.
- Teachers CANNOT talk to each other structurally: context in → items out.
  Shared needs (the winner-analysis engine, later) become service-level
  functions BELOW the teachers.

### Frontend — NEW directory `app/src/modules/SEO/optimizer/`
```
app/src/modules/SEO/optimizer/
  types.ts             ← Suggestion, TeacherSection, Basket
  useOptimizer.ts      ← per-teacher results/loading, basket set,
                         analyze(teacherId), buildTopic() → directives text
  OptimizerRail.tsx    ← purpose sections in registry order · found/not-found
                         split · checkboxes · per-section re-analyze ·
                         basket footer → Optimize handoff
  sections.ts          ← teacherId → OPTIONAL custom section body (the
                         keywords teacher needs one; most teachers render
                         the generic item shape = ZERO frontend change)
  KeywordsSection.tsx  ← feature 3's custom body (primary/supporting/bucket
                         + the compact GSC table)
```
- The rail renders the GENERIC catalog item — a new server teacher with no
  special UI needs NO frontend work at all.

### The two seams in SectionModal (the ONLY integration, F3)
1. An **Analyze** button (workbench row) toggles the OptimizerRail (right
   side; mutually exclusive with the review rail).
2. `onOptimize(directives)` → the EXISTING `startAiReview(topic)` (F4).
   The keyword bucket auto-rides every optimize topic once feature 3 lands.

### Feature 6 (client approval card) is NOT a teacher
It consumes the review OUTCOME (per-section decisions + basket provenance
for the purpose grouping) — architecturally the first slice of the
approval-pipeline rails (autonomy roadmap #1). It gets its OWN gap when its
turn comes; the optimizer only guarantees the provenance is serializable.

### Data (MVP needs NO new tables)
- Teacher checklists: seeded option `pcm_optimizer_checklists` (idempotent
  seed, editable — F7).
- Keyword bucket: option map per site+post (the proven page-type-map
  pattern). A table comes only if/when scale demands it — named, deferred.

## Execution plan after GO (owner-approved value order)
Six MVP increments, EACH a full ritual pair (BEFORE commit → build →
verify 88/59/build → changelog → AFTER commit):
1. **SPINE + search teacher** — module skeleton, registry, rail, basket →
   topic handoff, checklist seed. The system is ALIVE end-to-end here.
2. **Keywords teacher** — GSC list + bucket + auto-ride.
3. **Subtopics teacher** — knowledge-graph coverage check.
4. **Approval-card slice** — own gap first (rails dependency).
5. **Interlinks teacher** — in-context FROM-links per GSC relevance.
6. **AI-visibility teacher** — web-search runs, honest brand mapping.

## Regression surface (named)
SectionModal behavior byte-identical until the Analyze button is pressed ·
review machinery untouched (the basket only writes the topic string) ·
no existing module modified (new module + new directory) · module loader
untouched (auto-discovery, F1) · GSC integration consumed read-only.

---

# THE FULL PLAN (owner order 2026-07-13: complete, factual, fishbone-per-thing)

## The GOAL STATE the user sees when the plan is executed
- An **Analyze** button — BLUE OUTLINE pill (white bg, blue border, blue
  text — visually distinct from the FILLED blue Optimize) sitting
  immediately LEFT of the Optimize split button in the workbench row.
  One click = every teacher analyzes with all the inputs it has.
- The **right Analyze rail**: purpose sections in order (Search engine
  optimization → AI optimizations → …), each split into FOUND (tickable
  checkboxes) / NOT FOUND (quiet), each with its own re-analyze button;
  basket footer → ONE optimization → red/green review.
- The **left GSC keyword drawer**: primary + supporting keywords (from the
  SEO fields, settable here), the additional-keywords bucket, days-back,
  related-only, the compact keyword table (+ per row). Bucket keywords
  auto-ride EVERY optimize run.

## Additional verified facts the increments stand on

| # | Fact |
|---|---|
| F8 | `PCM_LLM::invoke_json(messages, schema, options)` EXISTS (core/llm/class-pcm-llm.php:189) — structured per-check verdicts are one call, no parsing hacks |
| F9 | `PCM_LLM::invoke_with_grounding` EXISTS (class-pcm-llm.php:304, Gemini google_search) — the AI-visibility teacher's web-search runs need ZERO new plumbing |
| F10 | `PCM_GSC::sa_rows` is a generic paginated Search-Analytics fetch; `page_stats` (class-pcm-gsc.php:356) already returns per-URL clicks/impressions/position + top `keywords[]` PER PAGE — the interlinks teacher's page↔topic map already exists; the drawer needs one thin `query_stats` (dimensions `[query]` + page filter) reusing `sa_rows` |
| F11 | Per-page keyword fields exist BOTH ways: local meta map `pcm_seo_primary_keyword`/`pcm_seo_meta_keywords` (seo/service.php:48-77, :210-211) and remote fields `seo:keyword`/`seo:meta_keywords` (:852-853) with read/write plumbing — the drawer reuses the seo module's existing paths (modular boundary held) |
| F12 | THE shared table exists: `app/src/components/ui/data-table.tsx` (`DataTable<T>`) — the drawer compacts it, never reinvents |
| F13 | The GSC stats controller pattern (integrations/controller.php:299-351) shows key resolution + property matching — the optimizer consumes `PCM_GSC` the same way, integrations module untouched |
| F14 | PillButton variants are the sanctioned extension point (the `success` variant was owner-ordered the same way) — the Analyze button = ONE new shared `outline` variant (white bg, blue border/text), reusable app-wide |

## The increments (each = ONE full ritual pair; one fishbone per thing)

**I1 — SPINE + search teacher (the system is alive here).**
Server: `includes/modules/optimizer/` — config.php · controller.php
(`GET /optimizer/teachers`, `POST /optimizer/analyze {teacherId, siteId,
postId, html, context}`) · service.php (teacher glob-registry, shared
context assembly: pageType + brand + primary keyword, checklist option
seed `pcm_optimizer_checklists`) · teachers/interface +
`class-pcm-teacher-search.php` (ONE `invoke_json` call: page-type
checklist in, per-check `{id, found, evidence}` out; F8).
Frontend: `app/src/modules/SEO/optimizer/` (types · useOptimizer ·
OptimizerRail · sections.ts) · PillButton `outline` variant (F14) ·
the TWO SectionModal seams (Analyze button left of Optimize; basket →
`startAiReview(directives)`) · trpc-routes entries.

**I2 — keywords teacher + the LEFT drawer.**
Server: `PCM_GSC::query_stats` (thin, reuses `sa_rows`; F10) ·
`POST /optimizer/keywords/stats {siteId, postId, days}` ·
bucket option map `GET/POST /optimizer/keywords` (page-type-map pattern) ·
`class-pcm-teacher-keywords.php` (bucket + primary as catalog items so
they're VISIBLE in the rail too).
Frontend: left `<aside>` drawer (mirror of the right rail) ·
KeywordsSection: primary/supporting inputs (existing seo field paths,
F11), bucket pills, days-back (default 30), related-only checkbox,
compact `DataTable` (F12: keyword/clicks/impressions/position, default
sort impressions desc, noise filter ≥100 removable, + per row).
`buildTopic()` appends bucket keywords to EVERY optimize topic —
one-click AND instruction runs (the seam exists from I1).

**I3 — subtopics teacher.** ONE server file: `invoke_json` — expected
subtopic branches for the page's topic vs what the content covers →
missing ones as items ("add a coverage section about X"). ZERO frontend
work (generic catalog items prove the add-on law).

**I4 — approval-card slice.** Own gap analysis when reached (approvals
rails dependency; the spine already serializes basket provenance).

**I5 — interlinks teacher.** ONE server file consuming `page_stats`'s
existing per-URL top-keywords map (F10): phrases in THIS page's text that
match OTHER pages' top queries → in-context link items
(`{phrase → targetUrl}` + instruction). Cannibalization flag when two
pages share a top query. ZERO frontend work.

**I6 — ai-visibility teacher.** ONE server file: 3–5 simulated questions
→ `invoke_with_grounding` (F9) → winners + citation classification
(own-site vs third-party = the controllability weight) → `invoke_json`
commonalities + honest brand mapping vs brand/GBP data. MVP: single run
per question; sampling/KPI later per backlog.

---

# ADDENDUM 2 — THE BASKET COMPILER + purpose verification (owner orders 2026-07-13, post-I1 live round)

## Facts (verified)
| # | Fact |
|---|---|
| F15 | I1 shipped and works live (owner-verified: analyze → tick → optimize → red/green regenerates to request). The basket is passed VERBATIM (`buildDirectives`, useOptimizer.ts): nothing drops by construction, but merging is delegated implicitly to the rewriting model — with many/overlapping directives, reconciliation happens inside the rewrite, silently and unattributed. The owner's compiler closes a real gap the plan did not have |
| F16 | The review rail prints the MODEL name under each suggestion (`s.genModel`, SectionModal rail row) — the owner ruling: that spot must show the suggestion's PURPOSE (simple bullets from the compiled order) so the user can VERIFY the suggestion fulfills it before Accept/Revise |
| F17 | Rail rows lead with the check LABEL + missing-thing evidence — reads as a fault list. Owner ruling: lead with the ACTION (the instruction — positives), the finding becomes subtext/tooltip |

## The design

**C1 — Positive phrasing (display flip).** Found rows lead with the
instruction (the "do this"); the check name + found-evidence become the
small subtext/tooltip. Wording pass over the checklist seed so every
instruction reads as a clean imperative.

**C2 — THE COMPILER (a new spine stage — exactly one, built once).**
`POST /optimizer/compile {items: [{instruction, teacherId, label}]}` →
ONE call to the model with a strict contract: merge overlaps, resolve
collisions, KEEP EVERY OPTIMIZATION'S INTENT (dropping one is forbidden
and stated so in the contract), output a concise ordered to-do list where
each compiled directive carries its source purposes
(`{text, purposes: teacherId[]}`). The compiled list becomes the
optimization order (the run topic) AND the provenance source. Zero-result
or lost-intent responses are honest failures, never silent.

**C3 — Purpose verification in the review (replaces the model line).**
The review rail row shows the run's compiled directives as simple bullets
— what this suggestion was SUPPOSED to accomplish — so the user verifies
purpose-fulfillment at a glance before Accept/Revise. Phase 1: run-level
(same compiled order shown per suggestion). Phase 2 (later, own step):
the rewriter reports per section WHICH directives it applied → exact
per-change purposes.

**C4 — Purpose pills.** Small pills (SEO / AI / …) on each suggestion;
two-purpose changes show both. Phase 1 from the run's compiled purposes;
phase 2 exact per change (rides C3 phase 2).

## THE ROADMAP TO THE FULL GOAL (sequence locked, each step = one ritual pair)
- [x] I1 SPINE + search teacher (live, owner-verified) + json-contract fix
- [ ] S1 Positive phrasing flip (C1)
- [ ] S2 THE COMPILER + purpose display + pills phase 1 (C2 + C3 + C4)
- [ ] S3 = I2 keywords teacher + the LEFT GSC drawer
- [ ] S4 = I3 subtopics teacher
- [ ] S5 = I4 client approval card slice (own gap first; purpose-grouped per the presentation law)
- [ ] S6 = I5 interlinks teacher
- [ ] S7 = I6 ai-visibility teacher
- [ ] S8 Per-change attribution phase 2 (exact pills + per-change purposes)
