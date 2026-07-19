# GAP ANALYSIS — THE EDITOR DECOMPOSITION (owner mandate 2026-07-19) — GO GIVEN, EXECUTING S1

**Owner mandate:** decompose first. Every edit must become easy, safe,
obvious; recurring regressions are the structure's fault, not fate.

## THE FACTUAL INVENTORY (SectionModal.tsx @ 0dd958c, 2,864 lines, read in full for the moved region)

| Lines | Concern | Verdict |
|---|---|---|
| 80-138 | Public data contracts (SectionParagraph/SectionData/SectionAnchor/InsertData) + SectionModalProps | types move; Props stay with the component |
| 140-164 | Layout constants + pageCardWidth + LANGUAGE_LAW | move: `editor/layout.ts` |
| 166-179, 449-478, 580-588 | The three type scales (PAGE_TYPE_SCALE, BLOCK_STYLES, TYPE_SCALE) | move: `editor/layout.ts` |
| 181-447 | SIX TipTap extensions (LockedImage, Diff marks, SectionBlocks + blockDecorations + BLOCK_ORIGIN_CLASS, ReviewControls, FaqItem/FaqSummary) + faqTemplate | move: `editor/extensions.ts` (+ faqTemplate → content-laws) |
| 480-508 | ReviewStatus + ReviewSection | move: `editor/types.ts` |
| 510-610 | The content laws (htmlText, canonicalAiHtml, stampReviewId, restateOrigin, normSrc, composeSectionHtml, escapeHtml, stripPcmAnchors) + ImgSelection | move: `editor/content-laws.ts` / types |
| 612-626 | ToolButton atom | move: `editor/ToolButton.tsx` |
| 628-2864 | THE COMPONENT (~2,236 lines: state, run engine, review, rails, versions, panels, layout JSX) | stays this slice; S3-S5 carve it |

External contract (verified): `index.tsx` imports SectionModal + the
exported types/composeSectionHtml from './SectionModal' — RE-EXPORTS keep
every consumer byte-identical, zero files touched outside the editor dir.

## THE TARGET (slice plan)

**S1 (THIS PAIR):** the ~550 module-level lines move into
`app/src/modules/SEO/editor/{types,layout,content-laws,extensions,ToolButton}` —
verbatim, behavior-preserving; SectionModal imports them back and
re-exports its public contract; dead imports removed symbol-by-symbol
(grep-verified). File drops to ~2,300 lines of pure component.

**S2 (next):** THE RUN-STATE MACHINE — one `runMode` object
('idle'|'quick'|'super'|'custom'|'insert'|'review') replaces the leaky
flag web (analyzeOpen/askOpen/review interplay) — kills the
"super optimize after a custom edit" class of bug BY CONSTRUCTION.
**S3:** the review rail → its own component (props contract, no closures
into the monolith). **S4:** versions/original → own module. **S5:** the
image panel + drawer glue. Each slice = one ritual pair, one gap fold.

## Checklist (S1)
1. Create the five editor/ files (content verbatim from the read).
2. Cut lines 140-627 (sed by verified boundaries), add imports +
   re-exports.
3. Symbol-by-symbol grep: every removed import proven unused.
4. tsc 59/0 new · build "built in" · harness 88/88 + research 34/34 ·
   changelog · pathspec AFTER commit LOCAL ONLY.

## Regression surface (named)
Behavior byte-identical by construction (moves, not rewrites) · public
exports preserved via re-export (index.tsx untouched, verified) · the
component body untouched this slice · tsc is the wiring proof.

---

## ADDENDUM — THE TARGET ARCHITECTURE + THE 700-LINE LAW (owner ruling 2026-07-19)

**Owner ruling:** max ~700 lines per file, aim 400-500 — "you need to know
exactly what's going on by just looking at the file very quickly."
S1's 2,361 is a first cut, NOT the standard. The standard is this map.

