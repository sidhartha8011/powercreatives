# Plan: Strategy generation reliability + real publish-to-site + interlinks (worker mode)

**User report (2026-07-09):** "generation has lot of api issue showing but then also automatically
generates; it does not have any publish to site; interlinks and lot of features present in the
original zip" (AutoPress reference, still extracted in scratchpad).

**Note:** the previous `plan.md` became OS-locked mid-session (EPERM on read/write/delete, shell AND
harness, sandbox disabled — macOS provenance protection). Its content is preserved step-by-step in
`.claude/SESSION_LOG.md`. This plan therefore lives at `plan-worker.md`.

## Diagnosis (all file:line verified this session)

**D1 — "lots of API issues" is per-item re-discovery of the same model limits.** The self-healing
added yesterday works but learns nothing: EVERY item on a json_schema-incapable model first 400s
(ladder falls back), and EVERY item on a small-context model 400s again on token budget
(reduce-and-retry). 5 keywords → the same 1-2 failing calls repeated 5 times → a log/UI full of
errors even though each item ultimately succeeds. `PCM_LLM` has no memory
(`class-pcm-llm.php` — ladder at `invoke_json`, token retry inside `invoke()`).

**D2 — "errors show but it generates anyway" has three mechanisms:**
- (a) The multi-call healing chain (up to ~4-6 API calls worst case) can push a synchronous
  `/generate` request past the host's gateway timeout → browser shows an API error while PHP quietly
  finishes the item server-side.
- (b) The background wp-cron continuation (by design, Phase A) keeps draining pending items after any
  Generate click. The Strategies page has **zero polling** (verified — no `refetchInterval`), so
  articles appear "by themselves" only on manual refresh, which reads as spooky/buggy.
- (c) A REAL duplicate-generation race: item pick → `'generating'` write is non-atomic
  (`service.php:536` pick → `:547` write). The browser's Generate-All loop and a cron tick can both
  grab the same pending item → duplicate articles. Bonus wedge: a killed PHP process strands an item
  in `'generating'` forever — the strategy sticks at "In Progress", and Generate falsely toasts
  "All items have been generated" (null-pick path).

