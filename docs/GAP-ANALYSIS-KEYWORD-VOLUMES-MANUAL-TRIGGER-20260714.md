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

---

# ADDENDUM — owner refinement 2026-07-14 (same session, pre-GO):
# stale volumes + global reuse + solidity. D3 is REVERSED; D5–D7 added.

**Owner refinement:** volumes change over time, so an update-every-time
trigger must exist alongside the get-missing one; BOTH tables get a manual
trigger; the top table keeps auto-fetching empties + caching; the cache
should be global for the SEO model — a keyword one site already paid for
serves every other site free; the architecture must stand no matter how
many pages/versions.

## Additional facts (verified at lines)

| # | Fact |
|---|---|
| F9 | Staleness is ALREADY architected: entries older than the 30-day TTL count as MISSING on any cache-first read (`service.php:286`, `:314-315`) — a "get missing" automatically re-buys expired volumes; drift self-heals within 30 days |
| F10 | **The cache is ALREADY hub-global:** the map is keyed by the keyword ALONE — no siteId/postId anywhere (`service.php:285`, `:306`, `:313-316`, `:339`). One pool for every site, page, and version in the hub; cross-site reuse exists by construction (the owner's ask = confirmed fact, zero work) |
| F11 | `refresh: true` bypasses cache reads per keyword (`:314`) — the update-every-time semantic exists server-side; the header button already uses it (F5) |
| F12 | Scale bound: cap 500 most-recently-fetched keywords, evicted by `fetchedAt` (`:287`, `:341-344`), autoload OFF (`:345`) — the row loads only on volume calls. 500 is the one weak point for fleet scale |

## Design refinements

- **D3 — REVERSED (owner ruling):** the header update button KEEPS its
  full scope — it is THE update-every-time trigger (fresh pull, cache
  bypass, F11) for everything currently listed: selected + finder rows.
  Volumes change; this is the deliberate re-buy. (The earlier "ONE header
  update button" law stands untouched.)
- **D5 — The finder's small button = GET:** fetches the active tab's rows
  that are missing OR expired (F9), cache-first — credits only for what
  the hub genuinely lacks. Answer to the owner's either/or: the get
  button buys only no-volume + older-than-30-days; the header button is
  the buy-everything-fresh.
- **D6 — The matrix, stated:** AUTO & frugal — top table fetches its
  empties + caches (F3, stays); finder tabs read the cache for FREE only
  (D1). MANUAL — finder button = GET (D5); header button = UPDATE all
  listed (D3-reversed). Nothing spends a credit without a click except
  the top table's empties, exactly as ordered.
- **D7 — Solidity for fleet scale:** the global keyword-keyed pool (F10)
  scales with VOCABULARY, not pages or versions — the right shape. Raise
  the cap 500 → 2000 (one constant, one source of truth, ~150 KB
  serialized worst-case, autoload off so page loads never carry it).
  Named upgrade path, deferred: a dedicated `kw_volumes` table when the
  fleet's vocabulary outgrows the option — the function signature already
  isolates storage, so the swap touches one method.

## Checklist (unchanged pair, updated items)

1. `service.php`: cachedOnly misses omitted (D4) · cap 500 → 2000 (D7) —
   php -l.
2. `KeywordsDrawer.tsx`: Ideas auto-fill → cachedOnly (D1) · finder GET
   button in the tab row (D5) · header update button scope UNCHANGED
   (D3-reversed — no code change).
3. Harness 88/88 · tsc 59 + zero new · build ("built in" line) ·
   changelog line · AFTER commit ending LOCAL ONLY.
