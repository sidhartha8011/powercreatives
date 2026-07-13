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

## CHECKLIST
- [ ] BEFORE state = this doc committed, tree clean
- [ ] G1 attribute-preserving diff render
- [ ] G2 pruning strip (image-safe)
- [ ] G3 baseline + effectiveContent oracle wired into accept + revise
- [ ] G4 PillButton hover reset
- [ ] Verify: tsc 59 · build · harness untouched
- [ ] Changelog + AFTER commit — LOCAL ONLY
