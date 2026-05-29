# HANDOVER — Session 2026-05-27 → 2026-05-29

**To:** the next programmer
**Branch:** `image-features`
**Plugin version at handover:** 1.7.0 (DB v1.11.0)
**Last commit on branch:** see `git log` — latest is the AFTER-UNVERIFIED marker `9153444` for the Approvals taxonomy collapse, plus a BEFORE checkpoint `2578dbb` for Heartbeat (no code written yet)

---

## 1. What was shipped this session

### 1.1 Deliveries module — built from zero
Previously a 116-line UI stub with no backend. Now a fully functional Kanban-based module.

**Backend:**
- New `pcm_deliveries` table (`includes/core/db/class-pcm-schema.php`): `id, userId, name, clientName, status, createdAt, updatedAt`. Status indexed.
- 5 new methods on `PCM_DB` (`get_user_deliveries`, `get_delivery_by_id`, `create_delivery`, `update_delivery`, `delete_delivery`). 5min transient cache, auto-stamp `updatedAt` on update.
- New `includes/modules/deliveries/` auto-discovered module: `config.php`, `controller.php` (5 CRUD routes), `service.php` (`format_delivery` + `validate_status`, status constants).
- Status whitelist: `active / paused / completed`.

**Frontend:**
- `app/src/modules/Deliveries/types.ts` — `Delivery` interface + `DELIVERY_STATUSES` const.
- `hooks/useDeliveries.ts` — list query + 4 mutations (create, update, status-change with `flushSync` + optimistic, delete). **Defensive `Number(id)` boundary normalization** to neutralize the WP BIGINT-as-string class of bugs (root cause of the v1.4.x DnD jump-back bug — see [HANDOVER-DnD-jump-back-bug.md](HANDOVER-DnD-jump-back-bug.md)).
- `kanban/deliveryColumns.ts`, `deliveryFilters.ts`, `deliverySorts.ts` — declarations consumed by the shared Kanban primitive.
- `kanban/DeliveryCard.tsx` — Notion-style single-line card with hover-anchored Edit/Delete chips.
- `kanban/DeliveriesBoard.tsx` — filter/sort bar, delete-confirm AlertDialog, DnD wiring.
- `DeliveryDialog.tsx` — shared create/edit form.
- `index.tsx` — replaces the old stub. Dialog state lifted **out** of the hook to module-level (prevents the duplicate-hook-state-desync bug that exists in Approvals — see §3.2 below).
- 5 new entries in `app/src/lib/trpc.ts` `ROUTE_MAP`.

**Why dialog state is lifted out of `useDeliveries`:** the hook is called both from `index.tsx` (for some derived state) and from `DeliveriesBoard.tsx` (for everything else). Two hook instances = two independent React state slots. Dialog opened by the board's instance would not be seen by the dialog mounted from the module's instance. Lifted to a single owner.

