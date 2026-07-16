# GAP ANALYSIS — TITLE CELL CLICK BEHAVIOR (owner order 2026-07-16)

Owner design: in the SEO table, THE TITLE CELL ONLY — single click opens
the page EDIT WINDOW; double click edits the title TEXT inline. Every
other column keeps its current behavior untouched ("the other rows are
more sensitive").

## FACTS (live-verified, HEAD 472ea56)
1. TODAY: single click on the title text enters INLINE EDIT — the shared
   EditableCell's click handler (`setDraft; setEditing(true)`,
   index.tsx:~145). There is no double-click behavior anywhere.
2. The page editor opens ONLY via the hover-revealed pen icon:
   `setPageEditRow(row)` (index.tsx:1096) → SectionModal mode="page"
   (:1748-1754) — CONNECTED sites only. Local rows have no dynamic
   editor; their pen is a plain link to the WP editor (:1084-1092).
3. EditableCell is SHARED by every text column (title passes `emphasis`)
   — a change to its default click behavior would hit the sensitive
   columns. The scope law therefore forces an OPT-IN prop, never a
   default change.
4. The title cell also hosts: the expand chevron (heading structure), the
   hover Eye (preview), the hover pen — all untouched by this change.

## DESIGN (additive, one component, zero behavior change elsewhere)
- D1 EditableCell gains ONE optional prop `onOpen?: () => void`:
  - absent → behavior byte-identical to today (all other columns).
  - present → single click arms a short timer (~220ms) that fires
    onOpen; a double click within it cancels the timer and enters the
    inline edit (`setEditing(true)`). The discriminator lives INSIDE
    EditableCell — no consumer reimplements it.
- D2 The title cell passes onOpen ONLY on connected sites:
  `() => setPageEditRow(row)` — the exact pen action, one source. LOCAL
  rows pass nothing (no dynamic editor exists to open — fact 2) and thus
  keep today's single-click inline edit; stated honestly here as the
  intended split (owner may re-rule).
- D3 The pen icon STAYS (discoverability + the local-row link); the title
  gets `title`-attr text naming both actions (sanctioned tooltip, no
  bare chrome).
- Cost: ~15 lines in EditableCell + 1 prop at the title call site.

## CHECKLIST
[ ] D1 onOpen prop + click/dblclick discriminator · [ ] D2 title cell
wires setPageEditRow (connected only) · [ ] D3 tooltip text · [ ] other
EditableCell consumers verified unchanged (grep call sites) · [ ] tsc
59/0 new · [ ] build · [ ] changelog · [ ] AFTER commit LOCAL ONLY ·
[ ] owner browser check: click=editor, dblclick=text edit, other columns
unchanged
