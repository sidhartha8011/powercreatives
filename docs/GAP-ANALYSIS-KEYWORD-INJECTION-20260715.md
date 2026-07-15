# GAP ANALYSIS — KEYWORD INJECTION (owner order d135e3c · keywords-lane Task 3) — AWAITING GO

**Owner spec (backlog.md:231-251):** the drawer's SELECTED rows become
selectable; with a selection the Optimize button transforms to "Insert
keywords"; ONE run weaves exactly the selected keywords into the existing
content per THE KEYWORD HIERARCHY LAW, landing as the normal red/green
review. Natural always, stuffing never.

## Facts (verified at lines, HEAD 892c3d0 + other dev's WIP untouched)

| # | Fact |
|---|---|
| F1 | The run machinery exists: `startAiReview(topic, scope?, directives?)` (SectionModal.tsx:1066) — every run lands as red/green by construction; `runDirectives` (:1068) feeds the review's purpose display |
| F2 | The Optimize control is the shared `PillSplitButton` (SectionModal.tsx:1707-1723); its label already transforms by state (`hasSelection ? 'Optimize (selected text)' : 'Optimize page'`, :1722) — a third state is the same pattern, no new control |
| F3 | The shared `DataTable` ALREADY supports controlled multi-select — `selection` prop renders a leading checkbox column + header select-all (data-table.tsx:144-150, :360-370). The selected table just passes the prop (shared-components law satisfied, zero bespoke UI) |
| F4 | The drawer's SELECTED table rows carry `{kw, role}` with role P/S/A (KeywordsDrawer.tsx:42-46, table at :388-395) — the hierarchy mapping is already in the data |
| F5 | The drawer seam: all drawer state flows through SectionModal's props block (:1529-1542, `drawerVisible` :1523) — selection state lives in SectionModal beside `keywordsOpen`, passed down; MY side of the seam only |
| F6 | THE KEYWORD RIDE appends primary+bucket to EVERY topic (SectionModal.tsx:1069-1075). An injection topic encodes keywords per-role itself — letting the generic ride ALSO list them would put two conflicting keyword orders in one prompt |
| F7 | The hierarchy law text is owner-fixed (backlog.md:240-251): primary threads headings+body · supporting some headers+some text · additional natural light touch, ≈ one paragraph max each |

## Design

- **D1 — Selection.** `SelectedRow` selection as a `Set<string>` (keyword =
  row key) in SectionModal beside the drawer state; `KeywordsDrawer` gets
  `injectSelection` + `onInjectSelectionChange` props and passes them to
  the selected table's existing `selection` prop (F3). Selection clears
  when the drawer closes and when a run starts.
- **D2 — The transform.** With a non-empty selection the split button
  (F2) gains its third state: label `Insert keywords`, title says what one
  run will do; caret/instruction untouched. Empty selection = today's
  behavior, byte-identical.
- **D3 — The injection run.** One click builds the topic: the hierarchy
  law verbatim (F7) with ONLY the selected keywords grouped by their live
  role (F4), then `startAiReview(topic, null, [{text, purposes:
  ['keywords']}])` — the review shows the injection order as its purpose
  (existing C3 display, F1). No new pipeline, no PHP.
- **D4 — Ride suppression (documented, intentional).** `startAiReview`
  gains an optional `opts { suppressKeywordRide?: boolean }` — additive,
  default off, used ONLY by the injection run because its topic subsumes
  the ride (F6: one keyword order per prompt, never two).

## Checklist (ONE ritual pair, frontend only)

1. SectionModal: selection state + clear rules (D1) · third button state
   (D2) · injection topic + run (D3) · ride opt (D4).
2. KeywordsDrawer: two props → the selected table's `selection` (D1).
3. Harness 88/88 · tsc 59 + zero new · build ("built in") · changelog ·
   AFTER commit LOCAL ONLY. Commits add MY files only (other dev's WIP
   interface edit stays untouched in the shared tree).

## Regression surface (named)

Zero-selection path byte-identical (D2) · every other run keeps the ride
(D4 default off) · rail seam untouched (other dev's lane) · review/compiler
machinery untouched (the run enters through the existing front door) ·
no server change.
