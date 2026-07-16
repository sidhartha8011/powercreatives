# GAP ANALYSIS — ANALYZE RAIL VISUAL HIERARCHY (owner order 2026-07-15, screenshot)

Owner: the rail is unusable — walls of text, no visible hierarchy; primary
checkbox rows must not carry large text; sections need background; details
belong in a disclosure. NO information may be removed. Senior-UX pass.

## FACTS (current OptimizerRail.tsx, verified)
1. Each FOUND row renders the FULL instruction as its primary text (often
   3-5 lines) + full evidence under it — every row is a paragraph;
   30+ items = a text wall (screenshot).
2. Sections have no container — flat list, border-b only; groups are a
   thin uppercase label; nothing separates purposes visually.
3. Clean (passed) items render as a permanent list — more standing text.
4. The item LABEL is short by contract ("Contact visible early") and is
   currently used ONLY as a title attr — the one concise handle is unused.
5. All information already exists per item: label (short), evidence
   (what we found), instruction (what the fix will do), source (the tap).

## DESIGN (Apple-calibre hierarchy — three levels, all info one tap away)
- **Level 1 — group**: uppercase micro-label (unchanged position).
- **Level 2 — purpose card**: each teacher section becomes a WHITE
  rounded card (subtle border+shadow) on the rail's slate bg — the
  background the owner asked for; header row = purpose name + peek +
  re-analyze.
- **Level 3 — item row = ONE line**: checkbox + the SHORT label
  (truncated) + chevron. The row is scannable; ticking stays one click.
- **Disclosure per item** (chevron / row-title click): the full detail —
  evidence ("what we found", grey) and the instruction that rides the
  basket ("the fix", primary-tinted block) + source. Nothing removed —
  relocated one tap deep.
- **Passed items collapse**: one quiet summary row "N passed ✓" with a
  chevron → expands to the familiar list. Standing height shrinks ~70%.
- Informational items (no directive): no checkbox — label + disclosure
  only (they can't ride the basket anyway).
- POSITIVES law kept at the detail level: the disclosure leads with the
  fix; the row uses the short label because scannability IS the usability
  ruling here (this order supersedes the row-shows-instruction reading).

## CHECKLIST
[x] BEFORE commit (this doc) · [x] OptimizerRail rework (my lane file
only) · [x] tsc 59/0 new · [x] build "built in" · [x] changelog ·
[x] AFTER commit LOCAL ONLY (1c7a52b)

---

# ADDENDUM — ROUND 2 (owner screenshots 2026-07-15, QUEUED — owner will
send more feedback; build as ONE pass on his GO)

## FACTS (owner screenshots, verified against the code)
1. AMBIGUITY: checkbox rows show the check NAME ("Natural human flow") —
   a ticked NAME reads as a feature the page HAS, when it means the
   OPPOSITE (a gap whose fix is selected). Only list position
   distinguishes gap vs passed — too subtle.
2. DOUBLE HEADER: group "SEARCH OPTIMIZATION" + first card "Search
   engine optimization" — near-identical titles stacked.
3. REPEATED PREFIXES: serp teacher labels = 'Winners for "kw": <id>' and
   demand labels = 'Proven demand: "kw"' — the constant prefix fills the
   row, the differentiator truncates off the end; rows look identical
   and heavy (the "big text" impression = repetition, font is 11px).

## DESIGN (round-2, one pass after owner completes feedback)
- D1: found rows read as one-line ACTIONS (the fix's first line,
  truncated) — a ticked action reads as what will happen. Passed rows
  keep check names (read correctly there).
- D2: rename the page-type checks teacher label ("Search engine
  optimization" → "Page-type checks") — the group owns the word.
- D3: display-side prefix stripping — rows show only the differentiator
  (serp: "Format" / "Coverage: X" / "Angle"; demand: the keyword itself,
  rank+impressions as subtext). Shared context ("for `kw`") stated ONCE
  per card, never per row. Labels stay unique in DATA (itemKey identity
  untouched) — presentation only.
- (await further owner rounds before building)
