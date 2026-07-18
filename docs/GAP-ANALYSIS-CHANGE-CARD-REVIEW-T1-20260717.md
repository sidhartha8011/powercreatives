# GAP ANALYSIS — THE CHANGE-CARD REVIEW T1 — 2026-07-17

Owner critical (blueprint §10 2026-07-17 entry; scope ruling: ONLY the
acceptance step changes — rail/catalog/basket/compiler/Optimize untouched).
Goal: the review shows per-section WHAT+WHY change cards with verified
claims, hover-to-highlight, and calm Before/After blocks on heavy rewrites.
No regression: a section without a verifiable change list renders EXACTLY
today's review.

## A. FACTS (all verified at file:line today)

1. **The repeated-text bug**: SectionModal.tsx:2275-2303 renders the GLOBAL
   `runDirectives` (the whole compiled basket: pills + plain-name line +
   first 3 directive texts) under EVERY section card — identical
   everywhere. Per-change attribution (§9.1) does not exist.
2. **The reply is plain HTML**: remote_optimize_section (service.php:6022)
   returns {value, model, provider}; the run loop (SectionModal:1240-1338,
   pool of 4) sends the SAME topic to every section and word-diffs the
   reply into the doc (diffBlocksHtml word-LCS → red confetti on rewrites;
   word-diff.ts:150-172 pairs blocks, word-diffs paired text blocks).
3. **Marks machinery**: diff view = data-diff-added/removed spans;
   stripDiffHtml is the save guard; effectiveContent oracle + identity
   anchors + versions are the frozen review pipeline (untouched by T1).
4. **Append-contract precedent**: the revise human-editor contract is
   appended server-side around the template output (service.php:6025-6031)
   — the JSON envelope follows the same pattern: NO template/seed
   migration, user-customized templates unaffected.
5. **Retention machinery exists**: sentence_retention (:6072) — reusable to
   judge "rewritten" server-side; tunables live in pcm_optimizer_research
   with array_replace_recursive merge (optimizer/service.php:736-770) —
   new keys reach old installs automatically.
6. **FOUND DEFECT (pre-existing, fixed in this pair, named)**: the
   remoteOptimizeSection transform (trpc-routes.ts:561) DROPS `draft` —
   reviseSection sends it (SectionModal:1438) but it never reaches the
   server, so the REVISE FIDELITY contract + retention check (gap e8fcae5
   D3) have been dead on the wire since they shipped. One body key fixes it.
7. Flash/decoration machinery: blockDecorations (SectionModal:220) +
   SectionBlocks storage — extensible for a quote-highlight decoration.

## B. DESIGN (T1)

**B1. Reply contract (server, append-envelope):** when the request carries
`reportChanges`, remote_optimize_section appends to the topic:
respond ONLY with JSON {"html": "<complete revised section HTML>",
"changes": [{"what": one plain sentence, "why": one of the provided
purpose ids or "", "quote": 5-12 words copied VERBATIM from the revised
html}]} — list every real change, never one you did not make.
**Parse + VERIFY server-side** (new static parse_section_reply): strip
fences → json_decode → value = html; each change kept ONLY if `what`
non-empty AND `quote` found in the normalized text of html (whitespace/
case-collapsed strpos) AND `why` ∈ provided purposes (else why='');
capped at review.maxChanges. Parse failure → value = raw reply,
changes=[] — **THE FLOOR: byte-identical current behavior.**
**Rewritten flag**: rewritten = sentence_retention(original section text,
value) < review.rewriteRetention — computed server-side with the tunable.
Reply gains `changes` + `rewritten`. Retention/revise logic parses BEFORE
the existing retention path (value = extracted html).

**B2. Tunables (data, merge-seeded):** pcm_optimizer_research gains
`review: {maxChanges: 12, rewriteRetention: 0.35}`.

**B3. Wire:** controller remote_optimize_section passes `purposes[]`
(sanitized keys) + `reportChanges` (bool); trpc transform body gains
`draft` (the A6 defect fix) + `purposes` + `reportChanges`.

**B4. Consolidated diff (word-diff.ts):** diffBlocksHtml gains an options
arg {consolidated?: boolean}: consolidated renders ALL original blocks
via markBlock('removed') then ALL new blocks via markBlock('added') —
one calm struck group + one green group, SAME marks/strip/oracle, zero
new machinery. Used when the reply says rewritten.

**B5. Frontend review:** ReviewSection gains changes?/rewritten?; run
loop + reviseSection store them and pick the diff mode. The rail card:
when s.changes has entries → the card shows ITS OWN changes (what line +
plain-name why via teacherById + group pill) replacing the global block;
`+N more` past 4; hover a change → quote highlighted in the doc
(findQuoteRange: char-accurate normalized search inside sectionRange(i)
with position mapping; not found → the existing section flash — honest
fallback); mouse-leave clears. **No changes list → today's global block
renders unchanged (the floor).** Global run pills stay in the rail
header area only via the existing top summary (untouched in T1).

**B6. Explicitly untouched:** catalog/basket/checkbox step, compiler,
Optimize triggers, accept/revise/reject semantics + all controls,
identity anchors, content gate, effectiveContent oracle, versions,
keyword ride, client colors, stripDiffHtml guard.

## C. CHECKLIST

1. [ ] optimizer research_tunables seed += review block (B2).
2. [ ] service.php: parse_section_reply() + envelope append + verify +
       reply keys changes/rewritten (B1); revise path parses both runs
       (initial + retry).
3. [ ] controller: purposes[] + reportChanges pass-through (B3).
4. [ ] trpc-routes: body += draft (A6 FIX) + purposes + reportChanges.
5. [ ] word-diff.ts: consolidated mode (B4).
6. [ ] SectionModal: type + storage + diff-mode pick + own-changes card
       w/ fallback + hover highlight + clear (B5).
7. [ ] Harness (page_versioning_test section 5): parse_section_reply —
       valid JSON extracts, fenced JSON extracts, unverifiable quote
       dropped, foreign why blanked, non-JSON falls back raw, cap holds.
8. [ ] php -l each · 88/88 · 30+/30+ · 34/34 · tsc 59 zero new · build
       "built in" · served byte-identical.
9. [ ] Optional live envelope probe (ONE bounded small-section optimize
       via web context) if a text key is configured — else owner browser
       run is the live check (expected: cards with why, hover highlight,
       calm rewrite blocks, revise retention NOW ACTIVE via the draft fix).
10. [ ] Changelog + seo module 1.0.3 + AFTER commit LOCAL ONLY.
