# Plan: Make the strategy ↔ site binding visible and reliable

**User report (2026-07-08, live hub create.widgetify.co):** "not able to select the site for which
we are making it … also its not showing the site in which it should be added." Reference: AutoPress
(`autopress-intelligence.zip`, extracted copy already in scratchpad) — its `StrategyRow.tsx` shows a
**Site select on every strategy row**, inline-editable, plus per-item post URLs. Our UI has neither.

Previous plan (complete the Strategy module) is DONE — archived at
`docs/plan-archive-2026-07-08-strategy-completion.md`. This is a new, narrower plan.

## What I verified in the code (facts, file:line)

1. **The create dialog DOES have a Target Site select** in current source
   (`app/src/modules/Keywords/CreateStrategyDialog.tsx:161-185`): fetches `trpc.sites.list`,
   auto-defaults to the first connected site (`:106-113`), sends `siteId` in the payload (`:133`),
   controller stores it in `config.siteId` (`strategy/controller.php:123`). **But this shipped only
   in today's 18:10 zip** — the previous zip on the live site is from June 30 and has NO site field
   at all. If the live dialog shows no "Target Site" field, the site is simply running the old build
   (the JS is cache-busted with `time()` in `class-pcm-admin.php:128`, so after a plugin update the
   new bundle loads — no cache issue).
2. **The Strategies page never shows the site anywhere** (`app/src/modules/Strategies/index.tsx` —
   read in full, 396 lines): rows show name/status/progress/dates only; the `Strategy` interface
   (`:38-52`) doesn't even include `config`. The backend DOES return it — `list_strategies` →
   `PCM_DB::get_user_strategies` is `SELECT *` (`class-pcm-db.php:922-926`), so `config` (a JSON
   string containing `siteId`) is already in the response, just never parsed or rendered.
3. **The site is not editable after creation.** AutoPress lets you change a strategy's site on the
   row at any time. Our `PATCH /strategies/{id}` (`strategy/controller.php:169-199`) whitelists
   `config` but **wholesale-replaces** the JSON — a partial `{config:{siteId:5}}` would wipe
   `approvalMode`/`scheduleConfig`/`interlinksConfig`/`model`. No frontend caller of
   `strategy.update` exists yet (grep: only the route-map entry), so changing it to merge is safe.
4. **Items never show where they were published.** On publish, the article row gets
   `publishedUrl` + `siteId` (`sites/service.php:270-272`), but `get_strategy_items`
   (`class-pcm-db.php:1036-1046`) selects only `strategy_items` columns — the UI can't show a
   "view on site" link even for published items.
5. **Schedule mode has NO site guard.** The dialog warns "Select a Target Site" only when
   `publishingMode === 'publish'` (`CreateStrategyDialog.tsx:368`); `schedule` mode proceeds
   silently with no site — the daily scanner will then generate drafts that never publish anywhere.
6. **Plausible live cause for an EMPTY dropdown** (if the field exists but shows "No connected
   sites"): `sites.list` is owner-scoped (`get_user_sites`, `class-pcm-db.php:1289` —
   `WHERE userId = %d`) and the sites controller carries module-grant key `'sites'`
   (`sites/controller.php:26-28`). A platform user granted `strategies` but not `sites` gets an
   empty/denied list inside the strategy dialog. For the admin in the screenshot this shouldn't
   apply — for them, hypothesis #1 (old build) is the most likely.

## Diagnostic first (no code — do before/alongside Step 1)

On the live site, after installing today's `power-creatives.zip` (built 18:10, contains the field):
open Keywords → select keywords → Create Strategy. (a) If "Target Site" is absent → the old build
was still active; the update resolves it. (b) If present but "No connected sites" → open the Sites
module under the same login; if Sites lists them, capture the `/pcm/v1/sites` response (Network
tab) and we debug from data; if Sites is also empty, the sites belong to a different user account.

## Steps

