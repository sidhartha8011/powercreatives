# HANDOVER — 2026-07-17 → NEXT DEVELOPER

You are taking over as Senior Developer, Senior Engineer, Senior Architect
(and senior Apple-calibre UX designer when asked) of Power Creatives.
Read this WHOLE file before touching anything.

## 0. THE OWNER (product owner — how to work with him)
Short, PLAIN product language — never tech-speak. Sends "lo" as an
alive-check → answer ONE line of status and keep working. Expects every
request REITERATED as a list before you build. One bullet per thing.
Accountability is absolute: name your own mistakes plainly, fix them at
the root. He catches shortcuts — there are no cheap wins here.

## 1. THE PLATFORM (purpose)
A WordPress hub plugin (PHP 8.1+ modular monolith + React/TS Vite SPA)
for an agency running MANY client WordPress sites: AI content generation
(copy/image/video), client approval boards, and THE SEO SUITE — a page
editor that edits CLIENT sites safely via a CONNECTOR plugin installed on
each site (overlay rules; original content never touched), an AI research
system (THE OPTIMIZER: parallel "teacher" researchers → catalog → basket
→ one compiled optimization → red/green review), keyword tooling
(GSC/Ahrefs), and a results loop. The vision doc of record:
**docs/BLUEPRINT-MASTER-OPTIMIZER-20260715.md** — THE never-lose file;
every new feature idea is APPENDED to its §10 log first. Never lose it.

## 2. THE REPO
- Working copy = the SERVED hub site:
  `C:\Users\dataadmin546\Desktop\PROJECTS\PowerCreatives\app\public\wp-content\plugins\power-creatives`
  (a git repo; Local by Flywheel serves app/public directly — PHP changes
  are live instantly; frontend needs `cd app && npm run build`).
- Remote: https://github.com/profitmediaab/powerplatform.git · branch
  `feat/seo-suite-port` · HEAD `b81ba51` · **30 commits UNPUSHED (LOCAL
  ONLY — never push without the owner's explicit word;** last owner-
  ordered push landed `f9adfc7..3951961` on 2026-07-16).
- A SECOND developer works the KEYWORD lane in the SAME checkout (hard
  lanes, owner ruling): optimizer module + SectionModal + editor = THIS
  seat; Keywords module + KeywordsDrawer = his. Check `git log/status`
  before committing — his commits appear between yours.

## 3. THE LAWS (owner-enforced, violated at your peril)
1. NEVER `git push` without his word.
2. NEVER code without a FACTUAL GAP ANALYSIS committed first + his GO
   (he often pre-authorizes "commit and go"). Facts verified at
   file:line or by live probe — NEVER from memory.
3. Commit BEFORE starting (clean tree counts — say so). Full ritual per
   pair: gap → BEFORE commit → build → php -l each touched file →
   `tests/standalone/run.php` **88/88** → `page_versioning_test.php`
   **26/26** → `optimizer_research_test.php` **34/34** → `cd app && npm
   run check` = **59 pre-existing tsc errors, ZERO new** → `npm run
   build` (the "built in" line MUST print) → changelog line in
   docs/CHANGELOG-20260709-2200.md → AFTER commit ending "LOCAL ONLY".
3. Zero hardcoding: every tunable (timeouts/caps/intervals/thresholds/
   prompt questions) is hub DATA (seeded read-through options, merge
   pattern so new keys reach old installs). Zero dead code — deletions
   land in the same commit as their replacement, named.
4. No silent fallbacks, no empty successes: failures are NAMED errors
   with the fix in the message; degraded data is LABELED (stored vs
   live); an unanswered check is shown unanswered.
5. TRACE THE REAL CONSUMER end-to-end before claiming done (lesson
   2026-07-17: a server-side filename stamp was silently overridden by a
   frontend hardcode — the owner caught it).
6. Anthropic JSON law: the exact output contract lives IN the prompt
   (PCM_LLM drops response_format for Anthropic by design).
7. Every AI content change lands through the red/green review; catalog
   suggestions are checkboxes, never accept/reject.
8. Shared components law: ModelDropdown/Pill/PillButton/DataTable/
   dropdown-menu are THE controls; extend additively, never bespoke.
9. UI: no permanent instructional chrome; hover/title tooltips ARE
   sanctioned; state statements (loading/drift lines) are allowed;
   English only.
10. Data-first debugging: probe (DB/HTTP/logs) BEFORE any fix.
11. Editor content: identity anchors (data-pcm-review-id), canonicalAiHtml
    gate, never auto-replace an open document (2026-07-11 law).
12. Never splice UTF-8 with PowerShell (use the Edit tool); never put
    double quotes inside a PS heredoc commit message.

## 4. ENVIRONMENT (this exact machine — dataadmin546)
- Hub: **http://powercreatives.local** (Local by Flywheel; nginx; PHP
  8.2.29). **pm.max_children = 2** (conf/php/php-fpm.d/www.conf.hbs:4-5)
  — THE standing bottleneck; raising 2→6 ("R4") is OWNER-pending.
