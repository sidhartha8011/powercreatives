# GAP ANALYSIS — ONE CANVAS, ZEN PASS (owner order 2026-07-16, "then go" pre-authorized)

**Owner order:** minimal changes, maximum impact — one background, elements
per the design rulings (shadow = floats, border = data edge, grey = state).

## Facts
| # | Fact |
|---|---|
| F1 | THE DOUBLE BACKGROUND: `.module-container` paints `var(--main-bg)` (near-grey oklch 0.98, index.css:512-517) OVER the shell's per-module background — the white shell showed only as a ring around a grey interior (the owner's screenshot). The var has no other consumer |
| F2 | Shell.tsx already owns per-module canvas color (whiteShellModules, 670b057) — TWO owners of one canvas today; the shell is the right single owner |
| F3 | The left section nav wears a card (`rounded-lg border bg-card`, SEO/index.tsx:1353); active state is already the blue pill (:1359) — the card is decoration, the pill is state |
| F4 | The table container carries `shadow-sm` at rest (:1617); the View control carries `shadow-sm` (:1460) — both violate "shadow = floats"; the floating bulk bar (:1502) and modals keep theirs legitimately |
| F5 | `module-container` consumers: 6 modules, all inside the Shell — for grey-shell modules the change is a visual no-op (shell grey ≈ --main-bg); Deliveries had the same double-paint and is cured free |
| F6 | Other dev: tree clean (7365500 + my commits); the index.tsx hunks are three one-liners — minimal collision surface |

## THE ZEN MAP (4 touches, 3 files)
1. index.css: `.module-container` loses its background — THE SHELL IS THE
   ONE CANVAS OWNER (F2). The orphaned `--main-bg` var dies with it (no
   dead code).
2. SEO/index.tsx:1353: the nav card chrome dies — items float on the
   canvas, blue pill = state (F3).
3. SEO/index.tsx:1617: table `shadow-sm` dies — the hairline border stays
   (the one data object).
4. SEO/index.tsx:1460: View control `shadow-sm` dies — the hairline
   border is the affordance.

NOT touched: bulk bar/modals/popovers (genuinely float), table header
(shared component, already reads fine), every other module's layout.

## Checklist
Edits 1-4 · tsc 59/0 new · build "built in" · harness 88/88 · changelog ·
pathspec AFTER commit LOCAL ONLY.

---

## ADDENDUM (owner screenshot 2026-07-16): the missed edge

**Fact:** the app Sidebar is `bg-white` with NO right border
(Sidebar.tsx:156) — its boundary existed only as grey-shell CONTRAST.
On the white canvas the sidebar and the page merge into one sheet.

**Fix (the rulings applied):** a region's edge = a hairline —
`border-r border-border` on the Sidebar container. One class, all
modules (on grey modules a hairline between white and grey is the
standard, subtler-than-contrast edge). Also: the stale "Main content
background: #f8f9fa" header comment in Shell.tsx dies (predates the
whiteShellModules pattern).
