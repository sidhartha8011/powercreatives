# Plan — Strategy item "Written" status (blue, pre-publish); "Completed" = published

## Goal (user-confirmed, 2026-07-28)
A generated-but-not-yet-published item is currently marked `completed` (green) and can
flip the whole strategy to Completed/green — misleading. New semantics:
- **`written`** (BLUE) = article generated, NOT yet published. Draft-mode / no-site items
  stay `written` forever (nothing publishes them).
- **`completed`** (GREEN) = actually published. The ONLY thing that sets `completed`.
- Approval flow: approved → `written`, then `completed` on publish (consistent).
- Progress count: only `completed` (published) items count toward `completedItems`; a
  strategy of all-written items stays `In Progress`, never green.

DB-safe: `status varchar(50)` (schema.php:437), no ENUM, no migration needed.

## Decisive facts (all verified against code this session)
- Generation sets `completed` BEFORE publish and ignores the publish result:
  per-item `service.php:1580` (`maybe_auto_publish` at :1629); consolidated `service.php:933`
  (publish at :953); approval `service.php:3972` via `advance_strategy_item_from_in_review`
  (publish at :3982).
- `maybe_auto_publish()` returns: `null` = publish not applicable; `['success'=>true]` =
  published; `['success'=>false]` = attempted+failed. Callers CAN distinguish.
- `publish_item()` (`service.php:4756`) guard at :4768 requires `status==='completed'`; it
  does NOT write item status after publishing — that's the new flip point.
- `recompute_counters()` (`service.php:3992`) tallies only `completed`/`error`; sets strategy
  `completed` when `completed>=total`; fires `finalize_on_completion` (interlinks + automation).
- Frontend status map: `index.tsx:186-193` + mirror `ScheduleView.tsx:50-56`, fallback to
  `pending`. Publish gates: `index.tsx:810` (bulk), `:1644` (manual) — both on `==='completed'`.
- Color tokens (design-tokens.ts): blue is `statusColors.published` (`#007bff` text/`#e7f5ff` bg)
  and `colors.primary`/`primaryLight`. No raw hex allowed (CLAUDE.md HARD RULE).

## Backend edits (`includes/modules/strategy/service.php` + `class-pcm-db.php`)

1. **Per-item generation** `service.php:1580-1586`: set `status => 'written'` (not `completed`).
   AFTER `maybe_auto_publish` (:1629): if `$publish !== null && !empty($publish['success'])`,
   flip the item to `completed` via `PCM_DB::update_strategy_item()` then `recompute_counters`.
   (Keep the claim-guard `complete_strategy_item_if_generating` for the generating→written step;
   the written→completed step is a plain update since no concurrent generator can own it.)

2. **Consolidated generation** `service.php:933-954`: same — set all `pending_ids` to `written`
   (:934); after publish (:953), if success, flip all to `completed` + recompute.

3. **Approval advance** — `advance_strategy_item_from_in_review()` in `class-pcm-db.php`: change
   SET `status='completed'` → SET `status='written'`. Then in `advance_item_on_approval()`
   (`service.php:3972-3984`) after `maybe_auto_publish` (:3982): if success, flip item to
   `completed` + recompute. (Approval with no site → stays `written`, correct.)

4. **Manual publish** `service.php:4768` guard: accept `completed` OR `written`
   (`in_array($status, ['written','completed'], true)`). After successful `publish_to_site`
   (:4821): `PCM_DB::update_strategy_item($item_id, ['status'=>'completed'])` + `recompute_counters`.
   (Already-`completed` re-publish path at :4797 unchanged — idempotent.)

5. **`recompute_counters()`** `service.php:3992-4008`: add `elseif ($it->status === 'written')`
   counted toward a new `$written` var; do NOT count toward `$completed`; include `$written` in
   the `in_progress` test (`($completed + $failed + $written) > 0`). Strategy only goes
   `completed` when published-items count reaches total — matches "only published counts".

6. **`reset_item_to_pending()`** `service.php:5115`: add `'written'` to the allowed reset set
   `['completed','error']` → `['completed','written','error']`.

NOT changed: `get_next_pending_item` (`pending`-only), wedge/reclaim sweeps (`generating`-only),
`maybe_inject_interlinks` filter (intentionally `completed`-only — don't interlink unpublished).

## Frontend edits (`app/src/modules/Strategies/`)

7. **`index.tsx:186-193`** StatusBadge config — add:
   `written: { icon: <FileText className="w-3 h-3" />, label: 'Written', color: statusColors.published.text, bg: statusColors.published.bg }`
   (`FileText` already imported at `index.tsx:16`; blue via the `published` token — no new token).
8. **`ScheduleView.tsx:50-56`** — mirror the same `written` entry (icons: confirm import there).
9. **`index.tsx:810`** (bulk-publish) + **`index.tsx:1644`** (manual Publish button): gate on
   `item.status === 'written'` (the unpublished-generated state) instead of `'completed'`.

## Tests (`tests/unit/Strategy*Test.php`)
Update post-generation-no-publish assertions `completed` → `written`; keep post-publish as
`completed`. Primary: `StrategyAutoPublishTest` (draft/no-site cases → `written`; success →
`completed`), `StrategyLifecycleTest`, `StrategyOpsTest`, `StrategyConsolidatedParentLinkTest`,
`StrategyItemOverridesTest`. Mock `advance_strategy_item_from_in_review` + completion mocks
updated to the new transitions.

## Verification
- `php -l` on every edited PHP file.
- `cd app && npm run check` (tsc) — zero new errors vs baseline (59).
- `cd app && npm run build`.
- Logic trace: draft strategy → generate → item `written`, strategy `in_progress`, 0/Y;
  publish-item → `completed`, counts tick; auto-publish success → `completed` directly.
- ⚠️ PHPUnit: only runnable on a box with dev deps (`composer test`); this working copy has
  runtime-only vendor/. Update tests anyway; flag for the owner to run.

## route: driver (cross-cutting state-machine change, judgment on semantics; backend coherence)
