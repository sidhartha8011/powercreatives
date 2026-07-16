# GAP ANALYSIS — KEYWORD STATS CACHE (honest, labeled) — 2026-07-14 — owner GO given, implement after commit

**Owner order:** the drawer's keyword table must be testable on this
machine (no GSC connection possible) via a ROBUST senior solution — and
nothing test-specific may be left behind in the code.

## Facts
| # | Fact |
|---|---|
| C1 | The keyword table is the drawer's ONLY live-Google surface — `keyword_stats` fetches per click, stores nothing (optimizer/controller.php). No integration → honest error; nothing in any DB feeds it. That is WHY seeding couldn't light it up |
| C2 | The bucket already proves the storage pattern: an option map keyed `site:post` in the optimizer service — the cache reuses the identical pattern, no new concepts |
| C3 | A response cache is a legitimate PRODUCTION feature (instant drawer opens, fewer Google quota hits, resilience when Google hiccups) — the test-enablement is a side effect of seedable DATA, zero test flags, zero mocks, zero code paths that exist only for testing |
| C4 | The no-silent-fallback law requires stored data to SAY it is stored — a visible "stored <date>" status fact (status facts are sanctioned UI; instructional prose is not) |

## Design
- **Store on success:** every successful live fetch writes
  `{property, rows (capped 300), fetchedAt}` into option map
  `pcm_optimizer_kw_stats_cache` keyed `site:post` (C2 pattern, autoload no).
- **Serve labeled on failure:** no integration / Google error → if a stored
  entry exists, return it with `source: 'stored'` + `fetchedAt`; else the
  honest error exactly as today. Live responses return `source: 'live'`.
- **Drawer:** shows a small quiet status chip when `source === 'stored'`
  ("stored YYYY-MM-DD") beside the controls; the refresh button still
  attempts live. No other UI change.
- **Seeding = data:** fake keyword rows written into the cache option for
  the PowerLeads test pages — deletable rows, the code never knows they are
  fake.

## CHECKLIST
- [ ] This doc committed → implement (owner pre-authorized)
- [ ] Server: store-on-success + labeled stored-serve in keyword_stats + cache helpers in the service
- [ ] Drawer: source/fetchedAt handling + stored chip
- [ ] Seed fake rows for the PowerLeads pages (pure data)
- [ ] Verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
