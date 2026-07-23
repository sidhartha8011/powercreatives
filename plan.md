# Plan — Strategy generation: reduce failures + stop stuck "generating" items

Task (2026-07-22): "the generation fails a lot of times for some errors in the
strategy; also sometimes the generations keep on going without getting completed."

Tier: T3 (GLM-5.2 via api.anthropic.com endpoint). Three interdependent PHP
fixes in the strategy generation pipeline. Previous plan archived to
`docs/plan-archive-2026-07-22-schedule-publish-image-reuse.md` (shipped in a56d857).

## Problem reflection (from code reading + session log)

**Two symptoms:**
1. "generation fails a lot" — LLM refusals/empty replies and exceptions surface
   as `status='error'`. Recent sessions already fixed refusal surfacing +
   JSON salvage. Remaining failure-magnifier: an item that *deterministically*
   fails (model always refuses/empties on that keyword) is retried **forever**
   every 90 min, each retry burning LLM spend, and from the owner's view it is
   a pile of red rows that never clears + money spent.
2. "generations keep on going without getting completed" — an item claimed
   (`status='generating'`) whose worker process is **killed** (PHP
   `max_execution_time` / FPM `request_terminate_timeout` / OOM / nginx
   `fastcgi_read_timeout`) **never reaches the catch block**, so it stays
   `generating` until the 90-min global sweep. That sweep runs **only** from
   `run_keepalive_chain()` — there is **no independent cron**. If the keepalive
   chain is dead (`DISABLE_WP_CRON` + no traffic, blocked loopback), the item
   is wedged **permanently**.

## Root causes (proven, file:line)

- **W1** — No `register_shutdown_function` to flip `generating`→`error` when
  the process is killed mid-generation. The `\Throwable` catch at
  service.php:1562 is the only resolution path; a fatal/OOM/timeout that
  terminates the worker is not a catchable exception and never reaches it.
  Recovery depends entirely on a traffic-driven keepalive chain. (grep:
  `register_shutdown_function` → 0 hits in strategy/.)
- **W2** — `reclaim_wedged_items()` (service.php:5373) has exactly ONE caller:
  `run_keepalive_chain()` (service.php:5301). No `wp_schedule_event` references
  it. (grep: power-creatives.php:163/170/188 schedule only approvals /
  strategy-scan / sites-health; service.php:5587 schedules the RSS scan.)
- **W3** — No max-retry cap. `reclaim_stale_generating()`
  (class-pcm-db.php:1405) blindly flips `generating`→`pending` with no attempt
  counter; a deterministically-failing item loops every 90 min forever.
  Documented as a known limit at service.php:5364-5371. `strategy_items.config`
  (JSON) already exists → an attempt counter is feasible **without a schema
  change**.

## Candidate approaches (ranked)

- **A — independent cron + retry cap + shutdown safety net** (CHOSEN).
  Cheapest, hits all three root causes with no schema change (attempt count in
  `config` JSON) and no new dependency. Reuses the existing `reclaim_*` +
  `complete_strategy_item_if_generating` plumbing.
- B — ownership-token per claim (needs schema change, adds a column) — heavier,
  rejected for "minimal diff".
- C — true async job queue (ActionScheduler / custom) — architectural change,
  rejected.

## DO NOT TOUCH
- The 90-min global / 10-min per-strategy cutoffs (deliberate; documented at
  service.php:5378-5393).
- The `\Throwable` catch as the primary error path (it carries the real
  exception message; the shutdown net is a fallback for uncatchable kills only).
- Refusal/empty-reply surfacing + `stream_request` shape (already fixed /
  documented last session).
- The synchronous generate endpoint itself (async-ifying is architectural —
  rejected).
