/task Surface the LLM's refusal reason instead of reporting an unexplained "EMPTY reply".

## The bug (proven — do not re-diagnose, implement)

`includes/core/llm/class-pcm-llm.php` → `parse_response()` (~line 1818):

```php
$content = '';
if (isset($data['choices'][0]['message']['content'])) {
    $content = $data['choices'][0]['message']['content'];
}
elseif (isset($data['content'][0]['text'])) {
    $content = $data['content'][0]['text'];
}
```

`isset()` returns **false** when the value is `null`. OpenAI returns
`message.content: null` **and** a `message.refusal` string when the model
declines. Verified:

```
isset($d['choices'][0]['message']['content'])          => false
array_key_exists('content', $d['choices'][0]['message']) => true
```

`grep -rn "refusal" includes/` returns **no match** — the API tells us exactly
why it refused and nothing reads it. Result: `$content = ''`, and the user gets
`LLM returned invalid JSON (json_object mode) — the model returned an EMPTY reply`
with no cause. That is a real production error on a live strategy.

## What to build

### A. `parse_response()` — stop dropping null content, capture the refusal

- Read the OpenAI content with `array_key_exists`, not `isset`, and only accept
  it when it is a string (a `null` must NOT be cast to `''` and then silently
  treated as a successful empty reply).
- Capture `choices[0].message.refusal` into a new `refusal` key on the returned
  array (empty string when absent).
- Also map Anthropic's `stop_reason === 'refusal'` to a refusal string.
- The Anthropic content shape (`content[0].text`) must keep working exactly as
  now. Careful: the current `elseif` means the Anthropic branch is only reached
  when the OpenAI key is absent — preserve that behaviour (an OpenAI reply with
  real string content must never fall through to the Anthropic branch).
- Add `'refusal' => $refusal` to the returned array. Do NOT remove or rename any
  existing key (`content`, `usage`, `model`, `finish_reason`, `raw`) — other
  code reads them.

### B. Surface it in the terminal error

`describe_json_failure(bool $by_finish, string $salvage_src): string` (~line 500)
is the pure static that builds the detail clause. Give it a third parameter
`string $refusal = ''`. When `$refusal` is non-empty it takes priority over
every other branch and returns a clause naming it, e.g.:

`' — the model REFUSED to generate this content: <refusal text>'`

Cap the refusal at 300 chars. Keep every existing branch unchanged when
`$refusal` is `''` (back-compat: the parameter is optional and defaults to '').

Then update the ONE call site in `invoke_json_fallback()`'s terminal
`throw new \RuntimeException(...)` to pass `(string)($result['refusal'] ?? '')`.

## File allowlist — touch NOTHING else
- `includes/core/llm/class-pcm-llm.php`
- `tests/unit/LlmJsonRepairTest.php`

If the change appears to need any other file, STOP and reply
`BLOCKED: needs <file>`. Do not improvise.

## Project conventions you will not otherwise know
- **Do not** bump `PCM_VERSION`, the `Version:` header, or any changelog.
- **Do not** commit, stash, checkout, or revert. The tree is DIRTY with
  unrelated in-flight work from other tasks — build on top, never undo.
- Comments state **why**, never what. A comment that just narrates the code is
  rejected. Comments that pin a constraint the code cannot show are wanted
  (e.g. *why* `array_key_exists` and not `isset`).
- No new dependencies.
- `composer test` is BROKEN on this machine (`vendor/bin/phpunit: Permission
  denied`). Use `php vendor/bin/phpunit`.
- `LlmJsonRepairTest.php` loads the real class with only `ABSPATH` defined — it
  is hook-free. Follow that file's existing style. `parse_response()` is
  **private**: invoke it via `\ReflectionMethod` (that file already uses
  `ReflectionMethod` for `schema_key_partition`).

## Tests to add in `tests/unit/LlmJsonRepairTest.php`
1. OpenAI `content: null` + `refusal: "I'm sorry, I can't help with that."` →
   `parse_response()` returns `content === ''` **and** `refusal` containing that
   sentence.
2. OpenAI normal string content → `content` unchanged, `refusal === ''`.
3. Anthropic `content[0].text` shape → `content` unchanged, still works.
4. Anthropic `stop_reason: 'refusal'` → `refusal` non-empty.
5. `describe_json_failure(false, '', 'I cannot reproduce that.')` → the returned
   clause contains `REFUSED` and the refusal text, and does NOT contain
   `EMPTY reply`.
6. `describe_json_failure(false, '')` (no refusal) → unchanged, still contains
   `EMPTY reply`.

## Acceptance check — run it and paste the VERBATIM output
```
cd "/Users/sidharthaparasramka/Desktop/Claude code/Landing page -demo/powerplatform/powerplatform"
php -l includes/core/llm/class-pcm-llm.php
php vendor/bin/phpunit --filter LlmJsonRepairTest
php vendor/bin/phpunit 2>&1 | tail -3
```
Expected:
- `No syntax errors detected`
- `LlmJsonRepairTest` → **OK**, with 6 more tests than before (was 44).
- Full suite → `Tests: 553, ... Errors: 1, Failures: 3`. The 1 error + 3
  failures are PRE-EXISTING (`SeoIntegrationTest` ×2, `PlatformRoleInvariantTest`,
  `TextMatcherTest`) — they must stay at exactly that count. Any increase is a
  regression you caused.

## Constraint echo — required, the result is rejected without it
End your reply by restating each constraint below and how your result satisfies it:
1. File allowlist respected (list every file you touched).
2. No version/changelog bump, nothing committed, nothing reverted.
3. Which ladder rung the solution stopped at and why higher rungs did not hold.
4. Existing return keys of `parse_response()` all preserved; Anthropic path
   still works.
5. Full-suite failure count unchanged at 1 error + 3 failures.
Also paste the full `git diff` of what you changed.
