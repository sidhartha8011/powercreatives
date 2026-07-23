/task Round 2 — fix the defects an independent verifier found in YOUR round-1 work on these three requirements:

1. "in the keywords new strategy popup - Proper and easy to understand schedule / cadance for posting"
2. "strategies with source social media Posts can re-use the image in the social media post for the post on the blog"
3. "the emoji's should be dismissed while we write the article using the social media post no emojis in the articles"

Your round-1 code is already in the working tree. Fix the findings below ON TOP of it. Do not start over.

---
## ALREADY FIXED BY THE REVIEWER — do not redo, do not revert

- `strip_emoji()` character class widened to `\x{1F000}-\x{1FAFF}` + `\x{2B00}-\x{2BFF}` (⭐ 🆕 🆚 🈚 were surviving).
- `strip_emoji()` no longer wipes the article on malformed UTF-8 (`preg_replace` with `/u` returns NULL; the
  `(string)` cast turned that into `''`). Now returns `$text` unchanged. Test added.
- `maybe_enrich_social_post()` idempotency guard: reverted off `sourceImage`, now keyed on a new
  `$item_cfg['socialEnriched']` marker set whenever Apify answers. Regression test added
  (`test_a_create_time_og_image_must_not_block_the_caption_fetch`).

Baselines to hold: `php vendor/bin/phpunit` → **1 error + 3 failures, pre-existing, none in scope**
(count is now 516). `php tests/standalone/run.php` → 88/88. tsc baseline 59, **0 in CreateStrategyDialog.tsx**.
Build: `cd app && node node_modules/vite/bin/vite.js build --config vite.config.wp.ts` (plain `vite build`
uses the WRONG config and fails — that is not a real failure).

---
## REQ 1 — the new summary confidently states things the backend will NOT do

**F1 (P2).** The box says "Articles **publish** …" but `publishing` defaults to `'draft'`
(`CreateStrategyDialog.tsx:286` `useState('draft')`) and `service.php:2521` hard-returns:
`if (is_array($publishing_gate) && ($publishing_gate['publishing'] ?? '') === 'draft') { return null; }`
So nothing is published. Say "Articles are written …", and only append "and published to <site>" when
`publishing === 'auto' && siteId`. Same for the rss/social box (`:801` "New posts publish up to N per week").

**F2 (P2).** `:771` `<> Your {articleCount} keyword{...} publish one per slot.</>` is wrong when
`structure === 'consolidated'` — `service.php:1208-1209` routes to `generate_consolidated_batch()`, which
produces **ONE shared article** for all keywords. `:764` ("N articles generate on demand") is wrong the same
way. The Content-per-Keyword selector is in section ④, below the summary, so the user can flip it and the
summary keeps lying.

**F3 (P2).** The RecurrenceEditor has its own "Ends" control (`ends:{type:'after',count:N}`), passed through
untouched at `:433`, but `describeDurationSuffix` only handles the separate Duration field and returns `''`
for `'ongoing'` (`:236`). Set Ends → "After 13 occurrences" with Duration = Ongoing and the summary claims
an unbounded schedule while `calculate_recurrence_dates` only dates the first 13 items
(`service.php:185-190`). Two end-controls, one invisible in the summary.

**F4 (P3).** 3 keywords + Duration "Article limit" (default 10) renders:
"…, until **10 articles** are published. Your **3 keywords** publish one per slot." Only 3 can exist.

**F5 (P3).** "starting <date>" is not the first publish date when weekday chips are set —
`service.php:588-598` picks the first selected weekday ≥ the start weekday. Start Friday + byDays `[Mon]`
→ first article is the following Monday, summary says "starting Fri".

**F6 (P3).** The rss/social summary ignores Duration, but `service.php:2003-2005` does cap the watcher on
`duration.mode === 'limit'`.

**F7 (P3).** You DELETED the rss/social trigger disclosure (the disabled "New source item" Segmented + the
line "RSS strategies trigger on new feed items.") and replaced it with nothing. Nothing in the dialog now
tells the user what fires an rss/social strategy. Restore that information in the new layout.

## REQ 2 — the image capture does not actually fire on the configured actors

**F9 (P2).** Probe output against your real `apify_map_items`:
```
IG empty imageUrl + images[] present -> ''   (?? does not fall through on '')
IG displayUrl only                   -> ''   (apify~instagram-scraper's main image field is not read)
TikTok videoMeta.coverUrl (nested)   -> ''   (clockworks~tiktok-scraper's real cover field is not read)
FB media[0].photo_image.uri          -> ''   (apify~facebook-posts-scraper's real shape is not read)
```
You read `imageUrl` / `coverUrl` / `fullPicture` — fields the same switch does not use for anything else
(it correctly uses the real `shortCode`, `caption`, `timestamp`, `webVideoUrl`, `createTimeISO`). Read the
actual actor output shapes (the request builders in `apify_request()` name the actors) and add the real
fields, keeping defensive fallbacks.