**Step 1 — Backend: merging PATCH for strategy config.** In `update_strategy`
(`strategy/controller.php`), when `config` is present: decode the EXISTING strategy's config, decode
the incoming object, shallow-merge (incoming wins per key), sanitize incoming keys with the same
whitelist/coercion used in `create_strategy` (extract that block into a small private helper used by
both), store the merged JSON. Put the merge itself in `PCM_Strategy_Service` (e.g.
`merge_strategy_config(?string $existing_json, array $incoming): array`) so it's unit-testable with
the established fakes pattern. Safe: zero existing callers send `config`. Files:
`strategy/controller.php`, `strategy/service.php`. Verify: unit tests (merge preserves untouched
keys; incoming overwrites; null/garbage existing JSON handled) + `php -l`. Status: `[done]` —
`PCM_Strategy_Service::merge_strategy_config()` is a pure function (no DB access) that decodes
existing JSON (empty array on null/empty/malformed input — `json_decode` failure degrades cleanly
rather than throwing) and `array_merge`s the sanitized incoming fields over it. Extracted
`sanitize_config_fields()` as a private controller helper shared by both `create_strategy` (merges
the result over full defaults — first-ever config) and `update_strategy` (merges the result over the
EXISTING stored config via the service method — partial update); uses `array_key_exists()` rather
than truthiness so a caller can explicitly null out a key, distinct from not mentioning it.
`update_strategy` now does one extra ownership-scoped `get_strategy()` read, but ONLY when `config`
is actually present in the request body (the common name/status/hierarchyMode/publishingMode-only
updates are unaffected). Verified: `php -l` clean on both files. Added 5 unit tests to
`tests/unit/StrategyAutoPublishTest.php` (preserves untouched keys including a nested object;
incoming overwrites matching keys; null/malformed/empty-string existing JSON all degrade to empty
config; empty incoming is a true no-op) → 67/67 isolated (155 assertions, up from 61/145); full
suite 185/185 (556 assertions, up from 179/546), same 1 pre-existing unrelated
`PlatformRoleInvariantTest` failure.

**Step 2 — Backend: expose published destination per item.** `PCM_DB::get_strategy_items()`:
LEFT JOIN `articles` on `articleId`, adding `a.publishedUrl AS articlePublishedUrl` and
`a.siteId AS articleSiteId` to the select. Callers only read existing fields (service reads
`status`/`articleId`/`keyword`/`position`), so additive columns are harmless — confirm by grep.
Update the test fakes only if a new test needs the fields. Files: `class-pcm-db.php`. Verify:
`php -l`, full suite green, real-WP smoke (`get_strategy` response shows the new fields for the
published test article). Status: `[done]` — grepped every `get_strategy_items()` call site (14
across `controller.php`/`service.php`); none select `a.*` or rely on positional/`SELECT *` column
order, all read named fields, so the additive JOIN is safe. Aliased BOTH new columns explicitly
(`articlePublishedUrl`/`articleSiteId`) to avoid a silent collision — `articles` also has its own
`id`/`status` columns, and `si.*` alongside an unaliased `a.*` would have clobbered
`strategy_items.status`/`.id` with the article's. No test-fake changes needed (existing fakes don't
model the JOIN and no current test reads the new fields). Verified: `php -l` clean. **Real-WP smoke
test** (this repo's plugin dir is symlinked into `~/Desktop/wordpress-local`): created a throwaway
strategy + item + article via `PCM_DB` directly, set the article's `publishedUrl`/`siteId`, called
`get_strategy_items()` and confirmed the returned object had `articlePublishedUrl`/`articleSiteId`
populated correctly AND `status`/`id` still reflected the STRATEGY ITEM's own values (not the
joined article's) — the exact collision risk this design guards against. Cleaned up the throwaway
rows afterward. Full suite still 185/185 (556 assertions), same 1 pre-existing unrelated failure.

**Step 3 — Frontend: Strategies page shows + edits the site (the core of the complaint).** In
`app/src/modules/Strategies/index.tsx`:
- Add `config?: string` (and parse it once per row) to the `Strategy` interface; fetch
  `trpc.sites.list` (same pattern as the dialog, `Array.isArray` unwrap like `Sites/index.tsx:87`).
- On each strategy row (header line, next to the status badge): an inline shadcn `Select` with the
  site list, value = parsed `config.siteId` — AutoPress `StrategyRow.tsx:216-227` is the reference.
  `onValueChange` → `strategy.update` mutation `{ id, config: { siteId } }` (Step 1 makes this a
  safe partial), toast + refetch. `stopPropagation` so it doesn't toggle expansion (same as the
  existing action buttons at `:273`). Empty list → a muted "No sites — connect one in Sites" item.
