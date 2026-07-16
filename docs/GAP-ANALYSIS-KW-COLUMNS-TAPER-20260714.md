# GAP ANALYSIS — compare columns return + the card TAPERS — 2026-07-14 — owner to-do, implement after commit

**Owner rulings:** the transformed trend-cells hid what you sort by — the
SEPARATE Δ columns were better; to make room, the big card TAPERS IN while
the keyword drawer is open and tapers back out when it closes.

## Facts
| # | Fact |
|---|---|
| T1 | The trend-cell transform (2e327b4) made the sort target ambiguous — the header said Δ but the cell showed two numbers; the owner is right: explicit columns = explicit sorting. The Δ-column version existed and worked (88bbcc9) — restoring it is a partial revert, keeping the amber-refresh fold-in |
| T2 | Eight columns need ~500px; the drawer is 360px. The room comes from the CARD: page-card width is already ONE constant (PAGE_CARD_WIDTH) — a taper is `calc(W - taper)` on the same constant, animated by a plain CSS width transition |
| T3 | The current drawer is anchored OUTSIDE the card's left edge — with a 520px drawer that clips on laptop screens. The senior geometry: a page-mode WRAPPER (fixed, centered flex) holding [drawer][card] — the PAIR centers as one unit, the anchor formula DIES entirely (layout derives geometry, zero math), and 980-wide card + 520 drawer fits a 1366 viewport once tapered |
| T4 | Section mode must keep its exact click-through/drag behavior — the wrapper exists ONLY in page mode; the card element is ONE JSX tree either way (extracted to a variable, wrapped conditionally) |

## The design
- **Columns:** the three Δ columns return (sortable, green/red, position
  inverted); trend-cells die; static filter defs incl. the Δ columns
  (number kind). Amber refresh stays.
- **Geometry:** page mode renders `[drawer][card]` inside one centered
  fixed flex wrapper. Card width = `PAGE_CARD_WIDTH` normally,
  `calc(PAGE_CARD_WIDTH - 280px)` while the drawer is open, with a 300ms
  width transition — the taper in/out the owner described. Drawer widens
  to 520px (the 8 columns fit). The right-anchor calc is DELETED — the
  flex layout IS the anchor now.
- Outside-click semantics preserved: rootRef/drawerRef guards unchanged;
  clicks outside the pair still save-and-close in page mode; section mode
  untouched (T4).

## CHECKLIST
- [ ] This doc committed → implement (owner to-do)
- [ ] Δ columns restored + Δ filter defs; trend-cells die
- [ ] Wrapper layout + taper transition + 520px drawer; anchor calc dies
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
