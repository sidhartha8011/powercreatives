# GAP ANALYSIS — drawer: delete · search · volumes button · full parity — 2026-07-14 — owner GO given, implement after commit

## Facts (all proven live or at their lines)

| # | Fact |
|---|---|
| Z1 | DELETE: the bucket option (dumped live) proves the owner's + ADDS persisted server-side for page 2:3, and his × DELETES never arrived (the option is unchanged). Add and delete share ONE endpoint — the break is client-side in the ×→persist chain, which has NO .catch and NO optimistic update (useKeywordBucket.persist): any failure is invisible and the UI never moves — the silent-fallback law violated in UX form. Fix: optimistic cache update (setQueryData immediately) + honest .catch toast + revert on failure — either it just works, or the REAL error becomes visible text we can kill precisely |
| Z2 | SEARCH: proven in the server log at the exact minute of the owner's attempts — `Google suggest wp_remote_get failed — cURL error 28: Connection timed out` (twice). Outbound to suggestqueries.google.com times out ON THIS MACHINE (environment class); `google_suggest` returns [] on failure and the controller wraps that as an EMPTY SUCCESS — the UI honestly can't tell "no ideas" from "Google unreachable". Fix: `google_suggest` returns WP_Error on transport failure; its two consumers adapt — the single-search endpoint surfaces the real error (the drawer then SHOWS it), the batch keeps its by-design per-prefix tolerance (logs + skips) |
| Z3 | VOLUMES: the log ALSO proves Ahrefs enrichment WORKS on this machine (…enriched 9/15…). The owner wants volume in BOTH lists + a manual UPDATE: the ranking (GSC) table lacks a Volume column; the volumes endpoint has no cache-bypass. Fix: Vol. column on the ranking table (same width/filter as elsewhere), volumes auto-fill from the CACHE for listed keywords, and ONE update button in the selected zone re-fetches all listed keywords with `refresh: true` (endpoint param that skips the 30-day cache) — deliberate press, credits respected |
| Z4 | PARITY: the ideas table has no layoutKey/filters; column widths differ across tables. Fix: every table gets layoutKey + the same filter kinds (text contains / number icons); shared columns get shared width constants (KW_COL, VOL_COL…) so the tables read as one system |

## CHECKLIST
- [ ] This doc committed → implement (owner pre-authorized)
- [ ] Z1 optimistic bucket updates + honest failure toasts (add AND remove)
- [ ] Z2 google_suggest → WP_Error on transport failure; search endpoint surfaces it; batch tolerance unchanged; drawer shows the real message
- [ ] Z3 refresh param on the volumes endpoint · Vol. column in ranking · the update-volumes button
- [ ] Z4 ideas table layoutKey+filters · shared width constants across all three tables
- [ ] Verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
