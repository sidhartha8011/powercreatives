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
