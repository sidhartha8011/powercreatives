# HANDOVER — 2026-07-18 → NEXT DEVELOPER

Read this FULLY, then docs/HANDOVER-20260717-NEXT-DEV.md (owner style, laws,
platform purpose — all still binding). You are senior dev/engineer/architect
(+ Apple-calibre UX on request) of Power Creatives.

## 0. ⚠ IN-FLIGHT STATE — FINISH THIS FIRST
The working tree holds UNCOMMITTED backend edits of one bundle (gap
`bff4a68` = docs/GAP-ANALYSIS-HIERARCHY-AI-COMPLETE-20260718.md — read it;
its checklist is the truth). DONE (lint-clean, uncommitted): sites.projectId
+ DB 1.45.0 chain migration · resolver walks Site→Project→Delivery→Brand
(legacy sites.brandId = labeled read-only fallback, NO writer remains) ·
/sites/{id}/business-map (4 modes, ONE chain-builder in sites service, also
wired into add-site auto-map) · /business/ai-complete (page-fetch + ONE
evidence-bound invoke_json, fill|overwrite, manual layer survives) ·
/business/brand-sync (owner-clicked offer) · card reply chain data ·
address=street fix + harness test. LEFT: card frontend (3 dropdowns w/
derive-and-lock: Brand combobox FIRST pre-selected, Delivery, Project;
Complete-with-AI button + mode; brand-sync button; PLATFORM gray #f8f9fa —
NOT slate; field order name→niche→description→language…; City stays) ·
trpc entries (businessMap/aiComplete/brandSync) · verify ritual · live
probes (migration on real DB: site 2 legacy brand 13 → real chain; map e2e;
one bounded ai-complete) · changelog · seo stamp 1.0.11 · AFTER commit.

## 1. THE RITUAL (unchanged, owner-enforced)
Factual gap committed BEFORE code + owner GO → build → php -l each file →
tests/standalone: run.php **88/88** · page_versioning_test.php **69/69**
(grew from 26: parse/ladder/maps/gbp-normalize/txn sections live here) ·
optimizer_research_test.php **34/34** → `cd app && npm run check` = **59
pre-existing tsc errors, ZERO new** → `npm run build` ("built in" MUST
print) → curl byte-compare served vs dist (served = disk, ver=time()) →
changelog line (docs/CHANGELOG-20260709-2200.md) → AFTER commit "LOCAL
ONLY". NEVER push. Bump the touched module in app/src/lib/module-versions.ts
(the bottom-left stamp; seo currently 1.0.10) — the stamp ritual law.
LIVE-PROVE server work via a temporary mu-plugin (admin-ajax, secret-keyed,
wp_set_current_user(1) + wp_create_nonce('wp_rest') + rest_do_request for
REAL routes) — DELETE the probe after, verify 400. Never claim a frontend
outcome without tracing EVERY consumer of the state you changed (grep) —
this session's biggest lesson, twice.

## 2. WHAT THIS SESSION BUILT (2026-07-17→18, all committed except §0)
- **Fixed the day-one white/red editor saga**: state-check 500 (array()
  GET body → http_build_query fatal — the red cloud since birth), timeout
  tunable 5→30 (pcm_seo_state_check), E1 check-after-paint, E1b — the REAL
  white: 9 screen/action gates keyed to pageReady (inventory-only flag the
  instant-open path never sets) → rewired to docLoaded. Editor works.
- **THE ATOMIC SAVE**: one save = ONE push = ONE version, total rollback
  ("Nothing was saved"), deferral chokepoints harness-tested; corner
  seeds confirmed-green from the push itself; save reply carries
  pushed+pageState. Save's baseline pull still rides the slow site.
- **CHANGE-CARD REVIEW T1+T2**: append-envelope JSON contract, server-
  VERIFIED per-section change list (quote must exist; why ∈ purposes),
  rewritten verdict → calm consolidated diff, own-changes rail cards w/
  hover quote-highlight, per-change ticks + "Update proposal (N of M)"
  via the revise path. FOUND+FIXED: trpc transform dropped `draft` —
  revise retention was dead on the wire since shipping.
- **BUSINESS SPINE P1-P3**: brand_business_units (fetched/manual/sources
  two-layer; manual survives refresh), PCM_SEO_GBP facade, pure ladder
  (site override > unit > brand > site basics) under ALL generation
  consumers, per-site SEO overrides (pcm_seo_biz_site_{id}), the Business
  card (registry-driven two rooms, per-field source hover), auto-map by
  exact domain, refresh popover saving the Indexed URL.
- **GOOGLE NATIVE → APIFY**: n8n DELETED; google_places + apify
  Integrations providers; apify is default (seo_gbp_provider setting) —
  ONE actor (tunables pcm_seo_apify) returns place + placeId/cid/fid/
  KGMID + reviews. share.google links are a JS-only WALL (proven) —
  named error; Find-on-Google search→pick→fill is the reliable path.
  Centroid guard: SAB listings (nationwide) have NO address — never store
  Google's country-centroid geo. Service areas/primary location are NOT
  in public data — owner-declared, or Phase B (see
  docs/REFERENCE-GBP-REVIEWS-LOCATIONS-20260717.md: owner-account GBP
  API, serviceArea readMask, accounts-walk + pagination — also fixes the
  owner's n8n missing-locations mystery; reviews machinery preserved
  there for a future reviews plugin).
- **Fleet**: heartbeat FLAP GUARD (failThreshold 2 consecutive fails,
  instant heal) — killed the random red dot (root: one Avast blip flipped
  the stored verdict). Module version stamps bottom-left (+__PCM_BUILD__
  hover = build time; the stale-tab detector).

## 3. ENVIRONMENT (this machine — beyond yesterday's handover)
- **Avast intermittently kills outbound HTTPS from WEB PHP too** (proven
  twice live) — retry before believing a transport error; the flap guard
  exists because of it. Owner env items open: workers (fpm conf is a
  Windows NO-OP — real lever unknown), AV exclusions test.
- Hub fatals → app/public/wp-content/debug.log (logs/php is stale).
- Local site DB ports: hub 10017, PowerLeads 10041 (sites.json). mysqli
  probes need mysqli_set_charset utf8mb4 (emoji in reviews data).
- Apify + a text-AI key are configured for user 1 (live-tested). DB now
  1.45.0-pending (migration runs on next page load after §0 commits).

## 4. ARCHITECTURE DOCS (blueprint §10 references them — never lose)
BLUEPRINT-MASTER-OPTIMIZER-20260715.md (§10 = the append log; ICE +
Business Spine + change-card + Meta-Ads harness tabs + Google-native
entries all logged) · ARCHITECTURE-BUSINESS-SPINE-20260717.md ·
ARCHITECTURE-ICE-STEERING-WHEEL-20260717.md (owner-confirmed, unbuilt —
rank table/PRT/unified graph/notes) · every GAP-ANALYSIS-*.md is dated
and states its own verify plan.

## 5. OPEN STACK (after §0), owner-priority order roughly
1. Owner browser-verifies the finished bundle (his 30s checks are the law).
2. ICE P1 (the rank-table spine; PRT provider exists in Integrations).
3. GBP Phase B (reference doc = the spec seed) — service areas, full
   reviews, locations.
4. Schema builder (Business Spine P5 — the IDs now flow; jsonld connector
   push exists; NO self-review markup — policy law).
5. E2 stored-first corner verdict (pipeline gap C2) · atomic-save
   baseline-from-fingerprint · Meta-Ads harness page switcher · stamp
   placement polish · bundle cache-busting (ver=time()) · R4/Avast (owner).
