# GAP ANALYSIS — AI CONTENT GATE — 2026-07-13 — AWAITING GO

**Owner report (live, screenshot):** rewriting a section that contains a
bullet list renders the suggestion with an EMPTY bullet before every real
one. Owner order: no spot-fix — the long-term senior solution.

## Facts (each verified at its line)

| # | Fact |
|---|---|
| F1 | The editor pipeline speaks ONE dialect everywhere — the editor's own normalized serialization: captured originals `s.html` (split from `editor.getHTML()`), baselines (read back from the editor after landing), live compares, saves. The AI reply is the ONLY stored input that stays RAW |
| F2 | Raw AI replies enter at exactly TWO points and are STORED raw: the initial run worker (SectionModal.tsx:997 `res?.value` → :1002 diff render + stored as `s.ai`) and Revise (SectionModal.tsx:1068 → :1072 + stored as `s.ai`) |
| F3 | The raw value flows to every consumer: the diff VIEW (`diffBlocksHtml(s.html, value)`), untouched-ACCEPT (`effectiveContent` returns `s.ai`, SectionModal.tsx:952), the REVISE draft (same oracle), and each lands in the doc via `applySection` (SectionModal.tsx:930) |
| F4 | `insertContentAt` — applySection's write — defaults `preserveWhitespace: "full"` (node_modules/@tiptap/core/dist/index.js:716, :739-741): whitespace-only text nodes in the input are KEPT as content |
| F5 | The diff view passes AI list/structural blocks through VERBATIM: `addedBlock` clones the AI's `<ul>` (word-diff.ts:74-77) and structural blocks return `b.outerHTML` (word-diff.ts:88) — a pretty-printed AI reply (`<ul>\n<li>…` ) carries a newline text node before every `<li>`. A bullet list cannot hold loose text, so under F4 the parser coerces each one into a listItem = ONE EMPTY BULLET BEFORE EVERY REAL ONE — the owner's screenshot, mechanically |
| F6 | The same raw string is what untouched-Accept inserts (F3) — the artifact class survives ACCEPT too, not just the view. G2's strip-prune (committed today) cannot catch these: the empties carry no diff marks |
| F7 | Section mode is SAFE by construction: `runAi` enters via `setContent` (SectionModal.tsx:887→889) which parses with normal whitespace rules (dist/index.js:1148-1150, `!== "full"` branch) and stores nothing raw — no change needed there |
| F8 | The gate machinery ships in the installed editor core, exported: `createNodeFromContent(html, schema, {parseOptions})` (dist/index.js:614, slice path :683 returns a Fragment) and `getHTMLFromFragment(fragment, schema)` (dist/index.js:1297-1303). Zero new dependencies |

## The design (the long-term law)

**One gate at the door: no raw model output ever enters the pipeline.**
The moment an AI reply arrives it is parsed through the editor's OWN schema
with normal whitespace rules and re-serialized to the editor's canonical
dialect. From then on the suggestion IS editor-native: the diff view, the
stored `s.ai`, untouched-Accept, the Revise draft, the baseline compare —
all consume canonical content by construction. The whole artifact class
(whitespace phantoms, unparseable markup, alien attributes) is resolved at
ONE visible point instead of being tolerated at N consumers. Content the
schema cannot represent is dropped at the gate — exactly what the editor
itself would do on insert, only once and upfront, never mid-review.

Not chosen (and why): `parseOptions` on `applySection` = teaching one write
point to tolerate dirty input while `s.ai` stays raw for every other
consumer — a patch, not a law. Server-side normalization = a SECOND parser
to keep in lockstep with the editor's schema forever — permanent debt.

## Gaps → changes (exact file, exact line)

| # | Change | File : line |
|---|---|---|
| A1 | Import the gate machinery: add `createNodeFromContent`, `getHTMLFromFragment` and `type Editor` to the existing `@tiptap/core` import; add `Fragment` to the `@tiptap/pm/model` import | SectionModal.tsx:43, :48 |
| A2 | `canonicalAiHtml(editor, html)` — module-level pure helper beside the other content helpers (after `htmlText`): '' stays '', otherwise parse via `createNodeFromContent(html, editor.schema, { parseOptions: { preserveWhitespace: false } })` → `Fragment.from` → `getHTMLFromFragment` | SectionModal.tsx:~434 (after :428-433) |
| A3 | Initial run worker: `value` wrapped by the gate at its birth — one line | SectionModal.tsx:997 |
| A4 | Revise: same wrap at the same shape | SectionModal.tsx:1068 |

No other code changes. No PHP. `applySection`, word-diff, the oracle, the
strip: all untouched — they now receive only canonical input.

## Regression surface (named)
Untouched-accept === the canonical `s.ai` byte-for-byte (one source stands) ·
`changed` detection (`htmlText`) already whitespace-collapsed — unaffected ·
FAQ details/summary parse through the registered FaqItem/FaqSummary nodes —
canonical form = what landing in the editor produced anyway · reject path
byte-faithful (`s.html`, gate never touches it) · section mode untouched (F7)
· empty AI reply stays '' (the clean/failed branches keep their meaning).

## CHECKLIST
- [ ] BEFORE state = this doc committed, tree clean
- [ ] A1 imports (SectionModal.tsx:43, :48)
- [ ] A2 `canonicalAiHtml` helper (SectionModal.tsx after :433)
- [ ] A3 gate at the initial-run worker (SectionModal.tsx:997)
- [ ] A4 gate at Revise (SectionModal.tsx:1068)
- [ ] Verify: tsc 59 · build · harness untouched
- [ ] Changelog + AFTER commit — LOCAL ONLY
