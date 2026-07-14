# GAP ANALYSIS — DIFF VIEW FIDELITY (the removed side must look as it WAS) — 2026-07-13 — AWAITING GO

**Owner order (live screenshot):** a reviewed bullet list shows its OLD
content as one run-on red paragraph — you cannot see you HAD a bullet list.
"It needs to look as it was. It needs to be what it was." Full factual gap.

## Why it happens (facts, each verified at its line)

| # | Fact |
|---|---|
| F1 | `removedBlock` flattens EVERY removed block into ONE plain red paragraph: `<p><span data-diff-removed>` + the block's squashed text (word-diff.ts:92-93). A 4-bullet list becomes a run-on red line — the shape of the previous content is destroyed IN THE VIEW. This is the screenshot, mechanically |
| F2 | The GREEN side already keeps shape — `addedBlock` renders lists as lists with per-`<li>` marks (word-diff.ts:76-81) and text blocks in their own tag (:83-87). The red/green asymmetry is a code fact, not a design necessity |
| F3 | Pairing is POSITIONAL — `oldBlocks[k]` vs `newBlocks[k]` (word-diff.ts:123-126). The AI added ONE intro paragraph at the top, so every later pair misaligns (old list meets new paragraph) and the whole section falls into the noisy structural path — even though the list survived nearly intact in the reply |
| F4 | View-only defect: no decision reads the view. Accept = the oracle (untouched → clean `s.ai`, edited → stripped live), Reject = `s.html` byte-faithful. Content was never at risk — the view just lies about the original's shape |
| F5 | The G2 strip-prune (committed today) is what MAKES shape-preserving red rendering safe: wrappers emptied by removing red runs are pruned bottom-up, image-safe (word-diff.ts stripDiffHtml). A fully-red `<li>`/`<ul>`/`<details>` cannot leave phantom shells on accept/save |
| F6 | FAQ `details` blocks on the removed side flatten through the same F1 path — one law covers them too |

## The design

**One law: every block renders in ITS OWN shape on BOTH sides — only the
color says added or removed.** A removed list is a red list, bullet by
bullet. A removed FAQ item is a red question-and-answer box. Identical to
what it was — that is how you know what you had.

**B1 — ONE shape-preserving marker.** `markBlock(b, 'added' | 'removed')`
replaces BOTH `addedBlock` and `removedBlock` (both die — zero dead code,
the red/green asymmetry cannot come back because there is only one
renderer). It clones the block and wraps each text-bearing LEAF in the diff
span: lists per `<li>` (or the `<p>` inside it), text tags whole,
`details` per leaf (`summary` + answer paragraphs). Attributes survive on
both sides (the G1 identity law, now symmetric).

**B2 — tag-aware pairing.** Blocks pair by an LCS over their TAG sequences
(the same in-house DP family as `wordDiff` — zero dependencies by law)
instead of by position: equal-tag blocks pair up (text tags word-diff
inside their tag; same-tag structural = red shape + green shape), an
unmatched old block renders removed-in-shape, an unmatched new block
added-in-shape. One inserted intro paragraph can never cascade the rest of
the section into noise again.

## Gaps → changes (exact file, exact lines)

| # | Change | File : lines |
|---|---|---|
| B1 | `markBlock(b, kind)` replaces `addedBlock` (74-89) + `removedBlock` (91-93); the three call sites in `diffBlocksHtml` switch to it | word-diff.ts:69-93, :139-144 |
| B2 | `pairByTag(oldBlocks, newBlocks)` — LCS on tag arrays returning pairs; `diffBlocksHtml`'s positional loop (:123-126) consumes pairs instead of indexes | word-diff.ts:119-148 |

No other files. SectionModal consumes `diffBlocksHtml` unchanged. Oracle,
strip, origin law, lanes: untouched.

## Regression surface (named)
Same-tag text pairs (p/h word-diff) byte-identical · untouched blocks
byte-identical · Accept/Reject content identical (view-only change, F4) ·
strip+prune already proven on red leaves (F5) · marks inside `summary` are
legal inline marks (FaqSummary content `inline*`) · a section with equal
tag sequences pairs exactly as today (LCS of identical sequences = the
positional pairing).

## CHECKLIST
- [ ] BEFORE state = this doc committed, tree clean
- [ ] B1 one shape-preserving `markBlock` (both old renderers die)
- [ ] B2 tag-LCS pairing replaces positional pairing
- [ ] Verify: tsc 59 · build · harness untouched
- [ ] Changelog + AFTER commit — LOCAL ONLY
