# GAP ANALYSIS — THE RESEARCH SPINE (owner GO 2026-07-14)

Owner order: implement the full research roster in one arc — every teacher
working, keys picked from the Integrations module per provider, and every
research result SHOWING which source/engine actually answered ("I want to
see what answer we're actually using"). Templates exposure DEFERRED (owner
ruling same day); keyword-module frontend (drawer) untouched — the other
developer's active lane. Client approval card stays LAST (standing ruling).

## FACTS (verified at lines, this tree)

1. **Teachers are context-blind.** Analyze context = siteId/postId/html/
   pageType/model/provider/userId ONLY (optimizer/controller.php:334-344).
   No keywords, no business data reach ANY teacher. The subtopics teacher
   asks the model to invent "the ONE core topic" (class-pcm-teacher-
   subtopics.php:51) when the user has literally set a primary keyword.
2. **The rewrite side is ALREADY partially grounded** — run_prompt_section
   has the append law for page type + business phone/address/category
   (seo/service.php:702-710, owner order 2026-07-13) and the section
   template carries {{primary_keyword}}/{{supporting_keyword}} vars
   (prompts.php:75-78); the keyword ride (primary + bucket) joins every
   optimize run frontend-side (SectionModal.tsx:1067-1073). Remaining
   rewrite gap: business description/hours never appended.
3. **Business data layer is complete and unused by research**: site.brandId
   → PCM_SEO_GBP::get_for_brand() resolved record with name/address/phone/
   website/category/rating/reviews/hours/types/description (gbp.php:145-160,
   170-184). Editor header already links the brand (SectionModal.tsx:724-767).
4. **SERP data ALREADY exists via Ahrefs**: PCM_Keywords_Service::
   ahrefs_serp_dr() → MCP serp-overview, top-15 ORGANIC results w/ position/
   title/url/DR/traffic (keywords/service.php:465-560). Owner's Ahrefs key
   WORKS on this machine (handover §2). NO new SERP provider needed.
5. **Grounded web search exists**: PCM_LLM::invoke_with_grounding (native
   Gemini google_search tool, class-pcm-llm.php:549) — the AI-mention
   teacher's grounding path (plan item 5).
6. **GSC demand data reaches the hub already**: per-page query rows stored
   in the kw-stats cache (optimizer/service.php:358-390); live fetch flow
   in optimizer/controller.php:161-207. GSC absent on THIS machine —
   stored rows are seeded (handover §2) and must be LABELED, never passed
   as live. Domain-wide per-page map: PCM_GSC::page_stats (class-pcm-
   gsc.php:409).
7. **Engines-with-keys are enumerable**: integrations table (provider,
   apiKey, isActive, userId — base-controller.php:592-608) + models table
   rows w/ canGenerateText + costTier per provider (models/controller.php).
8. **No group taxonomy**: teacher interface has id/label/order/analyze only
   (interface-pcm-optimizer-teacher.php); teacher_meta() returns id/label/
   order (optimizer/service.php:90-99); the rail renders a flat list
   (OptimizerRail.tsx:63).
9. **No transparency**: analyze responses return items only (controller
   :352-355) — nothing reports what context/model/source produced them.
10. **Compiler is context-blind** (optimizer/service.php:138-242): merges
    directives with no keyword/business awareness.
11. **The editor is the live source of truth** by standing design — html
    already ARRIVES from the editor (controller.php:314-316 comment);
    primaryKw/supportingKw/bucket/sitePages all live as editor state
    (SectionModal.tsx:747-749, index.tsx:1773). The same law therefore
    carries keywords + pages into analyze payloads (unsaved edits must
    count, exactly like unsaved content).
12. **2 PHP workers** (handover §2): parallel LLM fan-out inside ONE
    request is a freeze risk — the mention panel must run its engines
    SEQUENTIALLY in its own single analyze request (the rail already runs
    teachers as separate parallel HTTP requests; that stays).
13. **Harness**: tests/standalone/run.php = 88/88 with WP shims
    (get_option/update_option present); feature tests live as separate
    standalone files. tsc baseline 59; build green.

## DESIGN (lego on the existing spine — no new machinery class)

- **D1 — ONE context package.** PCM_Optimizer_Service::page_context():
  keywords {primary, supporting[], additional[]} (sent by the editor —
  fact 11; additional falls back to bucket_get when not sent), business
  (server-resolved via siteId→brandId→GBP resolved record), pageType.
  ONE formatter context_block() renders it for every prompt (one source
  of truth). Controller builds it ONCE in analyze() — every current and
  future teacher receives it in $context (the registry law untouched).
- **D2 — group() on the teacher contract** ('search'|'ai') + in
  teacher_meta(); the rail renders TWO headed groups (backlog taxonomy
  ruling). Additive interface method; both existing teachers declare
  'search'.
