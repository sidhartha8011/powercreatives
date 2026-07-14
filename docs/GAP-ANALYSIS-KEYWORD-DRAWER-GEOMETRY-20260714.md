# GAP ANALYSIS — KEYWORD DRAWER: slide OUT, senior-grade — 2026-07-14 — AWAITING GO

**Owner rulings (live round):** (1) the drawer must expand OUTWARDS from the
card's left edge — the content column is never squeezed; (2) supporting
keywords must be the SEO table's real field; (3) my first attempt was
rejected as not senior — it mirrored the card's width formula as a second
hardcoded constant. This gap replaces it with a fact-anchored design.

## Facts (verified at their lines)

| # | Fact |
|---|---|
| K1 | The page card is `overflow-hidden` (SectionModal.tsx:1405) — a child positioned outside its bounds is CLIPPED: the drawer cannot live inside the card |
| K2 | The card's page-mode geometry is `width: min(980px, 94vw)` + `minWidth: 720` + `maxWidth: calc(100vw - 32px)`, centered via `left/top 50% + translate(-50%,-50%)` (SectionModal.tsx:1406-1408) |
| K3 | CSS resolution of K2 (width → max-width cap → min-width floor, min-width wins over max-width per spec) is EXACTLY equivalent to ONE expression: `max(720px, min(980px, 94vw, 100vw - 32px))` — provable, no behavior change |
| K4 | The outside-click flow saves+closes on any pointerdown outside `rootRef` — a drawer outside the card MUST be exempted (a `drawerRef` guard) or clicking it saves and closes the editor |
| K5 | The SEO table's "Supporting KW" column is the `supportingKeyword` field (index.tsx:253, remote rows carry it at seo/service.php:1071) — NOT `metaKeywords`; the drawer's supporting input must read/write `supportingKeyword` through the existing `seo.remoteSaveCell` route |
| K6 | `tw-animate-css` is imported (app/src/index.css:9) and `animate-in slide-in-from-*` classes are already used in the codebase — a real slide-out animation costs ONE class, zero new dependencies |

## The design (single source of truth, zero duplicated geometry)

**D1 — ONE geometry constant.** A module-level constant holds the card's
effective width expression (K3): the CARD consumes it as its `width`
(replacing the three-property trio with the provably equivalent single
expression), and the DRAWER consumes the SAME constant for its anchor:
`right: calc(50% + (WIDTH) / 2)` — the card's left edge, derived, never
mirrored. If anyone ever changes the card's width, the drawer follows by
construction. No JS measurement, no resize listeners, no magic numbers.

**D2 — the drawer is a fixed SIBLING of the card** (K1 forces it), rendered
BEFORE the card in the DOM so the card's shadow owns the seam; height 86vh
(inside the card's 90vh), left-rounded, its own shadow; `animate-in
slide-in-from-right` (K6) so it visibly slides out from under the card —
the "cool little drawer" the owner asked for. The card itself never moves
and never resizes: reading width is untouched.

**D3 — outside-click exemption** (K4): `drawerRef` joins `rootRef` in the
pointerdown guard.

**D4 — the supporting-keywords rewire** (K5): props/state/save-field all on
`supportingKeyword`; seeded from the row through the call site.

**Named bound (honest):** on viewports narrower than card + drawer
(~1050px) the drawer partially leaves the screen. V1 accepts this — the
drawer is a desktop work surface; a clamp is a one-line follow-up if the
owner ever works that narrow.

## Regression surface
Card geometry byte-equivalent (K3 proof) · section mode untouched (drawer
is page-mode only) · outside-click flow unchanged except the exemption ·
no server change in D1-D3; D4 uses the existing cell route.

## CHECKLIST
- [ ] BEFORE = this doc committed (code changes held back for GO)
- [ ] D1 one geometry constant (card + drawer consume it)
- [ ] D2 sibling drawer, slide-in animation, card never moves
- [ ] D3 drawerRef outside-click exemption
- [ ] D4 supportingKeyword rewire
- [ ] Verify: tsc 59 · build · harness untouched · changelog · commit LOCAL ONLY
