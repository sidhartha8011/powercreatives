/task fix this seeded one the templste should have the things as i told
fix the four p3

---
Context the codebase map does NOT cover. Read the map and CLAUDE.md yourself for everything else.

## Part 1 — the seeded SEO Pillar template still hides one fragment

Five prompt variables exist: `{{ keyword }}`, `{{ brand_context }}`, `{{ research }}`,
`{{ output_format }}`, `{{ media_instructions }}`. A fragment is auto-appended ONLY when its variable
is not referenced.

The seeded **SEO Pillar Article** template (`includes/core/class-pcm-template-seeds.php`, key
`seo_pillar_prompt`) currently places FOUR of them — `grep -c` gives keyword 1, brand_context 1,
research 1, media_instructions 1, **output_format 0**.

So `Return a JSON object with the following fields: title, content (HTML), metaTitle,
metaDescription.` is STILL injected invisibly into the user turn. The owner's requirement is that
nothing is hidden. **Place `{{ output_format }}` in the seed**, in the existing `OUTPUT FORMAT:`
section (it is the JSON field contract, and that section already covers output rules).

This was previously left out on purpose because placing all three user-side variables emptied the
user message and Anthropic rejects an empty content block. That is already fixed — `build_prompt()`
now falls back to the bare keyword — so it is safe now. Verify the user turn is still non-empty
after your change (there is a test for it: `test_placing_every_user_side_variable_never_empties_the_user_turn`).

## Part 2 — the four open P3s (from an independent review of the last round)

**P3-a — no upgrade note.** A user template that ALREADY contains one of the five token names (e.g.
someone had typed `{{ keyword }}` as a literal) silently loses its auto-appended fragment on upgrade.
There is no migration, notice, or entry in `docs/UPGRADE-SAFETY.md`. Add a short, accurate entry to
that file describing the behaviour change and who it affects. Documentation only — do NOT write a
migration.

**P3-b — the empty-user-turn fallback contradicts its own comment.** In `build_prompt()`:
```php
$user_segments[] = $keyword_text !== '' ? $keyword_text : $output_format;
```
The comment directly above promises it restores a valid request "WITHOUT smuggling back any of the
guidance the author just took control of" — but when the keyword is ALSO empty it emits
`$output_format`, which is one of the five variables the author just placed, so the prompt then
contains it twice. Replace that second branch with a short neutral pointer that is NOT one of the
five fragments, and make the comment true.

**P3-c — a docblock makes a false claim.** `render_template_vars()`'s docblock says unknown tokens
are left untouched so "real `{{{ triple }}}`, `{{#if}}`, and JSON braces are never eaten". That holds
only for names NOT in the variable map — `'Triple: {{{ keyword }}} end'` renders to `Triple: {kw} end`
because `keyword` IS known. The known set just grew from 3 to 8, including collision-prone names like
`research` and `keyword`. Reword the claim so it is accurate.

**P3-d — an undocumented deviation.** When a template has ZERO prompt entries, `build_prompt()` still
injects a hardcoded default system line (`You are an expert SEO content writer…`) with no variable.
That is unavoidable — a template with no prompt entry has nowhere to place a token — but neither the
code nor the plan records it as a deliberate exception. Add a brief comment at that line saying so.

## File allowlist — touch NOTHING else
- `includes/core/class-pcm-template-seeds.php`
- `includes/modules/strategy/service.php`
- `docs/UPGRADE-SAFETY.md`
- `tests/unit/StrategySourceVarsTest.php`

Anything else → reply `BLOCKED: needs <file>` and stop. Do not improvise.

## DO NOT TOUCH
- `includes/core/llm/class-pcm-llm.php` — its `$json_instruction` is an OpenAI `json_object`
  PROTOCOL requirement (the API rejects the request unless the prompt mentions JSON), NOT a hidden
  prompt. Leave it exactly as is.
- The BYTE-IDENTITY contract: a template referencing NONE of the five variables must still produce
  the exact prompt it produced before the variables existed. There are tests for this — if any of
  them go red, you broke it.

## Project conventions you will not otherwise know
- The tree is DIRTY with unrelated in-flight work. Do NOT commit, stash, checkout, revert or clean.
- Do NOT bump `PCM_VERSION`, the `Version:` header, or any changelog.
- Comments state **why**, never what.
- No new dependencies.
- `composer test` is BROKEN here (`vendor/bin/phpunit: Permission denied`) — use `php vendor/bin/phpunit`.

## Acceptance check — run it and paste the VERBATIM output
```
cd "/Users/sidharthaparasramka/Desktop/Claude code/Landing page -demo/powerplatform/powerplatform"
php -l includes/core/class-pcm-template-seeds.php
php -l includes/modules/strategy/service.php
for v in keyword brand_context research output_format media_instructions; do
  printf "%-20s %s\n" "$v" "$(grep -c "{{ $v }}" includes/core/class-pcm-template-seeds.php)"
done
php vendor/bin/phpunit --filter StrategySourceVarsTest
php vendor/bin/phpunit 2>&1 | tail -3
php tests/standalone/run.php 2>&1 | tail -2
```
Expected:
- both `php -l` clean;
- **all five variables report 1** (that is the whole of Part 1);
- `StrategySourceVarsTest` OK;
- full suite `Errors: 1, Failures: 3` — those four are PRE-EXISTING (`SeoIntegrationTest` ×2,
  `PlatformRoleInvariantTest`, `TextMatcherTest`). Baseline is 585 tests; any INCREASE in
  errors/failures is a regression you caused;
- standalone `ALL GREEN — 88 passed, 0 failed`.

## Constraint echo — required; the result is rejected without it
End your reply by restating each and how your result satisfies it:
1. Every file you touched (must be inside the allowlist).
2. You did NOT modify `class-pcm-llm.php`, and why that was right.
3. The byte-identity contract still holds, and which test proves it.
4. For P3-b: the exact replacement string, and why it is not one of the five fragments.
5. Nothing committed, no version bump, no new dependencies.
Also paste `git diff --stat`.
