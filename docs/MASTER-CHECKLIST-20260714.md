# MASTER CHECKLIST — 2026-07-14 — two features, owner GO (build after this commit)

Gaps: GAP-ANALYSIS-REVIEW-SECTION-IDENTITY-20260714.md (d37f10d) ·
GAP-ANALYSIS-KW-DRAWER-FILTERS-PAGEPICKER-20260714.md (b71d565).
Each feature = its own verified commit pair.

## FEATURE A — review section identity (index → anchors)
- [ ] A1 register `data-pcm-review-id` heading attribute (beside data-pcm-origin, same block)
- [ ] A2 stamp anchors at review start — ONE transaction; the ordinal count is trusted only at t0
- [ ] A3 `applySection` stamps the id on the first heading of EVERY write (one-writer choke point)
- [ ] A4 `sectionRange` finds by id; range ends at the next ID-CARRYING heading (id-less = the section's own content)
- [ ] A5 `splitReviewSections` (word-diff sibling, id-boundary walk) consumed by the oracle + both baseline captures
- [ ] A6 chips decoration maps by the heading's id attribute (counter dies)
- [ ] A7 `focusSection` (rail click) locates by id during review
- [ ] A8 exit cleanup transaction on review completion + server strip belt (extend seo/service.php:4695 regex)
- [ ] A9 deleted-anchor edge: decision no-ops honestly (status resolves + toast)
- [ ] A-verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY

## FEATURE B — drawer: per-column filters + page picker
- [ ] B1 shared ModelDropdown gains optional `searchable` (filter input at panel top, additive, groups hide when emptied)
- [ ] B2 drawer table: `layoutKey` + filterDefs (keyword contains · clicks/impressions ≥ N · position ≤ N); the ≥100 checkbox DIES
- [ ] B3 page picker in the drawer (searchable ModelDropdown of the site's pages, default = the edited page); picking re-points {postId, pageUrl} and rescans; + always feeds the EDITED page's bucket
- [ ] B4 index.tsx passes the pages list (id/title/permalink from the rows it already holds)
- [ ] B-verify: tsc 59 · build · changelog · commit LOCAL ONLY

## Then
- [ ] Owner live re-test: subtopics-ticked optimize → accept few → Accept All (Feature A) · column filters + page switching on seeded pages (Feature B)