- Read-only context on the row: small text for `publishingMode` (already on the row data) so
  "Publish/Schedule/Draft" is visible without expanding.
- In the expanded item rows: when `articlePublishedUrl` (Step 2) is present, an external-link
  button opening it in a new tab — this is literally "the site in which it should be added".
Files: `app/src/modules/Strategies/index.tsx` only. Verify: `npm run check` (baseline 56 errors, 0
new), `vite build`. Status: `[done]` — added a `parseStrategyConfig()` helper (malformed/absent
JSON degrades to `{}`, never throws) computed once per row; `trpc.sites.list` fetched at the module
level (`Array.isArray` unwrap, matching `Sites/index.tsx`); a new `strategy.update` mutation wired
to a compact inline `Select` on every strategy row (`stopPropagation`'d so it doesn't toggle the
row's expand/collapse), `onValueChange` → `handleSiteChange` → `{id, config: {siteId}}` — a safe
partial thanks to Step 1's merge. Added a `PUBLISHING_MODE_LABELS` lookup rendered inline next to the
existing completed/failed/date meta text (no new row needed). In the expanded item list, a "View on
site" button (only rendered when `item.articlePublishedUrl` is set, from Step 2's JOIN) opens the
real published URL in a new tab via `window.open(..., '_blank', 'noopener,noreferrer')` — this is
literally the second half of the user's complaint ("not showing the site... it should be added").
Verified: `node node_modules/typescript/bin/tsc --noEmit` → exactly 56 errors (the documented
baseline), 0 new, and none in `Strategies/index.tsx` specifically (confirmed by grep). `vite build`
via the same non-`.bin` invocation → clean build, same asset shape as before (`index.css`/
`index-writer.js`/`chunks/browser.js`). **Browser visual verification not performed**: attempted to
open the local WP install's `wp-admin` (this repo is symlinked into `~/Desktop/wordpress-local`) via
the preview browser tools, but it requires a login and no stored admin credentials exist for that
install — per the standing rule against entering credentials, this is a genuine limitation, not a
skipped step; noting it rather than claiming visual proof I don't have. Step 6's live-site checklist
is where an actual logged-in look at this UI happens.

**Step 4 — Frontend: create-dialog guard parity for schedule mode.** Extend the existing
publish-mode guard (`CreateStrategyDialog.tsx:368-374`) to also cover `schedule`: when
`publishingMode` is `publish` OR `schedule` and no `siteId`, show the destructive hint AND disable
the Create button (today the hint shows for publish only and the button stays enabled). Files:
`CreateStrategyDialog.tsx`. Verify: tsc + build; manual: both modes block without a site, draft
mode doesn't. Status: `[done]` — both the hint condition and the Create-button `disabled` condition
now check `publishingMode === 'publish' || publishingMode === 'schedule'`; the hint text itself is
parameterized (`schedule`/`publish` wording) rather than hardcoded to "publish automatically" so it
reads correctly for either mode. Draft mode is untouched (condition is false for `'draft'`, exactly
as before). Verified via tsc (56/56 baseline, 0 new, none in this file) + `vite build` (clean).
**Manual click-through not performed** — same login-gate limitation as Step 3; the logic itself is a
direct, mechanical extension of the already-working publish-mode condition (same variables, same
operator), so risk is low, but this is a real gap in verification depth, not a false "verified"
claim. Step 6's live-site checklist covers it.

**Step 5 — Frontend: schedule-mode frequency + start-date pickers (closes the known gap from the
previous plan).** In `CreateStrategyDialog.tsx`, when `publishingMode === 'schedule'`, render two
controls that are currently missing (state exists — `frequency`/`startDate` at `:74-75` — but no
inputs are wired, so every scheduled strategy silently gets weekly-starting-now):
- **Frequency `Select`** — values MUST match the backend's `calculate_schedule_dates()` branches
  exactly (`strategy/service.php:110-148`): `all_once`, `daily`, `every_other_day`, `weekly`,
  `biweekly`, `monthly`. Label `biweekly` honestly as "Twice a week (~every 3 days)" — the backend
  faithfully ports AutoPress's quirk where "biweekly" advances +3 days, not +14; do not let the UI
  label promise fortnightly.
- **Start date `Input type="date"`** — emits `YYYY-MM-DD`, which the backend's `strtotime()` parses
  fine; empty stays valid (backend defaults to now, existing behavior).
Payload already carries both (`scheduleConfig` at `:137`) and the controller already sanitizes/stores
them (`strategy/controller.php:102-108`) — this is UI-only, zero backend change. Files:
`CreateStrategyDialog.tsx`. Verify: tsc + build; manual: create a scheduled strategy with `daily` +
a future date, then confirm via real-WP query that `strategy_items.scheduledDate` shows the daily
spread from that date (the backend unit tests for the spread already exist — 61-test file). Status:
`[done]` — added a `Select` (6 options, values matching `calculate_schedule_dates()`'s branches
verbatim, `biweekly` explicitly labeled "Twice a week (~every 3 days)" per the plan's own honesty
requirement) and a native `Input type="date"` for start date, both rendered only in Schedule Mode,
positioned right after the Publishing Mode/Approvals row. Verified: tsc (56/56 baseline, 0 new) +
`vite build` (clean). **Real-WP smoke test, end-to-end through the actual service layer** (not just
inspecting the JSX): called `PCM_Strategy_Service::create_from_keywords()` with a payload shaped
EXACTLY like the new UI now sends (`publishingMode: 'schedule'`, `config.scheduleConfig: {frequency:
'daily', startDate: '2026-08-01'}`, 3 keywords) against the local WP install (this repo's plugin dir
is symlinked in) and confirmed the 3 resulting items got `scheduledDate` = 2026-08-01, -02, -03 —
the exact daily spread, from the real start date, through the real code path. Cleaned up the
throwaway strategy afterward.

**Step 6 — Package, deploy, and VERIFY the deployment (not just ship the zip).** Rebuild `app/dist`
+ regenerate classmap + re-zip (same recipe as today's 18:10 build →
`~/Desktop/powercreatives/power-creatives.zip`). Then, after the user installs it on
create.widgetify.co, verify the live site actually runs the new build — via the claude-in-chrome
browser tools against the user's open wp-admin session (or, if the extension isn't connected, hand
the user this exact checklist):
1. Keywords → select keywords → Create Strategy: the dialog shows **Target Site** (populated, first
   site preselected) and, with "Schedule Mode" chosen, the new **Frequency + Start date** controls.
