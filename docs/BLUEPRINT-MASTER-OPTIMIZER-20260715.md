# BLUEPRINT MASTER — THE OPTIMIZER — 2026-07-15

> **THE ONE DOCUMENT.** Everything the Optimizer is and will be lives HERE.
> Nothing may be removed — features are appended, statuses are updated,
> nothing is ever lost. New features land in §10 (THE APPEND LOG) first,
> then get folded into their section when built.
> Sources merged on creation: GAP-ANALYSIS-OPTIMIZER-ARCHITECTURE-20260713
> (THE PLAN, untouched), GAP-ANALYSIS-RESEARCH-SPINE-20260714, backlog.md
> (spine/injection/approval/KG/interlink/Ask-AI specs),
> RESEARCH-CONTENT-ANALYSIS-20260713, HANDOFF-KEYWORDS-LANE-20260715,
> owner sessions 2026-07-13 → 2026-07-15.

---

## 1. THE MENTAL MODEL (owner law — stated so it can't be misread)

- Each purpose is a **SEPARATE TEACHER (researcher)** — never a committee.
  Separate professionals going out and researching **in parallel**.
- Teachers never talk to each other. They only add items to **THE CATALOG**.
- The user selects/deselects from the catalog — **checkboxes, never
  accept/reject** (suggestions get merged; accept/reject belongs to the
  resulting content changes only).
- The selection goes as a **BASKET** into **ONE optimization run** —
  its content decided entirely by what the user put in the basket.
- Every AI change lands through the **red/green review** (accept/reject
  per section; grey own / amber edited / sky added lane colors).
- **THE FORMULA**: traffic through magically good content = real research
  (SERP reality + proven demand + AI-engine reality) × real facts (the
  business record) × the keyword hierarchy — never invention, never
  stuffing.

## 2. THE PIPELINE (the spine — built, live)

```
Analyze (one click, every teacher parallel)
  → THE CATALOG (per-purpose sections: FOUND tickable / NOT-FOUND quiet)
    → THE BASKET (user's selection)
      → THE COMPILER (ONE reconciliation call — merge overlaps, resolve
        collisions, certainty contract: no intent may drop, ever)
        → ONE OPTIMIZATION RUN (per-section, bounded pool of 4)
          → RED/GREEN REVIEW (identity anchors, content gate,
            accept/reject/revise per section, versions = full undo)
```

- Teacher registry: **one file per teacher** in
  `includes/modules/optimizer/teachers/` — glob-discovered,
  self-registering. Adding a purpose = adding a file. Nothing central
  ever changes. (THE add-on law.)
- Catalog item contract (one shape, forever):
  `{ id, teacherId, found, label, evidence, instruction, source? }`
  — `source` = which integration/engine answered (owner provenance law
  2026-07-15). Items with no `instruction` are informational — they
  render but can never tick into the basket.
- Compiler: single-item bypass (no no-op LLM hop); every input index must
  appear in the output's sources union or the call THROWS.
- Review integrity: `data-pcm-review-id` heading anchors stamped at t0
  (ordinals never trusted after), `canonicalAiHtml` content gate (no raw
  model output enters the pipeline), the effective-content oracle
  (untouched → AI's clean HTML; edited → user's words win).

## 3. THE FOUNDATION — THE CONTEXT PACKAGE (built, live 2026-07-15)

Built ONCE in the controller, handed to EVERY teacher, the compiler, and
(as prompt vars + append law) the rewriter:

- **keywords** `{primary, supporting[], additional[]}` — from the
  editor's LIVE state (the html's source-of-truth law; unsaved edits
  count). Server fallback: additional ← the stored bucket.
- **business** — server-resolved (siteId → brandId → GBP snapshot +
  manual overrides): name, address, phone, website, category, rating,
  reviews, hours, types, description. Never trusted from the client.
- **pageType** — general/local/service/blog/product/landing (stored
  per page; picks the checklist).
- **pages[]** — the site's other pages (interlink map).
- ONE formatter (`context_block`) renders it for every prompt — one
  source of truth; empty sections render NOTHING (no hallucination bait).
