# ARCHITECTURE — ICE, THE STEERING WHEEL — 2026-07-17

Owner-confirmed vision (blueprint §10 entries 2026-07-17). Home: a per-site
**Keywords tab inside the SEO module** (like the content tab) + results
columns on the Pages table + one unified graph per page. This seat's lane.

## 1. DATA SPINE — one timeline, everything else is views

**Table `wp_pcm_seo_rank_weeks`** (PCM_Schema, indexed — NEVER options):
`id · siteId · keyword (normalized) · targetPostId · weekStart (date,
Monday) · rankPrt (nullable smallint) · rankManual (nullable smallint) ·
rankedUrl (varchar) · fetchedAt · manualBy/manualAt · UNIQUE(siteId,
keyword, weekStart)`; indexes (siteId, weekStart) + (siteId, keyword).
- **Effective rank = rankManual ?? rankPrt** — the ONE value every view
  uses; the other field is the hover receipt. A fetch writes rankPrt only —
  a manual correction can never be clobbered.
- **Mismatch = derived**, never stored as verdict: rankedUrl vs the target
  page's URL at render time (target moves → history re-judges honestly).
- Scale: 200 sites × 20 kw × 104 wk ≈ 416k rows ≈ tens of MB. Fine.
- Retention: cron prune > `weeksKept` (seeded 104) — tunables in ONE
  read-through option `pcm_seo_rank_tracking` (weeksKept, fetch cadence,
  batch size). The tunables law.

**Tracked-keyword registry**: the tab's rows = the site's agreed working
set. Source of truth = the existing per-page keyword assignments (primary/
supporting per page — already stored) + site-level extras added in the tab.
No second keyword store; the tab READS page assignments and OWNS only its
extras + the tracking flag.

**Events (notes)**: table `wp_pcm_seo_timeline_notes` — `id · siteId ·
postId (0 = site-wide) · noteDate · text · createdBy/At`. Optimization
stamps already exist (results-loop history) — consumed as a second event
source, not duplicated.

## 2. FEED — Pro Rank Tracker

- New **Integrations provider** entry `prorank` (key per user — the
  existing pattern: add a key, gain a voice).
- Weekly pull: cron job walks sites SEQUENTIALLY (2-worker law), upserts
  the current weekStart row per keyword (rankPrt + rankedUrl + fetchedAt).
- A site's failed fetch = a visible failure row in the tab with the named
  reason — never a silent gap (empty cell stays honestly empty).
- Depends on the standing owner infra item: local server-cron switch for
  cron reliability. Manual "Fetch now" button in the tab = the same code
  path, user-triggered.

## 3. VIEWS (all read the spine, none own data)

1. **Keywords tab (SEO module, per selected site)** — the weekly service
   hatch: rows = keywords (target page beneath), frozen first column;
   columns = weeks, newest adjacent, auto-new each Monday, horizontal
   scroll over the window. Cell = effective rank + movement vs prior week;
   click-to-type = manual override (mark + receipt on hover); mismatch
   flag when rankedUrl ≠ target URL. Row tick(s) → line graph from exactly
   those rows. DataTable + shared controls (shared-components law).
2. **Pages table columns (steering wheel)** — per page: current effective
   rank of its primary keyword, movement arrow, sparkline. RESULTS-FIRST
   column order (blueprint reminder).
3. **THE UNIFIED GRAPH (per page)** — one time axis; series: effective
   rank (from the spine), GSC position/impressions/clicks (stored rows,
   labeled `gsc:stored`); each series toggleable. Event markers on the
   same axis: optimization stamps + notes (add-note = click a date).
   Every point carries its source label (manual/prt/gsc:stored).
4. **ICE rollups** — site: improving/flat/declining counts + avg movement
   over a chosen window; domain: the same aggregated. Movement only —
   scoring stays parked (owner ruling).

## 4. BUILD ORDER (each = own gap → GO → pair)

P1 spine: tables + tunables option + tracked-keyword registry read.
P2 tab: the weekly table + manual override + Fetch now (PRT provider).
P3 cron: weekly pull + prune + failure rows.
P4 steering wheel: Pages results columns + sparkline.
P5 unified graph: series + toggles + events + notes.
P6 ICE rollups: site + domain.

## 5. LAWS THAT BIND IT
Gap before every pair · tunables = hub data · no silent fallbacks, sources
labeled, empty stays empty · manual never clobbered by fetch · shared
components only · mismatch surfaced (cannibalization signal → §9.4 fix
actions) · Keywords-module/drawer untouched (lane ruling 2026-07-17).
