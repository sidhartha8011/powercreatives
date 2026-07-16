# GAP ANALYSIS — KEYWORD TABLE: period compare + completeness + real numeric filters — 2026-07-14 — AWAITING GO

**Owner asks:** (1) a compare toggle — current window vs the SAME-LENGTH
period immediately before it — adding per-keyword difference columns for
clicks/impressions/position, sortable, to catch trends; (2) does the table
hold ALL keywords, and is it scrollable; (3) numeric columns must filter
with math (above / below / between, as icons), never "contains".

## Facts (verified)

| # | Fact |
|---|---|
| N1 | COMPLETENESS: the live GSC fetch paginates 25,000 rows/page up to 4 pages (class-pcm-gsc.php:327-345) — effectively ALL keywords for any practical page. The ONLY truncation is the STORED layer's cap (KW_STATS_CACHE_MAX_ROWS = 300, my constant); rows arrive clicks-desc so a cap keeps the most significant. The table body is already scrollable (the drawer's overflow-auto flex area) |
| N2 | FILTERS: the shared filter mechanism has exactly two kinds — 'text' (a "Contains…" input, column-head.tsx:151-161) and 'choice' (checkbox options). Numbers currently ride a 'text' input with a ≥ predicate I supplied — functional but wrong UI, the owner is right. The shared surface is small and additive: one new kind in useColumnFilters + one render branch in column-head |
| N3 | COMPARE: `query_stats` computes its own window from $days (class-pcm-gsc.php) — the previous period is the SAME call with the window shifted back by $days; the Search Analytics API takes arbitrary start/end. Two fetches + a server-side merge by query key = the delta rows. The property chosen for the current period is reused for the previous one (no second candidate hunt) |
| N4 | The stored layer serves whatever shape the live fetch stored — storing the MERGED compare rows makes trends testable on this GSC-less machine via re-seeded data, zero test code (the proven kw-stats-cache law) |

## The design

**C1 — real numeric filters (shared, additive).**
- `FilterKind` gains `'number'`; encoded value `gt:N` / `lt:N` / `bt:N:M`;
  a shared `numberMatch(get)` predicate helper lives beside FilterDef (one
  parser, every consumer).
- column-head renders the number kind as THREE ICON toggles — above (>),
  below (<), between (⇄) — plus one numeric input (two for between). No
  words (owner ruling). 'text'/'choice' untouched.
- The drawer's clicks/impressions/position defs switch to the number kind
  (position keeps its lower-is-better semantics via `lt`).

**C2 — period compare.**
- `PCM_GSC::query_stats` gains `int $offset_days = 0` (window shifted back;
  0 = today's behavior).
- `keyword_stats` gains `compare: bool`: fetch current, fetch previous on
  the SAME property (N3), merge by query →
  `{query, clicks, impressions, position, prev: {clicks, impressions, position|null}, d: {clicks, impressions, position|null}}`.
  Keywords present only in the previous period stay IN (current zeros,
  negative deltas — a vanishing keyword IS the trend signal); keywords new
  this period carry `d.position = null` (no previous rank — a dash, never
  a fake zero).
- The cache stores the served merged set + `{days, compare}` meta (N4).
- Drawer: a `compare` checkbox beside `days` (previous period is implied —
  no second input, owner ruling); when the rows carry deltas the table adds
  three sortable Δ columns (Δ clicks, Δ impr., Δ pos where negative =
  climbed) with number-kind filters. Sorting a Δ column = the trend view.

**C3 — completeness.**
- Live is already complete (N1); the stored cap rises 300 → 1000 (clicks-
  desc keeps significance; option stays autoload-off). Stated honestly: the
  STORED view is "top 1000"; live is everything.
- Re-seed the PowerLeads pages with previous-period values so the compare
  columns are testable today (pure data).

## CHECKLIST
- [ ] BEFORE = this doc committed
- [ ] C1 shared number filter kind (hook + column-head icons) + drawer defs
- [ ] C2 query_stats offset · endpoint compare+merge · cache meta · drawer checkbox + Δ columns
- [ ] C3 cap 300→1000 · re-seed with previous-period data
- [ ] Verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
