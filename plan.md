# Plan — template-driven post generation with `{{ post_* }}` variables

Task (2026-07-22): "the generation of the post should be in templates and the
content of the posts (social media post) should be in an expression or a
variable ex. `{{ post_content }}`. RSS reposting with variables (expressions):
`{{ post_content }}`, `{{ post_title }}`, `{{ post_link }}`."

Previous plan (LLM JSON recovery) archived at
`docs/plan-archive-2026-07-22-llm-json-recovery.md`.

## What exists today (read, file:line)

- `build_prompt()` (`service.php:4672`) takes the template's `category === 'prompt'`
  entries and uses them **verbatim** as the system prompt (`:4680-4684`). There is
  **no variable substitution of any kind** — the docblock says "injecting
  keyword(s)", but the keyword only ever reaches the *user* message (`:4731-4736`),
  never the template text.
- The source post reaches the model only through a **hardcoded English rider**,
  `rss_source_instruction()` (`service.php:2768`), which builds
  `"Write an article about this social media post: '<title>' (<link>). The post
  says: \"<text>\"."` and is passed in as `$rss_source` from the per-item call
  site (`:1327`). The consolidated call site (`:768`) passes no rider at all.
- Values available on the item config (`item_config()`): `sourceText`,
  `sourceTitle`, `sourceLink`.
  **CORRECTED IN REVIEW:** `sourceText` was populated for social posts ONLY —
  `fetch_rss_feed_items()` (`:2273`) captured just permalink/id/title/date, so
  `{{ post_content }}` could never resolve on an RSS strategy, which is the
  owner's headline case. Fixed by also reading the feed entry's
  `get_description()`; it rides the same `text` key the social branch already
  uses, so ingest → pop → `sourceText` needed no further change.
- `grep` for `{{` across `includes/` and `app/src`: **no existing `{{ }}`
  convention**. `PCM_Prompt_Placeholders` is a different system (the prompts
  module / SEO editor sections) and is not involved here.

So today the user cannot influence how the post is used — the phrasing is
frozen in PHP. That is exactly what this task removes.

## Design

Three lowercase variables, whitespace-tolerant (`{{post_title}}` ==
`{{ post_title }}`), resolved from the item config:

| variable | source | empty when |
|---|---|---|
| `{{ post_content }}` | `sourceText` | keyword item, or no caption captured |
| `{{ post_title }}` | `sourceTitle` | keyword item |
| `{{ post_link }}` | `sourceLink` | keyword item |

1. **`source_vars(array $item_cfg): array`** — pure; the var→value map.
2. **`render_source_vars(string $text, array $item_cfg): string`** — pure;
   substitutes the three known tokens. **Unknown `{{ … }}` tokens are left
   untouched** — a typo (`{{ post_body }}`) stays visible to the author rather
   than being silently swallowed, and we never eat a legitimate brace pair.
3. **`uses_source_vars(string $text): bool`** — pure; does this template
   reference any of them?
4. **`build_prompt()`** applies (2) to each template prompt entry. Nothing else
   in the prompt is touched, so a template with no variables produces a
   **byte-identical** prompt to today.
5. **The rider becomes conditional** (`generate_next_item()`): when the template
   genuinely carries the post, pass `$rss_source = ''` — the template is now in
   control, and appending the hardcoded sentence would duplicate/contradict it.
   When it does not, the existing rider fires exactly as now. This is the
   "generation should be in templates" half of the request, and it is what keeps
   every existing strategy working unchanged.
   **CORRECTED IN REVIEW:** suppression must key off the referenced variables'
   resolved VALUES (`template_carries_source()`), not merely the presence of a
   token. Keying off the token alone meant a template referencing only
   `{{ post_content }}` on an item with no caption lost BOTH its context and the
   rider's mandatory "link back to the original post" instruction — strictly
   worse than before the feature existed.
6. **Frontend**: surface the three variables in the Templates dialog so the
   feature is discoverable rather than hidden.

Deliberately NOT doing: a general expression language, conditionals/loops, or
new variables beyond the three named (`{{ keyword }}` etc. can follow if asked
— the map in (1) makes it a one-line addition). No schema change, no new
dependency, no version/changelog bump.

## Risks to check in review

- A leftover raw token must never reach the LLM for the three KNOWN vars —
  they substitute to `''` when absent (keyword strategies, consolidated batches).
- The consolidated path (`:768`) has no single item config; variables must
  resolve to empty there, not fatal.
- Suppressing the rider must be keyed off the **template actually using a
  variable**, not off source mode — otherwise a social strategy on a
  variable-free template loses its context entirely.

## Verification
- `php vendor/bin/phpunit` — baseline **524 tests / 1 error + 3 failures**, all
  pre-existing, none in scope.
- New unit tests: substitution (each var, whitespace tolerance, repeated var,
  unknown token untouched, empty when absent), `uses_source_vars`, and a
  **wiring test** through the real `build_prompt()` proving the template text is
  substituted and the rider is suppressed — mutation-checked, since the last
  task proved helper-only tests let a requirement be deleted silently.
- `php tests/standalone/run.php` → 88/88.
- Frontend: `cd app && node node_modules/typescript/bin/tsc --noEmit` (baseline
  59, none in scope) and `node node_modules/vite/bin/vite.js build --config
  vite.config.wp.ts` (plain `vite build` uses the WRONG config and fails).