- `pcm_sites_health_interval` schedule definition (reuse it; don't redefine).
- `social_source_image()` / template variables / seeded templates (out of scope).

## Steps (least-to-most, sequential)

| # | Step | route | files | verify | status |
|---|------|-------|-------|--------|--------|
| 1 | **Independent cron for wedge reclaim (W2).** Register a recurring `pcm_strategy_wedge_reclaim` event (every 15 min) alongside the existing 3 events in `power-creatives.php` (`wp_schedule_event`, guarded by `wp_next_scheduled`, same shape as the approvals/strategy-scan blocks). Reuse the existing `pcm_sites_health_interval` if it is a close-enough cadence, else register a dedicated `pcm_wedge_interval` (~15 min) via the `cron_schedules` filter — pick whichever already exists to minimize diff. Register the callback `add_action('pcm_strategy_wedge_reclaim', ['PCM_Strategy_Service','reclaim_wedged_items'])` next to the existing strategy action registrations (service.php:5567-5571). `reclaim_wedged_items()` is already `public static` and idempotent. | driver | `power-creatives.php`, `includes/modules/strategy/service.php` | `php -l` both; grep confirms `wp_schedule_event(...'pcm_strategy_wedge_reclaim')` + `add_action('pcm_strategy_wedge_reclaim'...` | todo |
| 2 | **Shutdown safety net (W1).** In `generate_next_item()`, right AFTER an item is marked `generating` (both the claim path service.php:1277 and the targeted-retry path 1297) and BEFORE the `try`, `register_shutdown_function` a closure capturing `$item->id` by value. The closure: if `error_get_last()` is non-null (a fatal/error/OOM is terminating the process), mark the item `status='error'`, `errorMessage='generation interrupted (fatal/timeout)'`, gated through a `status='generating'` compare (use `PCM_DB::complete_strategy_item_if_generating` shape) so a reclaim that already moved it to `pending` is not clobbered. Use `unregister_shutdown_function` pattern: set a flag at the end of the normal `try`/`catch` so a clean exit does NOT fire the net. This covers `E_ERROR`/OOM/`max_execution_time` that never reach `\Throwable`. | driver | `includes/modules/strategy/service.php` (~1277-1300) | `php -l`; new unit test: after claim a shutdown fn is registered; on a simulated fatal it flips a `generating` item to `error` and does NOT touch a `pending` one. Mutation: registration removed → RED. | todo |
| 3 | **Retry cap via `config.attempts` (W3).** Add `PCM_Strategy_Service::record_item_attempt(int $item_id, int $user_id): int` — reads item `config` JSON, increments `attempts`, writes back, returns the new count. In `reclaim_wedged_items()`, the reclaim path (`PCM_DB::reclaim_stale_generating`) is per-strategy; wrap it so each reclaimed item is bumped: if `attempts >= MAX` (default **3**, option-tunable via `pcm_strategy_max_attempts`), set `status='error'` + `errorMessage='generation failed after N attempts'` for that item instead of flipping to `pending`, and do NOT re-arm for it. Otherwise increment + reclaim as today. A manual UI Retry resets `attempts` to 0 (owner explicitly asked). MAX check happens at reclaim time so the existing `\Throwable` catch (which already writes `error`) is untouched and doesn't double-count. | driver | `includes/modules/strategy/service.php` (~5373-5419), maybe `includes/core/db/class-pcm-db.php` (helper to list stale item ids) | `php -l`; new unit test: an item reclaimed `MAX+1` times lands in `error`, not `pending`; attempts counter persists across reclaims. Mutation: cap off → item stays `pending`. Existing `StrategySourceVarsTest` green. | todo |
| 4 | **Tests.** New `tests/unit/StrategyWedgeReclaimTest.php` (Brain Monkey + wp_mock harness, mirror existing strategy tests): (a) cron action + event registered; (b) shutdown net flips generating→error on fatal, no-op on clean exit + no-op on already-pending; (c) retry cap fails item after MAX, resets on manual retry. | driver | `tests/unit/StrategyWedgeReclaimTest.php` | `composer test`; new tests pass; pre-existing baseline (1 error + 3 failures) unchanged. | todo |
| 5 | **Verify + log.** `composer test`, `php -l` on touched files, `cd app && npm run check` (no TS changes → baseline unchanged). Dispatch `spec-verifier` with plan excerpt + diff; address P0/P1 (max 3 rounds). Append `.claude/SESSION_LOG.md`. Do NOT commit. | driver | `.claude/SESSION_LOG.md` | all green + verifier APPROVED. | todo |

## Verification (acceptance block)
```bash
cd "/Users/sidharthaparasramka/Desktop/Claude code/Landing page -demo/powerplatform/powerplatform"
php -l power-creatives.php
php -l includes/modules/strategy/service.php
composer test
cd app && node node_modules/typescript/bin/tsc --noEmit   # baseline, no TS changes
```
- `php -l` clean on every touched file.
- `composer test`: new tests green; pre-existing baseline (1 err + 3 fail) unchanged.
- grep evidence: a `pcm_strategy_wedge_reclaim` scheduled event + action callback exist; a `register_shutdown_function` fires after claim; an `attempts` counter gates reclaim.

## Out of scope (explicit)
- Cutoff tuning (90-min/10-min are deliberate).
- Async/queue rewrite of the generate endpoint.
- Refusal surfacing + `stream_request` (done last session).
- Seeded-template migration (idempotent seeder; owner's existing templates not auto-updated).
