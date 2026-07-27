# Plan — RSS/Social "On a schedule" drip-publish

Goal (user-confirmed): RSS/Social get the same scheduling UI as Keywords. "On a schedule":
new feed items/posts are still pulled automatically, but each generated article is HELD and
published on the recurrence. The recurrence REPLACES "posts per week".

## Steps
1. Frontend CreateStrategyDialog.tsx: state `socialScheduled`. RSS/Social Schedule branch gets a
   "When to publish" segmented [As posts arrive | On a schedule]; scheduled → RecurrenceEditor +
   Start date + summary (hide posts-per-week); immediate → posts-per-week. Submit: publishingMode
   = socialScheduled ? 'schedule' : (publishing==='auto'?'publish':'draft'); scheduleConfig already
   emitted; trigger stays 'new_source_item'.
2. PCM_DB::count_scheduled_strategy_items($id) — items with non-null scheduledDate (tail anchor).
3. service.php scan_rss_strategy: when publishingMode==='schedule' + scheduleConfig — pop full
   queue (bypass perWeek; keep 'limit' cap), assign each new item scheduledDate from
   calculate_recurrence_dates(already+toCreate, cfg, startDate) tail (null past ends → stays
   pending), and SKIP immediate generation. Else unchanged.
4. Tests: StrategyRssWatcherTest — scheduled assigns dates + no immediate gen; immediate unchanged.
5. Verify: tsc 59 + build; phpunit 4-known; live browser; spec-verifier gate.