- **Tunables are hub DATA** (`pcm_optimizer_research` seeded option):
  onpage thresholds, striking-distance window, SERP topN, mention engine
  cap + money-question patterns. Never code.

## 4. TRANSPARENCY & KEYS (built, live 2026-07-15)

- **THE PEEK**: every analyze returns `contextUsed`; the rail section's
  info icon shows read-only exactly what the run was given (keywords,
  business fields, page type, pages count, model/provider). The owner's
  confirmation tool until prompts become editable Templates.
- **Source labels**: data-driven items say where the answer came from —
  `via ahrefs`, `via gsc:stored (2026-07-12)`, `via openai:gpt-4o`.
- **Keys live in the Integrations module** — every tap resolves per
  provider per user (`ahrefs`, `gsc`, `google`, `openai`, `anthropic`,
  …). No key = an HONEST named error with the fix in the message.
  The AI panel scales with keys: add a key, gain a voice.

## 5. THE RAIL (built, live)

- TWO top groups (owner taxonomy ruling 2026-07-14): **Search
  optimization** · **AI optimization**. Teachers declare `group()`.
  Overlap between groups is FINE — the compiler reconciles.
- Per purpose-section: FOUND (tickable, pre-selected) / NOT FOUND
  (quiet, green check) / its own RE-ANALYZE button / its own failure
  row with retry (a slow or failed purpose fails ALONE).
- POSITIVES law: the row says what TO DO; the finding is quiet subtext.
- An empty result is STATED, never a blank section.
- Analyze button: blue OUTLINE pill left of the filled Optimize.
- PENDING FOLD-IN: purpose-display cleanup (order shown once at rail
  top, rows slim to pills — gap 6c9b3f9).

## 6. THE RESEARCHER ROSTER

### Search optimization group

