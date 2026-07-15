# GAP ANALYSIS — KEYWORD VOLUMES: MANUAL TRIGGER (owner order 2026-07-14) — AWAITING GO

**Owner order:** volumes must never be fetched for finder keywords without a
click. The SELECTED table (top) is the ONLY place that may get + cache
volumes automatically. A small button is the trigger — and it serves BOTH
finder sources: the GSC Ranking tab and the Ideas search tab. Goal: no
wasted Ahrefs credits.

## Facts (verified at lines)

| # | Fact |
|---|---|
| F1 | **The leak:** the Ideas tab live-fetches volumes automatically — `KeywordsDrawer.tsx:339` calls `fetchVolumes(list)` with NO `cachedOnly` after every search. Up to 20 keywords hit Ahrefs per search, unasked (the owner's screenshot: seed "seo" → seoul 115000 … all fetched on search) |
| F2 | The Ranking tab's auto-fill is ALREADY cache-only (`KeywordsDrawer.tsx:279`, `{ cachedOnly: true }`) — credit-free by design, comment says so |
| F3 | The SELECTED table auto-fetches live volumes on change (`KeywordsDrawer.tsx:181-184`) — exactly what the owner sanctions to remain |
| F4 | The server endpoint is cache-first (`optimizer/service.php:304-349`): cache hits are free; only misses go to Ahrefs; `refresh` bypasses the cache; `cachedOnly` never calls Ahrefs. TTL'd option map, capped |
| F5 | The header update button (`KeywordsDrawer.tsx:363-375`) re-fetches selected + ranking rows + ideas with `refresh: true` — a cache BYPASS, so every press costs credits for all listed keywords (sliced to 20) |
| F6 | **Defect found while probing:** `cachedOnly` misses are returned as `null` (`service.php:322-326`) and merged into client state (`KeywordsDrawer.tsx:170`); the dedupe filter `!(k in volumes)` (`:166`) then treats them as known — a keyword seen in a scan and LATER added to Selected never live-fetches its volume. No harness test pins the null-injection (grep over tests/standalone: zero matches) |
| F7 | The volumes endpoint has exactly ONE consumer — this drawer (`trpc-routes.ts:35`, `KeywordsDrawer.tsx:161`) — the response-shape fix in D4 breaks nobody else |
| F8 | The dash cell already renders for BOTH null and absent keys (`volCell`, `KeywordsDrawer.tsx:176-179`, `!= null` check) — omitting misses from the response changes zero pixels |

## Design

- **D1 — Ideas auto-fetch goes cache-only.** `:339` gains
  `{ cachedOnly: true }`, matching Ranking (F2). Cached volumes still
  appear instantly and free; the rest are dashes until the trigger.
- **D2 — THE TRIGGER: one small volume button in the finder tab row**
  (right side, TrendingUp icon — same anatomy as the header one, spinner
  while pending). Press = fetch volumes for the ACTIVE tab's rows,
  **cache-first, NOT refresh** (F4): credits are spent only on genuinely
  uncached keywords, deliberately. One button serves both tabs — "both
  for the GSC and for the search function".
- **D3 — The header update button scopes to the SELECTED table only** —
  the deliberate re-fetch (refresh bypass) of the page's own keywords.
  The finder's volumes belong to the finder's button. (If the owner
  prefers the header button to keep covering everything: one line, say
  the word.)
- **D4 — Fix F6 at the source:** `cachedOnly` misses are OMITTED from the
  response instead of returned as null (`service.php:322-326`). Rendering
  identical (F8), client state stays unpoisoned, a scan keyword later
  added to Selected live-fetches correctly. Cached "Ahrefs doesn't know"
  nulls still flow through — the dedupe rightly skips those.

## Checklist (ONE ritual pair)

1. `service.php`: cachedOnly misses omitted (D4) — php -l.
2. `KeywordsDrawer.tsx`: Ideas fetch → cachedOnly (D1) · finder volume
   button in the tab row (D2) · header button → selected rows only (D3).
3. Harness 88/88 · tsc 59 + zero new · build ("built in" line) ·
   changelog line · AFTER commit ending LOCAL ONLY.

## Regression surface (named)

Selected-table auto-fetch untouched (F3; F6's fix makes it CORRECT for
scan-then-add) · Ranking scan flow untouched (F2 stays) · server cache
write path untouched (only the cachedOnly miss-shape changes; sole
consumer verified, F7) · no shared component touched · review machinery,
optimizer rail, bucket writes: not in the blast radius.
