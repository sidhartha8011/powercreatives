# GAP ANALYSIS — BLANK FIRST OPEN + HONEST LOADING PHASES (owner critical 2026-07-17)

Owner screenshot: editor opens pure WHITE 30+s, no message. Plus: every
first load anywhere must SAY what it is doing; plus a seniority sweep of
the recent code.

## FACTS (live, HEAD 01eadda)
1. ROOT CAUSE (mine, instant-open e48b1ff): the open now gates on the
   page-versions query — but list_page_versions (seo/service.php) calls
   remote_fetch_snapshot INLINE (a remote round-trip) to build
   originalHtml BEFORE returning the saved rows. Every open = a remote
   fetch; my gate turned it into the open's critical path.
2. NO UI FOR THAT PHASE: while versions are unsettled the inventory query
   is disabled (isLoading false) and the overlay at SectionModal:2085
   keys on pageQuery.isLoading only → nothing renders. White.
3. originalHtml is consumed ONLY by the versions dropdown's Original row
   and pickVersion('original') — the OPEN needs the rows alone (a pure
   hub DB read, milliseconds).
4. The SEO table's first remote load (no cache yet, P4) shows the generic
   table loading state — no explanation that a first load builds the
   cache.
5. Seniority sweep of the recent arc: tsc 59 baseline/harnesses green;
   defect = fact 1+2 (gate on a slow query without UI); no other dead
   code found in the touched files (P2's deleted loop verified gone).

## DESIGN
- D1 ROWS-ONLY OPEN: page-versions gains ?rowsOnly=1 → version rows
  only, NO snapshot fetch (pure DB). The editor's open query uses it.
  originalHtml moves to a LAZY query fired when the versions DROPDOWN
  opens (its only consumer) — same reply shape, same route.
- D2 STAGED HONEST OVERLAY: while the page document is not yet loaded
  (new docLoaded state beside the ref) and no error: phase message —
  versions in flight: "Loading your saved version…" · inventory in
  flight (first open): "Pulling the page from your site — the first
  open takes longer while we build the local copy…". One overlay, real
  states, never white.
- D3 TABLE FIRST-LOAD MESSAGE: when the remote table loads with NO
  stored copy: "Fetching content from the site — the first load builds
  the local copy, next opens are instant…" (the P4 cache honesty).
## CHECKLIST
[ ] D1 rowsOnly + lazy original · [ ] D2 staged overlay + docLoaded ·
[ ] D3 table message · [ ] php -l · [ ] harnesses · [ ] tsc 59/0 ·
[ ] build · [ ] changelog · [ ] AFTER commit LOCAL ONLY