| Researcher | Status | What it does | Data tap |
|---|---|---|---|
| **search** (page-type checks) | LIVE | Page-type checklist judged with evidence — contact early, CTA, USP as facts, social proof, plain language, intent-answered, scannability, natural flow. Checklists = seeded editable option (`pcm_optimizer_checklists`). | LLM on content + context |
| **onpage** (placement) | LIVE 07-15 | DETERMINISTIC, zero LLM: word count vs minimum, per-keyword density + stuffing guard (all roles), primary in top heading / first paragraph, supporting in SOME subheadings (hierarchy law). | pure measurement |
| **subtopics** (topic coverage) | LIVE (re-anchored 07-15) | The core topic IS the primary keyword when set (invention killed); maps the 4-7 subtopics a complete page covers; missing ones = coverage-section items. | LLM on content + context |
| **serp** (SERP reality) | LIVE 07-15 | The REAL top organic results for the primary keyword → format / coverage / angle gaps vs our content. | Ahrefs serp-overview (`via ahrefs`) |
| **demand** (proven demand) | LIVE 07-15 | Striking-distance queries (default #4-20, impressions-desc) the page already ranks for but never says → weave-in items. Reads the stored GSC rows the drawer maintains — honest stored-label. | GSC (`via gsc:stored (date)`) |
| **interlinks** | LIVE 07-15 (FROM-half) | In-context links FROM this page: anchor must exist VERBATIM (server-verified), one link per target, descriptive anchors, never self-link. Relevance = GSC page→queries map when key exists (`via gsc`), else titles (`via page titles`). | GSC page_stats / pages list |

### AI optimization group

| Researcher | Status | What it does | Data tap |
|---|---|---|---|
| **answerability** | LIVE 07-15 | What gets pages QUOTED by AI: direct answer in first paragraph, question-form headings, liftable standalone sections, FAQ with real money questions, plain definitions. | LLM on content + context |
| **mention** (THE PANEL) | LIVE 07-15 | Multi-engine from birth: the money questions (tunable patterns) to EVERY keyed provider — one text model each, SEQUENTIAL (2-worker law), google grounded via web search. Verdict per engine: recommended competitors + whether OUR brand is mentioned (deterministic match, never self-report). Engine failure = its own visible row. | every keyed AI provider (`via provider:model`) |
| **facts** (entity/fact authority) | LIVE 07-15 | Judged against the REAL business record: NAP present/consistent, who/what/where plain, concrete numbers over adjectives, no unverifiable claims, one consistent identity. | LLM + business record |

## 7. THE RUN SIDE (rewrite) — built, live

- **THE KEYWORD RIDE**: primary + bucket join EVERY optimize run as
  context ("incorporate naturally, never stuff").
- **THE KEYWORD HIERARCHY LAW** (placement follows ROLE): PRIMARY threads
  the page (headings + body) · SUPPORTING in some headers and some text ·
  ADDITIONAL light touch, ≈ one paragraph max each.
- Business grounding append law (rewriter): name, phone, address,
  category, hours, description reach the model even on templates that
  predate the placeholders; `{{business.*}}` vars in all field prompts.
- Scope selection (a text selection narrows the SAME review), Revise
  per section with note, Accept-All / OK finish paths, versions.

## 8. THE KEYWORD DRAWER (keywords lane — other developer)

- V2 LIVE: smart Keywords button (primary · +count · live density%),
  taper pair layout, SELECTED table with P/S/A roles + primary-demotion,
  live uses/density, Ahrefs volumes (cache-first, manual triggers, hub-
  global 30-day pool), Ranking tab (GSC + Δ compare, stored-labeled) +
  Ideas tab, optimistic bucket add/remove.
- IN PROGRESS (his lane): **keyword injection** — selected rows become
  selectable; Optimize button transforms to "Insert keywords"; ONE run
  weaves exactly the selected keywords per the hierarchy law.
- HIS LANE NEXT: phrase-match keyword discovery (smarter than the v1
  related-token heuristic) · domain-wide related keyword scan.

## 9. PLANNED — NOT YET BUILT (nothing here may be lost)

1. **Per-change attribution** — the rewriter reports applied directives
   per section (separate structured reply field; the content gate strips
   inline metadata) → exact purpose pills per change. (Plan item 6.)
2. **Knowledge-graph insert + link-mode** — Insert-menu item: subtopic
   coverage section; per subtopic AUTO mode: supporting page EXISTS →
   teaser + real link · MISSING → real content block + logged as a
   supporting-page IDEA (free content plan). No dead links ever, no bare
   link lists. Subtopics derive from REALITY (winners + GSC). Also the
   FIX ACTION for the subtopics researcher. (Needs interlink machinery.)
3. **Entity / Knowledge-Graph mapping** (parked owner idea) — Google KG
   Search API (real entity ids) + Natural Language API (entity
   extraction w/ salience): page → top entity + sub-entities → feeds the
   knowledge-graph feature → eventually automated identify-and-generate.
4. **Interlinks: the TO-direction** — scan OTHER pages for this page's
   primary keyword, propose links FROM them TO here (the half that ranks
   THIS page; rides the per-page rule machinery). Plus cannibalization
   flags (two pages competing for one term) as first-class items.
5. **Reality-derived checklists + `category`/`brand` page types** — the
   checklist DATA derived from winner/GSC analysis instead of seeded
   assumptions.
6. **Templates exposure** (owner-deferred) — researcher + compiler
   prompts become editable Templates (module=seo pattern, fork-on-edit);
   the peek becomes the preview pane. MUST include the seed-version
   upgrade step (insert-once trap: existing installs keep old rows).
7. **Client approval card** (owner ruling: LAST, after content work) —
   shareable READ-ONLY link, the same red/green view section by section,
   changes GROUPED BY PURPOSE ("1. Organic search optimization", "2. AI
   recommendation optimizations", …), Approve/Comment; first slice of
   the approvals rails (seo_staged_changes + asset-type adapter registry
   + Before/After cards) — never parallel machinery.
8. **Ask AI — the document advisor** (owner-parked) — side panel Q&A
   about the page; every answer carries "Apply as optimize instruction"
   handing off to the red/green review. Advisor ≠ hands.
9. **AI-mention Part 2 refinements** (deferred at MVP cut): multi-run
   sampling, citation weighting (own-site vs third-party =
   controllability), mention-rate KPI over time.
10. **Demand live-fetch path** — the demand researcher currently reads
    the drawer's stored rows only (one data path, honest label); a live
    GSC pull inside the researcher is a possible later upgrade.
11. **SERP tap extensibility** — Ahrefs is THE tap today; a dedicated
    SERP provider (SerpApi/DataForSEO) can be added as a new Integrations
    provider entry if deeper SERP data (content scraping, PAA) is wanted.
12. **Keyword injection editor hookup** — the two-line Optimize-button
    transform in SectionModal lands through the optimizer lane when the
    drawer's selection state is ready (hard-lanes ruling).

## 10. THE APPEND LOG (add every new feature idea HERE first — never lose anything)

- 2026-07-15 · Document created. All features above merged from every
  prior plan/spec/gap doc. Statuses current as of commit `775054b`.
- 2026-07-15 · **THE SELECTION-SCOPED ACTION LAW (owner ruling).** EVERY
  action button (Optimize, Generate, all) acts on what is CURRENTLY
  SELECTED, and on EVERYTHING when nothing is: ticked keywords → the run
  uses exactly those; selected text → exactly that content (the existing
  scope law); both → both constraints; NOTHING → ALL the page's keywords
  (primary + supporting + additional) across the ENTIRE content. With
  keywords ticked the button TRANSFORMS into the keyword-optimization
  button for that selection. **"Insert into content"** is a NAMED run
  type — the frequent light touch: weave the chosen keywords naturally
  into the existing text per the hierarchy law, red/green as always. The
  user picks WHICH and HOW MANY keywords by ticking drawer selected-table
  rows. Supersedes §8's injection-button detail where they differ.
  Gap: GAP-ANALYSIS-KEYWORD-SELECTION-ACTIONS-COUNTRY-20260715.
  **[BUILT 2f6198a — keyword half live: drawer checkbox backbone (one
  upward contract) + Insert keywords (N) + all-roles ride + text-selection
  narrowing. Generate-button scoping = a later fold.]**
- 2026-07-15 · **AHREFS COUNTRY RESOLUTION (owner order).** Volumes/SERP
  must know WHERE the site is: resolve from the site's brand (GBP
  address) when possible; DEFAULT TO SWEDEN when no location resolves —
  the default is hub-editable DATA, never code. The volume cache key
  gains the country so one market's numbers never serve another's.
  (Today everything silently defaults to the US — same gap doc.)
  **[BUILT dd1d856 — THE SMART COUNTRY CHAIN live: brand address → quick
  AI language check on a content sample → hub default `pcm_kw_default_country`
  seeded 'se'; per-site cached with source; market-keyed volume cache,
  legacy US keys deleted; no silent 'us' default anywhere.]**
- 2026-07-15 · **REVIEW DIFF CONSOLIDATION (owner order).** Past a
  tunable threshold of adjacent word-level strike-throughs (~15), the
  section's red/green DISPLAY collapses to ONE struck block + ONE green
  block — a section-rewrite view instead of word confetti. Display layer
  only (word-diff rendering, editor lane); accept/reject semantics and
  the content pipeline unchanged. Own gap when its turn comes.
- 2026-07-15 · **THE FOUR VALUE LEVERS (owner: "extremely good stuff",
  order locked — SERP upgrade FIRST, results loop SECOND):**
  1. **SERP researcher analyzes the winners' actual CONTENT** — today it
     infers from TITLES only ("the SERP researcher is stupid right now" —
     owner). Use the existing scraper to fetch the top results' real
     pages; coverage/format/length gaps become FACTS (their sections,
     their word counts, their proof elements vs ours). NEXT UP.
  2. **THE RESULTS LOOP** — stamp every optimization run on the page's
     timeline; show GSC position/impressions BEFORE vs AFTER ("optimized
     July 15 → #8.4 → #5.2"). Proves the product's ROI, and produces the
     calibration data the future points/weighting system needs. SECOND.
  3. **Site-wide audit** — rank ALL the site's pages by striking-distance
     potential (close rankings × impressions × thin content) so the
     agency knows WHICH page to optimize first; opens the editor from
     the list. (Planned, after 1-2.)
  4. **Delivery verification** — post-run check that every ticked
     directive was actually applied per section (same machinery as
     per-change attribution §9.1). (Planned.)
- 2026-07-15 · **POINTS/WEIGHTING = the future senior version (owner):**
  suggestions carry an impact score (30- or 100-point scale discussed);
  v1 basis must be honest data (impressions-derived where data exists,
  flat hub-tunable values where not, basis always visible); the results
  loop (#2 above) later CALIBRATES the weights from what actually moved
  rankings. Parked by owner — do not build before the loop exists.
- 2026-07-15 · **RAIL + REVIEW SIMPLIFICATION (owner order, active UX
  round):** (a) checkbox row text smaller, never big/black, must NEVER
  overflow the rail — full text readable via the disclosure; (b) better
  GROUP names the user actually understands; (c) the red/green review's
  purpose pills are noise ("weird pills") — collapse to the TWO purposes
  the user knows: SEO / AI (detail one tap deep); (d) review-side text
  also oversized. Supersedes the queued round-2 cosmetics
  (title-contract/rename) where they overlap.
- 2026-07-15 · **REVIEW PURPOSE LABELS + CLICK-TO-VERIFY (owner order).**
  The accept step shows, per changed section: the SEO/AI pill + a light
  grey ITALIC line of the plain CATEGORY names the change serves
  ("untapped searches, internal links, topic coverage") — the WHY next
  to the WHAT (the directive bullets stay). Clicking a category SCROLLS
  to the section it optimized so the user verifies the writing actually
  does what the purpose claims ("this is for internal linking — let me
  check"). Data exists: compiled directives carry purposes (teacherIds)
  → map to the plain category names. An SEO inside the platform connects
  purpose → text instantly, and can judge "this is NOT how it should be
  → revise".
- 2026-07-15 · **REVISE FIDELITY — the human-editor contract (owner
  order, defect-grade).** TODAY: a targeted revise note ("change the
  phone number to X") rewrites the WHOLE section — ~80% of the text lost,
  phone changed. REQUIRED: revise behaves like a professional human
  editor — understand the INTENT AND SCOPE of the note dynamically
  (never hardcoded cases): a targeted request changes EXACTLY that and
  reproduces every other sentence VERBATIM; a broad request ("make this
  more selling") may rewrite broadly. Zero hallucination tolerance.
  Implementation direction: a fidelity contract in the revise prompt
  (scope-first: state what the note permits touching, forbid all else,
  verbatim-preservation law) + a server-side RETENTION CHECK (diff the
  result vs the draft; a targeted note with a collapsed retention ratio
  = honest retry/failure, never a silent 80% loss). The editor-lane
  revise path (SectionModal reviseSection) + the section prompt's
  optimize mode are the surfaces.
- 2026-07-15 · **META-ADS-STYLE EDIT MODAL (owner order — REMIND).** The
  page editor becomes the one place to edit EVERYTHING about a page,
  like opening an ad set in Meta Ads: (a) a CONCISE left sidebar (the
  keyword-drawer presentation pattern) listing the site's pages/posts —
  toggle between pages without leaving the modal; (b) top TABS switching
  the content area between CONTENT EDIT (today's editor) and PAGE DATA /
  METADATA EDIT — all fields as a simple field editor with per-field
  optimization visible. One modal, whole page, whole site.
- 2026-07-15 · **RESULTS-FIRST (owner order — REMIND).** Meta Ads leads
  with results; SEO tools lose that. (a) Reorder the SEO table columns so
  RESULTS come early — you see what you're working with. (b) Grow the
  results loop into the growth view: per-page "is it growing?" +
  site-level rollup + a simple graph annotated with WHEN changes were
  made and WHAT they were (one row per change, date + summary — the
  Google Ads change-history pattern). Full everything-history is too
  advanced for now; the page-content versions that already exist give
  "some versions back" for free.
- 2026-07-15 · **PAGE VERSIONING + REPLACE-NOT-APPEND SAVE** — gap
  c51b774, owner GO, building now: version+fingerprint both sides, save
  replaces the page's rule set (the 63-pile bug dies), compare-before-
  fetch (the GUI lag dies), honest outside-edit conflict. Nothing
  removed; per-section versions stay.
- 2026-07-17 · **ICE — THE STEERING WHEEL (owner vision, full spec session).**
  Fuses and supersedes-in-detail the RESULTS-FIRST reminder + results loop
  where they overlap. (a) **Weekly rank tracking**: per site a Keywords tab —
  rows = keywords (target page shown under each — the keyword-per-URL law),
  columns = weeks, newest column auto-appears beside the frozen keyword
  column, ~104-week window (retention = hub data, cron-pruned, own indexed
  table — NEVER options/postmeta; ~416k rows at 200 sites × 20 kw × 2y =
  objectively small). Feed: Pro Rank Tracker as a new Integrations provider,
  weekly sequential pull, upsert per keyword-week, failed sites = visible
  named failure rows. (b) **Manual override law**: every weekly cell is
  click-to-type; manual value IS the effective value everywhere (one graph
  line), the fetched value is kept underneath as the receipt (hover:
  "manual — tracker said X") so the next fetch can never clobber a
  correction and corrected weeks stay auditable. (c) **URL mismatch = data**:
  each week stores rank + the URL that actually ranked; target-mismatch
  weeks flagged on the cell (the cannibalization signal → §9.4 fix actions).
  (d) **The Pages table is the steering wheel** (Meta-Ads pattern): rank +
  movement + mini-trend per page, fed from the keyword history; the Keywords
  tab is the once-a-week service hatch. (e) **THE UNIFIED GRAPH per page**:
  ONE timeline store per page — number series (effective rank, GSC
  position/impressions/clicks) + event series (results-loop optimization
  stamps + date-stamped USER NOTES), every point source-labeled
  (manual/prt/gsc:stored), each series user-toggleable; notes/optimizations
  draw as date markers so cause-and-effect reads on one picture. Keyword
  table, pages columns, ICE rollups (improving/flat/declining + avg
  movement per site + domain) and the graph are all VIEWS of that one
  timeline — adding a future source = one more labeled series. Scoring
  stays parked per the standing points/weighting ruling.
- 2026-07-17 · **ICE LANE + HOME CORRECTION (owner confirmed).** The
  Keywords tab lives INSIDE THE SEO MODULE — a per-site tab exactly like
  the content tab, scoped to the selected site/domain; its rows are the
  keywords AGREED to work with for that site. It is NOT the Keywords
  module and NOT the editor's KeywordsDrawer (other dev's lane) — no
  shared code, no shared UI; it only READS the keyword-per-page data the
  platform already stores. The whole ICE build (weekly rank table, PRT
  fetcher, manual override, Pages steering-wheel columns, unified graph
  with notes, ICE rollups) = the SEO/optimizer seat's lane.
- (append below this line)

## 11. STANDING LAWS THAT BIND EVERY FEATURE ABOVE

- Factual gap analysis committed BEFORE every change + owner GO.
- Hard lanes (owner ruling 2026-07-15): optimizer module + SectionModal =
  research-spine dev · keywords module + drawer = keywords dev · one
  writer per file, nobody waits.
- No hardcoded tunables — hub-controlled data (options), always.
- No silent fallbacks; no empty successes; failures are named errors
  with the fix in the message; degraded data is LABELED (stored vs live).
- Anthropic JSON law: the exact output contract lives IN the prompt.
- 2 PHP workers: no parallel LLM fan-out inside one request.
- Never `git push` without the owner's word. LOCAL ONLY until then.
- Verify ritual per pair: php -l · standalone harness (88/88 +
  optimizer_research_test 34/34) · tsc 59 baseline zero new · build
  ("built in" line) · changelog line · AFTER commit.
