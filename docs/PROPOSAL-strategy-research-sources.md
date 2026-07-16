# Proposal: Multi-Source Research for Strategies

**For approval — 2026-07-14**

## The problem
When a strategy writes an article, "Research" uses **one source: a Google web search.** It can't read *your* feeds, use *your* client's own data, or guarantee real numbers for charts.

## The idea
Make research **multi-source**: point a strategy at 4–5 RSS feeds + other sources; the system reads them, dedupes, and writes from real, current, **cited** material.

## Sources — ranked by value vs. effort

| Source | Adds | Already built? | New key? |
|---|---|---|---|
| **RSS feeds** *(the ask)* | Your chosen feeds — competitors, news, your blog | No | **None** (WordPress reads feeds natively) |
| **Web search** | Live SERP + questions | ✅ (today's research) | No |
| **Client Search Console** | What this site already ranks for — highest relevance | ✅ | No |
| **Ahrefs** | Related keywords, volumes, gaps | ✅ | No |
| **Competitor page scrape** | Beat their article structure | ✅ | No |
| **Research API** (Exa/Tavily/Perplexity) | Deeper, better-cited web research | No | 1 key |
| **Stats / trends source** | Real numbers for quality charts | No | 1+ keys |

## Recommended rollout

- **Phase 1 — Core trio (start here):** RSS + web search + Search Console. **~80% of the value, zero new keys.**
- **Phase 2:** competitor scraping + Ahrefs (both already integrated).
- **Phase 3 (optional):** one research API (Exa/Tavily) for depth.
- **Phase 4 (optional):** a stats source for owned, cited chart data — best for AI-search ranking.

## RSS in one line
Per-strategy list of ~5 feed URLs + a freshness window → fetched (cached), read, fed to the writer as cited material. Uses WordPress's built-in feed reader → **no new dependency**; a dead feed is skipped, never blocks generation.

## The "Research" control
Replace Off / Standard / Deep with a **source checklist** (Web search · RSS · Search Console · Competitors) — transparent about what it's actually doing. "Deep" = all on.

## 3 decisions needed
1. **Round one:** RSS only, or the full core trio? *(Rec: core trio — same zero-key cost, far more value.)*
2. **Paid APIs:** open to 1 research key + later a stats key, or keep to already-integrated sources for now?
3. **UI:** keep the dropdown, or move to the source checklist? *(Rec: checklist.)*

## Notes
Additive only — feed lists live in the strategy's existing config, no new tables, no change to how articles are written. On approval this becomes a routed build plan, RSS first.
