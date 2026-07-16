# HANDOVER — 2026-07-14 → NEXT DEVELOPER

You are taking over mid-arc. Read this whole file before touching anything.
The owner is the product owner (PO): short, plain product language — NEVER
tech-speak at him; sends "lo" as an alive-check (answer with ONE line of
status and keep working); expects his requests REITERATED as a list before
you build so nothing is lost; wants ONE bullet per thing when he asks for
lists.

## 0. YOUR PERSONA (non-negotiable)
Senior developer + senior engineer + senior architect on every task;
senior UX designer (Apple-calibre — he asks for "3 suggestions, I pick")
when the task is visual. Facts with evidence — line numbers, live probes
(DB reads, server logs), never theory. When he asks a question, ANSWER IT
and stop. When he says something is not senior-grade, grade your own work
honestly and redo it.

## 1. THE LAWS (violated at your peril — he notices, and he interrupts tool calls)
1. **NEVER `git push`** without his explicit word. When he orders a sync:
   fetch → merge THEIRS into ours locally → resolve → FULL verify on the
   merged tree → commit → push fast-forward. Never force-push.
2. **NEVER code without a FACTUAL GAP ANALYSIS committed first + his GO.**
   "Never, ever do anything without the factual gap." Every fix, however
   small. Ritual: facts (verified at lines / by probe) → design → checklist
   → COMMIT the gap doc → GO → build. He often pre-authorizes
   ("implement after you commit") — only then may you continue unprompted.
3. **Commit BEFORE every task** (clean tree counts — say so).
4. **Zero dead code / tech debt / hardcoding.** One source of truth for
   every constant (the PAGE_CARD_WIDTH lesson: a mirrored formula got the
   work rejected). Delete superseded code in the same commit and SAY so
   ("honest deviations").
