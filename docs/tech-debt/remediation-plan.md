# Remediation Plan — Deliveries + Approvals Debt

**Created:** 2026-05-29
**Author:** Senior Dev (Claude)
**Companion doc:** `deliveries-approvals-debt.md` — the 15 confirmed items.
**Workflow:** Each item executes per `feedback_implementation_process.md` — BEFORE commit → code → AFTER-UNVERIFIED commit → manual test → AFTER-VERIFIED on user approval. One item at a time.

---

## Global principles applied throughout

1. **Modular boundary respect** — no item modifies tables, controllers, or data-models across more than one module's domain. Approvals work touches Approvals only; Deliveries work touches Deliveries only; cross-cutting work (Shell, shared/Kanban, trpc.ts) touches only the shared layer.
2. **No silent fallbacks** — any new defaults are documented in code.
3. **Reuse before creation** — existing primitives (shared/Kanban, design-tokens, `flushSync` pattern) are used; nothing reinvented.
4. **TS strictness preserved** — no new `as any`, every change typed.
5. **Version bumps** — `PCM_VERSION` bumps once at end of remediation series, not per item. `PCM_DB_VERSION` only if schema changes (none of these items change schema).

---

## Suggested execution order — with dependency rationale

| Step | Item | Priority reason                                                                 | Depends on |
| ---- | ---- | ------------------------------------------------------------------------------- | ---------- |
| 1    | #6   | Active bug — fix first, lowest risk, single line                                | none       |
| 2    | #9   | 1-line cleanup, validates the AFTER-UNVERIFIED → VERIFIED flow                  | none       |
| 3    | #7   | Dead-code deletion — clears noise before refactoring types.ts                   | none       |
| 4    | #3+#4| Combined: white-shell convention. Touch Shell.tsx + both index.tsx in one shot  | none       |
| 5    | #14  | Typed Deliveries routes in trpc.ts                                              | none       |
| 6    | #5   | Magic-string `'launch'` → constant in service.php                               | none       |
| 7    | #2   | CSS dedup — Approvals SetCard composes from shared                              | none       |
| 8    | #1   | Build script for PHP↔TS enum sync (touches the now-final types.ts)              | #7 done    |
| 9    | #10  | Service class static refactor (Deliveries) — fold validate_status semantics     | none       |
| 10   | #11  | Documentation only — `PCM_DB` class docblock declaring per-table is canonical   | #10 done   |
| 11   | #8   | Biggest refactor — split useApprovalSets. Schedule when other items are warm    | none       |
| 12   | #12  | Schedule (not now) — purge `LEGACY_STATUS_MAP` at v2.0 boundary                 | n/a        |
| 13   | #13  | Schedule (not now) — array-driven migration runner at v2.0                      | n/a        |
| 14   | #15  | Final `npm run check` — single pass after all of the above                      | all above  |

**Total session estimate:** ~6-8 working hours for items 1-11 across multiple sessions. Items 12 & 13 are deferred-by-design.

---

## Per-item plans

Each plan answers six senior-dev questions:
1. **Goal** (what success looks like, observable)
2. **Approach** (chosen solution, with the alternative I considered and rejected)
3. **Files touched**
4. **Exact change steps** (ordered, atomic)
5. **Risks + mitigation**
6. **Verification** (manual + automated)

---

### Item #6 — ClientReviewPage dead status check (active bug)

1. **Goal:** After client submits review, the page becomes read-only on every subsequent visit. Today it stays editable forever because `'completed'` is unreachable.
2. **Approach:** Replace the dead literal with a typed inclusion check against the post-submission state set. Declare a named constant for the post-submission statuses (semantic, not positional).
   - **Rejected alternative:** `set?.status !== 'draft' && set?.status !== 'client'`. Negative logic is harder to reason about and leaks the assumption that no future status sits *before* 'client' in the lifecycle. Inclusion against an explicit list of post-submit states is safer.
3. **Files touched:**
   - `app/src/modules/Approvals/components/ClientReviewPage.tsx` (1-line semantic change + 1 named constant)
