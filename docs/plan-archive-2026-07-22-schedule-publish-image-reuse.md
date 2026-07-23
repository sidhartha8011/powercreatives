# Plan — Strategies module: schedule precision, posting parity, post-image reuse

Task (2026-07-22): "Strategies module fully done — proper/easy schedule/cadence,
posting works, posts can re-use the image in the social media post for the blog."

Tier: T3 (GLM-5.2 via api.z.ai endpoint). The three sub-problems are small,
interdependent PHP+TS edits in the strategy module — solved least-to-most.

## Diagnosis (evidence-grounded, from this session's reads)

### 1. Schedule cadence — the gap is PRECISION, not the UI
- The cadence UI exists and works: `RecurrenceEditor.tsx` (326 lines, full
  interval/unit/byDays/ends editor), wired at `index.tsx:444-451` →
  `controller.php:446-460` → `service.php:4491 reschedule_pending_items()`.
  The recurrence engine `calculate_recurrence_dates()` (`service.php:540-651`)
  preserves time-of-day across DST. Per-item date edit works (`index.tsx:458-461`).
- **THE GAP:** `maybe_auto_publish()` (`service.php:2584`) calls
  `publish_to_site($site, $article, $user_id)` with **NO options** (line 2622).
  The MANUAL path (`publish_item()`, `service.php:4375-4385`) builds
  `$publish_options = ['tags'=>…, 'schedule_date'=>$item->scheduledDate]`.
  So: a scheduled item, once its daily-cron due-date arrives, publishes
  IMMEDIATELY rather than at the set time-of-day, and with no tags.
  The signature `maybe_auto_publish(object $strategy, ?object $article, int $user_id)`
  doesn't even receive the item/`scheduledDate`.
- Call sites: `service.php:911` (consolidated — no single item) and
  `service.php:1540` (per-item — `$item` IS in scope). The third call at
  `service.php:3697` is the approval-completion path (`$strategy`+`$article` only).

### 2. Posting to sites — works, but the auto path is asymmetric
- `publish_to_site()` (`sites/service.php:495-603`) is complete: future-date →
  `status:'future'`, featured-image sideload, in-content media sideload, Yoast meta,
  tags/category. Idempotent on the manual path.
- **THE GAP:** same as #1 — the auto path passes no options (no tags,
  no schedule_date). Fixing #1 fixes #2.

### 3. Post-image reuse — feature is COMPLETELY DORMANT
- Backend is fully built: `config.featuredImages` sanitized at
  `controller.php:584-585`; `social_source_image()` (`service.php:2741-2751`)
  reads `item.config.sourceImage`; wired into article creation at
  `service.php:1426-1429` (social image ?? AI-generated image, both gated by
  `featured_images_enabled()`). On publish, `push_featured_image()`
  (`sites/service.php:787-852`) sideloads it to the remote site.
- **GAP A (the big one):** there is **NO frontend toggle** for `featuredImages`
  ANYWHERE — `grep featuredImages app/src/` → zero hits. The feature defaults OFF
  (`service.php:2700`) and there's no way to turn it on. So no strategy ever
  reuses the social post's image.
