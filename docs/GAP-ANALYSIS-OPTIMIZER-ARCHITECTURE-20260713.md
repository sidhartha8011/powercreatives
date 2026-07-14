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

## CHECKLIST (architecture approval)
- [ ] BEFORE state = this doc committed, tree clean
- [ ] Owner GO on the architecture
- [ ] Then increment 1 (SPINE + search teacher) starts as its own pair
