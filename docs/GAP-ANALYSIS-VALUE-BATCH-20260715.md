# GAP ANALYSIS — THE VALUE BATCH (owner GO 2026-07-15, all facts LIVE-verified this tree)

Scope (owner-ordered): 1 rail pass · 2 review purpose labels · 3 revise
fidelity · 4 SERP winners-content · 5 results loop. Verified at HEAD
5ff5e39 (the other dev's country-chain + selection-backbone landed —
facts below reflect THAT tree, not memory).

## FACTS (live)
1. RAIL: cards are `rounded-lg … shadow-sm` (OptimizerRail.tsx:140);
   groups render from RAIL_GROUPS = 'Search optimization'/'AI
   optimization' (types.ts); row label `text-[11px] … truncate`.
   Teacher display names come from server `label()` — live labels:
   'Search engine optimization', 'On-page & keyword placement', 'Topic
   coverage', 'SERP reality', 'Proven search demand', 'Internal links',
   'AI answerability', 'AI mentions & recommendations', 'Entity & fact
   authority'.
2. REVIEW: each section row already SCROLLS on click (focusSection,
   SectionModal.tsx:1987 'Click to jump'). Purpose pills render
   TEACHER_PILLS[teacherId] per RUN (global, :1997-2013) — researcher-
   level pills (ONPAGE/SERP/…), not the two purposes; no plain-language
   category line. Directive bullets (3 + '+N more') exist. Heading row
   `text-[11px] font-medium`.
3. REVISE: reviseSection (SectionModal.tsx:1237) embeds the draft INSIDE
   the topic string; server remote_optimize_section(service.php:5681+)
   runs the generic 'section/optimize' prompt — NO fidelity contract, NO
   retention check ANYWHERE. Owner-observed defect: targeted note →
   ~80% text loss.
4. SERP teacher (live file): reads winner TITLES only from
   ahrefs_serp_dr; passes `$lang` from get_locale — the country chain
   (resolve_country, keywords/service.php:186; default_country :162;
   PCM_DB::get_site db/class-pcm-db.php:1405) landed AFTER and the
   teacher does NOT use it. Scraper exists: PCM_Scraper_Service
   (scraper/service.php:16) — fetch_html(:33), extract_text_content
   (:144).
5. RESULTS: NO optimization-event storage anywhere (grep history/stamp:
   none). Existing reusable parts: kw_stats_cache_get (stored GSC rows
   per page) + merge_compare (delta math) in optimizer/service.php.
   Review's ONE close point = finishReview (SectionModal.tsx:1226, OK
   button :1936); accepted statuses live in reviewRef.
6. research_tunables() returns the STORED option verbatim when present
   (`!empty($stored['onpage'])`) — new tunable KEYS never reach already-
   seeded installs (this hub seeded TODAY). Defect-grade for every
   future tunable.
7. trpc-routes.ts maps optimizer.* endpoints 1:1 (body passthrough) —
   new routes need one line each; extra body params flow through as-is.
8. OPEN QUESTION (documented, not changed): integrations.userId is
   queried with $pcm_user->id by controllers but with WP
   get_current_user_id() by PCM_LLM::get_api_key (:876) — works live
   (teacher runs answer), so the ids coincide on this hub; multi-user
   audit parked.

## DESIGN
- D1 RAIL: RAIL_GROUPS → 'SEO · Google results' / 'AI · recommendations';
  server labels renamed to plain categories (Structure & language ·
  Keyword placement · Topic coverage · Competitor gaps (SERP) · Untapped
  searches (GSC) · Internal links · Direct answers · AI recommendations ·
  Business facts); cards `rounded` (4px) + hairline shadow; rows
  `text-[10px] text-slate-600 line-clamp-2` (full text stays in the
  disclosure).
- D2 REVIEW: pills collapse to the TWO purposes — SEO (blue) / AI
  (violet) — mapped via the teachers meta (trpc.optimizer.teachers,
  cached; unknown purpose id → SEO, shown by its id in the line);
  beneath them ONE light-grey ITALIC line of the plain category names
  the run serves; heading row drops to text-[10px]; click-to-scroll
  already exists (fact 2) — kept.
- D3 REVISE FIDELITY: the note and the draft travel SEPARATELY
  (frontend sends `draft`; topic = the note alone). Server (fidelity
  path when draft present): the HUMAN-EDITOR CONTRACT prepended — apply
  the request exactly and only; every sentence not covered by it is
  reproduced VERBATIM; broad requests may rewrite broadly. THE RETENTION
  CHECK after: sentence-retention ratio result-vs-draft; below the
  tunable (revise.minRetention, default 0.6) → ONE invoke_json asks
  whether the note demanded a broad rewrite (dynamic judgment, never
  keyword-hardcoded) → not broad → ONE retry with the contract
  restated → still low → honest WP_Error ("the AI changed more than the
  note asked — try again or rephrase"). Zero silent loss possible.
- D4 SERP CONTENT: the teacher fetches the TOP fetchTop (tunable, 3)
  winners' pages via PCM_Scraper_Service (sequential, per-page
  try/catch, timeout + char cap tunables) — a failed fetch degrades
  THAT winner to title-only, marked 'content unavailable', never kills
  the run; country comes from the live chain (get_site →
  resolve_country, else default_country). The compare prompt receives
  real page content — gaps become facts.
- D5 RESULTS LOOP: POST /optimizer/history (finishReview fires it when
  ≥1 section was accepted; purposes from runDirectives) → option map
  'pcm_optimizer_history' "site:post" → last event {at, purposes, rows
  snapshot from the stored GSC cache (capped 200)}. GET /optimizer/
  history → {at, purposes, summary: clicks/position then-vs-now
  (merge_compare over stored rows), source:'gsc:stored'} — null summary
  stated honestly when no rows existed at stamp time. Rail top renders
  the one-line result card ("Optimized 15 Jul · clicks 12→19 · pos
  8.4→6.9 · stored GSC"). Snapshot-vs-stored = the honest MVP; live
  windows come with the full loop later.
- D6 TUNABLES MERGE FIX (fact 6): research_tunables() returns
  array_replace_recursive(seed, stored) — stored edits win, NEW keys
  always exist on old installs. (revise/serp-fetch keys depend on it.)

## CHECKLIST
[ ] this doc committed · [ ] D6 merge fix · [ ] D1 rail (types + 7 label
renames + classes) · [ ] D2 review pills+line · [ ] D3 revise (client
split + server contract + retention + tunable) · [ ] D4 serp content +
country · [ ] D5 history routes + service + rail card + finishReview
stamp · [ ] trpc route lines · [ ] php -l each · [ ] research test
green (+ new retention/merge cases) · [ ] harness 88/88 · [ ] tsc 59/0
new · [ ] build · [ ] changelog · [ ] AFTER commit LOCAL ONLY
