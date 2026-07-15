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
[ ] BEFORE commit (this doc) · [ ] OptimizerRail rework (my lane file
only) · [ ] tsc 59/0 new · [ ] build "built in" · [ ] changelog ·
[ ] AFTER commit LOCAL ONLY