4. **Change steps:**
   1. Add `import { type ApprovalStatus } from '../types';` (if not already imported).
   2. Above the component declaration, add:
      ```ts
      const POST_SUBMIT_STATUSES: ReadonlyArray<ApprovalStatus> = ['launch', 'live', 'archived'];
      ```
   3. Replace line 90: `const isReadOnly = set ? POST_SUBMIT_STATUSES.includes(set.status) : false;`
5. **Risks + mitigation:**
   - **Risk:** `isReadOnly` now flips true for sets whose status='internal' or 'client' that the team manually moved into post-submit territory via Kanban DnD. **Mitigation:** That's the *intended* behaviour — if team moves a set to 'launch', client-facing link should reflect that.
   - **Risk:** Status comparison against TS const list when server may return mixed case. **Mitigation:** Approvals service stores statuses lowercase consistently; no case normalization needed.
6. **Verification:**
   - **Manual:** Open `/wp-admin/...?localwp_auto_login=1` → Approvals → create new set → open client link in incognito → leave a comment → submit. Re-open the same link → confirm the toolbar is disabled and approve buttons are inert. Re-open the link before submitting → confirm interactive.
   - **Automated:** `npm run check` — zero new errors on ClientReviewPage.tsx.

---

### Item #9 — useDeliveries double `useMutation` on same route

1. **Goal:** One `useMutation` per route; `updateDelivery` and `updateStatus` share it.
2. **Approach:** Delete the redundant `statusMutation` instance and route `updateStatus` through `updateMutation`. The two callers pass different payload shapes, but the backend accepts both (PATCH with partial body — `{id, status}` is a subset of `{id, ...fields}`).
   - **Rejected alternative:** Add a dedicated `deliveries.updateStatus` route in trpc.ts mapping to a PHP endpoint like `PATCH /deliveries/{id}/status`. Premature — there's no observable behaviour difference; one route is fine.
3. **Files touched:**
   - `app/src/modules/Deliveries/hooks/useDeliveries.ts` (delete line 120, swap reference on line 184)
4. **Change steps:**
   1. Delete line 120 (`const statusMutation = trpc.deliveries.update.useMutation();`).
   2. In `updateStatus` (line 184): change `statusMutation.mutateAsync(...)` to `updateMutation.mutateAsync(...)`.
   3. Update useCallback dependency array on line 199: `[queryClient, statusMutation]` → `[queryClient, updateMutation]`.
5. **Risks + mitigation:**
   - **Risk:** A future `updateMutation` change that breaks one caller could now break both. **Mitigation:** Same route, same backend, same wire format — the caller-specific concern is the payload shape, which TS will surface at the `mutateAsync({...})` call site.
6. **Verification:**
   - **Manual:** Drag a delivery card between columns → confirm move sticks after release (no snap-back). Edit delivery in dialog → confirm save persists. Both gestures use the same mutation now.
   - **Automated:** `npm run check` clean.

---

### Item #7 — `trpc.articles.*` legacy aliases — dead routes deletion

1. **Goal:** Delete 4 unused route aliases and the orphan `Article` interface. Codebase has nothing referencing them.
2. **Approach:** Two-step safety verification before deletion → then deletion.
   - **Rejected alternative:** Leave as harmless dead code. Rejected because every dead route is a future false trail for someone debugging the Approvals module.
3. **Files touched:**
   - `app/src/lib/trpc.ts` (delete the Articles section, lines 288–304)
   - `app/src/modules/Approvals/types.ts` (delete `Article` interface lines 99–109)
   - Possibly: `includes/modules/articles/` or PHP REST endpoints serving `/articles` — needs grep.
4. **Change steps:**
   1. **Pre-deletion verification grep #1** — TS side: `grep -r "from.*Approvals/types" app/src/ | xargs grep -l "Article[^\w]"` → confirm no consumer.
   2. **Pre-deletion verification grep #2** — PHP side: `grep -r "articles" includes/modules/` → if PHP REST endpoints exist, decide whether they also get deleted or stay (the latter is fine; backend liveness ≠ frontend usage).
   3. Delete lines 288-304 in `app/src/lib/trpc.ts`.
   4. Delete `Article` interface in `app/src/modules/Approvals/types.ts` lines 99-109.
   5. Remove unused import of `StatusKey` in types.ts if it was only used by `Article`.