### THE FILE BUDGET LAW (binding from now)
No file in the editor domain may cross 700 lines. A slice that would
cross it splits further BEFORE it lands. The module's oversized files
(measured now): SectionModal.tsx 2,361 · index.tsx 1,872 · HeadingsPanel
791 — all in scope; the editor first (this arc), the table + panel as
the NEXT arc.

### THE TARGET MAP — app/src/modules/SEO/editor/
| File | Owns | Budget |
|---|---|---|
| types.ts / layout.ts / content-laws.ts / extensions.ts / ToolButton.tsx | BUILT S1 (e8a5739) | ≤300 each ✓ |
| **ReviewRail.tsx** (S2) | the review rail UI: header/actions, the run-order block, THE FILTER (state lives HERE — pure view state leaves the monolith), the section cards | ≤500 |
| **useAiReview.ts** (S3) | THE RUN ENGINE + THE MODE MACHINE: one `runMode` object ('idle'\|'quick'\|'super'\|'custom'\|'insert'\|'reviewing') replaces the leaky flag web; startAiReview/worker pool/inject/revise/resolve/accept-all/finish/effectiveContent/history stamp | ≤700 |
| **usePageDocument.ts** (S4) | open/save/savedHtml/dirty, versions + the Original (states, pinning glue when Group D lands), page status | ≤500 |
| **VersionsMenu.tsx** (S4) | the dropdown UI | ≤300 |
| **ImagePanel.tsx** (S5) | imgSel + image rules + the panel | ≤300 |
| **EditorToolbar.tsx** (S5) | bubble menu + toolbar + insert menu (FAQ/heading/list/link) | ≤300 |
| **SectionModal.tsx** (end state) | THE COMPOSER ONLY: mounts editor + hooks + panels, the portal pair, outside-click law | ≤700 |

Diagnosability by construction: a rail bug lives in ReviewRail, a run bug
in useAiReview, a save bug in usePageDocument — the file IS the error map.

### Slice order (adjusted; each = one ritual pair)
- **S2 ReviewRail.tsx** — carries TWO open fixes with it (the rail scroll
  regression + the hierarchy pass): extraction makes them clean, one pair.
- **S3 useAiReview.ts** — the mode machine kills the flag-leak bug class.
- **S4 usePageDocument + VersionsMenu** — the Original's frontend home.
- **S5 ImagePanel + EditorToolbar** — the composer reaches its end state.
- **NEXT ARC:** index.tsx (1,872) + HeadingsPanel (791) decompose under
  the same law once the editor domain is done.

---

## ADDENDUM 2 — THE COMPLETION MAP (owner GO: "complete the restructure properly")

**Verified state:** S1/S2/S3/S5a landed (e8a5739, 4d909f9, 574c5e2,
48fdd1e); monolith 1,450; every editor/ file inside budget (581 max);
consumers untouched; tsc 59/0 new, build green, harness 88/88 at every
commit. NOT DONE: the composer is still 2x the law.

**What remains inside SectionModal (grep-mapped):** the page document
machinery (open queries + savedHtml/dirty + save flow), the versions
machinery (pick/labels/delete + the dropdown JSX incl. the Original's
three states), the workbench header (title/status/brand/pageType/keywords
button/buttons row), the toolbar + insert menu JSX, the drawer glue, the
portal shell + outside-click law.

**The completion slices (order):**
- **S4a `useSectionVersions.ts` + `VersionsMenu.tsx`** — the versions
  logic + dropdown UI (the Original states live there).
- **S4b `usePageDocument.ts`** — open/savedHtml/dirty/save/saveClose/
  remove.
- **S5b `EditorChrome.tsx`** — bubble menu + toolbar + insert menu.
- **S5c `WorkbenchHeader.tsx`** — the header row block.
- End state: SectionModal = THE COMPOSER ≤700 (states + editor init +
  wiring + portal pair + outside-click law).
Read-in-full before every move (no reconstruction, ever); verify + commit
per slice; budget check per slice — anything crossing 700 splits first.
