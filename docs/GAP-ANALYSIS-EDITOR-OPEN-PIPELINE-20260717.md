# GAP ANALYSIS — THE EDITOR-OPEN PIPELINE, FULL TRACE — 2026-07-17

Owner order: map EVERY piece of code touching the page-editor open + state
check, what happens, what goes wrong, why — then the gap between current and
target. No technical debt: edits not additions, no duplicates, no stale
comments, no regressions. Every claim below is verified at file:line or by a
live probe run today. Builds on (does not repeat) the same-day gap
GAP-ANALYSIS-STATE-CHECK-TIMEOUT-POWERLEADS-SLOW-20260717 + addendum.

## A. THE COMPLETE TRACE (current code, verified)

### A1. Before any query — the bundle
- class-pcm-admin.php:126-131 — `index-writer.js` enqueued with `ver=time()`
  ("Cache-bust on every page load for dev testing") → the ~5MB bundle
  re-downloads on EVERY admin page load. Standing flagged perf item
  (handover §7). Amplifier, not a defect of this pipeline.

### A2. What fires when SectionModal opens (page mode, editable)
Every `useQuery` in SectionModal.tsx and its enablement, with its backend:

| # | Query (line) | Enabled at open? | Backend chain | Cost class |
|---|---|---|---|---|
| 1 | pageVersionsQuery rowsOnly (601-604) | YES | GET `…/page-versions?rowsOnly=1` → controller.php:680-690 → service list_page_versions():4493 rows_only branch → list_versions():4458 (prepared SELECT on wp_pcm_seo_rule_versions, userId-scoped) | hub DB — ms of SQL + ~1-3s PHP boot |
| 2 | stateQuery (643-646, staleTime 0, refetchOnMount always) | YES | GET `…/page-state` → controller.php:716-724 → page_state_compare():4034 → remote_rest() sites/service.php:137 → connector `/pcm-conn/v1/page-state` (seohub service.php:1342-1352, get_option read) | **REMOTE — measured 12-15.7s today; holds ONE hub worker the entire wait; timeoutS=30 (hub data, set today)** |
| 3 | brandsQuery (804) | YES | brands.list — hub DB | hub DB |
| 4 | imageRulesQuery (1525-1528) | YES | GET `…/rules` → remote_list_rules controller.php:446-454 → list_dynamic_rules (hub DB; NOT remote despite the name) | hub DB |
| 5 | kwBucket (useKeywordBucket, ~830) | YES | hub DB | hub DB |
| 6 | pageOriginalQuery (606-609) | NO — versionsOpen only | full list_page_versions → remote_fetch_snapshot (service.php:2917, timeout 30) | REMOTE, lazy |
| 7 | pageQuery inventory (627-630) | NO — only when versions settle EMPTY or liveViewWanted (625-626) | served snapshot from the site | REMOTE, fallback only |
| 8 | versionsQuery sections (868-874) | NO in page mode (`!isInsert && !!section`) | — | — |
| 9 | teachersMetaQuery (1129-1132) | NO — only while a directive run shows | — | — |

### A3. The content paint gate
- SectionModal.tsx:741-754 — needs editor + pageVersionsSettled only; with
  versions present, versions[0].replacement → setContent, docLoaded=true.
  **No remote dependency.** Fallback (no versions): waits for pageQuery
  (line 747), then paints, then docLoaded=true.
- Staged honest overlay keys on docLoaded (738-740, gap 02d3cb7 D2).

### A4. The corner status (the ONE status, gap d4aa30b)
- SectionModal.tsx:1809-1836 — isFetching → spinner · drifted → amber ·
  stateUnreachable (isError OR remote===null, line 652) → red cloud w/ retry
  · drifted===false → green · else neutral.
- Second consumer of stateQuery (814-819): brandId/pageType header context —
  **fact: both are filled server-side BEFORE the remote call**
  (page_state_compare:4043-4044 — $site->brandId + get_page_type, both hub
  DB) and the fallback path fills them from pageQuery too.

### A5. The verdict producer (hub)
- page_state_compare (service.php:4034-4081): local record ← option
  pcm_page_state (4014-4024, map "siteId:postId"); cfg ← read-through seed
  pcm_seo_state_check (4047-4051); remote call 4052 (**$body=null since
  today's fix — was array() → wp_json_encode→"[]" string → WP cURL
  http_build_query TypeError → 500 on EVERY run since the feature shipped**);
  honest 404 message 4062-4065; non-200 named 4066-4068; drift =
  fingerprint mismatch when either side has a version (4077-4079).
- Sole callers: controller.php:723 (the editor route) — verified by grep;
  no cron/background caller exists.

### A6. The connector (powerleads, verified 3.0.8 on disk)
- `/page-state` route: seohub service.php:1342-1352 — reads option
  `pcm_conn_page_state_{pid}`, answers {version:0, fingerprint:''} when
  never stored (the true baseline).
- Store path: rules push reply handler 2482-2486 (3.0.6+) writes it;
  echo helper 1993-1998. **powerleads DB (port 10041): pcm_conn_page_state_3
  NOT SET** (last push predated the store on this install) while
  pcm_conn_rules_3 (37KB) exists → remote answers version 0 → drifted=true
  vs hub's recorded v24 — the amber the owner now sees is CORRECT.

