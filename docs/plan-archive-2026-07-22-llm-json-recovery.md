# Plan — "LLM returned invalid JSON (json_object mode)" on a Social strategy

Task (2026-07-21/22): owner screenshot of strategy "fifa fotbolls-vm 2026"
(Source=Social, instagram.com/hailthegame, template "SEO Pillar Article"):
4/10 completed, **3 failed**, all three with the identical error
`LLM returned invalid JSON (json_object m…`. Row 8 sits at "Generating…" on a
Paused strategy — that is the separate wedge bug fixed in the previous entry.

The previous session's leading hypothesis (empty model dropdown → gemini
fallback → "No active API key") is **DISPROVEN** by the screenshot: the LLM is
reached and replies; the reply just doesn't parse.

## Evidence gathered this session

**Owner-confirmed:** all three failing rows carry the same message.

**Nondeterministic, not content-determined:** rows 2 and 7 come from near-
identical source captions ("All that happened in the last 24 hrs 😱🎉🌐 1️⃣…") —
row 2 failed, row 7 completed. Same strategy, template, and model. So no single
emoji/caption breaks a parser.

**Recovery chain probed directly** (scratch harness driving the REAL public
statics `extract_json` / `repair_json` / `salvage_json_by_keys` with the real
`article_schema()` key partition — 4 string keys + `media_assets` as a null
key). Results:

| payload shape | outcome |
|---|---|
| valid JSON | OK (decode) |
| unescaped quote in `content` | OK (salvage) |
| unescaped quote in `title` | OK (salvage) |
| raw newlines in `content` | OK (repair) |
| trailing comma | OK (repair) |
| unescaped quote + populated `media_assets` | OK (salvage) |
| emoji + unescaped quote | OK (salvage) |
| `media_assets` first, then bad quote | OK (salvage) |
| truncated mid-content | **FAIL** — gate blocked (by design, no half articles) |
| missing `metaDescription` + bad quote | **FAIL** — salvage refuses a partial |
| `content` containing a literal `,"metaTitle":` | **FAIL** — sanity gate (by design) |
| fenced reply whose content contains an inner ``` fence | **FAIL** — `extract_json` bug |
| leading prose containing `[` before `{` | **FAIL** — `extract_json` bug |

So the chain is robust for emoji and unescaped quotes — which rules out the
obvious suspects — and the surviving holes are the two `extract_json` defects
plus truncation.

**`article_schema()` IS strict-compliant** (`required` + `additionalProperties:
false` at both levels), so tier 1 (json_schema, API-enforced) should work.
Reaching tier 2 at all means the model previously rejected `json_schema` and it
is now remembered-skipped — leaving output entirely unconstrained, which is the
precondition for every failure above.

## What is NOT determinable from here

Which of the three surviving holes actually hit these rows. The stored message
carries `json_last_error_msg()` + 500 raw chars, but the UI truncates it and the
owner reports no more detail. Critically, the terminal error only reports
truncation when `finish_reason === 'length'` — and this codebase already
documents that Gemini's OpenAI-compat endpoint truncates *without* setting it
(`class-pcm-llm.php:344-348`). So a truncation failure and a malformed-output
failure are currently **indistinguishable in the UI**. That is why three
different underlying causes could all read as one identical error.

## Fix (one file + tests; no schema/version/changelog bump)

`includes/core/llm/class-pcm-llm.php`:

1. **`extract_json` — inner fence.** The fence regex is non-greedy, so a ```
   fence *inside* the article content ends the match early and truncates the
   JSON. Try the narrow match first and widen to the LAST fence only when the
   narrow body fails to decode — an unconditional greedy match would weld two
   separate fenced blocks together (a regression the first attempt introduced).
   The fence branch then **RETURNS its body**, exactly as at HEAD. An earlier
   revision let it fall through into the brace/bracket scan; adversarial review
   proved that re-created the very failure class this plan rejects twice below —
   the scan mines a decodable span out of a fence body that is not JSON at all
   (a ```js block containing `{}` → `"{}"` → decodes to an empty array → passes
   every `!is_array($parsed)` gate → item completes with an EMPTY article
   instead of throwing). Verified A/B against HEAD; pinned by a data-provider
   test.
2. **`extract_json` — leading `[`: ATTEMPTED, then DELIBERATELY ABANDONED.**
   `min($start_obj, $start_arr)` picks a bracket appearing in leading prose and
   slices to the last `]`, producing garbage. Two fixes were implemented and
   both were rejected in review, each having introduced a strictly WORSE
   failure than the bug they fixed:
   - *object-first preference* silently reduced `[{"a":1}]` to `{"a":1}`;
   - *offset-ranking + a nested-candidate rule* still lost to an inner
     well-formed array whenever that array **overhangs** the object span —
     which is the normal shape, because `media_assets` is `article_schema()`'s
     LAST property, so a truncated reply's `]` sits past its `}`.

   In both cases the inner array decoded and won, the caller received a
   decodable-but-WRONG value, `is_array()` was true so nothing threw, salvage
   never ran, and the item completed with `content` defaulting to `''` — a
   **silent empty article** replacing a loud, diagnosable error. That is worse
   than the bug, and this function is on the path of every LLM call in the
   plugin.

   **Reverted to the original.** Doing this safely needs a real brace-matching
   scan (track string state, match the first opener to its own close) rather
   than `strpos`/`strrpos` guessing, which deserves its own change and review.
   The limit is documented in the code and pinned by a test that asserts the
   CURRENT behaviour. It is also not what is biting today: in json_object mode
   the API returns a bare object with no leading prose.
3. **Terminal error — report truncation honestly, without misdiagnosing.**
   Extracted into a pure, testable `describe_json_failure()`. `looks_truncated()`
   is consulted beside `finish_reason` BUT only when the reply is also
   structurally incomplete (does not close with `}`): it counts quote parity,
   so a complete reply with an unescaped inner quote — exactly the payload class
   that reaches this error — false-positives as truncated, and confidently
   blaming the token budget would be worse than the vague message it replaces.
   An empty reply gets its own wording, but `finish_reason` is tested FIRST —
   short-circuiting on an empty span ahead of it discarded the only
   non-heuristic truncation signal (a regression against HEAD). The parse
   OUTCOME and its message are both frozen into locals BEFORE `extract_json()`
   runs: that function probes candidates with `json_decode()`, so reading the
   global afterwards can report an unrelated slice's "Syntax error" — or flip a
   SUCCESSFUL parse into the failure branches.

Deliberately NOT doing: relaxing the salvage's all-required-keys rule or its
sanity gate (both are intentional "fail loudly rather than publish a half
article" decisions), and no change to the generation pipeline or budgets.

## Verification
- `php vendor/bin/phpunit` — baseline is 475 tests / 1 error + 3 failures
  (pre-existing, none in strategy or LLM code).
- New tests in `tests/unit/LlmJsonRepairTest.php` covering both `extract_json`
  defects and the truncation-reporting change; each mutation-checked.
- Re-run the scratch probe: the two `extract_json` rows must flip to OK.
- `php tests/standalone/run.php` → 88/88.

## Honest limitation
This fixes three provable defects and makes the failure self-diagnosing. It is
**not confirmed** to be the cause of these three rows — if they were plain
truncation, the fix makes the next error say so explicitly rather than
resolving it.