**D3 — publish-to-site is genuinely absent for the user's flows** (flagged in yesterday's gap audit;
the user has now DECIDED: make it real). `maybe_auto_publish` hard-gates on
`publishingMode === 'publish'` (`service.php:833`): draft strategies (the user's actual one) and
schedule strategies never publish; there is no per-item Publish action; `publishingMode` isn't
editable after creation (row edits only `siteId`). The backend building block already exists:
`POST /sites/{id}/publish {articleId}` (`sites/controller.php:304`) and the trpc route map already
has `sites.publish` (`trpc-routes.ts:299`).

**D4 — interlinks exist but are invisible.** `maybe_inject_interlinks` (`service.php:1155`, private,
returns void) auto-fires only on the one-time transition into `completed`. No manual trigger, no UI,
no feedback count — vs AutoPress's visible interlink actions. Strategies completed before the feature
shipped can never get interlinks.

## Steps (worker mode: [sonnet] = delegated mechanical brief; [driver] = Fable executes)

**Step 1 [sonnet] — LLM remembers discovered model limits.** `class-pcm-llm.php` only.
- When `is_response_format_unsupported` fires → `set_transient('pcm_llm_nojschema_' . md5($model), 1, WEEK_IN_SECONDS)`;
  `invoke_json` checks it and starts at json_object mode (skips the doomed json_schema call).
- When `reduced_token_budget` yields a working budget → `set_transient('pcm_llm_tokcap_' . md5($model), $budget, WEEK_IN_SECONDS)`;
  `invoke()` clamps the requested completion budget to a remembered cap BEFORE sending.
- All transient calls guarded `function_exists('set_transient')` (unit-test env has no WP).
- Accept: `php -l`; full suite green; reflection probe showing clamp applied when transient present.
Effect: after the first item, subsequent items are single-call — the error spam stops.

**Step 2 [driver] — atomic item claim + stale-'generating' reclaim.** (Core pipeline — not delegated.)
- New `PCM_DB::claim_strategy_item($id)`: `UPDATE ... SET status='generating' WHERE id=%d AND
  status='pending'`, true iff 1 row (mirrors the existing `advance_strategy_item_from_in_review`
  pattern). Next-pending flow claims; on a lost race re-picks (bounded loop); targeted Retry keeps its
  unconditional write (any-status regeneration is by design).
- New `PCM_DB::reclaim_stale_generating($strategy_id, $minutes=10)`: items stuck `'generating'` with
  `updatedAt` older than N minutes → back to `'pending'`; called at the top of the next-pending pick
  so a killed process can't wedge the strategy.
- Unit tests: claim win/lose; reclaim resets only stale rows.
- Accept: suite green; `php -l`.

**Step 3 [sonnet] — publish gate honors schedule mode.** `strategy/service.php` + tests.
- `maybe_auto_publish`: allow `'publish'` OR `'schedule'` (one gate — the per-item, consolidated, and
  approval-advance call sites all inherit). Scheduled items then truly publish on their due date via
  the existing cron chain. Documented side effect: manually generating a schedule-mode strategy
  publishes immediately (matches "the user asked for it now").
- Tests: schedule+site → `publish_to_site` called; draft → never.
- Accept: suite green (existing draft-mode tests must stay green — they prove no regression).

**Step 4 [driver] — retro/manual publish + interlink trigger (backend).** (Cross-file coherence.)
- Service `publish_item($strategy_id, $item_id, $user_id)`: ownership-scoped, requires item
  `completed` + `articleId` + a configured `siteId`; reuses `PCM_Sites_Service::publish_to_site`.
  Route: `POST /strategies/{id}/items/{itemId}/publish`. This is the "publish to site" for
  already-generated/draft strategies — the user's actual fifa-world-cup case.
- Interlinks: `maybe_inject_interlinks` now returns the injected-link count; new public
  `run_interlinks($strategy_id, $user_id): int`; route `POST /strategies/{id}/interlinks`.
  `finalize_on_completion` behavior unchanged.
- trpc route map: `strategy.publishItem`, `strategy.injectInterlinks`.
- Tests: publish_item happy / no-site / foreign-item / not-completed; run_interlinks count.
- Accept: suite green; `php -l` both files.

**Step 5 [driver] — Strategies page UX.** `app/src/modules/Strategies/index.tsx`. (One file, many
concerns — driver.)
- Row: **Publishing Mode select** (Draft / Auto-publish / Scheduled) → existing PATCH (top-level
  `publishingMode`, already whitelisted) — replaces the read-only label.
- Item row: **Publish button** when `completed && articleId && !articlePublishedUrl && strategy has
  siteId` → `strategy.publishItem`; toast + refetch ("View on site" then appears via the existing
  JOIN). Tooltip hint when no site is set.
- Row: **Interlinks button** (≥2 completed items) → `strategy.injectInterlinks`; toast "N links
  injected".
- **Liveness**: `refetchInterval` ~5s on `strategy.list` ONLY while something is generating/bulk
  running — background continuation becomes visible instead of spooky.
- Generate error toast: append "generation may still be running in the background; this list
  refreshes automatically" so a gateway timeout doesn't read as total failure.
- Accept: tsc 56-error baseline (0 new), `vite build` clean.

**Step 6 [driver] — verify + package.** Full suite; tsc; build; real-WP smoke (gate change + claim/
reclaim probes against the symlinked local install); rebuild the `powerplatform/`-foldered zip;
verify fix symbols present inside the extracted zip.

**Done gate:** `spec-verifier` with this plan + full diff; address P0/P1, max 3 rounds.

## Out of scope (deliberate — say the word and these become the next batch)
AutoPress parity items NOT in this plan: the Interlink MANAGER modal (manual anchor→URL rules),
per-row prompt selection, per-row frequency editing, hierarchy manager UI, AI topic suggestion.

## Verification standard
`php -l` every touched PHP file; PHPUnit full suite green (185 baseline + new tests, same 1
pre-existing unrelated `PlatformRoleInvariantTest` failure); tsc 56-baseline/0 new; `vite build` via
direct node invocation; real-WP/real-API probes where a claim depends on runtime behavior. No
commits. `PCM_VERSION`/`PCM_DB_VERSION` untouched (no schema changes anywhere in this plan).

---

## EXECUTION RESULT (2026-07-10)

All 6 steps `[done]`. Steps 1+3 delegated to Sonnet workers (briefs honored, reports driver-verified);
Steps 2/4/5/6 driver-executed. **Done-gate spec-verifier: APPROVED, no P0/P1** — its P2 finding (endpoint
could double-publish an already-published item) was fixed anyway (idempotency guard in `publish_item` +
test). Final: 197/197 tests (12 new; same 1 pre-existing unrelated failure), tsc 56 baseline, build clean,
real-WP + real-API smokes green. Zip: `~/Desktop/powercreatives/power-creatives.zip`. Residual P3s
documented in SESSION_LOG (consolidated-batch claim bypass; json_object rejection not remembered;
user-triggered reclaim; scalar token cap). Not committed.