### A7. The environment (measured today)
- Both sites: exactly 2 single-threaded php-cgi workers. Proof: nginx
  upstream = the 2 `fastcgi_servers` (conf/nginx/site.conf.hbs:1-5) ← Local
  sites.json cgi ports [hub 10014/10015, PL 10038/10039]; and
  conf/php/php-fpm.d/www.conf.hbs:1 states the fpm conf (the documented R4
  lever) **is not used on Windows — R4-as-documented is a NO-OP here.**
- Latency: powerleads PHP 10-16s/request (static 0.3s); hub 0.9-3s.
- Queue starvation measured DURING the owner's open: three identical hub
  requests answered 1.5s / **23.8s** / 0.9s — one worker was pinned by the
  state check, everything else serialized behind the second.

## B. WHAT GOES WRONG — the defect list (why the 40s happened)

- ~~B1 the 500 crash~~ (FIXED today, one token, service.php:4052) and
  ~~B2 timeout 5s < site latency~~ (FIXED today, hub data → 30) — proven
  fixed end-to-end in web context: compare(2,3) = local v24 / remote v0 /
  drifted true / error null in 13s.
- **B3 — THE REMAINING DEFECT: the state check is a synchronous remote wait
  inside a user-facing hub request, on a 2-worker pool.** Its comment claims
  "Never blocks anything" (SectionModal.tsx:639-642) — true for React
  render, FALSE for the worker pool: it pins 1 of 2 hub workers for the full
  site round-trip (12-30s), so the open's OTHER requests (including #1, the
  content) queue behind ONE remaining ~1-3s/request worker → the owner's
  30-40s wait. The design contradicts the platform's own fleet-health law
  (gap fad81ea: stored reads + background refresh; "dots read the stored
  map, never live tests").
- B4 — no-versions pages: the fallback (#7) is a live remote snapshot →
  first-open of any page without saved versions rides the 10-30s site
  latency. Honest staged message exists (02d3cb7 D3); slow but truthful.
- B5 — the ver=time() bundle re-download amplifies every open (A1).
- B6 — the machine itself: 3-16s per PHP request everywhere (AV on-access
  scan suspected; opcache confirmed ON in both php.ini.hbs). Not plugin code.

## C. TARGET STATE + THE GAP

### C1. E1 — THE ORDERING EDIT (this pair, owner GO):
**Content first, question after.** The state check must not compete with the
open. Edit (not add):
- SectionModal.tsx:643-646 — `enabled: isPage && !readOnly` gains
  `&& docLoaded` (state already exists, 740). The check fires only after the
  document painted.
- SectionModal.tsx:639-642 — the comment is EDITED in the same pair to state
  the worker-pool truth (a stale comment is debt).
- brandId/pageType consumer (814-819) keeps working unchanged — on the
  saved-version path they now arrive with the (deferred) state reply, on the
  fallback path from pageQuery as today. No other consumer exists (grep A4).
- Named edge (accepted, honest): if BOTH versions are empty AND the
  fallback inventory fails (pageError, 633-637), docLoaded never sets → the
  corner stays neutral while the error line names the failure — no fake
  verdict on a page that never loaded.
- NOT touched: glyph states, drift semantics, route, compare, connector,
  keywords lane. Zero new machinery, zero duplicates, one enabled-flag edit
  + one comment edit.
- Expected effect (from measured numbers): open paints after rowsOnly+boot
  (~3-6s today), corner spins ~13s more, lands amber. The pool is still
  held 12-30s AFTER paint (named trade-off) — cure for that is C2/C3.

### C2. E2 — STORED-FIRST VERDICT (follow-up pair, priced, owner decision):
The architectural end-state per the fleet-health law: page_state_compare is
EDITED (not duplicated) into stored-verdict read (instant, new autoload-off
option keyed siteId:postId with checkedAt) + background refresh via
wp_schedule_single_event (single-flight transient, dedupe); the route
answers stored + age; the glyph shows the age ("checked 2m ago" title) and
spins only while a refresh is actually pending. Tunables (verdict TTL,
lock TTL) merge into pcm_seo_state_check (the merge-seed law). Kills ALL
worker pinning inside user requests forever; refresh costs pool time only
once per stale open. Needs its own gap when called.

### C3. Environment (owner, outside the repo):
- Real worker increase on Windows Local: the fpm file is a no-op; the true
  lever (Local's cgi port count) needs investigation before any promise.
- AV exclusion test for both site folders — before/after with the same curl
  timings used in this gap (one minute, decisive for B6).
- One page re-save (F4, standing) turns the correct amber green.

### C4. B4/B5 stay in NEXT PLANNED (handover §7: bundle cache-busting;
table-level snapshot prefetch is a possible later fold) — named, not lost.

## D. VERIFY PLAN FOR E1
tsc 59 baseline zero new → build ("built in" line) → live: hub request storm
during an open must show no 20s+ queue outlier on the content path;
owner-side: content paints in seconds, corner answers after. Changelog +
AFTER commit ending LOCAL ONLY.
