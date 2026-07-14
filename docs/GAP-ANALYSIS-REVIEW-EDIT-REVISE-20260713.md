# GAP ANALYSIS — EDITABLE SUGGESTIONS + REVISE (per section & whole run) + ONE GREEN (2026-07-13) — AWAITING GO

**Owner order:** (F1) the green suggestion text is directly editable; what the
section says at Accept is what's saved. (F2) Revise per section AND per whole
generation (rail header, near Accept all): a small box, an adjustment note,
the AI redoes it receiving ORIGINAL + CURRENT suggestion (incl. manual edits)
+ the note. (F3) the diff green = the save-button green family. Heading
word-diff untouched. Ask-AI advisor parked in backlog.

## THE LOAD-BEARING FACT (found in the code read-through)

The review engine is a **full-document rebuilder**: every review state change
re-renders the WHOLE doc from stored state (`setContent(orphan + sections)`,
SectionModal review effect). Under F1 this architecture is not just
insufficient — it is **destructive**: accept section 3 and the rebuild wipes
the manual edits you just made inside section 5's green text (the rebuild
uses the STORED ai text, never the live doc). Editable suggestions therefore
require replacing wholesale rebuilds with **per-section in-place surgery**.

## Facts

| # | Fact |
|---|---|
| F1 | Rebuild effect: doc = orphan + Σ(diff → diffBlocksHtml(stored html, stored ai) · accepted → stored ai · else stored html) — user edits in the live doc are invisible to it |
| F2 | `editor.setEditable(false)` for the whole review — the F1 lock to remove |
| F3 | Accept today applies the STORED `s.ai`; F1 semantics = apply `stripDiffHtml(live section content)` — the strip guard already exists and runs on every save anyway |
| F4 | Section ranges are computable by heading index (the focusSection walk); `insertContentAt(range, html)` gives atomic in-place replacement; `splitDocSections(editor.getHTML())` reads a live section's current content (imgs lifted separately — surgery must re-append `s.imgs`) |
| F5 | The optimize endpoint takes arbitrary `html` + free-text `topic` — Revise needs NO server change: html = the ORIGINAL section, topic = the note + the CURRENT draft ("build on it") |
| F6 | Inline chips + rail rows already share `resolveSection(i)` — Revise slots into the same storage/callback pattern |
| F7 | DiffAdded mark = `bg-green-100 text-green-900`; the shared save green = #16a34a (green-600). Different tint families on screen — the owner's "two greens" |
| F8 | Bounded worker pool (4) exists for section runs — Revise-all reuses it |

## Gaps → changes (all in SectionModal.tsx; zero PHP; heading diff untouched)

| # | Change |
|---|---|
| G1 | **Surgery engine replaces rebuilds:** suggestion arrives → replace THAT section's range with its diff render; accept → replace with `stripDiffHtml(live content) + imgs` (captures manual edits BY CONSTRUCTION); reject → restore `original + imgs`; all-resolved → unlock + toast, no rebuild. The setContent storm dies (also kills review flicker). |
| G2 | **Unlock the editor during review** (the F2 lock removed). Saving/versions stay blocked until the review ends (existing gates). Honest edge, documented: editing a section while its suggestion is still generating gets overwritten when the suggestion lands. |
| G3 | **Accept-all / OK** route through the same surgery (accept-all captures each section's live content; OK restores originals for undecided). |
| G4 | **Revise per section:** third control on the chip + rail row → small inline box (note + Adjust) → section back to pending → optimize call (F5 payload: original as html; note + current clean draft in topic) → new suggestion lands via G1 surgery → re-review. genModel updates per round. |
| G5 | **Revise all:** rail-header control beside Accept all → ONE note → every section currently in 'diff' re-runs through G4's path (pool of 4); decided sections stay decided. |
| G6 | **One green:** DiffAdded re-tinted to the green-600 family (`bg-green-600/15 text-green-800`) — same hue as Save/Accept. |

## Regression surface (named)
Accept must equal what the user SEES (G1 strip = the existing save guard —
same law) · rejected/undecided sections byte-identical to originals ·
save/versions/undo gates unchanged during review · selection-scoped runs ride
the same pipeline by construction · section mode untouched · focus flash +
chips keep working (both are index-addressed; surgery preserves indexes —
headings are never inserted/removed by the review).

## CHECKLIST (one pair, full ritual)
- [x] BEFORE state committed (`6db10a0`, tree clean)
- [ ] G1 surgery engine (sectionRange + applyContent + per-transition wiring)
- [ ] G2 unlock during review
- [ ] G3 accept-all / OK on surgery
- [ ] G4 Revise per section (chip + rail + inline box)
- [ ] G5 Revise all (rail header)
- [ ] G6 one green
- [ ] Verify: tsc 59 · build · harness untouched (no PHP)
- [ ] Changelog + AFTER commit — LOCAL ONLY
