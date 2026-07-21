# GAP ANALYSIS — THE OPTIMIZE CONSOLIDATION (owner order 2026-07-18) — GO GIVEN, IMPLEMENTING

**Owner order:** the Analyze button dies. Optimize is THE one entry with
three modes — Quick (today's click), Super (the full analysis flow inside
an optimizing move), Custom (a plain surgical order). Super must be aware
of the whole page and put changes in the right places without rewriting
everything. Zero tech debt: superseded code deleted, no duplication, only
working code remains.

## WHERE WE ARE (facts, line-verified this session)

| # | Fact |
|---|---|
| G1 | The Analyze pill is its own button (SectionModal.tsx:2091-2092, ScanSearch icon, toggles `analyzeOpen`); the rail mounts on `analyzeOpen` (:2510) |
| G2 | The split button's caret toggles the instruction box (`askOpen`, :2117-2118, box at :2171) — already the "custom order" surface, unnamed |
| G3 | The basket compiles into ONE page-level order and the run sends THE WHOLE ORDER TO EVERY SECTION (worker :1331-1336, same `topic` each) — the proven cause of the duplicated intros/"rewrite everything" (owner-observed) |
| G4 | A section call carries ONLY its own HTML + the order + page meta — no outline, no neighbors (remote_optimize_section, seo/service.php:6005+) — sections cannot know what is covered elsewhere |
| G5 | The careful-editor contract + sentence-retention guard + broad-rewrite escape exist but protect ONLY the draft/Revise path (`$draft !== ''`, :6016-6021, :6049-6076; helpers :6534, :6564) |
| G6 | The compiler (optimizer/service.php::compile) has a strict sources contract, single-item bypass, context suffix — NO notion of section targets; standalone tests do NOT pin the compile contract (grep: zero hits) |
| G7 | compileBasket (useOptimizer.ts:123-147) sends {items, model, provider, siteId, keywords}; the rail owns `getHtml` — the outline is derivable client-side via the existing `splitDocSections` (word-diff.ts:254) |
| G8 | PillSplitButton (shared/PillButton.tsx:183) has a plain caret button — the sanctioned additive extension point (precedents: outline variant, searchable) |
| G9 | The review, identity anchors, change-card envelope, added-section (sky) lane all stay untouched — every mode lands through the same red/green door |

## WHAT THE CODE MUST LOOK LIKE (the design)

- **D1 — ONE BUTTON, THREE MODES.** The Analyze pill is DELETED (G1; the
  ScanSearch import goes with it). PillSplitButton gains an additive
  `menu` prop (G8): the caret opens a Radix menu — Quick optimize ·
  Super optimize (opens the analysis rail = the old Analyze, same state)
  · Custom instruction… (opens the existing instruction box, G2). Main
  click stays the action-matrix label (Insert keywords N / selected text
  / page) = Quick.
- **D2 — THE ROUTER.** The compile payload gains `outline: string[]`
  (section headings, client-split via G7). The compiler's ONE call also
  assigns every directive `targets: int[]` — the section indexes it
  concerns; a directive adding NEW content targets the section it should
  follow (the AI adds a sibling there → the existing sky lane, G9);
  page-wide directives target only sections that truly need work. The
  single-item bypass survives ONLY when no outline is sent (routing has
  value even for one item). Invalid/absent targets on a directive =
  unrouted → that directive broadcasts (the honest floor, never a drop —
  the sources certainty contract is untouched).
- **D3 — THE SCOPED RUN.** `startAiReview` routes: when directives carry
  targets, each section's topic = ONLY its own directives; untargeted
  sections are never sent (they resolve clean, exactly like out-of-scope
  under the selection law — same skip path, G3 dies). Per-section
  envelope purposes = that section's directives' purposes.
- **D4 — PAGE AWARENESS.** Every optimize call gains `outline` +
  `sectionIndex`; the server appends THE PAGE MAP to the order: "you are
  editing section i of n — edit ONLY this section; the others are
  handled separately, never duplicate their content" (G4 dies).
- **D5 — RUN DISCIPLINE.** The optimize endpoint gains `strict`: the
  existing editor contract is prepended to the order and the SAME
  retention guard + one retry + broad-rewrite escape (G5's machinery,
  measured against the section's original) applies. Super and Custom
  runs send strict; Quick stays byte-identical (the owner likes it).
- **D6 — DEAD CODE DIES IN THE SAME PAIR:** the Analyze pill + its icon
  import; no parallel button path; the caret's old direct-toggle
  behavior replaced by the menu (Custom lives inside it).

## THE PLAN (one pair, execution order)
1. types.ts: `targets?: number[]` on CompiledDirective.
2. shared/PillButton.tsx: additive `menu` prop (Radix trigger on the caret).
3. optimizer/service.php: compile routing (D2) — php -l.
4. optimizer/controller.php: outline passthrough (sanitized) — php -l.
5. useOptimizer.ts: outline in the compile payload (via getHtml + splitDocSections).
6. seo/service.php: strict + outline + sectionIndex (D4, D5) — php -l.
7. seo/controller.php: param passthrough — php -l.
8. SectionModal.tsx: D1 menu + Analyze deletion + D3 routing + strict wiring.
9. Verify: harness 88/88 · research 34/34 · tsc 59/0 new · build "built in".
10. Changelog line · blueprint §10 append · pathspec AFTER commit LOCAL ONLY.

## Regression surface (named)
Quick mode byte-identical (no strict, no routing — directives absent) ·
review machinery untouched (G9) · rail internals untouched (only its
entry point moves into the menu) · compile without outline behaves as
today (bypass + no targets) · optimize endpoint params all optional —
old callers unaffected · shared PillSplitButton: menu prop optional,
every other consumer byte-identical.