**F13 (P3), same root cause.** `??` does not fall through on `''` —
`class-pcm-social-source.php:414` and `:445`. Use explicit `!== ''` checks so an actor emitting
`imageUrl: ""` still reaches the `first_url_in($item['images'])` fallback.

**F10 (P2).** `class-pcm-social-source.php:423`
`$image = (string)($item['coverUrl'] ?? $item['coverImageUrl'] ?? $item['videoUrl'] ?? '');`
falls back to an **MP4**. It reaches `push_featured_image()`, whose `ext_from_mime('video/mp4')` returns
`'jpg'`, so a video is uploaded to the client's media library as `slug.jpg` and set as `featured_media`.
Drop the video fallback (or accept only image content types).

**F11 (P2).** `service.php:1364`
`'featuredImage' => self::social_source_image($item_cfg) ?? self::maybe_generate_featured_image(...)`
short-circuits past the opt-in gate, which lives INSIDE the right-hand call (`service.php:2618`
`return null; // strategy didn't opt in`). A user who unchecks "Featured images" on a Source=Social
strategy still gets one sideloaded and published. Respect the opt-out.

## REQ 3 — still under- AND over-strips, and misses the published meta

**F15 (P2).** These 13 standard emoji still reach the article (base glyph survives, only the VS-16 is removed):
```
⌚ ⌛ ⏰ ⏳ ▶️ ◀️ ℹ️ ‼️ ↔️ Ⓜ️ ㊙️ ▪️ 〰️
```
Missing: U+2190-U+21FF, U+231A-U+23FA, U+24C2, U+25AA-U+25FE, U+2139, U+203C, U+3030, U+3297/3299.
Also the comment claiming `\x{2049}-\x{204A}` is "exclamation/question mark emoji" is wrong: it covers ⁉
but NOT ‼ (U+203C), and U+204A is ⁊ TIRONIAN SIGN ET — a real Gaelic letter, not an emoji.

**F16 (P2) — in tension with F15, resolve BOTH deliberately.** The current U+2600-U+27BF range over-strips
legitimate typography:
```
'Supported ✓ / Not ✗'  ->  'Supported / Not'
```
It also swallows ♠ ♥ ♦ ♣ ♪ ♫ ✂ ✈ ✉ ✏. A "✓ included / ✗ not included" comparison table is a very common
SEO-article device and silently loses its markers. Widen coverage per F15 while carving out the characters
that are ordinary text in an article. State your rule in a comment and pin it with tests both ways.

**F17 (P2).** `service.php:1360-1361` — `metaTitle` and `metaDescription` never go through `strip_emoji`,
and `includes/modules/sites/service.php:539-542` pushes them to `_yoast_wpseo_title` / `_yoast_wpseo_metadesc`
on the client's site. Emoji from the source post land in the SERP snippet. REQ 3 is not met for the meta.

**F18 (P2).** `service.php:3195` `preg_replace('/[ \t]{2,}/', ' ', $cleaned)` runs over the whole HTML
unconditionally, even when no emoji was removed:
```
"<pre><code>function f() {\n    return 1;\n}</code></pre>"
-> "<pre><code>function f() {\n return 1;\n}</code></pre>"
```
Code indentation is destroyed. Restrict the collapse to the site of an actual removal, or skip `<pre>`.

**F19 (P3).** `generate_consolidated_batch()` returns at `service.php:1208-1209` before `$item_cfg` is read,
so a Consolidated + Source=Social strategy gets neither the emoji strip nor the featured-image reuse.

## Tests — the biggest gap

**F21 (P2).** Your tests cover the pure helpers but NOT ONE wiring point. Each of these mutations deletes a
requirement outright and the whole suite stays green at baseline:
```
service.php:1364  drop `self::social_source_image($item_cfg) ??`   -> baseline, no failure
service.php:1332+1342  remove both strip_emoji() calls             -> baseline, no failure
service.php:383   $source_image = '';  (create-path capture)       -> baseline, no failure
class-pcm-social-source.php:253  $image = '';  (og:image scrape)   -> OK (52 tests)
```
Add call-site tests: `generate_next_item()` writes `featuredImage` from `sourceImage` AND strips emoji from
the persisted article; `post_context()` scrapes og:image. Then MUTATION-CHECK each new test — re-apply the
mutation above and confirm your test goes red. Report the before/after output.

---
## Constraints (unchanged from round 1)
- Dirty tree with another task's in-flight work — do NOT revert, stash, checkout or commit anything.
- Plan to `.claude/glm-plan-r2.md` if the guard allows; if `.claude/` is write-blocked, just say so.
- Lane: Keywords / Strategies / Writer only. No SEO optimizer, no teachers, no `SectionModal.tsx`.
- No new dependencies.
