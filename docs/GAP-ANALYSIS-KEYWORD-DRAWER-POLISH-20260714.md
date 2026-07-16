# GAP ANALYSIS — KEYWORD DRAWER POLISH (3 fixes) — 2026-07-14 — owner-ordered, implement after commit

**Owner rulings:** bare instructional text in the UI is banned (standing
law) — it MAY live in a hover popover/info icon, never on the surface; the
additional-keywords spot is an INPUT + the collection of added keywords;
the drawer needs more width for longer keywords.

## Facts
| # | Fact |
|---|---|
| P1 | KeywordsDrawer's bucket empty-state renders a bare instructional paragraph ("Add keywords from the list below…") — a violation of the standing no-instructional-chrome law that I shipped; it dies, replaced by nothing (interactions reveal themselves; placeholders are sanctioned app-wide idiom) |
| P2 | The bucket hook already exposes `add(keyword)` — a manual type-and-Enter input needs ZERO new plumbing |
| P3 | The drawer is `w-[300px]`; it is anchored by its RIGHT edge to the card's left edge, so widening extends OUTWARD — no geometry change, the one-constant anchor holds |

## The 3 fixes
1. **Kill the bare helper text** (P1). No replacement chrome — the input's
   placeholder and the list's + column self-reveal. (A hover info icon is
   sanctioned if ever wanted; not needed v1.)
2. **Additional keywords = input + collection** (P2): a small input in that
   exact spot — type a keyword, Enter adds it as a pill — above the pill
   collection; the GSC listing stays below.
3. **Wider drawer** (P3): 300px → 360px for longer keywords.

## CHECKLIST
- [ ] This doc committed → implement immediately (owner pre-authorized)
- [ ] Fixes 1-3 in KeywordsDrawer.tsx
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