5. **Full ritual per pair:** BEFORE state → build → php -l each touched file
   → `tests/standalone/run.php` = 88/88 → `cd app && npm run check` = 59
   pre-existing tsc errors, ZERO new → `npm run build` (CHECK the "built
   in" line prints — a chained commit once ran without it) → changelog line
   in docs/CHANGELOG-20260709-2200.md → AFTER commit ending "LOCAL ONLY".
6. **No bare instructional text on UI surfaces — ever.** Refinement
   2026-07-14: hover popover / info icon / `title` tooltip IS sanctioned;
   placeholders are fine. English only. No confirmation dialogs.
7. **Shared components law:** ModelDropdown / PillButton / PillSplitButton /
   DataTable / ColumnHead are THE controls. Extending them ADDITIVELY is
   the sanctioned move (precedents: PillButton `outline` variant,
   ModelDropdown `searchable`, FilterKind `number`, DROPDOWN_TRIGGER_STYLE
   export). Never a bespoke control, never a LESSER table (every table
   gets layoutKey + filters + sort + resize).
8. **Every AI change lands through the red/green review.** The optimizer's
   basket compiles into ONE run; accept/reject only ever on the RESULTING
   changes, never on suggestions (those are checkboxes).
9. **Lane colors fixed** (grey own / amber edited / sky added; only the 2s
   flash may recolor). Review section identity = the `data-pcm-review-id`
   ANCHOR, never counted headings.
10. **Data-first debugging.** Today's five root causes were all proven
    before fixing: debug.log timestamps (LLM invocations, Google timeouts),
    live DB reads (bucket option), live HTTP repro (the 405), worker-pool
    config. His trust follows probe output.
11. **No silent fallbacks / no empty successes.** An unreachable upstream
    is an ERROR the user sees (google_suggest lesson). Optimistic UI by
    law: edits move the UI immediately; failures revert AND toast
    (useKeywordBucket lesson).
12. **Anthropic JSON law:** PCM_LLM drops response_format for Anthropic
    (class-pcm-llm.php:126) — the exact JSON contract MUST live in the
    prompt. Zero matched rows = THROW, never an empty success.
13. Never splice UTF-8 with PowerShell (use Edit tool / bash). NEVER put
    double quotes inside a PS heredoc commit message (breaks args — bit us
    twice). Owner's real rules on powerleads must never be consumed by
    tests; disposable posts only, report leftovers honestly.

## 2. ENVIRONMENT HARD FACTS (this exact machine)
- **The hub has 2 PHP workers** (pm = static, max_children = 2 —
  conf/php/php-fpm.d/www.conf.hbs). Two long requests (e.g. parallel LLM
  calls) freeze the whole app 30-50s. Raising it (R4) is the OWNER'S
  decision — offered, not yet ruled.
- **Avast quarantines NEW `.php` files minutes after creation** (probes via
  STDIN heredoc into PHP — memory `project_probe_toolchain`) — module files
  survived, but `git add` new .php files IMMEDIATELY after creating them.
- **Avast MITMs CLI PHP TLS** (api.anthropic.com unreachable from CLI —
  never diagnose "AI broken" from CLI). Web PHP outbound WORKS — except
  **suggestqueries.google.com TIMES OUT even from web PHP** (proven in the
  log; the Ideas tab shows the honest error now).
- Hub DB: mysqli 127.0.0.1:10017 root/root db `local`. Powerleads = site
  id 2 (its DB port unknown — probing ports hangs, don't).
- Bootstrap probes: heredoc into PHP with
  `require 'C:/Users/dataadmin546/Desktop/PROJECTS/PowerCreatives/app/public/wp-load.php';`
  + `$_SERVER['HTTP_HOST']='localhost';` — run from the PLUGIN root.
- PHP: `/c/Users/dataadmin546/AppData/Local/Programs/Local/resources/extraResources/lightning-services/php-8.2.29+0/bin/win64/php.exe`
  with `-d extension_dir=<bin>/ext -d extension=mysqli -d extension=mbstring
  -d extension=openssl -d extension=curl -d mysqli.default_port=10017`.
- **Remote deletes are FIXED fleet-wide**: the front web server refuses the
  DELETE method (bare 405, empty body) — remote_rest tunnels DELETE as
  POST + X-HTTP-Method-Override (sites/service.php). Don't regress it.
- Ahrefs enrichment WORKS here (owner has a key). GSC has NO integration
  on this machine — the drawer's Ranking tab serves SEEDED stored rows
  (labeled amber). Fake data seeded for powerleads pages via the
  kw-stats-cache + bucket options — pure data, deletable.
- composer/PHPUnit NOT runnable here (composer absent) — the other dev's
  suite runs on his machine.

## 3. STATE OF THE SYSTEM
- Branch `feat/seo-suite-port`, HEAD `d135e3c`, **38 commits ahead of
  origin (LOCAL ONLY — push needs his word)**. Last push `00654e5` = the
  merge of the other developer's strategy/AutoPress arc (one conflict:
  PCM_DB_VERSION → **1.42.0**; his machines re-stamp on wp-admin load).
- Harness **88/88** · tsc baseline **59** · build green · DB **1.42.0**.
- **THE OPTIMIZER is live** (module `includes/modules/optimizer/` +
  `app/src/modules/SEO/optimizer/`): the Analyze spine (outline pill left
  of Optimize) · SEARCH + SUBTOPICS teachers (one file each, glob
  registry) · THE COMPILER (merge/never-drop certainty check; single-item
  bypass) · purpose pills + compiled-order display · review section
  IDENTITY ANCHORS (Accept-All corruption killed) · the AI-content gate
  (canonicalAiHtml — no raw model output enters the pipeline).
- **THE KEYWORD DRAWER V2 is live**: smart Keywords button (primary ·
  +count · live density%) right of Page type · card TAPERS 280px while
  open (centered [drawer][card] flex pair — NO anchor math) · SELECTED
  table (P/S/A roles w/ Primary-demotion, live uses/density, Ahrefs
  volumes, optimistic add/remove) · ONE finder, two tabs (Ranking = GSC
  w/ permanent Δ compare columns, page picker, amber stored-refresh;
  Ideas = keyword-engine suggestions) · volumes: cache-first endpoint,
  cachedOnly on scans, ONE header update button (refresh bypass) · full
  table parity everywhere · number filters (above/below/between icons,
  shared kind).
- Fixed today besides: preview portals above the editor; Open opens drafts
  as previews; Radix-popper clicks don't close the editor; keyword ride
  (primary + bucket) joins EVERY optimize run.

## 4. WHERE YOU COME IN — THE ORDERED CONTENT PLAN (owner-confirmed, one pair each, gap first each)
1. **Rail taxonomy** — TWO top groups (Search optimization w/ subsections ·
   AI optimization); teachers declare a group; overlap fine (the compiler
   reconciles). FOLD IN the already-gapped purpose-display cleanup
   (order shown ONCE at the rail top, rows slim to pills — gap 6c9b3f9).
2. **Keyword injection** — drawer selected-rows become selectable; the
   Optimize button transforms to "Insert keywords"; ONE run weaves the
   selected keywords per THE HIERARCHY LAW (primary threads the page,
   supporting in some headers+text, additional ≈ one paragraph max) —
   spec in backlog.md.
3. **Interlinking teacher** — in-context FROM-links via the existing GSC
   per-page keyword map (PCM_GSC::page_stats); accept/reject per link.
4. **Knowledge-graph link-mode** — subtopic checks gain exists→teaser+link /
   missing→content+idea (needs 3).
5. **AI optimization teacher** (Part 2 MVP) — 3-5 grounded questions via
   the EXISTING PCM_LLM::invoke_with_grounding → winners → honest brand
   mapping; lands under the AI group.
6. **Per-change attribution** — the rewriter reports applied directives per
   section (separate structured reply field — the content gate strips
   inline metadata) → exact pills per suggestion.
7. Domain-wide related scan + phrase-match discovery (drawer).
8. Reality-derivation for checklists + `category`/`brand` page types.
9. **Client approval card LAST** (owner ruling) — own gap; purpose-grouped;
   first slice of the approvals rails (autonomy roadmap #1).

**AWAITING THE OWNER:** load-speed R1-R3 GO (gap 61dbf4e: parallel list
fetch · defer versions+image-rules to first use · 60s row staleTime) ·
R4 pool decision · browser passes (identity re-test: tick subtopics →
optimize → accept few → Accept All; drawer round) · the next push.

## 5. KEY FILES
`includes/modules/optimizer/{config,controller,service}.php` + `teachers/`
(one file per teacher — THE add-on law) ·
`app/src/modules/SEO/optimizer/{OptimizerRail,KeywordsDrawer}.tsx,
{useOptimizer,useKeywordBucket,keywordStats,types}.ts` ·
`app/src/modules/SEO/{SectionModal.tsx,word-diff.ts,index.tsx}` (the
editor: TWO seams to the optimizer + the drawer pair; identity anchors;
canonical gate) · `includes/core/class-pcm-gsc.php` (query_stats w/
offset) · `includes/core/llm/class-pcm-llm.php` (invoke_json :189,
invoke_with_grounding :304, the Anthropic law :126) ·
`app/src/components/{shared/{ModelDropdown,PillButton}.tsx,
ui/{data-table,column-head}.tsx}` + `app/src/hooks/useColumnFilters.ts` ·
docs/: **GAP-ANALYSIS-OPTIMIZER-ARCHITECTURE-20260713.md (THE PLAN — the
owner checks it exists; never touch it)** · backlog.md (ALL feature specs
incl. taxonomy + injection + hierarchy laws) ·
RESEARCH-CONTENT-ANALYSIS-20260713.md (the guideline) ·
MASTER-CHECKLIST-20260714.md (status index) · every GAP-ANALYSIS-*.md ·
CHANGELOG-20260709-2200.md.

## 6. KICKOFF PROMPT (owner: paste this into the new session)

---
You are taking over as the senior developer, senior engineer, senior
architect (and senior UX designer when needed) of the Power Creatives
WordPress hub plugin. Read `docs/HANDOVER-20260714-NEXT-DEV.md` COMPLETELY
first — it contains the laws, the environment traps, the live state of THE
OPTIMIZER and THE KEYWORD DRAWER, and the ordered content plan. Confirm
you are up to speed by (1) verifying the repo state matches (branch
feat/seo-suite-port, HEAD d135e3c, 38 unpushed, harness 88/88, tsc 59,
build green), (2) reading docs/GAP-ANALYSIS-OPTIMIZER-ARCHITECTURE-
20260713.md and docs/backlog.md §THE ONE-CLICK OPTIMIZATION SPINE — that
is THE PLAN. Then reiterate to me in a short list: the laws you will obey,
the state you found, and the ordered plan you await my GO on. Do not write
or change ANY code until I say GO. Never push. Factual gap analysis
committed before every change. Facts with evidence, always.
---
