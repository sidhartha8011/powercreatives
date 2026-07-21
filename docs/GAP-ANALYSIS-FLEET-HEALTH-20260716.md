# GAP ANALYSIS — FLEET HEALTH ARCHITECTURE (owner GO 2026-07-16, researched)

Owner: heartbeats + background probes + stored-map reads + cached tables —
100-site scale, zero click-path remote calls, AND a 100% debt cleanup that
cannot break anything (safety invariants below).

## FACTS (live, HEAD b223bbb)
1. remote_rest (sites/service.php:137) is THE single choke point every
   site call already flows through — the heartbeat's one home.
2. sites_health (sites/controller.php, shipped TODAY 6e35d3c) tests every
   site SEQUENTIALLY inside the request — up to N×5s holding one of TWO
   PHP workers at SEO mount; proven the mount regression. THIS is the
   debt to remove.
3. useSiteHealth (SEO/hooks) reads {health:{id:{ok,error}}} — the
   frontend contract can stay; additive checkedAt only.
4. test_connection(site,timeout) exists (additive param, 6e35d3c) — the
   probe's engine, reused as-is.
5. pcm_sites_health_check option exists {timeoutS:5} — grows cadence keys.
6. seo.remoteContent (hooks/useRemoteSeoContent:43) = live remote list
   round-trip on every SEO mount for a connected tab — phase 4's target.
7. Research (committed sources): MainWP pattern = background per-site
   checks + stored dashboard reads; server-cron over WP-cron; SWR reads.

## DESIGN
- P1 HEARTBEAT: in remote_rest's return path record per-site
  {ok, error, at, source:'heartbeat'} into option 'pcm_site_health'
  (autoload off). Failures = transport WP_Error or status>=500 (4xx is an
  ANSWER = alive). ~20 lines, zero new calls.
- P2 STORED READS: sites_health handler REPLACED — returns the stored map
  + checkedAt/ageS per site; NEVER tests. Dot tooltip gains age. The
  sequential loop is DELETED in the same commit (named cleanup).
- P3 PROBE QUEUE: wp_schedule_event 'pcm_site_health_probe' (interval
  from option, seed 300s); each run probes the M stalest sites (seed
  maxPerRun:3) whose heartbeat age > staleS (seed 900s), via
  test_connection(short timeout), writes the same option,
  source:'probe'. Guarded registration (no duplicate schedules),
  unscheduled on deactivation if an unschedule hook exists — else noted.
- P4 CACHED TABLES (SWR): remoteContent serves stored copy instantly —
  hub stores last list per site in option 'pcm_remote_content_cache'
  (capped rows, autoload off, savedAt) — reply gains {source:'stored'|
  'live', fetchedAt}; frontend: serve cache, background refetch swaps
  rows when fresh lands (kw-drawer precedent). Live fetch failure with a
  cache = stored+labeled; without = honest error (no silent fallback).
## CLEANUP (100%, with safety invariants)
- DELETE: the sequential test loop in sites_health (P2 replaces it).
- KEEP: test_connection + timeout param (P3 consumer), single-site
  /test route (deliberate manual action), pcm_sites_health_check option
  (absorbs new keys via merge pattern).
- INVARIANTS that make cleanup safe: (a) frontend contract unchanged
  (health map keys identical, additive fields only); (b) every deletion
  has its replacement land IN THE SAME COMMIT; (c) full harness suite +
  tsc + build green BEFORE the commit; (d) no other consumer of
  sites_health exists (verified: only useSiteHealth).

## CHECKLIST
[ ] P1 heartbeat in remote_rest · [ ] P2 stored-read handler + delete
loop + tooltip age · [ ] P3 cron probe + tunables merge · [ ] P4 SWR
content cache (hub + hook) · [ ] cleanup per invariants · [ ] php -l ·
[ ] harnesses 88/26/34 · [ ] tsc 59/0 · [ ] build · [ ] changelog ·
[ ] AFTER commit LOCAL ONLY · [ ] owner: SEO mount instant, dots age
