# GAP ANALYSIS — SEO WHITE SHELL (owner order 2026-07-16) — pre-authorized surgical pair

**Owner order:** the SEO module reads as one white canvas; every other
module unchanged; must not touch the other dev's in-flight SEO-table work.

## Facts
| # | Fact |
|---|---|
| F1 | The grey comes from the SHELL, not the module: Shell.tsx:78 `shellBg = activeModule === 'deliveries' ? '#ffffff' : '#f8f9fa'` — applied to the screen wrapper and the module container |
| F2 | The white-shell opt-in PRECEDENT is that same line (Deliveries, commented as the sanctioned, trivially-reversible pattern) |
| F3 | The SEO table lives in modules/SEO/index.tsx — a different file; per-file edits cannot conflict; tree clean at 7365500 |
| F4 | Shell.tsx already uses the module-list idiom for layout opt-ins (`fullBleedModules`, :62) |

## Design
ONE list following F4's idiom: `whiteShellModules = ['deliveries', 'seo']`
replaces the ternary (the Deliveries special-case folds in — no second
pattern for the same concept); comment rewritten to the new shape. SEO
gets the white shell; all other modules byte-identical.

## Checklist
Shell.tsx edit · tsc 59/0 new · build "built in" · harness (no PHP, run
anyway) · changelog · pathspec AFTER commit LOCAL ONLY.
