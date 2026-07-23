# ClickUp Subtasks — Strategy Reliability + LLM Diagnostics Batch

Source: GitHub `profitmediaab/powerplatform` (branch `feat/seo-suite-port`) +
`sidhartha8011/powercreatives` fork. Grounded in the uncommitted working tree
(1,559 lines across strategy/LLM/tests from 4 sessions) and the OPEN items
in `.claude/SESSION_LOG.md`. Each subtask is implementation-ready with files,
scope, and acceptance criteria.

> **Commit hygiene note for all subtasks:** the working tree currently holds
> ~1,559 uncommitted lines spanning 4 sessions (template vars, refusal
> surfacing, SEO-pillar variables, wedge reclaim). Subtask 1 ships the
> strategy-reliability slice; the LLM/template slices should be committed in
> their own focused commits before starting subtasks 2–3.

---

## Subtask 1 — Ship & verify the strategy wedge-reclaim + retry-cap fix
**Why:** "generations keep on going without getting completed" and "generation
fails a lot." Fixes are BUILT and unit-tested but uncommitted + unverified live.
**Type:** Backend (PHP) + verification. ~0.5 day.
**Files (already changed in working tree):**
- `includes/modules/strategy/service.php` — W1 shutdown net, W2 cron, W3 retry cap
- `includes/core/db/class-pcm-db.php` — `get_stale_generating_items()`
- `tests/unit/StrategyWedgedItemReclaimTest.php`, `tests/unit/StrategyAutoPublishTest.php`
**Remaining work:**
1. Commit the strategy-reliability slice as a focused commit (exclude the
   unrelated LLM/template-seed/UPGRADE-SAFETY hunks — stage file-by-file or
   `git add -p`).
2. On the Windows live box (`powercreatives.local`), confirm: (a) the
   `pcm_strategy_wedge_reclaim` cron appears in `wp-cron` / a cron-info endpoint;
   (b) an item whose generation is killed (e.g. stop the PHP process mid-generate)
   flips to `error` within ~15 min, not 90; (c) an item that fails 3 reclaim
   cycles lands in `error` with "generation failed after 3 attempts".
3. Capture ONE real failing payload from the owner's box (the diagnosis is
   code-traced, never runtime-confirmed) — even a screenshot of the row's
   error tooltip — so we know whether the failures are refusals, content_filter,
   or truncation.
**Acceptance:** focused commit on `feat/seo-suite-port`; live confirmation that
a stuck item recovers in ≤15 min; one captured real error payload attached.
**Open risk to close:** the shutdown net is source-invariant-tested only — the
live test in step 2 is its first runtime exercise.

---

## Subtask 2 — Close the LLM refusal-diagnosis observability gaps (F8/R9/R12)
**Why:** refusal surfacing was added but the streaming path bypasses it, docblocks
omit the new keys, and the root cause is still INFERRED (no captured payload).
These are the verifier's explicitly-left-open P3s from two sessions ago.
**Type:** Backend (PHP). ~0.5 day.
**Files:** `includes/core/llm/class-pcm-llm.php`
**Work:**
1. **F8:** Update `invoke()` / `parse_response()` / `blocking_request()` `@return`
   docblocks to list the `refusal` and `finish_reason` keys. Document in
   `stream_request()`'s docblock that it returns a narrower shape with NEITHER key
   (currently only safe because of `?? ''`).
2. **R9:** The count assertion `count(throw_if_refused) === count(self::invoke)`
   pins HOW MANY guards exist, not WHERE — strengthen the test to assert each
   invoke() call site is individually guarded (anchor on `self::invoke` offsets).
3. **R12:** Add a guard so a future caller passing `on_chunk` to a streaming
   request can't silently bypass the refusal mechanism (either route the refusal
   check into the stream path, or assert+document that streaming is refusal-blind
   and block it for refusal-sensitive callers).
4. Resolve whether the owner's failing rows are refusals or content_filter using
   the payload captured in Subtask 1 step 3 — the `finish_reason` clause is what
   distinguishes them.
**Acceptance:** `composer test` green (baseline unchanged); the 4 docblocks
accurate; R9/R12 tests pin the behavior; a written note on refusal vs
content_filter for the observed rows.

---

## Subtask 3 — Strategy CreateStrategyDialog summary rework (F1–F7, UNVERIFIED)
**Why:** the dialog summary was reworked last session but the round-2 verification
never completed (the run died). It shipped type-clean but UNVERIFIED by anyone.
**Type:** Frontend (React/TS) + visual verification. ~0.5 day.
**Files:** `app/src/modules/Keywords/CreateStrategyDialog.tsx` (and the
recurrence summary logic — check `RecurrenceEditor.tsx` for the "Ends" visibility
gap flagged as F-something).
**Work:**
1. Read the current dialog summary logic against the 7 defects the verifier named:
   - F1: summary said "publish" while the default publishing mode is **Draft**;
   - F2: "one per slot" is wrong for Consolidated mode (one article, all keywords);
   - F3: the RecurrenceEditor's own "Ends" setting was invisible to the summary;
   - F4–F7: (confirm the remaining items in the dialog against the session log).