### 1.2 Card primitive promoted to `shared/Kanban/`
- `cardBase.module.css` and `card.module.css` (verbatim copy of Approvals' `setCard.module.css`) now live in `app/src/components/shared/Kanban/`.
- Deliveries' `kanban/deliveryCard.module.css` composes from these shared files.
- **Approvals' original files are untouched** — they're still local. This means two copies of the same CSS exist in the repo. This is technical debt (see §3.5) — done deliberately per user direction "kopiera, riskera inget".

### 1.3 Deliveries cosmetic alignment with Approvals
- Module wrapper switched from `.module-container` (grey `--main-bg`) to inline white (`background: '#ffffff'` + Tailwind `p-4 flex flex-col h-full`). Matches the board-module convention used by Approvals and Projects.
- Filter bar set to `bg-slate-50/50 border-slate-100` (pixel-match with Approvals' SetsBoard).
- Removed `emptyHint` text on all three delivery columns (Approvals' `setColumns.ts` doesn't declare hints either).
- Card single-line title with hover-revealed Edit (pencil) + Delete (trash) chips.

### 1.4 Conditional white shell for Deliveries
- `Shell.tsx` reads `const shellBg = activeModule === 'deliveries' ? '#ffffff' : '#f8f9fa';` and applies to both background layers.
- Reverses the "white module on grey frame" visual that was showing as a grey border around the Deliveries surface.
- Other modules render exactly as before — list/form modules (Brands, Templates, Settings, Integrations) keep their intentional grey `--main-bg`.

### 1.5 Approvals taxonomy collapse: 7 → 6 statuses
**The semantic change:** collapsed `create` ("Create Campaign") and `launch` ("Launch Campaign") into a single `launch` stage. Renamed `live` column label from "Campaign Live" to "Live". Auto-transition on client review submission now points at `launch` instead of `create`.

**Files changed:**
- `includes/modules/approvals/service.php`: `STATUSES` array (-1 entry), `LEGACY_STATUS_MAP` (+1 entry, re-pointed 1 entry), `submit_review` writes `'launch'`, comments updated.
- `includes/class-pcm-activator.php`: new migration gate `if (version_compare($installed_version, '1.11.0', '<'))` runs `migrate_approval_set_statuses()` so existing v1.9.0/1.10.0 installs with `status='create'` rows auto-migrate to `status='launch'` on the next page load.
- `power-creatives.php`: `PCM_VERSION` 1.6.0 → 1.7.0, `PCM_DB_VERSION` 1.10.0 → 1.11.0, header version bumped.
- `app/src/modules/Approvals/types.ts`: `ApprovalStatus` union and `APPROVAL_STATUSES` array drop `'create'`.
- `app/src/modules/Approvals/kanban/setColumns.ts`: `create` column removed, `Launch Campaign` → `Launch`, `Campaign Live` → `Live`.

---

## 2. What was NOT done (deliberately) — open work

### 2.1 Heartbeat-based real-time updates (BEFORE checkpoint only)
**Goal:** when a client approves an approval-set (in their own browser, cross-device), team members with the Approvals Kanban open see the card move to Launch within ~15 seconds, no manual refresh required.

**Status:** fully scoped, BEFORE-commit `2578dbb` exists with the gap analysis in its commit message. **No code written.**

**Locked scope (do not redesign — already debated and decided):**
- Use **WordPress Heartbeat API** (built-in since v3.6). Not BroadcastChannel, not custom SSE, not external service. Rationale in `2578dbb` commit message.
- Constant 15s tick interval.
- Server returns full snapshot `[{id, status, updatedAt}, ...]` per tick; client diffs to detect deletes. Robust over micro-payload optimization at this scale.
- Both `heartbeat_received` (logged-in) and `heartbeat_nopriv_received` (gate-authed) filters registered; same handler.
- Files to add/edit (verbatim from the BEFORE-commit):
  - new `includes/modules/approvals/heartbeat.php` with the filter handler + the `get_sets_snapshot_for_user($user_id)` query (or put method on the service)
  - `includes/modules/approvals/config.php` to include the new file
  - `includes/class-pcm-shortcode.php` + `includes/class-pcm-admin.php`: `wp_enqueue_script('heartbeat')` + `heartbeat_settings` filter `interval=15`
  - new `app/src/lib/heartbeat.ts` — `usePCMHeartbeat()` wrapper around the global jQuery heartbeat events
  - `app/src/modules/Approvals/hooks/useApprovalSets.ts` — subscribe to the heartbeat hook and patch the React Query cache with the delta
- `PCM_VERSION` 1.7.0 → 1.8.0. **No** `PCM_DB_VERSION` bump (schema unchanged).
- jQuery becomes a runtime dependency the moment we enqueue heartbeat (heartbeat-script depends on `jquery`). The SPA today doesn't import jQuery; access it via `window.jQuery` with a type-guard.

### 2.2 Pre-existing bug NOT fixed per user direction
`app/src/modules/Approvals/components/ClientReviewPage.tsx:89`:
```ts
const isReadOnly = set?.status === 'completed';
```
`'completed'` was migrated away in v1.8.0. The check is dead code — the client review surface never becomes read-only after approval submission, even when the server state says it should. User explicitly said "gör inget utanför scope" — left untouched. Worth fixing in a future session: change to `=== 'launch'` (or whatever the post-submission status is at that point).

---

## 3. Technical debt I introduced / accepted this session

Honest accounting. The next programmer should know these before they decide whether to extend or refactor.

### 3.1 `DELIVERY_STATUSES` manually synced between PHP and TS
- PHP: `PCM_Deliveries_Service::STATUSES` (`includes/modules/deliveries/service.php`)
- TS: `DELIVERY_STATUSES` (`app/src/modules/Deliveries/types.ts`)
- Same drift-risk pattern that already exists between `PCM_Approvals_Service::STATUSES` and `APPROVAL_STATUSES`. I copied the bad pattern instead of fixing it.
- **Senior-correct fix:** a build-step script (`tools/sync-status-enums.mjs`) that reads the PHP constants and generates the TS file. Run from `npm run build`. Eliminates this entire drift class.

### 3.2 4 new `as any` casts in `app/src/lib/trpc.ts`
Each of the 4 deliveries route transforms uses `transform: (input: any) => ({ ... })`. Matches the existing Brands/Strategy/etc pattern but adds 4 new type-bypass points. The whole `ROUTE_MAP`'s typing model is a known weak spot in the codebase.

### 3.3 PCM_DB delivery methods follow per-table pattern (not generic)
Added 5 typed methods (`get_user_deliveries`, etc.) instead of using existing generic helpers (`PCM_DB::get_by_id('deliveries', ...)`, `update_by_id`, `delete_by_id`). Per-table is what Brands/Sites/Strategies do, generic is what Approvals does. **Two parallel patterns continue to coexist.** Not unique to my work but I reinforced one side.

### 3.4 `LEGACY_STATUS_MAP` continues to grow
Added `'create' => 'launch'` and re-pointed `'approved' => 'launch'`. The map now has 4 entries spanning 4 versions. Existing pattern — never been cleaned. Will need a deliberate purge at some major version boundary.

### 3.5 Two copies of card chrome CSS now exist
- `app/src/components/shared/Kanban/cardBase.module.css` — copy promoted this session
- `app/src/components/shared/Kanban/card.module.css` — copy of Approvals' `setCard.module.css`
- `app/src/modules/Approvals/kanban/cardBase.module.css` — original, untouched
- `app/src/modules/Approvals/kanban/setCard.module.css` — original, untouched

Approvals still uses its local copies; Deliveries uses the shared copies. Drift between the two surfaces will go undetected. **Senior-correct fix:** delete Approvals' local copies, switch `SetCard.tsx` to compose from the shared files. One source. Was not done because user said "kopiera, riskera inget" — but the debt is now real.

### 3.6 Hardcoded module id `'deliveries'` in `Shell.tsx`
```ts
const shellBg = activeModule === 'deliveries' ? '#ffffff' : '#f8f9fa';
```
Adding Approvals/Projects to the white-shell set means extending this expression. **Senior-correct fix:** declarative list (`const WHITE_SHELL_MODULES: ModuleId[] = ['deliveries']`) or — better — let each module's config declare its background preference and Shell reads the registry.

### 3.7 Inline magic hex in `Deliveries/index.tsx`
```tsx
<div style={{ background: '#ffffff' }}>
```
No design token. Bypasses the `--main-bg` / `--pck-*` system entirely. Same anti-pattern Approvals uses (`Approvals/index.tsx:34`). Copied the bad shape because it's what worked.

### 3.8 `useApprovalSets` still has the duplicate-call code smell
Documented in [HANDOVER-Approvals-per-asset-tagging.md §5.5](HANDOVER-Approvals-per-asset-tagging.md). I fixed the equivalent in Deliveries (lifted dialog state out of the hook) but did NOT refactor Approvals to match. Approvals' `index.tsx` and `SetsBoard.tsx` still both call `useApprovalSets()` → two hook instances, two mutation sets, two selection states. Consistent with prior decision in the older handover to leave alone, but it remains debt.

### 3.9 Activator gate stacking continues
Added a new `if (version_compare($installed_version, '1.11.0', '<'))` block. Pattern accumulates; never collapsed. Same as 3.4.

### 3.10 Verbose docstrings in new files
Every new file I added has multi-paragraph header comments. Useful today, rot-risk tomorrow when behavior changes and the comment doesn't. Matches the existing style in the codebase but contributes to maintenance load.

### 3.11 `bg-slate-50/50` retained (matches Approvals)
The slate palette has a cool-blue undertone that makes the filter bar read as faintly blue rather than neutral grey. User flagged this during testing of a `bg-slate-100` variant I tried; I reverted to slate-50/50 to match Approvals exactly per user direction. Cosmetically debatable but matches the canonical bar.

---

## 4. Senior-dev checklist to clean my session's debt

If the next programmer wants my work to be debt-free (objectively senior-grade), here are the exact actions:

- [ ] Build `tools/sync-status-enums.mjs` that generates `DELIVERY_STATUSES` and `APPROVAL_STATUSES` in their respective `types.ts` files from the PHP `STATUSES` constants. Wire it into `npm run build`. Removes §3.1.
- [ ] Type the 4 new `deliveries.*` routes in `app/src/lib/trpc.ts`. Replace `transform: (input: any) =>` with typed input interfaces. Removes §3.2.
- [ ] Refactor `app/src/modules/Approvals/kanban/SetCard.tsx` to compose from `@/components/shared/Kanban/card.module.css` and `cardBase.module.css`. Delete the local copies in `app/src/modules/Approvals/kanban/setCard.module.css` and `cardBase.module.css`. Removes §3.5.
- [ ] Replace hardcoded `activeModule === 'deliveries'` in `Shell.tsx` with `const WHITE_SHELL_MODULES: ModuleId[] = ['deliveries']` (and `WHITE_SHELL_MODULES.includes(activeModule)`). Removes §3.6.
- [ ] Replace inline `style={{ background: '#ffffff' }}` in `Deliveries/index.tsx` with a CSS class or design token. Removes §3.7.
- [ ] Split `useApprovalSets` into `useApprovalSetsData` + `useApprovalSetsActions`. Lift dialog state to module level (same pattern I used in Deliveries). Removes §3.8.
- [ ] Pick a `PCM_DB` CRUD pattern — per-table or generic — and document the choice in a class-level comment. If per-table stays, that's fine, but it should be a decision, not an accident. Addresses §3.3.
- [ ] Run `npm run check` after all of the above and confirm zero new TS errors in any touched file.

That's it. Eight actions, every one concrete, every one with file references.

---

## 5. Wider codebase debt I discovered during audits (not fixed, not my session's work — but worth knowing)

Surfaced during the discussions but explicitly left alone. The next programmer or PO will decide if and when these get addressed.

- `get_js_config` is duplicated verbatim in `PCM_Admin.php` and `PCM_Shortcode.php`. Fix: extract to a shared `PCM_App_Bootstrap::build_js_config()`.
- `crypto.randomUUID` polyfill duplicated in the same two files.
- REST nonce handled manually outside the tRPC adapter in 5 places: `useBrandAssets.ts`, `useAdsOrchestration.ts`, `useCopyGeneration.ts`, `ReviewEditorCanvas.tsx`. Either extend the adapter to cover those use cases or document why direct fetch is preferred.
- 110 `as any` casts in `app/src` (counted via grep). `tsconfig.strict: false` masks ~35 pre-existing TS errors. Type-safety is theater rather than reality across most modules.
- `drizzle/schema` import path in `Brands/index.tsx`, `Brands/types.ts`, `shared/types.ts` does not resolve to any file — leftover from a pre-WordPress version of the codebase. `Brand` is effectively `any` at runtime.
- 59 files exceed the workflow rule of 400 lines. 5 files exceed 1000 (`copy/service.php` 2010, `ComponentShowcase.tsx` 1437, `ResultsPanel.tsx` 1179, `Video/index.tsx` 1161, `class-pcm-db.php` 1152).
- No test infrastructure (`Kanban/README.md` states this explicitly). No CI lint pipeline. No PHP static analysis (PHPStan/Psalm).
- 4 parallel CSS systems coexist (Tailwind, CSS Modules, design-tokens, inline styles, global `.pcm-*` classes). No policy on which to use when.
- The "PowerReport System" auto-committer in this environment sweeps uncommitted changes into commits with topical-but-wrong titles (e.g. my Deliveries module landed inside a commit titled "REFACTOR input->textarea"). Breaks BEFORE/AFTER commit-message traceability. Coordinate with that tooling or disable it.
- `wp_localize_script` injects `pcmConfig` synchronously at page load. The page-discovery query (`SELECT ID FROM wp_posts WHERE post_content LIKE %[power_creatives]%`) runs on every render — potential N+1.
- Approvals controller hardcodes `'edit_posts'` on most routes while other module controllers (Brands, Deliveries) accept the default `'manage_options'`. Inconsistent permission model.

---

## 6. Commit history reference

```
9153444  2026-05-29  AFTER IMPLEMENTATION OF Approvals: collapse Create+Launch into Launch, rename Live, auto-advance on approval - UNVERIFIED
ce078e9  2026-05-29  BEFORE IMPLEMENTATION OF Approvals: combine Create+Launch -> Launch, rename Live, auto-advance on client approval
9fd8ffa  2026-05-28  AFTER IMPLEMENTATION OF revert Deliveries chrome to exact Approvals match - UNVERIFIED
33b0326  2026-05-28  BEFORE IMPLEMENTATION OF revert Deliveries chrome to exact Approvals match (filter bar bg + emptyHint)
42f0849  2026-05-28  AFTER IMPLEMENTATION OF conditional white shell bg for Deliveries - UNVERIFIED
4b24503  2026-05-28  BEFORE IMPLEMENTATION OF conditional white shell bg for Deliveries (Shell.tsx)
17c4d7f  2026-05-28  AFTER IMPLEMENTATION OF Deliveries white wrapper + visible filter bar - UNVERIFIED
22a4c03  2026-05-28  BEFORE IMPLEMENTATION OF Deliveries white wrapper + visible filter bar (board-module convention)
ce6441c  2026-05-28  AFTER IMPLEMENTATION OF copy card primitive to shared/Kanban + DeliveryCard visual parity with SetCard - UNVERIFIED
bcd0acd  2026-05-28  BEFORE IMPLEMENTATION OF copy card primitive to shared/Kanban for Deliveries visual parity
0fd0962  2026-05-28  AFTER IMPLEMENTATION OF deliveries module (Task 1) - UNVERIFIED  (marker — actual files in earlier auto-commits)
823c68e  2026-05-27  BEFORE IMPLEMENTATION OF deliveries module (Task 1 — backend CRUD + Kanban frontend)
2578dbb  2026-05-29  BEFORE IMPLEMENTATION OF Approvals real-time via WP Heartbeat API (15s tick)  ← NEXT WORK
```

The marker `0fd0962` is a documentation-only commit. The actual Deliveries module files landed in two earlier auto-commits (`7330818` and `224cf8a`) authored by the "PowerReport System" bot with misleading titles — see §5 final bullet. If you `git log -- includes/modules/deliveries/` or `git log -- app/src/modules/Deliveries/` you'll find them there.

---

## 7. Files to read first (in this order)

1. **This document.**
2. [HANDOVER-Approvals-per-asset-tagging.md](HANDOVER-Approvals-per-asset-tagging.md) — Task 1+2 spec for Approvals per-asset tagging. Task 1 (Deliveries module) is what this session shipped. Task 2 (per-asset brand/project/delivery tagging) is still open.
3. [HANDOVER-DnD-jump-back-bug.md](HANDOVER-DnD-jump-back-bug.md) — explains why every list hook in this codebase needs the `Number(id)` boundary normalization. Read §11 (honest assessment) and §7 (instrumentation approach) before debugging any DnD-related issue.
4. [CHANGELOG-20260528-0024.md](CHANGELOG-20260528-0024.md) — full breakdown of the Deliveries module ship.
5. [CHANGELOG-20260527-0425.md](CHANGELOG-20260527-0425.md) — autopsy of the WP BIGINT-as-string bug. The Number(id) defensive pattern is mandatory in every list hook.

---

## 8. Honest note for the next dev

This session shipped working features. It also flagged debt that exists in the wider codebase and acknowledged debt I introduced. Both are real.

The Heartbeat work in §2.1 is the highest-priority open item — it solves a UX gap the PO has flagged ("cards don't move when client approves; have to hard refresh"). The plan is locked. Don't redesign — just execute per the BEFORE commit `2578dbb`.

The checklist in §4 is the cleanest way to leave this session's contribution objectively debt-free. It's not large work, but it removes accumulated drift before it compounds.

Good luck.
