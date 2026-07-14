# Plan: Complete the Strategy module

Plan-only (frontier-sandwich handoff). Nothing implemented here. Supersedes the prior `plan.md`
(site-selector slice — DONE; recorded in the map + SESSION_LOG). Grounded in this session's audit of
the AutoPress "PowerContent" source + a survey of PC infrastructure. Companion doc:
`docs/roadmaps/strategy-remaining-work.md`.

## Where the module is now (DONE this session — do NOT rebuild)
Create-from-keywords → per-item AI generation into `pcm_articles`, with: selectable **model/provider**
(data-driven, fallback), **Generate All** (client loop) + **Retry** + retry-safe counters,
**auto-publish** (`publishingMode='publish'` + `config.siteId` → `PCM_Sites_Service::publish_to_site()`,
failure-isolated), always-visible **Target Site** selector, Writer **"View"** link. The create dialog
also persists `structure` / `hierarchyMode` / `interlinksConfig` / `scheduleConfig` / `approvalMode`
into `config` — but **nothing executes those yet**. That's what "complete the module" means: turn the
stored intent into behaviour, matching AutoPress's `strategyService.ts`.

## Load-bearing infra to REUSE (verified this session, with file:line)
- **Cron/async**: `power-creatives.php:150-152` schedules the daily `pcm_automation_check_pending_approvals`
  event → `PCM_Automation_Engine::run_pending_client_scan()` (`automations/service.php:887`). Async seam:
  `wp_schedule_single_event(time(), 'pcm_automation_run_action', …)` → `run_scheduled_action`
  (`automations/service.php:549-550, 985`). These are the exact templates for a strategy queue + scheduler.
- **Publish**: `PCM_Sites_Service::publish_to_site($site,$article,$uid)` — already wired via
  `maybe_auto_publish()` (`strategy/service.php`). `pcm_articles` has `siteId/publishedUrl/publishedPostId/publishedAt`.
- **Approvals**: `POST /approvals/sets` → `PCM_Approvals_Service::create_set` (`approvals/controller.php:65,104`,
  `edit_posts`); the `approvals.set_fully_approved` trigger already fires (`approvals/automations.php:81`).
  Frontend seam: `components/shared/SendToApprovalSetDialog.tsx` + `approvals.createSet`.
- **Schema**: `strategy_items` (`class-pcm-schema.php:~395`) has NO `scheduledDate` / `setId` column.
  Additive `dbDelta` + `PCM_DB_VERSION` bump (currently **1.36.0** → 1.37.0+) per new column.

---

## DECISIONS (RESOLVED by the user 2026-07-08 — locked)
1. **Cron granularity → DAILY.** Reuse the existing daily WP-cron cadence; a scheduled item publishes on
   its due *date*, not a precise time-of-day. No new cron interval. (`frequency` maps to a date spread;
   `scheduleConfig` time-of-day, if present, is ignored in v1.)
2. **Approvals → DONE THROUGH THE CUSTOM APPROVALS MODULE (not a strategy-internal workflow).** When
   `approvalMode != 'none'`, the strategy creates an **approval set** via the Approvals module
   (`PCM_Approvals_Service::create_set`) — the same custom approval cards / client-review flow that
   already exists — and the item just links to it (`setId`) and sits in a lightweight review state.
   Publishing is gated on the existing `approvals.set_fully_approved` trigger. Ship `internal` + `client`;
   `both` skipped in v1. Strategy does NOT reimplement any approval UI — it hands off to Approvals.