- **GAP B:** `social_source_image()` gates on `empty($item_cfg['social'])`
  (`service.php:2743`). RSS-sourced items set `sourceImage` (`service.php:2102`)
  and `social=true` ONLY when `$entry['social']` was set (`service.php:2097-2098`).
  Free-platform RSS feeds (instagram/tiktok/x via Apify) DO set `social=true`
  on create (`service.php:381-386`), but plain-RSS items with an image don't —
  so they fall through to AI generation. This is the correct gate for the
  *reuse-the-post-image* feature (an RSS article's image isn't "the post's
  image"), so Gap B is **by design** — leave it.

## Decisions (ladder-climbed)

- **Gap B is out of scope** — the `social` flag is the right gate; an RSS
  article's image isn't "the social media post's image". Don't widen it.
- **schedule_date on the auto path:** `maybe_auto_publish()` needs the item.
  Least-invasive: add an optional `?object $item` param (default null) so the
  two call sites that lack an item (consolidated line 911, approval line 3697)
  don't break. The per-item call site (line 1540) passes `$item`.
- **tags on the auto path:** forward `$item->keyword` as a tag, same as the
  manual path — but only when `$item` is available (consolidated has no single
  keyword, so it stays tagless, which is fine).
- **featuredImages frontend toggle:** follow the EXACT `handleSiteChange` /
  `handleApprovalChange` pattern — a small inline control on the strategy row
  that does a partial config merge. Backend already accepts it (controller:584).
  No backend change needed. Default stays OFF (don't surprise existing
  strategies with images they didn't ask for).

## DO NOT TOUCH
- `calculate_recurrence_dates()` / `RecurrenceEditor.tsx` — the cadence engine
  and UI work; this task is about the auto-publish path honoring it.
- `social_source_image()`'s `social` gate — correct by design (Gap B).
- `publish_to_site()` signature — already accepts `$options`; we just pass it.
- `featured_images_enabled()` default OFF — leave it; opt-in is safer.

## Steps (least-to-most, sequential)

| # | Step | route | files | verify |
|---|---|---|---|---|
| 1 | `maybe_auto_publish()`: add optional `?object $item = null` 4th param. When `$item` is present and publishingMode is 'schedule' with a future `scheduledDate`, forward `['schedule_date'=>$item->scheduledDate]`; always forward `['tags'=>[$item->keyword]]` when `$item` is present. Merge both into one `$options` array passed to `publish_to_site($site,$article,$user_id,$options)`. Existing call sites with no `$item` (lines 911, 3697) pass nothing → identical behavior (no options). | driver | `includes/modules/strategy/service.php` (~2584-2627) | `php -l`; read back the diff |
| 2 | Update the per-item call site (line 1540) to pass `$item` as the 4th arg. Leave lines 911 + 3697 unchanged (no item available). | driver | `includes/modules/strategy/service.php:1540` | `php -l` |
| 3 | Frontend: add a "Reuse social image" checkbox on the strategy row (near the publishing-mode/site controls, line ~955-974), gated to social/RSS-sourced strategies (`isSocial \|\| isRss`), following the `handleSiteChange` partial-config-merge pattern → `config:{featuredImages:boolean}`. Show current state from `config.featuredImages`. | driver | `app/src/modules/Strategies/index.tsx` (~417 pattern, ~955 UI) | `cd app && node node_modules/typescript/bin/tsc --noEmit` (0 new vs baseline) |
| 4 | Done gate — `spec-verifier` with this plan excerpt + the diff; address P0/P1; append SESSION_LOG entry. | driver | `.claude/SESSION_LOG.md` | verifier APPROVED |

Routing: 3 driver edits (judgment on signatures + UI placement) + 1 driver gate.
No subagents — single PHP module + single TSX file, all mechanical once the
signatures are decided (steps 1-2).

## Verification (acceptance block)
```bash
cd "/Users/sidharthaparasramka/Desktop/Claude code/Landing page -demo/powerplatform/powerplatform"
php -l includes/modules/strategy/service.php
cd app && node node_modules/typescript/bin/tsc --noEmit
```
- `php -l` clean on the service.
- `tsc --noEmit` — 0 new errors vs the pre-existing baseline.
- Manual trace: a schedule-mode item with a future `scheduledDate` now forwards
  `schedule_date` on the auto path (read the patched `maybe_auto_publish`).
- Manual trace: the frontend checkbox sends `config:{featuredImages:true}`,
  controller sanitizes it, `social_source_image()` resolves it at generation.

## Deliberately NOT in this plan
- A start-date picker on the create dialog (existing comment at
  `service.php:174-176` notes it's not there; the recurrence editor handles
  start-date on existing strategies). Separate UX decision.
- Gap B (RSS image reuse) — by design, out of scope.
- Making `featuredImages` default ON — opt-in is safer for existing strategies.
- A second trigger for `reclaim_wedged_items()` — real gap, but separate design
  decision (the prior plan already called it out).