- Hub DB: mysqli 127.0.0.1:10005/**10017** root/root db `local` (10017
  verified working for probes). Client site: **powerleads.local** = site
  id 2 (its own Local site on this machine; draft "Privacy Policy" =
  post_id 3 — the standing test page).
- PHP CLI: `C:\Users\dataadmin546\AppData\Local\Programs\Local\resources\extraResources\lightning-services\php-8.2.29+0\bin\win64\php.exe`
  (+ `-d extension_dir=<bin>\ext -d extension=mysqli,mbstring,openssl,curl`).
  ⚠ **Avast MITMs CLI TLS** — CLI wp-load probes DIE on any outbound
  HTTP ("critical error" = the probe, not the site). Probe HTTP with
  plain `curl` (works), DB with mysqli one-liners. ⚠ Avast quarantines
  NEW .php files — `git add` immediately after creating.
- Frontend bundle: FIXED filenames (`app/dist/index-writer.js`), enqueued
  with `ver=time()` (class-pcm-admin.php:129 — re-downloads 5MB every
  admin load; flagged as a perf item). Long-lived SPA tabs run OLD code
  until the page reloads.
- No composer here; the standalone harnesses run with bare PHP.

## 5. WHAT THIS SESSION BUILT (2026-07-14 → 17, all verified+committed)
- **THE RESEARCH SPINE**: context package (live keywords + server-resolved
  business record + pageType + site pages) to EVERY teacher; two-group
  rail (SEO/AI) w/ plain category names; transparency peek; per-item
  `source` labels; compiler context; NINE researchers live (structure/
  onpage-deterministic/topic-anchored/SERP-with-real-winner-content/
  demand-striking-distance/interlinks/answerability/mention-multi-engine-
  panel/business-facts). Tunables = `pcm_optimizer_research` option.
- **Value batch**: REVISE FIDELITY (human-editor contract + sentence-
  retention check + dynamic scope judgment + honest failure), rail/review
  UX (cards, one-line rows, disclosures, SEO/AI pills + italic purpose
  line), RESULTS LOOP MVP (history stamp on accepted review + stored-GSC
  then-vs-now + rail result card).
- **Page versioning (owner's design)**: version+fingerprint both sides,
  save REPLACES the page's net rule set (the 63-rule pile bug), editor
  opens FROM THE SAVED VERSION, drift detection; connector echoes
  pageState; featherweight /page-state endpoint + hub compare route +
  background check; IDENTITY-CORNER STATUS (top-left square: spin/green
  dot/amber drift/red cloud/neutral — ONE status, one home).
- **Fleet health**: heartbeats at remote_rest (free health from real
  traffic), stored-map /sites/health reads w/ age, cron probe queue for
  stale sites, SWR content-table cache (instant table opens), SEO tab
  dots (SiteStatusDot + useSiteHealth), honest staged loading messages
  everywhere (editor open phases + first table load).
- **Connector 3.0.8**: SELF-AUTHORIZED LOOPBACK (one-time pid-bound
  single-use 60s token + pre_get_posts status widening — drafts pull
  without any session; the anonymous-fetch shortcut killed), page-state
  store+echo, versioned download filenames (server header = the source;
  the frontend hardcode that overrode it fixed same day).
- Also: header status dropdown (table's control reused), bulk version
  delete (Current guarded), Sites actions kebab, title gesture (click=
  editor, dblclick=rename), site health dots.
- Gap docs for every pair are in docs/GAP-ANALYSIS-*.md (dated).

## 6. ⚠ THE UNRESOLVED CRITICAL (take this FIRST)
Owner reports (screenshot 2026-07-17, after claiming the 3.0.8 update +
hard refresh): draft Privacy Policy editor STILL opens WHITE with the
top-left square RED (unreachable) — content not pulling, state check
failing. Evidence already proven THIS session: served bundle = new code
(HTTP byte probe), version 176 = 13KB real content in hub DB, anonymous
connector /page-state answered 401 (route EXISTS → 3.0.7+ installed at
probe time), hub REST alive. OPEN QUESTIONS the next probes must answer:
(a) is the INSTALLED powerleads connector REALLY 3.0.8 now (curl its
/wp-json/pcm-conn/v1 route list; the self-auth loopback only exists in
3.0.8); (b) does the HUB's authenticated page-state call succeed (probe
via WEB context — admin-ajax/mu-plugin — NEVER CLI, Avast TLS);
(c) why the editor shows white when versions[0] exists — check whether
remotePageVersions?rowsOnly returns rows for THIS user (user-scoping!)
and whether the browser requests show 200s (owner can share Network
tab); (d) the 2-worker queue (R4) amplifies everything. Do NOT guess;
probe, then gap, then fix. The owner's patience for this bug is spent.

## 7. NEXT PLANNED (owner-approved, not yet built)
- R4: workers 2→6 (one config line + site restart) — owner decision.
- Local server-cron switch (probe queue reliability) — owner infra step.
- Bundle cache-busting done right (filemtime ver, hashed chunks) —
  flagged perf item.
- From the BLUEPRINT §9/§10 (order roughly): per-change attribution →
  knowledge-graph insert + link-mode → entity/KG API mapping →
  interlinks TO-direction + cannibalization → reality-derived checklists
  + category/brand page types → templates exposure (owner deferred) →
  Ask AI advisor → mention panel part 2 → client approval card LAST.
- Owner REMINDERS logged in the blueprint: Meta-Ads-style edit modal
  (left page-switcher sidebar + content/metadata tabs), RESULTS-FIRST
  table columns + growth graph w/ change annotations (Google-Ads-history
  style), point scoring AFTER the results loop matures.
- Keyword lane (other dev): injection polish, phrase-match discovery,
  domain-wide related scan.

## 8. KEY FILES
`includes/modules/optimizer/**` (spine + teachers, one file each) ·
`includes/modules/seo/{service,controller}.php` (editor backend, rules,
versions, page-state) · `includes/modules/seohub/service.php` (THE
CONNECTOR TEMPLATE — nowdoc; harness evals it) ·
`includes/modules/sites/**` (connections, health, heartbeats) ·
`app/src/modules/SEO/{SectionModal.tsx,index.tsx,optimizer/**,hooks/**}` ·
`app/src/lib/trpc-routes.ts` · docs/: BLUEPRINT-MASTER (never-lose) ·
backlog.md · CHANGELOG-20260709-2200.md · every GAP-ANALYSIS-*.md ·
tests/standalone/*.php (3 suites: 88 + 26 + 34).
