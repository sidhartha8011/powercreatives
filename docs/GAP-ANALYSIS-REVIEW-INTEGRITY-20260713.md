# GAP ANALYSIS — REVIEW CONTENT INTEGRITY (all element types) — 2026-07-13 — AWAITING GO

**Owner order:** the bullet-list mangling is one symptom — audit EVERY element
type through the whole review pipeline so generation never distorts content.

## The pipeline audited (capture → AI → diff view → live edits → strip → surgery → save)
Element inventory: p · h1-h6 · ul/ol/li · inline marks (links, bold, italic,
underline) · images (locked + platform-added) · FAQ details/summary.

## Facts (each verified at its line)

| # | Fact |
|---|---|
| F1 | The diff renderer rebuilds same-tag blocks as BARE tags (word-diff.ts:113) — ALL attributes drop: headings lose `data-pcm-origin` (lanes flip blue and STAY blue after accept; the 500ms lane transition animates every flip — the owner's "weird animation") |
| F2 | The diff view is PLAIN TEXT by design (word-diff runs on visible text; structural/list views reduce items to text). That was SAFE when Accept applied the stored clean `s.ai` — under accept-what-you-see the VIEW became the source: **accepting an untouched section now flattens links/bold in word-diffed paragraphs and lists** — the widest distortion in this class |
| F3 | `stripDiffHtml` removes red spans but LEAVES THEIR EMPTIED PARENTS (word-diff.ts:127) — `<li></li>` = the phantom bullets; `<p></p>` = phantom gaps; applies equally to any wrapper whose whole content was removed text |
| F4 | FAQ `details` pairs take the structural path (removed line + verbatim new block — attributes preserved there) — view is noisy but content-safe IF F2's fix routes untouched sections to `s.ai` |
| F5 | Reject is safe: restores `s.html` captured from the live doc (origin attrs included) — byte-faithful |
| F6 | Revise sends the STRIPPED VIEW as the draft — same F2 flattening leaks into the revise round-trip |
| F7 | Shared PillButton: a button disabled under the cursor never fires mouseleave — the darker hover color sticks until the next mouse pass (browser fact; latent shared-component bug) |
| F8 | Images: top-level images are lifted per section and re-attached by surgery (safe); images nested INSIDE paragraphs were already outside the review's guarantees before this pair (server strip laws own them) — pre-existing bound, named, unchanged |

## The design that closes F2/F6 for every element AT ONCE (the smart core)

**Per-section baseline + one content oracle.** When a suggestion's diff view
lands (surgery), immediately read the section back from the editor and store
that NORMALIZED form as the section's baseline. Then ONE helper answers every
consumer: `effectiveContent(i)` = if the live section still equals its
baseline (the user typed NOTHING there — exact compare, marks included, same
serializer both sides) → the AI's stored CLEAN `s.ai` (full formatting, links,
lists — zero distortion, all element types); if the user EDITED → the stripped
live content (the user's words trump formatting — they are rewriting that
text). Accept AND Revise-draft both call this one oracle — no duplication.

**Documented honest edge:** in a section the user hand-edited, word-diffed
paragraphs carry the view's plain text — inline marks of the AI text flatten
THERE only. Named, bounded, the user is actively rewriting that text.

## Gaps → changes

| # | Change |
|---|---|
| G1 | diffBlocksHtml: rebuilt same-tag blocks copy the NEW block's attributes (origin survives review; F1 dies — lanes stop flipping/animating) |
| G2 | stripDiffHtml: prune wrappers left EMPTY by the strip (p, li, ul, ol, h1-h6 with no text and no img), bottom-up so emptied lists collapse fully (F3 dies for every element type, not just lists) |
| G3 | Baseline capture on every suggestion landing (initial + each revise round) + the `effectiveContent(i)` oracle; Accept and Revise-draft consume it (F2 + F6 die) |
| G4 | PillButton: hover state resets while disabled (F7 dies) |

## Regression surface (named)
Untouched-accept must equal `s.ai` byte-for-byte (the pre-F1 behavior
restored, now for every element) · edited-accept keeps user text exactly ·
reject byte-faithful (F5, untouched) · orphan zone untouched · strip prune
must NEVER remove a wrapper containing an image · section mode untouched ·
no PHP, engine untouched.

## ADDENDUM — 2026-07-13 owner review round (new facts, verified at their lines)

| # | Fact |
|---|---|
| F9 | The chip hover styles are CONTAINER-scoped: `hover:[&_.pcm-chip-accept]:bg-green-700` / `hover:[&_.pcm-chip-ghost]:bg-slate-100` live in BLOCK_STYLES (SectionModal.tsx:404-405) which sits on the EditorContent wrapper (SectionModal.tsx:1560) — hovering ANY paragraph anywhere in the document darkens EVERY chip at once; moving to the rail (outside the container) lights them all again. THIS is the owner's reported "hover animation / old darker color" — not F1/F7 |
| F10 | Owner design ruling: hover LIGHTENS, never darkens (hover = lift). Revise is an AI/generate action → blue family (outline + text blue, light-blue hover fill, matching the platform's pill grammar). ✕ stays the quietest (faint grey hover). Shared PillButton family is explicitly OUT of scope (owner) |
| F11 | ONE global `busy` flag (SectionModal.tsx:536) gates ~15 controls (615, 1382-1803, PillButton opacity .5 / `disabled:opacity-60`) — clicking any Accept/Save visibly dims EVERY button in the modal at once. Owner ruling: only the clicked control may visibly react; the rest keep their resting look (clicks stay guarded — functional disable remains) |
| F12 | Owner ratified the lane semantics (server truth at service.php:3265-3275): amber `owned` = rule-edited, sky `insert` = rule-added, grey `original` = untouched. So an AI-edited existing section must read AMBER after accept — blue claimed "we added this whole section", which was false. G1 therefore also paints origin `owned` on accept-with-change (matches what the server emits on next load) |

## Gaps added by the addendum

| # | Change |
|---|---|
| G5 | Chip hover scoping: container-hover → SELF-hover (`[&_.pcm-chip-accept:hover]` form) — only the hovered button reacts (F9 dies) |
| G6 | Chip hover look: Accept lightens (green-500 hover); Revise = white pill, blue outline, blue text, light-blue hover fill; ✕ grey ghost, faint grey hover. Same lighten-grammar on the rail cards' Accept/Revise/✕ twins (they darken today). Shared PillButton untouched (F10) |
| G7 | Busy containment: while `busy`, non-invoked controls keep their resting look (guard stays functional); only the clicked control shows the working state (F11 dies) |
| G1b | Accept of a CHANGED section sets the heading origin to `owned` — the lane reads amber immediately, matching server truth (F12) |

## CHECKLIST
- [ ] BEFORE state = this doc committed, tree clean
- [ ] G1 attribute-preserving diff render (origin carried from the OLD block — the AI response is not a reliable carrier) + G1b amber on accepted change
- [ ] G2 pruning strip (image-safe)
- [ ] G3 baseline + effectiveContent oracle wired into accept + revise
- [ ] G4 PillButton hover reset
- [ ] G5 chip hover self-scoped
- [ ] G6 chip + rail hover lighten; Revise blue outline
- [ ] G7 busy dims only the clicked control
- [ ] Verify: tsc 59 · build · harness untouched
- [ ] Changelog + AFTER commit — LOCAL ONLY
