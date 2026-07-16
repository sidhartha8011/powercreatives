# GAP ANALYSIS — REVIEW SECTION IDENTITY (index → stable anchors) — 2026-07-14 — AWAITING GO

**Owner live find:** accepted sections (single + Accept All) left unresolved
diff views and one mangled paragraph. Root proven from the screenshot + code:
the AI added MANY new sections mid-review and the engine's identity system
cannot survive that.

## Facts (each verified)

| # | Fact |
|---|---|
| I1 | Section identity is the HEADING ORDINAL everywhere: `sectionRange(i)` counts headings to find section i (SectionModal), `splitDocSections` groups by headings (word-diff), the chips decoration maps by heading counter (ReviewControls), and the review state is an array indexed by the ORIGINAL capture. The silent law: one section = one heading, count frozen during review |
| I2 | The law is breakable BY DESIGN now: the subtopics teacher's instructions legitimately order "add a … section covering X"; the AI obeys inside a per-section rewrite; `diffBlocksHtml` renders the extra headings as real heading blocks (shape-preserving added blocks). Every added heading = +1 to the ordinal space |
| I3 | Once the count grows, every later index points one-or-more sections OFF: accepts write to shifted ranges — some diff views never get replaced (the owner's all-green sections), overlapping writes mangle text (the interleaved red/green paragraph). Accept-then-Accept-All ordering only decides WHICH sections land before the divergence |
| I4 | The same exposure pre-existed (Insert-FAQ during review adds an h2) but was rare; the optimizer made multi-section replies COMMON — the collision is architectural, not cosmetic |
| I5 | The machinery for a stable heading attribute already exists and is proven: `data-pcm-origin` is a registered heading attribute (parse/render, keepOnSplit false) that rides through the review and is stripped server-side on save (seo/service.php:4695 regex) — a review-identity attribute is the SAME pattern |
| I6 | `applySection` is the ONE writer (every landing/resolution goes through it) — a single stamping point exists by construction |

## The design — identity by ANCHOR, not by count

**One law: a reviewed section is identified by a persistent id on its
heading, never by counting.**

- **R1 — stamp at review start:** each captured section's heading gets
  `data-pcm-review-id="<i>"` (registered heading attribute, the I5
  pattern) via one transaction.
- **R2 — the ONE writer keeps identity alive:** `applySection(i, html)`
  stamps the id onto the FIRST heading of whatever it writes (diff view,
  accept result, reject restore) — identity survives every landing (I6).
- **R3 — ranges by anchor:** section i spans from its id-heading to the
  NEXT heading carrying ANY review id (or doc end). AI-ADDED headings
  carry no id → they belong to the section that produced them: accept
  keeps them, reject removes them, revise re-sends them — multi-section
  replies become CORRECT by construction, not forbidden.
- **R4 — consumers switch to the anchor:** `sectionRange` finds by id;
  the live-section extraction splits on id-carrying headings only (a
  boundary-aware variant beside `splitDocSections`); the chips decoration
  maps chip → section by the heading's id attribute. The oracle, baselines,
  strip, compiler, teachers: UNTOUCHED — they operate on content, not on
  position.
- **R5 — clean exit:** when the review closes, one transaction removes
  every `data-pcm-review-id`; belt: the server's existing save-strip regex
  (I5) also removes the attribute — the id can never reach a served page.
- **Named edge (honest):** if the user DELETES a section's heading during
  review, its anchor is gone — the decision no-ops, the rail marks the
  section resolved-as-rejected, and a toast states the section no longer
  exists. Never a silent wrong-range write.

## Regression surface
Single-heading replies behave byte-identically (one id-heading, next
id-heading = the old boundaries) · lane/origin decorations untouched
(separate attribute) · saves clean by R5 + belt · orphan zone untouched ·
section mode untouched · optimizer/compiler/teachers untouched.

## CHECKLIST
- [ ] BEFORE = this doc committed, tree clean
- [ ] R1 stamp at review start (one transaction)
- [ ] R2 applySection stamps identity on every write
- [ ] R3+R4 anchor-based ranges: sectionRange · live extraction · chips mapping
- [ ] R5 exit cleanup + server strip belt
- [ ] Named-edge handling (deleted heading → honest no-op)
- [ ] Verify: php -l · harness 88 · tsc 59 · build · changelog · commit LOCAL ONLY
- [ ] Owner live re-test: the exact failing flow (tick subtopics → optimize → accept few → Accept All)
