# GAP ANALYSIS — CONTENT INTEGRITY IN AI EDITS (owner report 2026-07-20) — GO GIVEN

**Owner report:** the AI edit flow is "changing some brackets, some code,
and some instructions" — mangled content visible after suggestions land
and after approving.

## THE REVIEW — three defects, each pinned

**D1 — THE RAW-ENVELOPE DUMP (the "instructions in my content" bug).**
`parse_section_reply` (seo/service.php:6104-6119): any reply that FAILS
JSON parsing falls back to `value = $raw` — the raw reply text. When the
model DID answer the envelope but with broken JSON (an unescaped quote in
its html — common with real content), THE RAW JSON STRING becomes the
section's content: the user sees `{"html":"...","changes":[...]}` —
brackets, code, and the AI's instructions — inside the document. The
fallback is only legacy-safe for non-envelope replies; an envelope-shaped
reply that fails to parse must be treated as a FAILURE, never as content.
FIX (server, HELD — his in-flight file): if the body starts with `{` and
contains `"html"`, a parse failure throws an honest error ("the AI's
reply was malformed — run it again") instead of dumping the wrapper.
Attempt salvage first: extract the html field with a tolerant regex over
the outermost braces before giving up.

**D2 — NO CONTENT-INTEGRITY LAW IN ANY ORDER.** The prompts never tell
the model that WordPress shortcodes `[...]`, embedded code, entities, and
markup carry meaning — so it "improves" them: rewrites bracket tokens,
drops attributes, translates shortcode arguments (prompts.php has zero
preserve/verbatim/shortcode language — grep-verified). FIX (frontend, MY
files, NOW): a shared CONTENT_INTEGRITY_LAW appended beside the language
law on EVERY order (all run paths in useAiReview): shortcodes, HTML
attributes/classes/ids, code, entities are untouchable tokens — reproduce
them byte-identically unless the order names them. Root-echo lands
server-side with Group D (same appended-contract pattern).

**D3 — THE SCHEMA GATE'S SILENT DROPS (bounded, documented, watched).**
`canonicalAiHtml` re-serializes replies through the editor schema
(StarterKit + images/FAQ) — markup the schema can't represent (tables,
divs, custom tags) is dropped BY DESIGN at the gate. For simple pages
this is correct; for markup-heavy pages it explains "brackets/code
changed at open/save" independent of the AI. NOT fixed this pair —
named: schema coverage (tables, generic block passthrough) is its own
future gap; the integrity law (D2) reduces the AI-side half immediately.

## THE PLAN
1. **NOW (my files):** CONTENT_INTEGRITY_LAW constant in editor/layout.ts
   beside LANGUAGE_LAW; appended in useAiReview at every order build
   (worker topic, runAi, revise) — one source, all paths.
2. **HELD → joins GROUP D (his seo files, the moment they are clean):**
   D1 envelope salvage-then-throw · the language-law root fix (hub-locale
   masquerade) · the page map · the strict guard · the pinned original.
   ONE server pair, fully specified across gaps e533bc5 + eeec6b9 + this.
3. Verify: tsc 59/0 · build · harness 88/88 + research 34/34 · changelog
   · pathspec commits.

## Regression surface
Prompt-append only on my side (no parsing, no schema change) · quick runs
keep behavior except the added integrity sentence · the held server fixes
change failure handling only (content never silently degrades again).