- **D3 — the PEEK (owner confirmation tool).** Analyze responses gain
  contextUsed {keywords, businessFields, pageType, model, provider} and
  items gain optional source (which integration/engine answered). The rail
  section header gets an info icon w/ hover popover (sanctioned surface,
  law 6) rendering it; item source rides the evidence line. No editing —
  templates deferred.
- **D4 — research tunables are DATA**: one seeded option
  pcm_optimizer_research {onpage{minWords,stuffingPct,maxDensityPct},
  demand{minPos,maxPos,maxItems}, serp{topN}, mention{maxEngines,
  questions[] w/ {{primary_keyword}}/{{business.category}}/
  {{business.address}} placeholders}} — hub-editable, never code
  (standing law; same pattern as checklists).
- **D5 — the roster** (one file each, teachers/ glob, self-registering):
  - onpage (search, 15): DETERMINISTIC — word count, per-keyword uses/
    density vs tunables, primary in H1/first-paragraph/headings,
    supporting in headings. Zero LLM: instant, exact, free.
  - serp (search, 25): primary keyword → ahrefs_serp_dr top-N organic →
    ONE invoke_json comparing winner titles vs our content → format/
    coverage/angle gaps. source='ahrefs'. No Ahrefs key → honest error
    naming Integrations. No primary keyword → honest error.
  - demand (search, 30): stored GSC rows (kw-stats cache) → striking
    distance (pos within tunables, impressions desc, not already in the
    content) → "weave in" items. source='gsc:stored (DATE)' or 'gsc:live'
    — the honesty label rides the UI (fact 6).
  - interlink (search, 35): pages[] from the editor (fact 11) + when a
    GSC key exists ONE page_stats call maps url→proven queries
    (source='gsc'), else titles only (source='page titles') → ONE
    invoke_json proposing IN-CONTEXT from-links (anchor phrase must exist
    verbatim in our text; one link per target; never self-link — laws in
    the prompt). Items carry the target url in the instruction.
  - answerability (ai, 40): ONE invoke_json — answer-first-paragraph,
    question-form headings, liftable/quotable sections, FAQ presence,
    definition clarity.
  - mention (ai, 45): money questions from tunables D4 → engines =
    distinct providers w/ active key + a text model (fact 7), capped,
    SEQUENTIAL (fact 12); google engine uses invoke_with_grounding when
    available (labeled 'grounded'). Per-engine verdict item: recommended
    brands, whether OUR brand/domain is among them (match on business
    name + site host), one summary item. source='<provider>:<model>'.
  - facts (ai, 50): ONE invoke_json judging entity/fact authority against
    the REAL business record — NAP present/consistent, concrete numbers vs
    vague adjectives, who/what/where clarity, unverifiable claims.
  - search + subtopics: declare groups, receive the context block;
    subtopics anchors its core topic on the primary keyword when present
    (kills fact 1's invention).
- **D6 — compiler context**: compile() appends the same context_block to
  its reconciliation prompt — directives merge/order keyword- and
  business-aware. Certainty contract untouched.
- **D7 — rewrite append extension**: the existing business append law
  (fact 2) gains description + hours in the SAME block — one line, no new
  machinery.
- **D8 — frontend**: types (group/source/context), useOptimizer (keywords+
  pages in args + payloads; context stored per run), OptimizerRail (two
  groups + peek popover + source suffix), SectionModal (pass existing
  primaryKw/supportingKw/bucket/sitePages state into the rail args —
  additive props, drawer untouched).
- **D9 — tests**: new standalone file optimizer_research_test.php (bare
  PHP + the run.php shim pattern): context_block determinism, onpage
  verdicts, demand striking-distance filter, mention brand-match. run.php
  stays 88/88.

## HONEST DEVIATIONS / DEFERRALS
- Templates exposure deferred (owner). The peek is the confirmation tool.
- Keyword-injection run + drawer surfaces = other dev's lane; untouched.
- GSC live fetch inside the demand teacher reuses the STORED cache the
  drawer already fills (no second fetch path); live pull stays the
  drawer's Ranking tab. Stated on the item source label.
- No new SERP provider added: Ahrefs serp-overview (existing, working) is
  the tap; grounded Gemini remains the mention teacher's grounding, not a
  SERP fallback — one source per purpose, labeled.

## CHECKLIST
[ ] BEFORE commit (this doc) · [ ] interface group() · [ ] service D1/D4/
D6 + provider_key · [ ] controller context+peek payloads · [ ] existing
teachers context+groups · [ ] 7 new teacher files (git add IMMEDIATELY —
Avast) · [ ] D7 append line · [ ] frontend D8 · [ ] tests D9 · [ ] php -l
each · [ ] run.php 88/88 · [ ] new test file green · [ ] tsc 59/0 new ·
[ ] build "built in" line · [ ] changelog line · [ ] AFTER commit LOCAL ONLY