2. Fix any still-present defects; confirm the summary reflects: actual publishing
   mode, correct cadence wording per structure mode, and the Ends condition.
3. **Visual verification on the live box** (the gap that closed the last session):
   open Create Strategy, cycle through Draft/Publish/Schedule ×
   Standalone/Consolidated/Parent-children × no-recurrence/daily/weekly-with-ends,
   confirm the summary text matches reality in every combination.
**Acceptance:** `cd app && npm run check` 0 new errors; screenshot or owner
confirmation that all combinations read correctly; the session-log "NOT DONE" on
this item cleared.

---

## Subtask 4 — UPGRADE-SAFETY + option docs for the new strategy reliability knobs
**Why:** two new owner-tunable options + a new cron event landed with no
UPGRADE-SAFETY entry. An upgrading install won't know `pcm_strategy_max_attempts`
exists or that `pcm_strategy_wedge_reclaim` is now armed. Also closes the F8-style
gap from the SEO-pillar session (pre-existing templates silently losing auto-append).
**Type:** Docs. ~0.25 day.
**Files:** `docs/UPGRADE-SAFETY.md`
**Work:**
1. Document the new cron event `pcm_strategy_wedge_reclaim` (15-min interval
   `pcm_wedge_interval`) — what it does, that it's auto-armed on `init`, how to
   verify it's scheduled (`wp cron event list`).
2. Document the `pcm_strategy_max_attempts` option (default 3, clamp ≥1) — what
   it bounds, how to raise/lower it, that a manual Retry resets the per-item counter.
3. Document the `config.attempts` field on `strategy_items` — that it's JSON in
   the existing `config` column (no schema change), resets on manual retry, and
   that pre-existing items have no counter (treated as 0).
4. Add the F8 carry-over note: a pre-existing user template containing one of the
   5 new SEO-pillar tokens (`{{ keyword }}`, `{{ brand_context }}`, `{{ research }}`,
   `{{ output_format }}`, `{{ media_instructions }}`) silently loses its auto-append
   fragment on upgrade — call out the manual fix.
**Acceptance:** the 4 entries added; reviewed against the actual code (not the
session log's claims) to confirm accuracy.

---

## Subtask 5 — Approval-pipeline rails (autonomy roadmap #1) — spec + first pair
**Why:** the #1-leverage item on the agreed autonomy roadmap. Everything else
(dynamic optimization, AI page optimizer) depends on it. Currently only specced
in prose in `docs/backlog.md`; no code, no table, no adapter registry.
**Type:** Full-stack architecture (PHP + React + DB). Multi-day — this subtask
is the FOUNDATION slice only.
**Files (new):** `includes/modules/seo/staged-changes.php` (service),
`PCM_Schema` (`seo_staged_changes` table), approvals adapter registry in
`includes/modules/approvals/`, frontend Before/After card component.
**Work (foundation slice):**
1. **DB:** add `seo_staged_changes` table (site, post, column, before, after,
   status: staged→pending→approved/rejected→applied/failed; reject = MARKED not
   discarded). Bump `PCM_DB_VERSION`, add `migrate_*` gate in `maybe_upgrade()`.
2. **Adapter registry:** approvals module gains an asset-type adapter registry
   (same pattern as automations) — `adapter = {type, toSnapshotItems, renderer,
   onItemApproved}`. No hardcoding of SEO↔approvals either direction.
3. **Envelope + card:** "Send for approval" hands over
   `{type, tags:{siteId,projectId,deliveryId,brandId}, items per page}`. Build the
   Before/After card: one card per PAGE, one row per changed column. Register the
   apply-on-approve handler on the EXISTING `approvals.asset_approved` trigger
   (runs under the set owner's identity — verified `service.php:1163`).
4. Value-drift default = apply + keep recorded before-value as audit; site
   resolves from project via `PCM_Hierarchy::site_for_project`.
**Acceptance:** a staged SEO change flows through Send → Approval card → Approve →
Applied, end-to-end, with the before-value retained as audit; the adapter
registry is the ONLY seam between SEO and approvals (no direct calls).
**Out of scope for this slice:** per-change comments (roadmap #2), dynamic
optimization layer (#3), AI page optimizer (#4) — each is a later subtask.
