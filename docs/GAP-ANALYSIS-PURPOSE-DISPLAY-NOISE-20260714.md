# GAP ANALYSIS — why every suggestion says the same thing — 2026-07-14 — AWAITING GO

**Owner live find:** every section in the review rail shows the identical
purpose bullets. Full factual analysis.

## Facts

| # | Fact |
|---|---|
| P1 | It is BY CONSTRUCTION, not corruption: the purpose display is PHASE 1 (run-level) from the compiler pair — `runDirectives` is ONE state for the whole run, and the rail row renders that same list under EVERY suggestion (SectionModal rail map). The roadmap explicitly parked true per-suggestion attribution as its own step (S8): the rewriter must REPORT which directives it applied per section — we do not have that data yet, so nothing per-section exists to show |
| P2 | The repetition is honest but WRONG AS DISPLAY: identical bullets repeated N times read as a bug, imply per-section meaning that is not there, and eat the rail (each row carries ~5 lines of the same text) |
| P3 | The compiled order belongs to the RUN — its correct home is the rail HEADER, once. The per-section rows correctly carry only what IS known per run: the purpose pills |
| P4 | True per-suggestion purposes (S8) require a server contract change: `remote_optimize_section` returns only {value, model}; reporting applied-directive ids per section means a structured reply — AND the canonical AI-content gate strips anything that is not schema content, so the metadata must ride a separate reply field, never inline HTML. Correctly its own step, not a quick patch |

## The design (honest interim — no fake attribution)
- **The run's order shows ONCE**: a compact block under the rail header —
  "The order" + the compiled directives (all of them; it is the run's
  contract and the thing the user verifies against).
- **Section rows slim down**: pills stay (run-level truth), the repeated
  bullets DIE; the model name returns to the row tooltip as before.
- S8 (per-suggestion attribution) stays on the roadmap unchanged — when the
  rewriter reports applied ids, the rows regain bullets that are actually
  THEIRS.

## CHECKLIST
- [ ] BEFORE = this doc committed
- [ ] Rail header: the compiled order, once
- [ ] Rows: pills only (+ model tooltip); repeated bullets die
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
