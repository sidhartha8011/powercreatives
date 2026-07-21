# GAP ANALYSIS — THE IDENTITY-CORNER STATUS (owner/Jony order 2026-07-17)

## DEFINITION OF DONE (Jony's bar)
- The decorative document icon is GONE; its exact spot (the 7x7 rounded
  square, top-left) is the page's ONE connection status.
- Four true states, one home: checking = spinning sync · live/in-sync =
  calm GREEN dot · unreachable = muted red CLOUD (click retries) ·
  drifted = AMBER mark (click loads live view).
- The duplicate glyph beside the status pill is REMOVED — no second
  status anywhere. Hover tells the truth (incl. age/error), click acts.
- Nothing else in the header moves; read-only mode shows the quiet
  neutral square (no fake verdicts without a check).

## FACTS (live, HEAD bd552b3)
1. The icon: 7x7 rounded bg-blue-50 square w/ FileText (SectionModal
   :1805-1806) — pure decoration, no handler.
2. The glyph: block beside the status pill (:1830+), keyed on stateQuery
   {isFetching / pageDrifted / stateUnreachable} + liveViewWanted +
   refetch — ALL logic exists and stays; only its RENDER moves.
3. stateQuery runs only when isPage && !readOnly — read-only/section
   modes have no verdict (DoD: neutral square, honest).
4. FileText remains used elsewhere?? — verify import usage after removal
   (single other use = none in this file beyond :1806; check).

## DESIGN
- D1 The square keeps its 7x7 frame (identity anchor, no layout shift);
  its content + tint become state-driven: checking → RefreshCw spin
  slate on slate-50 · in-sync → 2.5px green dot on green-50 · drift →
  RefreshCw amber on amber-50 (click = load live view) · unreachable →
  CloudOff red-400 on red-50 (click = retry, title carries the error) ·
  no verdict (readOnly/section/no data yet) → neutral slate square with
  the doc's initial dot (bg-current slate).
- D2 The old glyph block (:1830+) is DELETED in the same commit; its
  handlers move into the square. Zero logic changes — presentation
  re-homed.
- D3 FileText import dropped if orphaned (fact 4 check).

## CHECKLIST
[ ] D1 state-driven square · [ ] D2 delete old glyph block · [ ] D3
import hygiene · [ ] tsc 59/0 · [ ] build · [ ] changelog · [ ] AFTER
commit LOCAL ONLY · [ ] owner: green dot when live, cloud when site
off, amber on drift, spinner during check