5. **Risks + mitigation:**
   - **Risk:** Surprise consumer found in step 1 or 2. **Mitigation:** If found, escalate: is it a live feature or another dead path? Decision before deletion. Plan stops here if found.
   - **Risk:** PHP backend still serves `/articles` and another (non-frontend) consumer uses it. **Mitigation:** Only delete frontend; document PHP routes as still-live but unused-by-SPA.
6. **Verification:**
   - **Manual:** Open every module of the SPA, confirm no console errors.
   - **Automated:** `npm run check` clean. `npm run build` succeeds.

---

### Item #3 + Item #4 — White-shell convention (combined)

1. **Goal:** Board modules opt into the white shell via one declarative decision point. No inline hex literals on entry files.
2. **Approach:** Single declarative list in Shell.tsx + Tailwind `bg-white` classes on entry files (no new CSS file).
   - **Rejected alternative #1:** New `--pcm-board-bg` CSS variable in tokens.css. Overkill for a binary choice; tokens.css is for compositional design primitives, not module-level on/off flags.
   - **Rejected alternative #2:** PHP-side preference per module config. Out of scope — Shell.tsx already lives in TS; round-tripping through `pcmConfig` is needless plumbing.
3. **Files touched:**
   - `app/src/components/layout/Shell.tsx` (declarative list)
   - `app/src/modules/Deliveries/index.tsx` (remove inline hex)
   - `app/src/modules/Approvals/index.tsx` (remove inline hex)
4. **Change steps:**
   1. In `Shell.tsx`, above the component:
      ```ts
      const WHITE_SHELL_MODULES: ReadonlyArray<ModuleId> = ['deliveries', 'approvals'];
      ```
      (Approvals goes in because its module entry has the same inline white. We commit to the white shell for both at once.)
   2. Replace line 71:
      ```ts
      const shellBg = WHITE_SHELL_MODULES.includes(activeModule) ? '#ffffff' : '#f8f9fa';
      ```
   3. In `Deliveries/index.tsx` line 62-65: remove `style={{ background: '#ffffff' }}` from the outer `<div>`. Change `className` to include `bg-white`.
   4. In `Approvals/index.tsx` line 34: same — remove inline style, add `bg-white` to className.
5. **Risks + mitigation:**
   - **Risk:** `ModuleId` type may not be importable in Shell.tsx context. **Mitigation:** Already imported there per the existing code reading `activeModule: ModuleId`. Confirmed at read time.
   - **Risk:** Tailwind `bg-white` may render differently than literal `#ffffff` due to tailwind preflight. **Mitigation:** Tailwind `bg-white` is `rgb(255 255 255)` = `#ffffff`. Identical.
   - **Risk:** Shell still uses inline `style={{ background: shellBg }}` — that's *intentional* because shellBg is a runtime value. We're not refactoring Shell's own use of inline style here, only the module entries.
6. **Verification:**
   - **Manual:** Switch to Deliveries and Approvals modules in admin SPA — confirm white shell. Switch to Brands/Templates/Settings — confirm grey shell. No visual regression.
   - **Automated:** `npm run check` clean. `npm run build` succeeds.

---

### Item #14 — Typed input interfaces for Deliveries routes in trpc.ts

1. **Goal:** Replace 3 `(input: any)` transforms with typed interfaces. No new `any` from this session's surface.
2. **Approach:** Define narrow input interfaces locally in trpc.ts for the 3 routes. The interface set is small (id-only / id+body) and these are the same shapes Brands/Sites use. Could be extracted to a shared `IdOnly` / `IdWithBody` helper.
   - **Rejected alternative:** Refactor the entire ROUTE_MAP typing model. Too large for this session; scope creep. The 3-cast fix is the minimum that closes the regression.
3. **Files touched:**
   - `app/src/lib/trpc.ts` (3 transform signatures, plus 2 small interface declarations near the Deliveries section)
