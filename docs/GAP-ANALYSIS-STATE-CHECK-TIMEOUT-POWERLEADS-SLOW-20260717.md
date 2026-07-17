# GAP ANALYSIS — RED CLOUD + WHITE EDITOR ON DRAFT PRIVACY POLICY — 2026-07-17

Owner report: after updating the connector to 3.0.8 + hard refresh, the draft
Privacy Policy editor still opens WHITE with the identity corner RED
(unreachable). Handover §6 probes (a)–(d) executed live this session.
Facts only — every claim below was measured on this machine today.

## FACTS (live probes, 2026-07-17)

1. **The connector update WORKED.** Installed file on the powerleads site's
   disk (`PowerLeads/app/public/wp-content/plugins/pcm-connector/pcm-connector.php`,
   mtime 2026-07-17 04:22): `Version: 3.0.8`, `PCM_CONN_VERSION '3.0.8'`,
   self-authorized-loopback block present (pcm_snap_auth + pre_get_posts).
   Plugin active — its REST namespace answers (route list curled, HTTP 200).
2. **The authed page-state call SUCCEEDS but takes ~12s.** Replayed the hub's
   exact call (Basic auth rebuilt from wp_pcm_sites row 2 + AUTH_KEY/AUTH_SALT,
   `/?rest_route=/pcm-conn/v1/page-state&post_id=3`): HTTP 200 every run;
   12.13s / 12.04s / 12.48s over three runs.
3. **The hub waits 5s.** Hub DB option `pcm_seo_state_check` =
   `{timeoutS: 5}` (seed default, seo/service.php:4049). 12 > 5 → the hub's
   cURL call dies before the answer → `page_state_compare()` returns
   error → identity corner = RED CLOUD. Deterministic, every open,
   regardless of connector version. **THE RED CLOUD ROOT CAUSE.**
4. **The whole powerleads PHP side is crawling.** front page 9.84s,
   wp-login 9.79s, anonymous REST 6.4s — but a static file 0.29s (nginx
   fine, PHP slow). The hub's own PHP: ~3.0s/request. Both sites run
   `pm = static / pm.max_children = 2` (conf/php/php-fpm.d/www.conf.hbs:4-5);
   opcache is enabled in both php.ini.hbs. The per-request slowness is
   ENVIRONMENT (this machine), not plugin code; R4 (workers 2→6) is the
   standing owner-pending item and this bug is its poster child.
5. **The connector has NO stored page state for post 3.** powerleads DB
   (port 10041): option `pcm_conn_page_state_3` NOT SET, while
   `pcm_conn_rules_3` (37KB) exists. Hub DB records `pcm_page_state["2:3"]`
   = version 24, savedAt 2026-07-16. The last rules push landed while the
   OLD (pre-3.0.6-echo-store on this install) connector was in place, so the
   pageState was never stored site-side. CONSEQUENCE: once the check can
   answer, remote=version 0 vs hub fingerprint → honest AMBER (drift) until
   the owner saves the page once more. Correct behavior, stated up front.
6. **The saved content is intact and visible to the owner's user.** Hub DB
   `wp_pcm_seo_rule_versions` site 2 post 3: 4 page-target versions
   (newest id 176, 13,090 bytes, 2026-07-15), 88 section versions — ALL
   userId 1. Site 2 row userId 1; all 27 dynamic rules userId 1. The owner
   reaches the editor at all → he passes `get_site(2, user)` → he IS user 1.
   User-scoping is RULED OUT as the white cause.
7. **The frontend open path is correct code** (SectionModal.tsx:741-754):
   rowsOnly versions settle → versions[0] (id 176) loads into the editor.
   No remote dependency on that path.

## THE WHITE — mechanism (facts 4+3, mechanism labeled as inferred)

With versions present, the ONLY thing between open and content is the hub
answering `page-versions?rowsOnly=1`. Every hub request costs ~3s of PHP on
this machine and the hub has 2 workers total; each editor open also fires
`pageState` which pins a worker for the full 5s timeout, plus the SPA's
other queries (health, keywords, 5MB ver=time() bundle re-download). Queued
serially on 2 slow workers, the rowsOnly answer can starve long enough to
present as "white". This mechanism is INFERRED from the measured numbers —
the owner's browser Network tab on the next occurrence confirms or kills it
(expected: rowsOnly pending/queued for tens of seconds, not erroring).

## FIX PLAN

- **F1 (hub DATA, this commit's action — no code):** raise
  `pcm_seo_state_check.timeoutS` 5 → 15 in the hub DB (measured max 12.5s
  + margin). The option is the designed tunable (read-through seed keeps
  existing values); the CODE default stays 5 — correct for healthy installs.
  Trade-off, named: while powerleads answers in ~12s, each check holds a hub
  worker ~12s instead of erroring at 5 — accepted to kill the red lie; R4
  shrinks the cost.
- **F2 (owner, one click — the real cure):** R4, `pm.max_children = 2 → 6`
  on the HUB site (and powerleads while at it) + Local restart. Kills the
  starvation math on both sides.
- **F3 (owner, machine): find why EVERY PHP request costs 3-10s here.**
  Opcache is on; prime suspect is AV on-access scanning of the PHP tree.
  An Avast exclusion for the two site folders would show up immediately in
  the same curl timings used above (before/after numbers, one command).
- **F4 (owner, 10 seconds):** open Privacy Policy once the corner answers,
  Save — pushes rules + pageState under 3.0.8, clearing the honest amber.
- NOT touched: connector code (3.0.8 is correct and live), the state-check
  seed default, the open path (already correct), anything in the keywords lane.

## ADDENDUM (same day) — THE DEEPER ROOT CAUSE, PROVEN IN WEB CONTEXT

Owner demanded factual proof the fix works. A temporary mu-plugin probe
(admin-ajax, web context, deleted after use) ran the hub's REAL
`page_state_compare(site 2, post 3)` — and it returned HTTP 500. debug.log
(hub, WP_DEBUG_LOG on) holds the same fatal at 12:31:23 (my probe) AND at
08:41:22 (the owner's own editor open this morning):

    Uncaught TypeError: http_build_query(): Argument #1 ($data) must be of
    type array, string given — Requests/Transport/Curl.php:582

**Mechanism:** seo/service.php:4052 passes `array()` as remote_rest()'s
`$body` for this GET. remote_rest treats any non-null body as payload →
`wp_json_encode(array())` = the STRING `"[]"` → WP's cURL transport calls
`http_build_query()` on GET data → TypeError → 500. **The state-check
request has never left the hub — it crashed on EVERY run since the feature
shipped.** The red cloud was this crash reported as "unreachable"
(stateQuery.isError → stateUnreachable). My F1 timing analysis was real but
SECONDARY — accountability: I claimed the timeout as THE root cause before
tracing the hub's own call end-to-end in web context. This addendum corrects
that.

**Sweep (factual):** line 4052 is the ONLY GET `remote_rest` call in the
codebase passing `array()` as `$body` — every other GET passes null/nothing.
POST callers passing `array()` are legal (string bodies are valid for POST;
`update-now` proves it live).

- **F5 (code, one token):** seo/service.php:4052 `array()` → `null`.
  F1 (timeoutS 15) STAYS — without it the now-working call would genuinely
  time out (5s < measured 12s).
- **Proof protocol:** re-run the same web-context probe after F5 — expected:
  JSON `{local:{version:24,...}, remote:{version:0,...}, drifted:true,
  error:null}` in ~12s. Then the probe mu-plugin is DELETED.
