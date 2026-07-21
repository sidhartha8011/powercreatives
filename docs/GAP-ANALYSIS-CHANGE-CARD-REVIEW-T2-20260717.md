# GAP ANALYSIS — THE CHANGE-CARD REVIEW T2: PER-CHANGE CONTROL — 2026-07-17

Blueprint §10 CHANGE-CARD entry, stage T2. T1 (gap 0a0a3c3, built f9342da)
gives verified per-section change cards. T2 gives the owner's ask: "maybe I
don't want the full rewrite, just some changes" — shaping the proposal per
change.

## A. FACTS

1. T1 state: ReviewSection carries changes[] (verified what/why/quote) +
   rewritten; cards render with hover-highlight; acceptance is per section
   (resolveSection accept/reject; Accept-All; OK).
2. The revise machinery (reviseSection, SectionModal) is the tested path
   for reshaping ONE section: sends the ORIGINAL (html) + the CURRENT
   proposal (draft) + a note; the human-editor contract + retention check
   protect untouched text (LIVE again since the T1 draft-fix); the result
   re-lands as a fresh diff with fresh verified cards.
3. **Deterministic splice-out is NOT honestly possible in the general
   case**: a change's quote lives in the PRODUCED text with no old↔new
   mapping — cutting it out of a rewritten section cannot restore what the
   original said there. NAMED DEVIATION from the blueprint sketch ("span-
   local changes splice deterministically"): T2 routes EVERY partial
   selection through ONE bounded recomposition via the existing revise
   path — correct over clever; a false determinism would corrupt text.
4. T1 cosmetic flaw (self-review): the quote highlight can linger when a
   hovered card unmounts (accept-while-hovering) — fixed this pair.

## B. DESIGN (frontend-only; zero backend changes)

1. **Ticks**: every change card row gets a small checkbox, default TICKED.
   State: ReviewSection.kept?: boolean[] (parallel to changes; seeded
   all-true whenever changes land — initial run and every revise).
2. **The fast paths are unchanged**: all ticked → Accept behaves exactly
   as today (no AI, no delay); Reject unchanged; Revise unchanged;
   Accept-All / OK unchanged.
3. **Partial selection → "Update proposal (keeps N of M)"** button appears
   on the card. Click = ONE bounded re-run through the EXISTING
   reviseSection with a generated note:
   "Remove these changes from the draft — revert those parts to how the
   original text read: 1) {what} (the part reading: \"{quote}\") …
   Keep every other change and ALL other text exactly as the draft reads."
   The fidelity contract + retention check guard the rest verbatim; the
   result lands as a fresh diff with fresh verified cards (all ticked).
   The user then Accepts — per-section semantics stay the one gate to the
   document (frozen pipeline untouched).
4. **Cleanup (A4)**: resolveSection clears the quote decoration.
5. NOT touched: backend, acceptance semantics, anchors/gate/oracle,
   versions, catalog/basket, client colors, keywords lane.

## C. CHECKLIST

1. [ ] ReviewSection.kept + seeding at both landing sites (run + revise).
2. [ ] Card checkbox row (ticked default), toggle wiring.
3. [ ] Partial-state "Update proposal" button → generated note →
       reviseSection(i, note) (existing path, no new machinery).
4. [ ] resolveSection clears the quote highlight (T1 flaw).
5. [ ] tsc 59 zero new · build "built in" · served byte-identical.
6. [ ] Changelog + seo 1.0.4 + AFTER commit LOCAL ONLY. (PHP untouched —
       harnesses unaffected; run them anyway as the ritual demands.)
