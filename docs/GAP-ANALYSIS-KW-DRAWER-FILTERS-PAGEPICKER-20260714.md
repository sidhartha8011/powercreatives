# GAP ANALYSIS — DRAWER: per-column filters + the page picker — 2026-07-14 — AWAITING GO

**Owner asks:** (1) use the shared table's own per-column filters instead of
the bolted-on impressions checkbox; (2) the page selector he originally
specced — see and CHANGE which URL the keyword data is pulled from
(searchable dropdown; the MVP cut had deferred it).

## Facts (verified)

| # | Fact |
|---|---|
| T1 | The shared DataTable HAS per-column filters: `filterDefs: Record<key, FilterDef>` rendered in the header (filter dot → box), REQUIRING a `layoutKey` (data-table.tsx:142, :135). The drawer table passes neither today — that is the whole gap |
| T2 | `FilterDef` is `{kind: 'text'|'choice', match(row, value)}` (useColumnFilters.ts:20-27) — module-supplied predicates: keyword = contains; clicks/impressions = numeric ≥ (typed number); position = numeric ≤ (lower is better). The ≥100 checkbox DIES (owner order) — noise filtering moves into the impressions column filter, user-controlled |
| T3 | The shared ModelDropdown has NO search (verified: zero matches for search/filter/input) — the page picker needs one. The shared-component law forbids a bespoke dropdown; the sanctioned move is EXTENDING the shared one (precedent: the owner-ordered `success` PillButton variant): an optional `searchable` prop rendering a filter input at the panel top — every consumer can use it later |
| T4 | The pages list already exists at the call site — the SEO table's `rows` (id, title, permalink) are in index.tsx where SectionModal is mounted: passing them as a prop costs ZERO server work. GSC-indexed-pages as the source stays unnecessary: the site's own pages ARE the choosable URLs, and the stats endpoint already takes any pageUrl |
| T5 | The stats cache is keyed site:post and the endpoint takes {siteId, postId, pageUrl} — switching the picker just re-points those three; the seeded stored rows per page make switching TESTABLE today. THE BUCKET STAYS BOUND TO THE EDITOR'S OWN PAGE (owner spec: keywords are added to the page being optimized) — the picker changes the DATA SOURCE only |

## The design
- **D-filters:** drawer table gains `layoutKey: 'optimizer-kw-drawer'` +
  filterDefs per T2; the impressions checkbox is deleted; `related` stays
  (it is a cross-column semantic toggle, not a column filter).
- **D-picker:** shared ModelDropdown gains optional `searchable` (one input,
  filters the open panel's items — additive, no consumer changes); the
  drawer shows it above the controls row: all site pages (T4), default =
  the page being edited; picking re-points {postId, pageUrl} for the stats
  fetch (T5) and rescans. A row's + still adds to the EDITED page's bucket.

## CHECKLIST
- [ ] BEFORE = this doc committed
- [ ] Shared ModelDropdown `searchable` (additive)
- [ ] Drawer: filterDefs + layoutKey, checkbox dies, page picker wired
- [ ] index.tsx passes the pages list
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
