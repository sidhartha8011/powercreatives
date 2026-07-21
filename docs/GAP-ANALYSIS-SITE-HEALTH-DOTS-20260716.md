# GAP ANALYSIS — SITE HEALTH DOTS + HONEST CONNECTOR ERRORS (owner GO 2026-07-16)

Owner design: the SEO tab bar's globes become EXTREMELY SMALL dots; the
dot is a SHARED feature (never hardcoded per spot); behind it runs a real
CONNECTION TEST (is the connector actually working), batched upfront so
failures are known BEFORE clicking. Red ONLY when broken; healthy keeps
the tab's normal color (active blue as today). Bundled (owner GO, same
arc): the glyph's "not reachable" lie for outdated connectors becomes an
honest named error (proven 2026-07-16: /page-state 404 on connector 3.0.6
while the site itself answered fine).

## FACTS (live-verified, HEAD cad42df)
1. The tab bar renders a 14px Globe in two spots (SEO/index.tsx:1332
   local, :1342 per site) — presentation only, no logic attached.
2. A REAL connection test EXISTS: POST /sites/{id}/test →
   PCM_Sites_Service::test_connection (sites/service.php — auth'd
   /wp/v2/users/me via rest_route form, 15s timeout, throws on failure)
   + tRPC "sites.test". Per-site, POST, one at a time — no batch form.
3. Firing N per-site tests from the frontend = N parallel hub requests
   on a 2-WORKER pool (verified conf) — the freeze trap. The batch must
   run SERVER-side, sequential, short-timeout.
4. test_connection's 15s timeout is hardcoded in code (pre-existing);
   the batch needs a short hub-DATA timeout — the tunables law.
5. The reachability lie (proven by probe): connector 3.0.6 answers 404
   on /page-state (route born in 3.0.7) while /config answers 401
   (alive) — page_state_compare currently reports any failure as the
   generic error the glyph titles "couldn't reach".
6. Sites domain owns connections (modular boundary law) — the batch
   route belongs to the SITES module; SEO only consumes.

## DESIGN
- D1 **Batch health route (sites module)**: GET /sites/health — lists
  the user's sites, tests each SEQUENTIALLY via the EXISTING
  test_connection logic with a short timeout from a seeded option
  pcm_sites_health_check {timeoutS:5} (read-through; the existing
  15s single-test path untouched), returns {siteId: {ok:bool,
  error:string|null}}. tRPC line sites.health.
- D2 **Shared dot**: NEW app/src/modules/SEO/SiteStatusDot.tsx — ONE
  component: ~6px round dot, `bg-current` (inherits the tab's color;
  active tab stays blue) · red + title naming the error when ok===false
  · no green state (owner ruling). NEW hook useSiteHealth.ts — ONE
  query of sites.health (staleTime 60s), map keyed by siteId; every
  future SEO surface reuses both.
- D3 **Tab bar**: both Globes replaced by SiteStatusDot; the local tab's
  dot never tests (the hub is itself) — always current-color.
- D4 **Honest connector error** (page_state_compare): a 404/rest_no_route
  reply → error 'The site's connector is outdated (needs 3.0.7+) —
  update it from the Sites module.' — the glyph title then names the
  fix, never "not reachable" for a reachable site.

## CHECKLIST
[ ] D1 batch route + seeded timeout + tRPC line · [ ] D2 SiteStatusDot +
useSiteHealth · [ ] D3 tab bar swap (2 spots) · [ ] D4 honest 404 error ·
[ ] php -l · [ ] harness 88/88 + 26/26 + 34/34 · [ ] tsc 59/0 ·
[ ] build · [ ] changelog · [ ] AFTER commit LOCAL ONLY · [ ] owner:
dots visible, red on a broken site, glyph names the connector update