4. **Change steps:**
   1. Just above the Deliveries section (around line 224), declare:
      ```ts
      interface DeliveryIdInput { id: number; }
      interface DeliveryUpdateInput extends DeliveryIdInput {
        name?: string;
        clientName?: string | null;
        status?: 'active' | 'paused' | 'completed';
      }
      ```
   2. Line 230 — `deliveries.getById` transform signature: `(input: any)` → `(input: DeliveryIdInput)`.
   3. Line 234 — `deliveries.update` transform signature: `(input: any)` → `(input: DeliveryUpdateInput)`.
   4. Line 240 — `deliveries.delete` transform signature: `(input: any)` → `(input: DeliveryIdInput)`.
5. **Risks + mitigation:**
   - **Risk:** `useDeliveries` may call these with shapes that don't match. **Mitigation:** `useDeliveries.ts` already declares `UpdateDeliveryInput` matching the same fields. TS will surface any drift at the call site.
   - **Risk:** Inline string-union for status duplicates `DELIVERY_STATUSES` source-of-truth. **Mitigation:** This is intentional for now — item #1 (sync script) will eventually generate this type from PHP. Order matters: do #14 with inline union, then #1 makes it generated.
6. **Verification:**
   - **Manual:** Same as #9 — DnD a delivery, edit a delivery. Both gestures still work.
   - **Automated:** `npm run check` clean. Should zero out the 3 newest `any`-casts.

---

### Item #5 — Magic string `'launch'` → named constant

1. **Goal:** The post-client-review status is a named constant, looked up at the call site, not a literal.
2. **Approach:** Add `POST_CLIENT_REVIEW_STATUS` class constant to `PCM_Approvals_Service`. Reference it in `submit_review`.
   - **Rejected alternative:** `self::STATUSES[3]` — positional lookup is more fragile than a named constant. The semantic meaning is "campaign-build-prep stage", not "fourth column".
3. **Files touched:**
   - `includes/modules/approvals/service.php` (add constant + 1 line in `submit_review`)
4. **Change steps:**
   1. Add new class constant below `LEGACY_STATUS_MAP`:
      ```php
      /**
       * Status auto-applied when a client submits their review. Held as a
       * named constant rather than a literal so taxonomy changes only touch
       * one location instead of grep-hunting through service methods.
       *
       * Must be one of self::STATUSES.
       */
      public const POST_CLIENT_REVIEW_STATUS = 'launch';
      ```
   2. Line 264 — change `'status' => 'launch',` to `'status' => self::POST_CLIENT_REVIEW_STATUS,`.
   3. Update the adjacent comment block (lines 256-259) to point at the new constant for history.
5. **Risks + mitigation:**
   - **Risk:** Forgotten places still hardcode `'launch'`. **Mitigation:** Grep `\'launch\'` across `includes/modules/approvals/` after the change — confirm only the constant declaration and migration map reference it.
6. **Verification:**
   - **Manual:** Submit a client review via the public review page → confirm Kanban card moves to the Launch column.
   - **Automated:** `php -l includes/modules/approvals/service.php` (lint).

---

### Item #2 — CSS dedup: Approvals SetCard composes from shared

