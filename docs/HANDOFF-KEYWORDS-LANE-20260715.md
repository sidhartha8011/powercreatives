# HANDOFF — KEYWORDS LANE (owner-approved work split, 2026-07-15)

For the developer on the keyword module. The owner split the research-spine
arc between us: I build the foundation + rail + five researchers; YOU own
everything keyword-flavored. This file is the factual contract — line refs
verified on `feat/seo-suite-port` at `f9adfc7`.

Read first: `docs/GAP-ANALYSIS-RESEARCH-SPINE-20260714.md` (THE gap doc —
facts 1-13, design D1-D9). Your tasks implement D1's keyword slot, D5's
demand researcher, and the backlog keyword-injection spec.

## THE FROZEN CONTRACTS (change only by agreement between us)

1. **Catalog item** (unchanged + one addition):
   `{ id, teacherId, found, label, evidence, instruction, source? }` —
   `source` is optional and names which integration/engine answered
   (e.g. `gsc:stored (2026-07-12)`, `ahrefs`, `openai:gpt-4o`). The rail
   renders it on the evidence line; omit it when the answer is pure LLM
   judgment on page content.
2. **Teacher interface** gains `group(): string` — `'search'` or `'ai'`
   (already edited in the working tree, lands with my foundation commit).
   Your demand teacher declares `'search'`.
3. **Analyze context** (built ONCE in the optimizer controller, handed to
   every teacher):
   `{ siteId, postId, html, pageType, model, provider, userId,
      keywords: { primary: string, supporting: string[], additional: string[] },
      business: <resolved GBP record>, pages: [{id,title,permalink}] }`
4. **Analyze response** gains `contextUsed` (the peek): what keywords/
   business fields/pageType/model/provider the run actually received.
   I build the render; you only need to keep the payload honest.
5. **Editor seams:** SectionModal has exactly TWO seams — I own the RAIL
   side (OptimizerRail args), you own the DRAWER side. Neither crosses.

## YOUR TASK 1 — wire keywords into the context package (the open slot)

- **Source of truth is the editor's LIVE state** (standing law — html
  already arrives from the editor, controller.php:314-316 comment; unsaved
  keyword edits must count the same way):
  - primary: `primaryKw` state — SectionModal.tsx:747
  - supporting: `supportingKw` state (comma-split) — SectionModal.tsx:748
  - additional: `kwBucket.keywords` — SectionModal.tsx:749 (your
    useKeywordBucket, optimistic mirror included)
- Frontend: `useOptimizer` args gain `keywords` (I add the arg + payload
  passthrough in my foundation — you wire the SectionModal side passing
  YOUR drawer state into the rail args; that one prop crossing is the
  agreed exception, one line).
- Server: the controller puts sanitized keywords into `$context['keywords']`;
  when the payload sends none, `additional` falls back to
  `PCM_Optimizer_Service::bucket_get($siteId,$postId)`
  (optimizer/service.php:252) — documented fallback, never silent beyond that.
- The compile endpoint gets the same `keywords` payload (D6) so the
  merger is hierarchy-aware.

## YOUR TASK 2 — the DEMAND researcher (new file, your data domain)

- File: `includes/modules/optimizer/teachers/class-pcm-teacher-demand.php`
  (glob-discovered, self-registering — nothing central changes).
  ⚠ Avast quarantines NEW .php files — `git add` immediately after creating.
- id `demand`, group `'search'`, order 30.
- Data: `PCM_Optimizer_Service::kw_stats_cache_get($siteId,$postId)`
  (service.php:358-363) — rows `{query, clicks, impressions, position}`,
  `property`, `fetchedAt`. The drawer's Ranking tab fills it; the teacher
  READS ONLY (no second GSC fetch path — deviation logged in the gap doc).
- Logic: striking distance = position within tunables (default 4-20),
  impressions desc, query NOT already present in `$context['html']`
  (use the html text, case-insensitive), capped. Each hit:
  `found=true`, label `Proven demand: "<query>"`, evidence
  `ranks #<pos> · <impressions> impressions (<clicks> clicks)`,
  instruction `Weave the search phrase "<query>" naturally into an
  existing relevant section — it already ranks #<pos>.`,
  `source` = `gsc:stored (<Y-m-d of fetchedAt>)`.
- Tunables come from the seeded option `pcm_optimizer_research` →
  `demand: {minPos, maxPos, maxItems}` (I seed it in the foundation —
  read-through like `checklists()`, service.php:471-480 pattern). Never
  hardcode the numbers.
- No stored rows → `throw new \RuntimeException('No Google Search Console
  data stored for this page yet — open the keyword drawer's Ranking tab
  once to load it.')` — the rail shows it with its own retry. Never an
  empty success (standing law).

## YOUR TASK 3 — keyword injection (your spec, unchanged)

- Backlog spec + commit d135e3c: drawer SELECTED rows get checkboxes; with
  a selection the Optimize button transforms to "Insert keywords"; ONE run
  weaves exactly the selected keywords per THE HIERARCHY LAW (primary
  threads headings+body · supporting some headers+text · additional ≈ one
  paragraph max each). All machinery exists (compiler, red/green review,
  keyword ride SectionModal.tsx:1067-1073).

## SEQUENCING

- My foundation commit lands FIRST (interface group(), context builder,
  tunables seed, controller, rail groups + peek). Start Task 3 any time
  (pure drawer); Tasks 1-2 after my foundation is in — or stub against
  the contracts above, they will not move.
- Ritual per pair as always: gap → BEFORE commit → build → php -l →
  standalone harness (88/88 + your own test file) → tsc (59 baseline,
  zero new) → build ("built in" line) → changelog line → AFTER commit
  ending LOCAL ONLY. Never push without the owner's word.