2. Publish/Schedule mode with no site selected → Create is blocked with the hint (Step 4).
3. Strategies page: every row shows the site select; changing it on the old "fifa world cup" row
   persists after a reload (proves the PATCH-merge path live, not just locally).
4. A published item row shows the "view on site" link (Step 2/3).
If check 1 fails, the old bundle is still active — re-check the plugin version/installation before
debugging any code. Status: `[done — packaging half; live-verify handed to user]` — regenerated the
composer classmap (`--classmap-authoritative`), confirmed `app/dist` already current from Steps 3-5's
own build, staged a clean production copy (same exclusion recipe as the earlier session: no
`node_modules`/`app/src`/`vendor`/`tests`/`.git`/dev docs/planning files), zipped as `power-creatives/`
(the correct slug, per the earlier zip's own fix). **Output:** `~/Desktop/powercreatives/
power-creatives.zip` (1.43 MB, 171 files). Verified the zip itself, not just the source: `php -l`
clean on all 4 touched PHP files inside the extracted zip; confirmed byte-identical hashes between
the staged copy and the live source for the touched files (rsync preserves mtimes, so a directory
listing alone looked "unchanged" — hash comparison is what actually proves freshness); grepped the
extracted `controller.php`/`service.php`/`class-pcm-db.php` for this session's new symbols
(`sanitize_config_fields`, `merge_strategy_config`, `articlePublishedUrl`) and confirmed all present;
grepped the minified `index-writer.js` for `handleSiteChange`/`articlePublishedUrl` and confirmed
present in the bundle. **The live-site half of this step (installing on create.widgetify.co and
running the 4-point checklist) is NOT something I can do myself** — I have no browser connection to
the user's actual hub in this session, only to a local WP install with no stored credentials (same
login-gate noted in Steps 3-4). Handing the checklist above to the user as the closing step.

**Done-gate: APPROVED.** Dispatched `spec-verifier` against all 6 steps combined (8 numbered checks:
`merge_strategy_config()`'s purity + null/malformed-JSON handling, `array_key_exists()` semantics,
ownership-scoped read before merge in `update_strategy`; the LEFT JOIN's aliasing/injection safety;
the frontend's partial-config send + `stopPropagation` + conditional "view on site" render; the
schedule-mode guard parity + exact frequency-value match against the backend + the honest `biweekly`
label; independently re-running `php -l`, the isolated test file, the full suite, and `tsc`; every
non-negotiable; and a working-tree sanity check confirming only the 6 described files were touched
and every claimed change is actually saved on disk). All 8 confirmed with file:line evidence,
independently re-running the isolated file (67/67, 155 assertions) and the full suite (185/185, 556
assertions, same 1 pre-existing unrelated failure) and `tsc` (56/56 baseline, 0 new) rather than
trusting the write-ups. Two P3/low, non-blocking observations, both already-intentional: the shallow
merge means a partial update to a nested object like `interlinksConfig` would replace it wholesale
rather than deep-merge (matches the plan's own stated "shallow-merge" design; the frontend only ever
sends `{siteId}` in practice); and pre-existing PHP 8.5 deprecation notices from an unrelated test
file (`ApprovalsLogicTest.php`) show up in full-suite output but don't affect pass/fail counts.

**This completes the "make the strategy ↔ site binding visible and reliable" plan.** All 6 steps are
done (5 fully verified — unit tests, real-WP smoke tests through the actual service layer, tsc,
build; Step 6's packaging half verified, live-site half hooked to the user's own install). Nothing
committed — standing constraint held throughout.

## Out of scope (deliberate)
- Porting AutoPress's full strategy table (inline prompt/frequency/approval editing per row) — only
  the SITE controls + publishing-mode visibility are in scope; the rest is a follow-up if wanted.
- Per-item site override (AutoPress items inherit the strategy site; ours do too).
- Retro-publishing already-completed articles when a site is set later (publish from Writer instead).
- Read-only site names for platform users lacking the `sites` module grant (product decision — ask).

## Verification standard
`php -l` on every touched PHP file; extend `tests/unit/StrategyAutoPublishTest.php` (process-isolated
fakes pattern) for Step 1's merge; full suite green (baseline 179/179 + 1 pre-existing unrelated
`PlatformRoleInvariantTest` failure, tracked separately); `npm run check` (56-error baseline, 0 new);
`vite build` via `node node_modules/vite/bin/vite.js build --config vite.config.wp.ts` (the `.bin`
shim is quarantine-blocked on this machine); real-WP smoke for Steps 1–2. Done-gate `spec-verifier`
once at the end (single-phase plan). Non-negotiables per CLAUDE.md apply (nonce+cap+sanitize+escape,
`$this->success()/error()`, `$wpdb->prepare()`, no `PCM_VERSION` bump).