3. **Interlink → PHRASE-MATCH** (AutoPress's "Auto (Phrase)"). No new deps; reuse the SEO link-rewrite
   plumbing (`scan-links`/`replace`).
4. **Queue failure → SKIP-AND-CONTINUE** (mirrors the client "Generate All" loop): a failed item does not
   halt the background run; cap iterations, log failures; the item stays retryable via per-item Retry.
5. **Workflows module → DEFERRED entirely** (not built; nothing below needs it).
6. **AI topic suggestion → OUT of scope for completion** (net-new feature, separate request). Step 12
   stays `[deferred]`.

---

## Steps (least-to-most; each independently shippable)

### Phase A — Background queue (unlocks scheduling + robustness)
**Step 1 — Server-side "process all pending" runner.** Status: `[done]`

**DESIGN DEVIATION from the original wording above (documented, not silent):** during execution I found
this project's OWN mu-plugin `wp-content/mu-plugins/prevent-loopback-deadlock.php` (per
`.claude/CODEBASE_MAP.md`'s local-WP section) exists specifically because the PHP built-in dev server
deadlocks on self-HTTP loopback requests — meaning a design that relies on the frontend synchronously
POSTing `/process-all` then POLLING `strategy.get` on an interval, with wp-cron's OWN opportunistic
loopback dispatch as the actual driver, is fragile in exactly the environment this plugin is developed
and tested in (and WP-cron's default opportunistic/pseudo-cron dispatch is known-unreliable on many
shared-hosting targets too — why real deployments often switch to a system cron hitting wp-cron.php).
Rather than build a new synchronous route + a new frontend polling loop on top of that fragility, I
embedded the continuation directly in the EXISTING `generate_next_item()`: after every "next pending"
call (i.e. `$item_id === null` — the Generate / Generate-All flow, NOT a targeted Retry), if a pending
item remains, it self-schedules a `wp_schedule_single_event` continuation (deduped via
`wp_next_scheduled()` on the exact args) targeting a new `run_queue_tick()` wp-cron callback, which
itself calls `generate_next_item()` again (re-arming itself) until nothing is pending. **Net effect: the
EXISTING "Generate"/"Generate All" buttons transparently gain closed-tab resilience — zero frontend
changes, zero new REST route.** This still fully satisfies the stated goal ("Generate All is a client
loop that dies if the tab closes") with a smaller, lower-risk diff (one file: `strategy/service.php`)
than the plan originally envisioned. Reused `run_pending_client_scan`'s exact architectural idiom (a
wp-cron event handler in `service.php`, hook registered at file-load behind
`if (function_exists('add_action'))`, mirroring `automations/service.php:985` verbatim) rather than
inventing a new pattern.
- Scoping decision made during implementation: continuation-scheduling only fires on the `$item_id===null`
  ("next pending") path, NOT on a targeted Retry (`$item_id` set) — a user clicking Retry on one failed
  item shouldn't be surprised by unrelated pending items silently generating in the background.
- Decision 4 (skip-and-continue) applies: a thrown generation failure is recorded on the item by
  `generate_next_item()`'s own catch block (unchanged), then the continuation is STILL scheduled before
  re-throwing — a failed item never halts the queue. `run_queue_tick()` additionally swallows (catches +
  `error_log`s) any exception from `generate_next_item()` so a background wp-cron failure never surfaces
  as a fatal on a future page load.
- Files touched: `includes/modules/strategy/service.php` only — `maybe_schedule_queue_continuation()`
  (private), `run_queue_tick()` (public, the wp-cron callback), the two call sites inside
  `generate_next_item()`, and the file-load `add_action('pcm_strategy_process_queue', …, 10, 2)` hook
  registration. No controller/route/frontend/trpc changes.
- Verified: `php -l` clean. Extended `tests/unit/StrategyAutoPublishTest.php` +8 tests (continuation
  scheduled when items remain; NOT scheduled when nothing remains; NOT scheduled on a targeted retry even
  with other items pending; deduped when `wp_next_scheduled()` already true; still scheduled after a
  failed generation — skip-and-continue; `run_queue_tick` generates+re-arms; `run_queue_tick` swallows a
  thrown failure without propagating; `run_queue_tick` no-ops when the strategy was deleted since
  scheduling) → **24/24 isolated, 69 assertions**. Full suite **142/142 (470 assertions, 0 errors)** — same
  1 pre-existing unrelated `PlatformRoleInvariantTest` failure. Test-infra note: PHPUnit's
  `@runTestsInSeparateProcesses` misreports `error_log()`'s default STDERR write as a child-process fatal
  (a known process-isolation gotcha, not a bug in the code) — fixed by `ini_set('error_log', <tmp file>)`
  in the test's fakes setup (test-run-only redirect, zero production code change). No frontend/tsc/build
  impact (backend-only). Not committed.
- **Done-gate: APPROVED.** Dispatched `spec-verifier` against both steps combined (10 numbered checks:
  ownership boundaries, the `$item_id===null` gating, dedup, hook registration, the module-loader eager-
  load claim, cron-tick re-entrancy/termination, non-negotiables). All 10 confirmed with file:line
  evidence, including independently re-running the isolated file (24/24, 69 assertions) and the full suite
  (142/142, 470 assertions, 0 errors, same 1 pre-existing unrelated failure) itself rather than trusting
  the claim. One P3/low noted: a narrow race window between an item's `pending`→selection and its
  synchronous `'generating'` status-write (a few lines in `generate_next_item`) — two overlapping wp-cron
  dispatches could theoretically both select the same item in that window. Not newly introduced (the same
  optimistic-write pattern already existed for the single "Generate" button before this diff) and matches
  the plan's own acknowledged wp-cron-reliability risk profile — no fix required, not re-dispatching a
  round 2 for it.

### Phase B — Scheduling execution
**Step 2 — Schema: `strategy_items.scheduledDate datetime NULL` + index.** Status: `[done]` — additive
`dbDelta` (nullable column + `idx_scheduledDate` index) on `strategy_items`; `PCM_DB_VERSION` 1.36.0 →
1.37.0 (`power-creatives.php`); documentation-only comment added to `class-pcm-activator.php` (no bespoke
`migrate_*`/gate needed — mirrors the established "purely additive" pattern used for v1.24/1.25/1.34-1.36).
**Verified against the REAL local WordPress runtime, not just unit-tested** (dbDelta is a genuine WP-core
function; this project's fast PHPUnit+WP_Mock suite deliberately doesn't boot real WP, so a fake-based
test can't meaningfully prove a schema migration works): loading `wp-load.php` on the local install
triggered `plugins_loaded` → `maybe_upgrade()` for real, which detected `1.36.0 < 1.37.0` and ran
`create_tables()` live — confirmed via `SHOW COLUMNS`: `scheduledDate` is `datetime`, `Null=YES`,
`Default=NULL`; confirmed via `SHOW INDEX`: `idx_scheduleddate` present. **Idempotency verified for real**:
called `PCM_Schema::create_tables()` a second time directly — no error, no duplicate columns/indexes.
`php -l` clean on all 3 touched files (`class-pcm-schema.php`, `power-creatives.php`,
`class-pcm-activator.php`).

**Step 3 — Compute the schedule at create time.** When `publishingMode='schedule'`, distribute items
over `scheduledDate` from `scheduleConfig.frequency` + `startDate` (port AutoPress `calculateSchedule`).
Files: `strategy/service.php` (`create_from_keywords`). Verify: pure-function unit test on the date
spread. Decision 1 (daily granularity). Status: `[done]` — new pure static
`PCM_Strategy_Service::calculate_schedule_dates(count, frequency, start_date): array` is a faithful port
of AutoPress's `calculateSchedule()`, including its "biweekly" label mapping to **+3 days**, not +14 (kept
verbatim, documented inline as intentional, not a transcription bug). `create_from_keywords()` calls it
right after `create_strategy_items()`, only when `publishingMode==='schedule'`, reading
`frequency`/`startDate` from `options.config.scheduleConfig` with safe fallbacks (`weekly` /
`current_time('mysql')`) when either is missing, then writes `scheduledDate` onto each item via the
existing `update_strategy_item()` — no new DB call shape. **Known frontend gap, explicitly flagged, out
of this step's scope**: `CreateStrategyDialog.tsx` has no `frequency` selector or `startDate` picker yet —
`frequency` is a hardcoded `'weekly'` default and `startDate` is always sent as `''`. Every "Schedule Mode"
strategy created today therefore takes the `weekly` + `start-now` fallback path through my backend code;
the code is correct and forward-compatible once a picker ships, but there is currently no way for a user
to pick daily/monthly/a future start date through the UI. Verified: `php -l` clean; extended
`tests/unit/StrategyAutoPublishTest.php` +11 tests — 8 pure-function cases (`all_once`, `daily`,
`every_other_day`, `weekly`, the biweekly-is-really-+3-days quirk, `monthly`, an unrecognized-frequency
fallback to weekly, and a `count=0` edge case) plus 3 `create_from_keywords()` integration cases
(schedule-mode writes `scheduledDate` across all items in position order; missing `scheduleConfig`
defaults to weekly+now; non-schedule `publishingMode` leaves `scheduledDate` untouched/unset on the
item entirely) → 35/35 isolated (83 assertions, up from 24/69); full suite 153/153 (484 assertions, up
from 142/470, same 1 pre-existing unrelated `PlatformRoleInvariantTest` failure, tracked separately).

**Step 4 — Cron scanner publishes due items.** New recurring event (or extend the daily one) →
`PCM_Strategy_Service::run_scheduled_scan()`: find items with `scheduledDate <= now` + `status` pending,
generate + publish (reuse Step 1's tick + `maybe_auto_publish`). Mirror `run_pending_client_scan()`
(dedupe, owner-scoped). Files: `strategy/service.php`, `power-creatives.php` (schedule the event).
Verify: unit test on the "due" query + scan logic. Consult: `architect-review`. Status: `[done]` — new
`PCM_DB::get_due_scheduled_strategies($now)` (`class-pcm-db.php`) returns distinct
`(strategyId, userId)` pairs with ≥1 pending item whose `scheduledDate <= $now` — a global, cross-user
query (unlike every other `strategy_items` method, which is scoped to a strategy already known to the
caller), matching `run_pending_client_scan()`'s shape. New `PCM_Strategy_Service::run_scheduled_scan()`
calls it and, for each due pair, calls the EXISTING `maybe_schedule_queue_continuation()` from Step 1 —
no new generation/publish path; this step is just a new trigger for the one Step 1 already built
(`maybe_schedule_queue_continuation → run_queue_tick → generate_next_item → maybe_auto_publish`).
Registered on its own daily event, `pcm_strategy_scheduled_scan` (`power-creatives.php`, mirroring the
existing `pcm_automation_check_pending_approvals` daily-scheduling idiom exactly); hook wired at file
load in `strategy/service.php` alongside the existing `pcm_strategy_process_queue` registration.
**Load-bearing design correction caught while implementing this step** (not present in the original plan
wording): `maybe_schedule_queue_continuation()` previously chained to the next PENDING item regardless of
its due date — fine for Step 1's Generate/Generate-All flow (no `scheduledDate`), but for a scheduled
strategy it would have blown through an entire week's worth of "daily" items in one background sweep the
moment the first one ran, since `run_queue_tick`'s self-re-arming chain doesn't otherwise know about due
dates. Fixed by adding a due-date gate directly to `maybe_schedule_queue_continuation()`: if the next
pending item's `scheduledDate` is set and in the future, the chain stops there instead of scheduling —
`run_scheduled_scan()`'s next daily pass re-arms it once that item's date arrives. Items with no
`scheduledDate` (draft/publish mode) are unaffected (`?? null` on the possibly-unset property, so this
never touches non-scheduled strategies) and keep chaining immediately exactly as before. Position order
is safe to rely on here because `calculate_schedule_dates()` (Step 3) always assigns non-decreasing dates
by position — so "lowest-position pending item" and "earliest-due pending item" are the same item for any
strategy created through this code path. Verified: `php -l` clean on all 3 touched files. Extended
`tests/unit/StrategyAutoPublishTest.php` +4 (scan schedules the queue for a due strategy; scan is a no-op
when nothing's due; the chain does NOT continue into a not-yet-due next item; the chain DOES continue
when the next item is already due) → 39/39 isolated (90 assertions, up from 35/83); full suite 157/157
(491 assertions, up from 153/484), same 1 pre-existing unrelated `PlatformRoleInvariantTest` failure.

**Phase B done-gate: APPROVED.** Dispatched `spec-verifier` against Steps 2+3+4 combined (10 numbered
checks: `calculate_schedule_dates()` correctness per frequency branch + purity, the schedule-write gating
in `create_from_keywords()`, the due-date gate's correctness and its `?? null` handling of items with no
`scheduledDate` property at all, SQL-injection safety of the new cross-user query, cron event wiring
matching the established daily-scan idiom, `run_scheduled_scan()` reusing rather than reimplementing
Step 1's chain, non-negotiables (additive-only migration, no raw SQL, `PCM_VERSION` untouched), no
regression to Step 1's original behavior, and the frontend-gap claim). All 10 confirmed with file:line
evidence, independently re-running both the isolated file (39/39, 90 assertions) and the full suite
(157/157, 491 assertions, same 1 pre-existing unrelated failure) rather than trusting the write-ups. One
P3/cosmetic noted: `calculate_schedule_dates()`'s `strtotime($start_date) ?: time()` fallback would inject
real wall-clock time for an unparseable `start_date`, technically breaking purity on that path — never
triggered in practice since callers always pass a parseable date; no fix required.

### Phase C — Approval gates (Decision 2: handled BY the custom Approvals module)
**Step 5 — Link item → approval set + a review status.** Add `strategy_items.setId int NULL` (additive
`dbDelta` + DB bump) to link an item to its Approvals set, and a single lightweight `in_review` item
status (the internal-vs-client distinction lives on the approval SET, owned by the Approvals module — the
strategy item doesn't need `internal_review`/`client_review` split). Files: `class-pcm-schema.php`,
`class-pcm-activator.php`, `strategy/service.php`. Consult: `sql-pro`. Status: `[done]` — additive
`dbDelta` (`setId int(11) NULL` + `idx_setId` index) on `strategy_items`; `PCM_DB_VERSION` 1.37.0 → 1.38.0.
New `PCM_DB::get_strategy_item_by_set_id($set_id, $user_id)` (owner-scoped via `strategy_items.userId`,
no join needed) for Step 7's lookup. No new item-status enum needed beyond the existing free-form varchar
column — `in_review` is just a new string value, same as `completed`/`error`/`pending`/`generating`
already are. `recompute_counters()` needed NO change: it already only tallies `completed`/`error`, so
`in_review` correctly falls through as "neither" (keeps the strategy at `in_progress`, not falsely
`completed`) — verified by reading the method, not assumed.

**Step 6 — Hand generated articles off to the Approvals module.** When `approvalMode != 'none'`, after an
item generates, create an approval set via `PCM_Approvals_Service::create_set` containing that article
(internal → internal set; client → shared/client-review set, the existing custom-approval-card flow — do
NOT rebuild any approval UI), set item `status='in_review'`, store `setId`. Files: `strategy/service.php`
(reuse the Approvals seam; the article surfaces as an approval card exactly like Copy/Image/Writer send-
to-approval). Consult: `security-auditor` (cross-module write + client share token). Status: `[done]` —
`generate_next_item()` now branches on `approval_mode($strategy)` (reads `config.approvalMode`, default
`'none'` — zero behavior change for every strategy created before this step). When != `'none'`, new
`create_approval_set_for_item()` builds the SAME `{media:[], copy:[], articles:[...]}` snapshot shape the
Writer module already sends to `POST /approvals/sets` (one article per set, not batched per strategy), so
it renders on the existing approval-card UI with zero special-casing; the item is parked `in_review` with
its `setId`, and `maybe_auto_publish()` is deliberately NOT called on this path — publish only happens
once approved (Step 7). **Known limitation, same honesty as Step 3's frontend gap**: `'client'` mode does
not actually email/share the set — `PCM_Approvals_Service::share_set()` needs a client email address, and
the create-strategy dialog captures none — so it creates the same unshared `draft`-lane set as `'internal'`
mode; a human still shares it from the Approvals module UI. Consistent with Decision 2 (sharing IS an
Approvals-module action) rather than silently-dropped scope.

**Step 7 — Publish on approval (react to the Approvals module's trigger).** New `strategy/automations.php`
(module auto-loaded glob) registers a handler on the EXISTING `approvals.set_fully_approved` trigger:
resolve the strategy item by `setId`, run `maybe_auto_publish` (when publish mode) and advance the item
`in_review → completed`/published. So the approve action happens in the Approvals module UI; the strategy
just listens. Files: new `strategy/automations.php`. Verify: fire the trigger in a unit test → the linked
item publishes/advances. Status: `[done]` — **the trigger→action wiring is fully rule-based in this
engine** (confirmed by reading `fire_trigger()`/`run_action()` — there is no raw "subscribe a callback to
an event" mechanism), so "registers a handler on the trigger" concretely means: a new action
`strategy.publish_on_approval` (`strategy/automations.php` + new
`class-pcm-publish-on-approval-action-handler.php`, implementing the existing `PCM_Automation_Action_Handler`
interface exactly like `approvals`'s own `PCM_Move_Lane_Action_Handler`), plus a new DEFAULT SEEDED RULE
(`strategy.flow.fully_approved_publish` in the shared `automations/class-pcm-automation-seeds.php` registry
— the same file the Approvals module's own default rules already live in, not a per-module file) linking
`triggerId=approvals.set_fully_approved → actionId=strategy.publish_on_approval`, `conditions=[]` (matches
every fully-approved set for that user). This runs alongside the EXISTING "move to Launch" rule on the
same trigger — a clean, expected no-op for the majority of sets that are NOT strategy-linked (Copy/Image/
Writer content sent to approval directly), since the handler's own `PCM_Strategy_Service::
advance_item_on_approval()` looks the set up by `setId` and returns null (reported as `skipped`, not an
error) when nothing matches. Backfilled for EXISTING users via a new `version_compare($installed_version,
'1.38.0', '<')` gate in `class-pcm-activator.php` (mirrors the established v1.19/v1.22.1/v1.30.0
seed-backfill pattern exactly) — new users get it automatically via the existing
`PCM_REST_Base::get_current_pcm_user()` per-user seeding path.

**Phase C security review + fixes.** Dispatched `security-auditor` against Steps 5-7 (cross-module trust
boundary, ownership/authorization, SQL injection, re-entrancy/replay, information disclosure). No P0/P1.
Three findings, two fixed inline:
- **P2 (fixed)**: `advance_item_on_approval()`'s original `in_review`-status guard was check-then-act, not
  atomic — two concurrently-delivered `set_fully_approved` events (e.g. a client double-clicking
  "approve all" before the async "move to Launch" rule lands) could both pass the read-guard and both call
  `maybe_auto_publish()`, producing a duplicate live post (`publish_to_site()` is not idempotent). Fixed
  with a new `PCM_DB::advance_strategy_item_from_in_review($id)` — a single `$wpdb->update()` gated on
  `status = 'in_review'` in the WHERE clause, returning true only if it actually matched a row (an atomic
  compare-and-set). `advance_item_on_approval()` now calls this claim BEFORE `maybe_auto_publish()`, not
  after — a losing caller returns `null` and never reaches publish at all.
- **P3 (fixed)**: client-mode set names embedded the internal strategy name + target SEO keyword
  (`"{strategy} — {keyword} (Client Review)"`), visible to any client who opens the unauthenticated,
  token-scoped review link. Fixed: `'client'`-mode sets now use a client-safe name (just the article
  title); `'internal'`-mode sets keep the more useful strategy+keyword identifier for the team's own
  dashboard, since those are never (automatically) shared externally.
- **P3 (not fixed, flagged as pre-existing/out of scope)**: LLM-generated article `content`/`title`/meta
  fields are stored with no server-side sanitization (`wp_kses`) at `create_article()` time. The
  verifier's own render-path trace found this is NOT currently exploitable — the client-facing approval
  card renders `content` through Tiptap/ProseMirror, which parses HTML into an allow-listed node schema
  (no `<script>`, no `javascript:` links) — and this behavior predates this phase entirely (every other
  content-generation path in this plugin has the same storage-layer shape). Fixing it would mean auditing
  `create_article()`'s callers plugin-wide, well outside this phase's file scope; noted for a future,
  separately-scoped hardening pass rather than folded in here.

Verified (after the security fixes): `php -l` clean on all touched/new files (`strategy/service.php`,
`class-pcm-db.php`, `class-pcm-schema.php`, `power-creatives.php`, `class-pcm-activator.php`,
`strategy/automations.php`, `class-pcm-publish-on-approval-action-handler.php`,
`automations/class-pcm-automation-seeds.php`). Extended `tests/unit/StrategyAutoPublishTest.php` +9
(approval-mode internal parks in_review + creates a set; client mode creates an unshared set distinguished
only by name; the approval gate defers publish even when publishingMode='publish'; mode='none' is
unaffected/pre-Step-6 behavior preserved; `advance_item_on_approval` publishes+completes; returns null for
a set not linked to any strategy item; is a no-op on re-entry once already completed; does NOT publish
when the atomic claim loses a race; the action handler skips cleanly when not strategy-linked) → **48/48
isolated, 112 assertions** (up from 39/90); full suite **166/166 (513 assertions)** (up from 157/491, plus
1 pre-existing test file's own hardcoded seed-count fixed as a **necessary, correct update**, not a
regression — `AutomationSeedsTest::test_seed_then_reseed_is_idempotent` asserted the total seed-definition
count, 7 → 8 after adding the new default rule; same 1 pre-existing unrelated `PlatformRoleInvariantTest`
failure). **Verified against the REAL local WordPress runtime** (same rigor as Step 2 — dbDelta and the
seed-backfill gate are genuine WP-core-dependent code the fast unit suite can't fully exercise): booted
`wp-load.php`, confirmed `setId`/`idx_setid` exist via `SHOW COLUMNS`/`SHOW INDEX`, re-ran
`PCM_Schema::create_tables()` a second time (idempotent, no errors), and confirmed all 3 existing local
users were actually backfilled with the new `strategy.flow.fully_approved_publish` rule (queried
`wp_pcm_automations` directly).

**Phase C done-gate: APPROVED.** Dispatched `spec-verifier` against Steps 5+6+7 combined (11 numbered
checks: `approval_mode()` default-`'none'` regression safety, `maybe_auto_publish()` genuinely skipped on
the gated path, the P2 atomic-claim correctness and call ordering, the P3 client-name fix, ownership
scoping + SQL safety of the new DB methods, `user_id` provenance from the trigger back to the set's real
owner, action-handler wiring, the v1.38.0 seed-backfill gate, non-negotiables, and the client-share-gap
claim). All 11 confirmed with file:line evidence, independently re-running the isolated file (48/48, 112
assertions), `AutomationSeedsTest` (2/2, confirming the 7→8 count bump is genuine, not fudged), and the
full suite (166/166, 513 assertions, same 1 pre-existing unrelated failure). One P3/low, non-blocking
prose-precision note: the write-up's "run() never throws" is imprecise — the handler's own body has no
`throw`, but it delegates to an unguarded `advance_item_on_approval()`; any propagated exception is
contained by the engine's own `run_action()` try/catch, so there is no functional defect. No fix required.

### Phase D — Content depth (execute the stored generation options)
**Step 8 — Consolidated structure.** When `structure='consolidated'`, generate ONE article covering all
keywords instead of one-per. Files: `strategy/service.php` (`build_prompt` + item modelling). Verify:
unit test (1 article, all keywords in prompt). Status: `[done]` — `generate_next_item()` now routes to a
new `generate_consolidated_batch()` at its very top whenever `config.structure==='consolidated'` (before
the per-item pipeline, for both the "next pending" flow and a targeted retry — there's only one shared
article either way). `build_prompt()`'s signature changed from a single `string $keyword` to `array
$keywords` (its one production call site updated to pass a single-element array; the consolidated path
passes every item's keyword) so the LLM gets ALL keywords woven into one prompt instead of a crude
comma-join. Every item shares the SAME `articleId` and advances together. **Deliberately reapplies the
Step 6 approval-gate branch** rather than skipping it for this structure — a strategy can combine
"Consolidated" with "Internal/Client" approvals in the create dialog, and silently bypassing the just-shipped
approval gate for that combination would be a real regression, not a scope-boundary. Verified: `php -l`
clean. Extended `tests/unit/StrategyAutoPublishTest.php` +3 (generates exactly one article covering all
3 keywords — asserted both via shared `articleId` across items AND via the captured LLM prompt actually
containing every keyword string, per the plan's own stated verify criterion; is a no-op once already
generated; respects `approvalMode` — one shared approval set, not one per item) → 59/59 isolated so far
(cumulative with Steps 9-10 below), full suite reported once all of Phase D is in.

**Step 9 — Hierarchy-aware generation.** When `hierarchyMode` ≠ standalone: generate the pillar first,
inject a parent link into each child's HTML (post-process). Uses `parentTargetUrl`/`parentKeyword` already
stored. Files: `strategy/service.php`. Verify: unit test (child HTML contains the parent link). Status:
`[done]` — **design deviation from the AutoPress reference, transparently documented**: the actual AutoPress
`processNextItem()` this was ported from injects the parent-link as a PROMPT INSTRUCTION ("you MUST include
a contextual link to the parent page..."), asking the LLM to place it — non-deterministic, the LLM can
ignore it. That directly conflicts with this plan's own stated verify criterion ("unit test: child HTML
contains the parent link" — only provable if the injection is guaranteed). Ported instead as **deterministic
post-process HTML append** (a `<p>` paragraph with the anchor, appended after the LLM call, before the
article is saved) — guaranteed present, matches the plan's own wording ("post-process") over the reference's
actual behavior. Covers both hierarchy modes that need a link: `children_only` (the stored external
`parentTargetUrl` — every item is a "child" of a pre-existing page) and `parent_and_children` (the strategy's
own designated parent item, matched by `keyword === config.parentKeyword` since this codebase stores the
parent as a keyword string, not an item id). For `parent_and_children`, item SELECTION itself is also
hierarchy-aware now (new `select_next_item_for_parent_and_children()`): the parent generates first regardless
of its position, and children wait (return null, same as "nothing to generate right now") until the parent
has an `articleId` — mirrors the AutoPress reference's own "waiting for parent" gate, simplified to "has
generated" rather than "has published" (draft-mode strategies may never publish at all). Parent URL
resolution prefers the parent article's real `publishedUrl`; falls back to `site.url + slug` when unpublished
— a best-effort guess ported faithfully from the reference, not a guarantee it matches the eventual real URL.
Verified: `php -l` clean. Extended `tests/unit/StrategyAutoPublishTest.php` +4 (`children_only` injects the
stored URL into every item; `parent_and_children` generates the designated parent first even when it's at a
later position than a child; children wait while the parent is still generating/has no article; a child's
injected link resolves to the parent's constructed site+slug URL once the parent has generated).

**Step 10 — Interlink injection.** After a batch completes, when `interlinksConfig` set, inject up to
`quantity` phrase-matched internal links between the generated articles. Decision 3. Reuse SEO link-rewrite.
Files: `strategy/service.php` (+ maybe a `interlinks.php` helper). Verify: unit test on the
anchor→url selection + injection cap. Consult: `architect-review`. Status: `[done]` — **"reuse SEO
link-rewrite" doesn't refer to anything that exists in this codebase** (searched `includes/` and `app/src/`
directly — nothing found outside this module's own new code); implemented as a small, purpose-built,
dependency-free method in `strategy/service.php` rather than inventing a separate `interlinks.php` file for
~80 lines of logic (unnecessary indirection for this size). "Phrase-match" (Decision 3) means: for each
completed article, literally search every OTHER completed item's own keyword text within this article's raw
HTML and wrap the FIRST occurrence in `<a href="...">` — deterministic, no extra LLM call (unlike the
AutoPress reference's AI-assisted anchor-text variant, which needs one), no new dependency. Runs from a
single choke point: `recompute_counters()` (already called from every completion path — the per-item flow,
Step 8's consolidated batch, and Step 7's `advance_item_on_approval()`) now compares the strategy's
PRE-update status against the newly computed one and fires interlink injection exactly once, on the
transition INTO `'completed'` — no new DB column needed to track "already injected." Idempotency
double-guarded: a candidate is skipped if its target URL is already present anywhere in the source's
content (protects against a theoretical re-entry, e.g. a later manual reset-and-regenerate of one item
re-triggering the same completion transition). **Known, documented limitation**: this is a plain string
search on raw HTML, not an HTML-aware injector — a keyword phrase that happens to sit inside an existing
tag/attribute could in theory be wrapped incorrectly; acceptable for a v1 given the "no new deps" constraint
and the plan's own narrow verify criterion (anchor→url selection + injection cap), not silently accepted.
Verified: `php -l` clean. Extended `tests/unit/StrategyAutoPublishTest.php` +5 (injects once on the
transition into completed; idempotent — never double-injects the same target across a second recompute
call; the `quantity` cap is respected even with more matching candidates available; a no-op when
`interlinksConfig` isn't set at all).

**Phase D architect-review + fixes.** Dispatched `architect-review` against all of Steps 8-10 (per the
plan's own Step 10 consult directive) — 5 questions: 3-way routing at the top of `generate_next_item()`,
overloading `recompute_counters()` with a side effect, string-based HTML mutation risk, duplication between
`generate_consolidated_batch()` and the per-item approval-gate branch, and combinatorial risk across the
now-5 config axes (structure/hierarchy/schedule/approval/interlinks). Verdict: approve-with-notes on 4 of 5,
one genuine gate-merge-level bug found and fixed:
- **Fixed (blocking)**: the interlink idempotency guard compared the RAW target URL against stored content,
  but the actual injection wraps `esc_url($target['url'])` — a URL with special characters (e.g. `&` in a
  query string, which `esc_url()` entity-encodes) would defeat the guard and re-inject on every re-run.
  Fixed: the guard now compares against the SAME escaped form actually injected.
- **Fixed (requested)**: `preg_replace(..., 1)` wrapped the FIRST keyword occurrence anywhere in raw HTML,
  including inside existing markup — an attribute value, or (realistically, given Step 9 runs first) inside
  an existing `<a>`'s own rendered text, producing an invalid nested anchor once persisted. Fixed with a new
  `is_inside_html_tag()` guard (checks both "inside a tag's own `<...>` markup" and "inside more open `<a>`
  tags than `</a>` closes") — a match found unsafe is skipped in favor of a later occurrence, all done via
  `preg_match_all(..., PREG_OFFSET_CAPTURE)` + `substr_replace()` rather than a single blind `preg_replace`.
- **Fixed (requested)**: `recompute_counters()` was overloaded with a batch-completion side effect under a
  misleading contract ("recompute counters" silently also "inject interlinks"). Extracted a small named seam,
  `finalize_on_completion()`, called from the one `!$was_completed && $status==='completed'` branch — same
  choke point, but the counter method's own contract stays honest and the completion hook is greppable.
- **Noted, not fixed (deliberate, reasoned decision)**: the reviewer's strongest structural ask —
  extracting a shared `finalize_generated_article()` helper to de-duplicate `generate_consolidated_batch()`'s
  approval-gate branch against the per-item path's — was considered and NOT done. Reasoning: the two paths'
  iteration semantics genuinely differ (one item vs. a list of ids; the consolidated path correctly omits the
  per-item queue-continuation call since generating the WHOLE batch in one pass leaves nothing to continue
  to, which the reviewer's write-up characterized as "already-diverged" but is actually correct, not a bug) —
  a shared helper would need internal branching over that difference anyway, trading a ~20-line duplication
  for an abstraction with its own conditional complexity. Flagged here rather than silently accepted; revisit
  if a THIRD structure mode is ever added.
- **Noted, not fixed (documented limitation, not a defect)**: `generate_consolidated_batch()` ignores
  `scheduledDate` entirely (generates every pending item in one shot, deliberately — that's the whole point
  of "consolidated") and the top-of-method routing means `structure='consolidated'` silently takes priority
  over `hierarchyMode` (parent-link injection never runs for a consolidated strategy, since there's no
  separate "child" article to inject into). Both combinations are arguably contradictory in the UI itself
  (schedule/hierarchy are about MULTIPLE separate articles; consolidated collapses everything into one) —
  documented as an explicit, known interaction rather than silently accepted or engineered around, per the
  reviewer's "reject or handle" framing; no create-dialog validation exists yet to actually prevent selecting
  the combination, which is the honest remaining gap here.

Verified (after the architect-review fixes): `php -l` clean on `strategy/service.php` (the only file
touched — no schema/version changes this phase, matching the plan's own file-scope). Extended
`tests/unit/StrategyAutoPublishTest.php` +2 more (an anchor-nesting guard test — the only occurrence of a
target keyword sits inside an existing `<a>`'s text and must not get double-wrapped; an idempotency test
with a URL containing `&`/query-string characters, confirming no re-injection across two recompute calls) →
**61/61 isolated, 145 assertions** (up from 59/143); full suite **179/179 (546 assertions)** (up from
177/544), same 1 pre-existing unrelated `PlatformRoleInvariantTest` failure.

**Phase D done-gate: APPROVED.** Dispatched `spec-verifier` against Steps 8+9+10 combined (7 numbered
checks: consolidated routing/prompt-shape/approval-reapply, hierarchy item-selection/link-injection-ordering/
URL-resolution, both architect-review bug fixes verified at the exact line level, the two "noted, not fixed"
claims (no `scheduledDate` check in the consolidated path; the early-return genuinely skips hierarchy
selection), non-negotiables, and an independent new-bug hunt). All 7 confirmed with file:line evidence,
independently re-running the isolated file (61/61, 145 assertions) and the full suite (179/179, 546
assertions, same 1 pre-existing unrelated failure). Fixed the one trivial finding inline (F1: `build_prompt()`'s
docblock still said `@param string $keyword` after the signature changed to `array $keywords` — corrected).
Two more P3/low findings from the verifier's own new-bug hunt, documented here rather than fixed (both
low-likelihood combinatorial edge cases, same treatment as the architect-review's own "noted, not fixed"
items above):
- **F2**: `structure='consolidated'` + `interlinksConfig` configured together produces self-referential
  links — every item shares one `articleId`/URL in consolidated mode, so `maybe_inject_interlinks()` (which
  assumes one-article-per-item) wraps another item's keyword in a link pointing at the SAME article's own
  URL. Harmless (a self-link, not broken HTML) but not what a user configuring "consolidated + interlinks"
  together would expect. Same root cause as the already-documented consolidated+hierarchy/scheduled gaps —
  consolidated mode's interaction with the other 4 config axes is generally unvalidated at the create-dialog
  level, and this is one more instance of that same, already-flagged limitation.
- **F3**: in `parent_and_children` mode, if the designated parent item permanently errors (exhausts retries
  without success), `select_next_item_for_parent_and_children()` returns null forever (parent isn't `pending`,
  has no `articleId`) — the background queue stops advancing for that strategy's children entirely, requiring
  a manual retry of the parent to unstick it. Semantically defensible (children genuinely can't link to a
  parent that doesn't exist yet) but is in tension with Decision 4's "skip-and-continue" framing, which this
  interaction wasn't originally scoped against. Both are narrow, documented gaps rather than blocking defects
  — no fix applied given the combinatorial edge-case nature and remaining scope, consistent with the
  reviewer's own "noted, not fixed" framing for the closely-related issues already on record above.

### Phase E — Optional (NOT required for "complete"; flagged per Decisions 5–6)
**Step 11 — Per-item status route** (`PATCH /strategies/{id}/items/{itemId}` — manual advance/reject/
reset-to-pending). Small, useful. Status: `[done]` — scoped to exactly the meaningful action available
pre-Phase-C: reset a `completed`/`error` item back to `pending` (detaches its `articleId`, clears
`errorMessage`, article itself untouched in Writer). Logic lives in
`PCM_Strategy_Service::reset_item_to_pending()` (not the controller) so it's unit-testable without
faking `WP_REST_Request`/`WP_REST_Response` — matches this codebase's controller=plumbing /
service=business-logic split. `recompute_counters()` widened from `private` to `public` (no behavior
change) since the controller/service boundary now needs it from two call sites. Ownership: ownership
of the STRATEGY is verified in the controller (existing pattern); ownership of the ITEM (must belong to
that strategy) is verified inside the service method itself, since `PCM_DB::update_strategy_item()` is
not itself scoped by strategy/user — mirrors `generate_next_item()`'s existing itemId-targeting pattern.
Verified: `php -l` clean; extended `tests/unit/StrategyAutoPublishTest.php` +4 tests (reset-completed
clears article+recomputes counters; reset-error clears errorMessage; rejects resetting a pending/
generating item; rejects a foreign itemId not in this strategy) → 16/16 isolated; full suite 134/134
(446+11 assertions; same 1 pre-existing unrelated `PlatformRoleInvariantTest` failure). No frontend
change (backend-only per plan scope) — a UI button can wire to `PATCH .../items/{itemId} {status:
'pending'}` later without further backend work.
**Step 12 — AI topic suggestion** (propose topics from keywords, not 1:1). Net-new. Status: `[deferred]`
**(Workflows module — DEFERRED entirely, Decision 5.)**

---

## Recommended execution order
Quick wins first (Step 11), then **A → B → C → D**. A (queue) unblocks B and D; C (approvals) is
independent of B but shares the publish path; D is polish/SEO depth. Each phase is its own /task with its
own done-gate. Budget: this is multi-session (2 DB migrations, cron, approvals integration, prompt work).

## Verification standard (every step)
`php -l`; extend `tests/unit/StrategyAutoPublishTest.php` (or a sibling) with real PHPUnit coverage
(process-isolated, same pattern already established); tsc 56 baseline (0 new); `vite build`;
`composer test` full-suite green (currently 179/179 minus 1 pre-existing unrelated `PlatformRoleInvariantTest`
failure — track separately). Browser verification is login-gated (I don't enter passwords) — note it as a
limitation rather than claim it. Done-gate `spec-verifier` per phase; address P0/P1.

## Non-negotiables (CLAUDE.md) every new route/handler must satisfy
Extend `PCM_REST_Base` (nonce + capability + sanitize in + escape out); responses via
`$this->success()/error()/not_found()`; no SQL without `$wpdb->prepare()` (tables via `PCM_Schema::table()`);
new modules' automations in `strategy/automations.php`; DB changes additive `dbDelta` + gated `migrate_*` +
`PCM_DB_VERSION` bump, idempotent; data-driven `model.provider`; keys stay server-side.
