# GAP ANALYSIS — AHREFS VOLUME CASE-LOOKUP BUG (owner report 2026-07-15) — AWAITING GO

**Owner report:** "Privacy" shows no volume — "the word privacy should have
a massive amount of searches." Correct, and the fetch is provably at fault.

## Facts (verified at lines + live cache dump)

| # | Fact |
|---|---|
| F1 | `ahrefs_enrich` keys its result by the keyword string AHREFS ECHOES BACK (`$item['keyword']`, keywords/service.php:382-385) — Ahrefs normalizes to lowercase |
| F2 | The optimizer's volume callback looks up `$enriched[$kw]` with the ORIGINAL casing (optimizer/controller.php:87-89) — `"Privacy"` never matches `"privacy"` → null |
| F3 | `keyword_volumes` then CACHES that null for 30 days as "Ahrefs doesn't know it" (optimizer/service.php:339) — the honest-dash tooltip now tells the user a falsehood built on a lookup miss |
| F4 | **Live cache dump proves the pattern:** ALL capitalized entries are null (`Privacy`, `Best Policy`, `Supporting Policy`, `Extra support`, `basta privacy policy` typed from a capitalized row) while lowercase entries carry real numbers (`privacy policy` = 10000, `seoul` = 115000) |
| F5 | Lane check: `keywords/service.php` is MY module and NOT in the other dev's in-flight set; `optimizer/controller.php` IS his in-flight file — the fix must land in keywords/service.php only |

## Design

- **D1 — Fix at the source, contract preserved:** `ahrefs_enrich` re-keys
  each echoed item back to the CALLER'S input casing (lowercase map
  input→original built once; echoed keyword matched case-insensitively).
  Every consumer keeps the same contract — map keyed by the keywords it
  asked for — so the optimizer callback (F2, his file) works unchanged.
- **D2 — Poisoned cache self-heals by existing machinery:** the wrong
  nulls sit under 30-day TTL; the selected table's update button is a
  `refresh: true` cache bypass — one press after the fix re-buys and
  overwrites. No migration, stated here.

## Checklist (ONE pair, one file)

1. keywords/service.php: D1 — php -l.
2. Harness 88/88 · tsc 59 (untouched TS) · build "built in" · changelog ·
   AFTER pathspec commit, LOCAL ONLY, my files only (F5).

## Regression surface (named)

ahrefs_enrich consumers: response keys only ever CHANGE for inputs whose
casing differs from Ahrefs' echo — exactly the inputs that got null before
(nothing that worked changes) · difficulty/cpc fields ride along untouched ·
optimizer callback, volume cache, drawer: no edits.