1. **Goal:** One source of truth for kanban card chrome. `SetCard.tsx` consumes the shared CSS via `composes:`, same pattern as `DeliveryCard`. Local duplicates deleted.
2. **Approach:** Rewrite `modules/Approvals/kanban/setCard.module.css` to be a composes-only thin shim (matching Deliveries' `deliveryCard.module.css`). Delete `modules/Approvals/kanban/cardBase.module.css` entirely (not referenced after dedup). `SetCard.tsx` keeps its import unchanged because the file is still at the same path — only the file contents change.
   - **Rejected alternative #1:** Change `SetCard.tsx` to import directly from `@/components/shared/Kanban/card.module.css`. Avoidable churn — keeping a thin shim per-module is the pattern Deliveries already established and allows per-module overrides later.
   - **Rejected alternative #2:** Delete the local files AND change SetCard's import path. Two changes when one would do.
3. **Files touched:**
   - `app/src/modules/Approvals/kanban/setCard.module.css` (rewritten as composes-only shim)
   - `app/src/modules/Approvals/kanban/cardBase.module.css` (deleted)
4. **Change steps:**
   1. Read `app/src/modules/Deliveries/kanban/deliveryCard.module.css` for the shim pattern. Mirror it.
   2. Identify every class used by `SetCard.tsx`: `card`, `checkbox`, `checkboxIcon`, `title`, `titleSep`, `actions`, `actionBtn`, `actionBtnDanger`, `actionIcon`.
   3. Rewrite `setCard.module.css` so each class is a single `composes: <name> from '@/components/shared/Kanban/card.module.css';` rule.
   4. Verify shared `card.module.css` exposes all those class names (read the shared file to confirm).
   5. Delete `cardBase.module.css` in Approvals (the shared variant exists at `shared/Kanban/cardBase.module.css` and is what the shared card.module.css already composes from).
5. **Risks + mitigation:**
   - **Risk:** Shared `card.module.css` is missing one of the 9 class names. **Mitigation:** Verify at step 4 before deletion; if missing, add to shared file as part of this same task (it should be there — they're verbatim duplicates).
   - **Risk:** `:hover` and `[data-selected]` selectors don't compose through CSS Modules cleanly. **Mitigation:** They do — CSS Modules `composes:` brings rules and pseudo/attribute selectors together. Already proven by `DeliveryCard` using the same pattern.
   - **Risk:** Visual regression. **Mitigation:** Manual side-by-side comparison.
6. **Verification:**
   - **Manual:** Open Approvals board → compare hover/selected/drag states pixel-by-pixel against the pre-change screenshot (capture before the BEFORE commit). Same with Deliveries board to confirm no cross-board regression.
   - **Automated:** `npm run build` — confirm CSS Modules don't fail to resolve.

---

### Item #1 — Build script for PHP↔TS enum sync

1. **Goal:** `DELIVERY_STATUSES` and `APPROVAL_STATUSES` are generated from PHP at build time. Single source of truth (PHP). Drift is impossible — anyone changing PHP constants gets the TS array regenerated automatically.
2. **Approach:** Node script (`tools/sync-status-enums.mjs`) that regex-parses two specific PHP files for the `STATUSES` constant and writes a generated TS file per module. The current `types.ts` files split — one becomes `types.ts` (manual: interfaces + re-exports) and one becomes `types.generated.ts` (auto: enums). Hook the script into npm `prebuild` so `npm run build` runs it automatically. Commit the generated files for cold-checkout DX.
   - **Rejected alternative #1:** Shared JSON source-of-truth. More plumbing; PHP-as-source matches what the comments already say.
   - **Rejected alternative #2:** Runtime sync from server. The status set is consumed by TS types (compile-time), not runtime; runtime sync doesn't solve drift.
3. **Files touched (NEW):**
   - `tools/sync-status-enums.mjs` (new script)
   - `app/src/modules/Deliveries/types.generated.ts` (new, committed, auto-generated)
   - `app/src/modules/Approvals/types.generated.ts` (new, committed, auto-generated)
4. **Files touched (MODIFIED):**
   - `app/src/modules/Deliveries/types.ts` (remove the enum, re-export from `.generated.ts`)
   - `app/src/modules/Approvals/types.ts` (same — remove enum, re-export)
   - `app/package.json` (add `prebuild` script + `sync-enums` script)
   - `app/public/wp-content/plugins/power-creatives/.gitignore` — explicitly DO NOT add the generated files; they must be committed.
5. **Change steps:**
   1. Write `tools/sync-status-enums.mjs`. The regex: `/public\s+const\s+STATUSES\s*=\s*\[([^\]]+)\]\s*;/`. Parse the array body into a JS array of strings. Render TS with a banner:
      ```ts
      // ⚠️ AUTO-GENERATED — DO NOT EDIT.
      // Source: includes/modules/deliveries/service.php :: PCM_Deliveries_Service::STATUSES
      // Regenerate: `npm run sync-enums` (auto-runs on prebuild).
      export const DELIVERY_STATUSES = ['active', 'paused', 'completed'] as const;
      export type DeliveryStatus = (typeof DELIVERY_STATUSES)[number];
      ```
   2. Test locally: `node tools/sync-status-enums.mjs` — should produce the two .generated.ts files matching current contents byte-for-byte (sanity check that we don't accidentally regress).
   3. Rewrite `Deliveries/types.ts`:
      ```ts
      export { DELIVERY_STATUSES, type DeliveryStatus } from './types.generated';
      export interface Delivery { /* unchanged */ }
      ```
   4. Same for `Approvals/types.ts`.
   5. Add to `app/package.json`:
      ```json
      "scripts": {
        "sync-enums": "node ../tools/sync-status-enums.mjs",
        "prebuild": "npm run sync-enums",
        ...
      }
      ```
   6. Run `npm run build` once — confirm prebuild fires + build succeeds.
6. **Risks + mitigation:**
   - **Risk:** Regex too brittle (multi-line array, trailing comma, comments inside array). **Mitigation:** Both target arrays are simple single-line declarations. Add a guard: script errors if it can't find the regex match (no silent fallback).
   - **Risk:** Generated file conflicts with current handwritten file. **Mitigation:** Run script first, diff against handwritten — should be byte-identical (modulo the auto-gen banner).
   - **Risk:** `prebuild` doesn't run when dev mode (`vite`) starts. **Mitigation:** Document: PHP changes require `npm run sync-enums` manually OR a rebuild. Add a watchscript later if pain becomes real.
7. **Verification:**
   - **Manual:** Make a temporary trivial change to `PCM_Deliveries_Service::STATUSES` in PHP (add a fake 'draft' value) → run `npm run sync-enums` → diff `Deliveries/types.generated.ts` shows the new value. Revert.
   - **Automated:** `npm run build` runs prebuild → build succeeds → no TS errors.

---

### Item #10 — Service-class pattern unification (Deliveries → static)

1. **Goal:** `PCM_Deliveries_Service` matches `PCM_Approvals_Service` — all-static methods on a no-state class. One convention across modules.
2. **Approach:** Convert `format_delivery` and `validate_status` to `public static function`. Remove the `$service` property + constructor from `PCM_REST_Deliveries`. Update the 4 call sites in the controller from `$this->service->X()` to `PCM_Deliveries_Service::X()`.
   - **Rejected alternative:** Convert Approvals to instance. Approvals is the canonical pattern (10/10 already static, mirrored in Brands/Sites). Going the other way would inflate the change set without value.
3. **Files touched:**
   - `includes/modules/deliveries/service.php` (2 methods static)
   - `includes/modules/deliveries/controller.php` (remove instance, update call sites)
4. **Change steps:**
   1. In `service.php`: change line 51 `public function format_delivery(...)` → `public static function format_delivery(...)`.
   2. Same line 73 `public function validate_status(...)` → `public static function validate_status(...)`.
   3. In `controller.php`: delete lines 32 (`private PCM_Deliveries_Service $service;`), 34-37 (constructor).
   4. Replace `array($this->service, 'format_delivery')` (line 65) with `[PCM_Deliveries_Service::class, 'format_delivery']` (static method as callable).
   5. Replace each `$this->service->format_delivery($row)` (lines 78, 130, 178) with `PCM_Deliveries_Service::format_delivery($row)`.
   6. Replace each `$this->service->validate_status($status_input)` (lines 101, 162) with `PCM_Deliveries_Service::validate_status($status_input)`.
5. **Risks + mitigation:**
   - **Risk:** `PCM_REST_Base` (parent) does something with `$this->service`. **Mitigation:** Quick read of `PCM_REST_Base` first to confirm it doesn't. If it does, adjust.
   - **Risk:** PHP < 8.1 syntax issue with `[Class::class, 'method']` callable. **Mitigation:** PHP 8.1+ is required by the plugin per `project_power_creatives` memory; safe.
6. **Verification:**
   - **Manual:** CRUD a delivery — create, edit, status change via DnD, delete. All flows pass.
   - **Automated:** `php -l` on both files. `npm run build` (frontend untouched, sanity).

---

### Item #11 — `PCM_DB` per-table pattern documentation

1. **Goal:** Future contributors know the chosen DB-access pattern from one docblock at the top of `class-pcm-db.php`. Zero behaviour change.
2. **Approach:** Add a class-level docblock declaring per-table methods as canonical, generic methods as legacy-style retained for backward compat. No code refactor — just decision-documentation.
   - **Rejected alternative:** Refactor all generic call sites (Approvals) to per-table methods. Cross-cuts modules without business value; out of scope.
3. **Files touched:**
   - `includes/core/db/class-pcm-db.php` (add docblock at class declaration)
4. **Change steps:**
   1. Above the `class PCM_DB` declaration, add:
      ```php
      /**
       * Database access layer.
       *
       * Pattern: Per-table typed methods are canonical. Each new module
       * gets a dedicated section (see DELIVERIES, BRANDS, SITES) with
       * `get_user_X`, `get_X_by_id`, `create_X`, `update_X`, `delete_X`.
       *
       * Generic methods (`get_by_id`, `update_by_id`, `delete_by_id`) are
       * retained for the Approvals module's legacy call sites and as a
       * fallback for one-off queries — but new modules MUST use per-table.
       *
       * Why per-table: lets each module's signature carry domain-specific
       * cache invalidation (e.g. `update_delivery` auto-stamps updatedAt
       * + invalidates the deliveries cache key). Generic methods can't.
       */
      ```
5. **Risks + mitigation:** None (documentation-only).
6. **Verification:**
   - **Manual:** None needed.
   - **Automated:** `php -l` for syntax safety.

---

### Item #8 — Split `useApprovalSets` into Data + Actions hooks

1. **Goal:** Two focused hooks. `useApprovalSetsData` for read-only consumers (Approvals/index.tsx splash check); `useApprovalSetsActions` for the Board's mutations. Dialog state + selection state become local React state on `SetsBoard.tsx` (one owner, no duplicate-mount).
2. **Approach:** Two-phase refactor — additive first, then deprecation.
   - **Phase A:** Add new hooks alongside the existing one. Migrate consumers. Existing `useApprovalSets` stays intact.
   - **Phase B:** Remove the old `useApprovalSets`. Confirm clean.
   - **Rejected alternative:** Big-bang rewrite in one PR. Too risky given SetsBoard.tsx destructures 16 fields. Phasing isolates rollback surface.
3. **Files touched (NEW):**
   - `app/src/modules/Approvals/hooks/useApprovalSetsData.ts`
   - `app/src/modules/Approvals/hooks/useApprovalSetsActions.ts`
4. **Files touched (MODIFIED):**
   - `app/src/modules/Approvals/kanban/SetsBoard.tsx` (consumer swap + local state for dialog/selection)
   - `app/src/modules/Approvals/index.tsx` (consumer swap)
   - `app/src/modules/Approvals/hooks/useApprovalSets.ts` (deleted at end of Phase B)
5. **Change steps:**
   - **Phase A:**
   1. Create `useApprovalSetsData.ts`: extracts the `trpc.approvals.listSets.useQuery()` + boundary normalization + `countsByStatus` + `getPublicBoardUrl` + `copyShareLink`. Returns: `{ sets, isLoading, error, refetch, countsByStatus, getPublicBoardUrl, copyShareLink }`.
   2. Create `useApprovalSetsActions.ts`: extracts the 3 mutations (`updateStatus`, `deleteSet`, `bulkDeleteSets`) + their `flushSync` patterns. Returns: `{ updateStatus, deleteSet, bulkDeleteSets }`.
   3. Update `Approvals/index.tsx` (line 20) to consume `useApprovalSetsData()`.
   4. Update `SetsBoard.tsx`:
      - Consume both new hooks.
      - Add local `useState` for `feedbackSet`, `previewSet`, `selectedIds`.
      - Lift the open/close handlers locally.
   5. Run `npm run check` and manual test — confirm full parity.
   - **Phase B:**
   6. Delete `useApprovalSets.ts`.
   7. Run `npm run check` and manual test again.
6. **Risks + mitigation:**
   - **Risk:** Splash check in `Approvals/index.tsx` regresses (different render due to different cache key shape). **Mitigation:** Both old and new hooks use the same `trpc.approvals.listSets.useQuery()` call → identical TanStack cache key. Verified at code-read time.
   - **Risk:** `SetsBoard` selection state interacts with `SetCard` props in surprising ways. **Mitigation:** Selection state is consumed via props (`isSelected, selectMode`) — swap source from hook to local state without changing the prop interface.
   - **Risk:** DnD flushSync timing breaks when `updateStatus` moves into a new module. **Mitigation:** Copy the `flushSync` pattern verbatim. Behavioural test — drag a card, confirm no snap-back.
7. **Verification:**
   - **Manual:** Full Approvals smoke test — load board, drag a card across columns, open feedback dialog, open preview, multi-select + bulk delete, single delete with optimistic rollback simulation (turn off network → drag → confirm rollback). Splash screen on cold load.
   - **Automated:** `npm run check` clean.

---

### Item #12 — `LEGACY_STATUS_MAP` purge (scheduled, NOT now)

1. **Goal:** At v2.0, install base is guaranteed to have run all v1.x migrations once. Map can be emptied; ordering fragility disappears.
2. **Approach:** Defer to v2.0. Add a single-line guard NOW (low-risk) to lock the array order against accidental reordering during v1.x:
   ```php
   // Locked iteration order — see comment block above.
   assert(array_keys(self::LEGACY_STATUS_MAP) === ['review', 'completed', 'approved', 'create']);
   ```
   But assert() in production WordPress is typically disabled. Better: explicit Test in unit suite. But there's no test infra (per handover §5).
   - **Better v1.x guard:** A header docblock note on the map explicitly listing the required order, plus a comment at every migration call site cautioning. Documentation, not enforcement.
3. **Decision:** Skip both — accept current docblock as sufficient for v1.x. **Schedule purge at v2.0** as a tagged backlog item.
4. **Files touched:** none now. Add a row to `docs/backlog.md` (v2.0 section) noting the cleanup.
5. **Risks:** none (documentation-only deferral).
6. **Verification:** none needed now.

---

### Item #13 — Activator gate runner (scheduled, NOT now)

1. **Goal:** At v2.0, replace the stacking `if` blocks with a versioned migration array + generic runner.
2. **Approach:** Same as #12 — defer to v2.0. Two gates today (1.9.0, 1.11.0) is below the pain threshold.
3. **Decision:** Schedule. Add a row to `docs/backlog.md` (v2.0 section).
4. **Files touched:** none now.
5. **Risks:** none.
6. **Verification:** none.

---

### Item #15 — `npm run check` final pass

1. **Goal:** Zero new TS errors introduced by any of items #1–#11 against the pre-remediation baseline.
2. **Approach:** Run after every item AND once at the end. Capture before/after error counts to confirm no regressions on untouched files either.
3. **Steps:**
   1. Before item #1 starts: `npm run check 2>&1 | tee docs/tech-debt/check-baseline.log` — capture baseline error count.
   2. After each item's AFTER-UNVERIFIED commit: `npm run check` — confirm no new errors *on files touched by that item*.
   3. After all items complete: `npm run check 2>&1 | tee docs/tech-debt/check-final.log` — diff against baseline.
4. **Verification:** Final delta should be ≥0 errors-removed (item #14 should remove 3 `any`-cast warnings; item #6 should not add anything; item #8 should not add anything).

---

## Rollback strategy

Every item gets a BEFORE commit → code → AFTER-UNVERIFIED commit. If user testing finds a regression at the UNVERIFIED stage:

1. Identify the issue from the failing test.
2. Decide: fix-forward (small adjustment in the same code block) or revert (`git reset --hard <BEFORE-commit-hash>`).
3. If reverting: re-plan that item before re-attempting. Never re-attempt the same approach blindly.

The per-item granularity means a regression in item #8 doesn't force a rollback of items #1–#7.

---

## What is explicitly NOT in this plan

- No version bump until all items 1–11 are AFTER-VERIFIED. Then one bump (`PCM_VERSION` 1.7.0 → 1.8.0; no `PCM_DB_VERSION` bump since no schema changes).
- No `wider codebase debt` from HANDOVER §5 (drizzle paths, 110+ as-any casts elsewhere, file-size violators, etc).
- No new tests / test infra (project has none today per handover §5; not scope-creeping into building it).
- No changes to `pcmConfig` payload shape.
- No changes to PHP REST endpoints (URL paths stay identical).

---

## Sign-off

Plan is complete and ready for execution. Each item has a stated goal, an approach with an explicitly rejected alternative, a precise file list, ordered atomic steps, identified risks with mitigations, and a verification gate.

**On user approval:** I start with item #6. One item at a time. BEFORE commit → code → AFTER-UNVERIFIED → user verifies → AFTER-VERIFIED.
