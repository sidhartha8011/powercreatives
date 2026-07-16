# GAP ANALYSIS — KW DRAWER: INDEPENDENT FETCHES + ROLE SORT (owner orders 2026-07-15) — AWAITING GO

**Owner orders:** (1) each table fetches volumes for ITSELF only — the
selected-table button must not update Ranking; (2) every table's volumes
must visibly work; (3) the selected table default-sorts by ROLE and the
role column becomes sortable.

## Facts (verified at lines + live probe)

| # | Fact |
|---|---|
| F1 | The header button fetches `[...selected, ...ranking rows, ...ideas].slice(0,20)` with `refresh: true` (KeywordsDrawer.tsx:365-368) — the earlier "update everything listed" ruling; THAT is why Ranking updates on a selected-table press. Owner supersedes: independent per table |
| F2 | Both buttons render the SAME `volumesMutation.isPending` (:379, :441) — one press animates both (the "fetching at the same time" look) |
| F3 | **Probed live:** the selected keywords ("Privacy", "basta privacy policy") WERE fetched 2026-07-15 07:36 and Ahrefs answered null — no volume data exists for them. The dash is honest data; the BUG is the tooltip: cached-null shows "press the volume button to fetch it" (volCell :176-179) — misleading after a successful fetch |
| F4 | The role column has NO `sortAccessor` (:200-226) and the selected table sets no default sort (:388-395) — role is unsortable today |
| F5 | The tree carries the other dev's mid-flight research-spine work (his lane per handoff 8c34788); KeywordsDrawer.tsx is untouched by him — this pair edits ONLY that file, my lane by the hard-lane ruling |

## Design

- **D1 — Independence.** Header button = SELECTED keywords only
  (refresh). Finder button = active tab only (already). No button ever
  touches another table's list.
- **D2 — Own spinners.** A `pendingSource: 'selected' | 'finder' | null`
  state set around the mutation — each button animates only for its own
  press (auto-fills animate neither).
- **D3 — Honest no-data cell.** volCell distinguishes: cached-null
  (`kw in volumes`, value null) → dash + "Ahrefs has no volume data for
  this keyword"; never-fetched (absent) → dash + the existing
  press-the-button tooltip.
- **D4 — Role sort.** Role column gains `sortAccessor` (P=0, S=1, A=2);
  selected table gets `defaultSortKey="role"` asc → P → S → A on open.

## Checklist (ONE pair, one file)

1. KeywordsDrawer.tsx: D1-D4.
2. tsc 59 + zero new · build "built in" · harness 88/88 (untouched PHP) ·
   changelog · AFTER commit LOCAL ONLY — my files only (F5).

## Regression surface (named)

Finder button unchanged in scope · auto-fills (selected empties, cache
reads on scan/search) unchanged · server untouched · other dev's in-flight
files untouched · header button loses only its cross-table reach (the
superseded ruling — honest deviation, stated).
