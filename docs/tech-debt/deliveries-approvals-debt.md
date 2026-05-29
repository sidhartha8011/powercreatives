# Tech Debt — Deliveries + Approvals Flow

**Created:** 2026-05-29
**Verified:** 2026-05-29
**Scope:** Strictly tech debt introduced by or touching the Deliveries module and Approvals module work shipped 2026-05-27 → 2026-05-29. Wider codebase debt is out of scope (see HANDOVER-20260529-Session.md §5).
**Methodology:** Each item lists its claimed location. Status starts as `[ ] PENDING VERIFICATION`. After re-reading the cited code, status is updated to `[x] CONFIRMED` with a verbatim quote, or `[!] NOT FOUND` with notes.

---

## Verification status legend

- `[ ] PENDING` — claim made, not yet re-verified against current code
- `[x] CONFIRMED` — claim verified against current code on the date below the entry
- `[!] NOT FOUND` — claim could not be reproduced; debt does not exist or was already fixed

---

## Items

### 1. PHP↔TS enum-drift (Deliveries + Approvals)
- **Files:**
  - `includes/modules/deliveries/service.php` (PCM_Deliveries_Service::STATUSES)
  - `app/src/modules/Deliveries/types.ts` (DELIVERY_STATUSES)
  - `includes/modules/approvals/service.php` (PCM_Approvals_Service::STATUSES)
  - `app/src/modules/Approvals/types.ts` (APPROVAL_STATUSES)
- **Claim:** Status arrays manually duplicated PHP↔TS, drift risk on every taxonomy change.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `includes/modules/deliveries/service.php:31` → `public const STATUSES = ['active', 'paused', 'completed'];`
  - `app/src/modules/Deliveries/types.ts:8` → `export const DELIVERY_STATUSES = ['active', 'paused', 'completed'] as const;`
  - `includes/modules/approvals/service.php:31` → `public const STATUSES = ['draft', 'internal', 'client', 'launch', 'live', 'archived'];`
  - `app/src/modules/Approvals/types.ts:26-33` → `APPROVAL_STATUSES` with identical 6-tuple
  - Both PHP files contain explicit `Keep in sync with ...` directives in their docblocks — drift risk is acknowledged in code but not enforced.

### 2. CSS duplicates between Approvals/kanban and shared/Kanban
- **Files:**
  - `app/src/modules/Approvals/kanban/cardBase.module.css`
  - `app/src/modules/Approvals/kanban/setCard.module.css`
  - `app/src/components/shared/Kanban/cardBase.module.css`
  - `app/src/components/shared/Kanban/card.module.css`
  - `app/src/modules/Approvals/kanban/SetCard.tsx`
- **Claim:** Approvals' local CSS files are verbatim duplicates of the shared copies; SetCard imports the local copy instead of composing from shared.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `diff modules/Approvals/kanban/cardBase.module.css components/shared/Kanban/cardBase.module.css` → only header docblock differs (shared copy explicitly says *"Verbatim copy of modules/Approvals/kanban/cardBase.module.css, promoted to shared/Kanban/"*).
  - Same for `setCard.module.css` vs `card.module.css`.
  - `app/src/modules/Approvals/kanban/SetCard.tsx:25` → `import styles from './setCard.module.css';` (still local, not shared).
  - Compare: `app/src/modules/Deliveries/kanban/deliveryCard.module.css:11` → `composes: card from '@/components/shared/Kanban/card.module.css';` (Deliveries uses shared via composes; Approvals does not).

### 3. Inline magic-hex `#ffffff` on both board-module entry files
- **Files:**
  - `app/src/modules/Deliveries/index.tsx`
  - `app/src/modules/Approvals/index.tsx`
- **Claim:** Both board modules use inline `style={{ background: '#ffffff' }}`, bypassing design tokens.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `app/src/modules/Deliveries/index.tsx:64` → `style={{ background: '#ffffff' }}`
  - `app/src/modules/Approvals/index.tsx:34` → `style={{ background: '#ffffff' }}`
  - No CSS variable / design token in use; both are literal hex strings inline.

### 4. Hardcoded module-id check in Shell.tsx
- **File:** `app/src/components/layout/Shell.tsx`
- **Claim:** Single literal string check `activeModule === 'deliveries'` decides shell background — does not scale to more white-shell modules.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `app/src/components/layout/Shell.tsx:71` → `const shellBg = activeModule === 'deliveries' ? '#ffffff' : '#f8f9fa';`
  - Note: file is at `components/layout/Shell.tsx`, NOT at `app/src/Shell.tsx` as the handover §1.4 implied — handover path was inaccurate.

### 5. Magic string `'launch'` hardcoded in submit_review
- **File:** `includes/modules/approvals/service.php`
- **Claim:** `submit_review` writes a string literal for the post-submission status instead of referencing a constant. Decoupled from `STATUSES` array.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `includes/modules/approvals/service.php:264` → `'status' => 'launch',` (inside `$wpdb->update(...)`).
  - The literal `'launch'` is not derived from `self::STATUSES` — purely textual. If `STATUSES` changes, this assignment will not get type/grep coverage automatically.
  - Adjacent comment (lines 256–259) DOES record the history (`v1.7.0 = 'completed', v1.8.0 = 'approved', ...`) — acknowledged hand-maintained.

### 6. ClientReviewPage dead-code status check (active bug)
- **File:** `app/src/modules/Approvals/components/ClientReviewPage.tsx`
- **Claim:** A `status === 'completed'` check decides read-only mode, but `'completed'` was migrated out in v1.8.0. Read-only mode never engages after client submits.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `app/src/modules/Approvals/components/ClientReviewPage.tsx:90` → `const isReadOnly = set?.status === 'completed';`
  - (Note: handover cited line 89, actual is line 90 — off by one.)
  - Cross-reference: `includes/modules/approvals/service.php:47-52` `LEGACY_STATUS_MAP` migrates `'completed' → 'approved'`, then `'approved' → 'launch'`. No path leaves `'completed'` in the live data set after v1.8.0+.
  - Consequence: `isReadOnly` is always `false` for any client-submitted set; the surface is editable when it shouldn't be.

### 7. trpc.articles.* legacy aliases — dead routes
- **Files:**
  - `app/src/lib/trpc.ts` (lines 289–304)
  - `app/src/modules/Approvals/types.ts` (Article type at lines 99–109)
- **Claim:** 4 route aliases labeled "legacy aliases for Approvals module" but no consumers exist in `modules/Approvals/`. Articles board was removed when the view-switcher was deleted.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `app/src/lib/trpc.ts:288-304` declares `articles.list`, `articles.get`, `articles.update`, `articles.delete` with comment *"// ── Articles (legacy aliases for Approvals module) ──"*.
  - Grep `trpc\.articles|trpc\[.articles.\]` across the entire `app/src` directory returned exactly **one match**: `app/src/modules/Approvals/types.ts:95` — and that is a comment, not a function call.
  - No `useQuery` / `useMutation` consumer found anywhere in the frontend.
  - The `Article` interface (`types.ts:99-109`) is also unreferenced by any other Approvals file — full deletion candidate pending one extra grep before action.

### 8. useApprovalSets — duplicate-call code smell, 4 mixed concerns
- **Files:**
  - `app/src/modules/Approvals/hooks/useApprovalSets.ts`
  - `app/src/modules/Approvals/kanban/SetsBoard.tsx`
  - `app/src/modules/Approvals/index.tsx`
- **Claim:** Hook owns query + mutations + dialog state + selection state in a single ~315-line file. Called from both index.tsx and SetsBoard.tsx → two React state slots that don't share.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - File length: **314 lines** (`wc -l` output). Handover said 315 — close, off by one.
  - `useApprovalSets.ts:282-288` owns dialog state (`feedbackSet`, `previewSet`) via `useState`.
  - `useApprovalSets.ts:255-279` owns selection state (`selectedIds`) via `useState`.
  - `useApprovalSets.ts:158, 191, 192` own three `useMutation` calls (status, delete, bulkDelete).
  - Call sites:
    - `app/src/modules/Approvals/kanban/SetsBoard.tsx:127` → `} = useApprovalSets();`
    - `app/src/modules/Approvals/index.tsx:20` → `const { sets, isLoading } = useApprovalSets();`
  - Two hook instances mounted simultaneously → independent state slots. `index.tsx` instance owns selection/dialog state that is never read, just allocated.

### 9. useDeliveries — two `useMutation` instances on the same route
- **File:** `app/src/modules/Deliveries/hooks/useDeliveries.ts`
- **Claim:** `updateMutation` and `statusMutation` both invoke `trpc.deliveries.update.useMutation()` — same endpoint, redundant.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `app/src/modules/Deliveries/hooks/useDeliveries.ts:119` → `const updateMutation = trpc.deliveries.update.useMutation();`
  - `app/src/modules/Deliveries/hooks/useDeliveries.ts:120` → `const statusMutation = trpc.deliveries.update.useMutation();`
  - Both target the same proxy path → both resolve to ROUTE_MAP entry `"deliveries.update"` (PATCH `deliveries/{id}`).
  - `statusMutation.mutateAsync({ id, status: next })` (line 185) sends `{id, status}` payload; `updateMutation.mutateAsync(input)` (line 149) sends `{id, ...fields}`. Identical endpoint, two mutation hook instances.

### 10. Sibling service-class pattern split (static vs instance)
- **Files:**
  - `includes/modules/approvals/service.php`
  - `includes/modules/deliveries/service.php`
- **Claim:** Two sibling modules use two different OOP conventions for the service layer.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - Grep `public (static )?function` on `approvals/service.php` → **10 matches, all `public static function`** (`list_sets_by_user:60`, `get_set_by_id:81`, `get_set_by_token:90`, `create_set:113`, `update_status:150`, `delete_set:182`, `bulk_delete_sets:209`, `submit_review:239`, `save_review_draft:286`, `update_snapshot_asset:440`).
  - `deliveries/service.php:51` → `public function format_delivery(object $row): array` (instance method, no `static`).
  - `deliveries/service.php:73` → `public function validate_status(mixed $status): ?string` (instance method).
  - Two sibling modules, two conventions, no documentation explaining the difference.

### 11. PCM_DB per-table vs generic split
- **Files:**
  - `includes/core/db/class-pcm-db.php` (deliveries section)
  - `includes/modules/approvals/service.php` (uses generic `get_by_id`)
- **Claim:** Deliveries uses per-table methods. Approvals uses the generic `PCM_DB::get_by_id('approval_sets', ...)`. Two parallel patterns coexist undocumented.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `includes/core/db/class-pcm-db.php:426-524` declares 5 typed delivery methods: `get_user_deliveries(int $user_id)`, `get_delivery_by_id(int $id, int $user_id)`, `create_delivery(array $data)`, `update_delivery(int $id, int $user_id, array $data)`, `delete_delivery(int $id, int $user_id)`.
  - `includes/modules/approvals/service.php:83` → `$row = PCM_DB::get_by_id('approval_sets', $id, $user_id);` (single generic call signature with table-name string).
  - No class-level docblock on `PCM_DB` declaring which pattern is canonical. Both styles co-exist without a stated rule.

### 12. LEGACY_STATUS_MAP grows 4 entries over 4 versions — order-fragile
- **File:** `includes/modules/approvals/service.php`
- **Claim:** Map has 4 entries; `approved → launch` depends on `completed → approved` running first. Comment explicitly acknowledges iteration-order coupling.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `includes/modules/approvals/service.php:47-52`:
    ```php
    public const LEGACY_STATUS_MAP = [
        'review'    => 'client',
        'completed' => 'approved',
        'approved'  => 'launch',
        'create'    => 'launch',
    ];
    ```
  - Docblock at lines 35-46 explicitly states: *"Iterated in declaration order ... later entries can catch values produced by earlier ones in the same pass"*. Map fragility is acknowledged in code.
  - History: v1.8.0 added review/completed mappings; v1.9.0 added approved→create; v1.11.0 added create→launch and re-pointed approved. Never compacted.

### 13. Activator gate stacking in class-pcm-activator.php
- **File:** `includes/class-pcm-activator.php`
- **Claim:** Sequential `if (version_compare($installed_version, 'X', '<'))` blocks accumulate, never collapsed.
- **Status:** `[x] CONFIRMED` (2026-05-29)
- **Evidence:**
  - `includes/class-pcm-activator.php:113` → `if (version_compare($installed_version, '1.9.0', '<')) { PCM_Schema::migrate_approval_set_statuses(); }`
  - `includes/class-pcm-activator.php:122` → `if (version_compare($installed_version, '1.11.0', '<')) { PCM_Schema::migrate_approval_set_statuses(); }`
  - 2 gates today. Note: both call the SAME migration method — second block is redundant for installs that already ran the first, but harmless because the underlying method is idempotent. Still a structural pattern that will grow linearly with future status migrations.

### 14. `transform: (input: any)` casts on Deliveries routes (count corrected)
- **File:** `app/src/lib/trpc.ts`
- **Claim:** Handover §3.2 said 4 new `any` casts; my read says 3 — list and create have no transform.
- **Status:** `[x] CONFIRMED — handover overcounted` (2026-05-29)
- **Evidence:**
  - `app/src/lib/trpc.ts:226-242` Deliveries section in ROUTE_MAP:
    - Line 226: `"deliveries.list": { endpoint: "deliveries", method: "GET" }` — **no transform, no `any`**
    - Line 227-231: `"deliveries.getById"` — `transform: (input: any) => ...`  ← #1
    - Line 232: `"deliveries.create": { endpoint: "deliveries", method: "POST" }` — **no transform, no `any`**
    - Line 233-237: `"deliveries.update"` — `transform: (input: any) => ...`  ← #2
    - Line 238-242: `"deliveries.delete"` — `transform: (input: any) => ...`  ← #3
  - Actual count: **3 new `any` casts**, not 4. Handover §3.2 was off by one. Debt is real but smaller than stated.

### 15. `npm run check` not run after session-shipped changes
- **Files:** all of the above + every Deliveries/Approvals touched file
- **Claim:** Type-check pass not executed; unknown if new TS errors exist on touched files.
- **Status:** `[ ] PENDING — verification is the act of running, not reading`
- **Note:** Will move to `[x] CONFIRMED clean` or `[!] FAILED with N errors` once `npm run check` has been executed against the current branch state.

---

## Summary

| # | Item                                                       | Status        |
| - | ---------------------------------------------------------- | ------------- |
| 1 | PHP↔TS enum-drift                                          | `[x]` Confirmed |
| 2 | CSS duplicates in Approvals/kanban vs shared/Kanban        | `[x]` Confirmed |
| 3 | Inline `#ffffff` in both board entry files                 | `[x]` Confirmed |
| 4 | Hardcoded module-id in Shell.tsx                           | `[x]` Confirmed |
| 5 | Magic string `'launch'` in `submit_review`                 | `[x]` Confirmed |
| 6 | ClientReviewPage `=== 'completed'` dead check (active bug) | `[x]` Confirmed (line 90, not 89) |
| 7 | `trpc.articles.*` legacy aliases — dead                    | `[x]` Confirmed |
| 8 | `useApprovalSets` 314-line god-hook + duplicate-call       | `[x]` Confirmed |
| 9 | `useDeliveries` double `useMutation` on same route         | `[x]` Confirmed |
| 10 | Service class static (Approvals) vs instance (Deliveries) | `[x]` Confirmed |
| 11 | `PCM_DB` per-table vs generic                              | `[x]` Confirmed |
| 12 | `LEGACY_STATUS_MAP` order-fragile, never cleaned           | `[x]` Confirmed |
| 13 | Activator gate stacking                                    | `[x]` Confirmed (2 gates) |
| 14 | `transform: (input: any)` in Deliveries routes             | `[x]` Confirmed — **3 casts not 4** |
| 15 | `npm run check` clean                                      | `[ ]` Pending — needs run |

**14 of 15 confirmed.** Item 15 pending until type-check is actually run.

---

## Verification log

Per-item commands and outputs used during the 2026-05-29 verification pass.

**Item 1:** Read `includes/modules/deliveries/service.php` lines 1-80, `app/src/modules/Deliveries/types.ts` lines 1-20, `includes/modules/approvals/service.php` lines 1-60, `app/src/modules/Approvals/types.ts` lines 1-110. Confirmed identical 3-tuple (Deliveries) and 6-tuple (Approvals) on both sides, plus PHP docblock directives `Keep in sync with`.

**Item 2:** `diff modules/Approvals/kanban/cardBase.module.css components/shared/Kanban/cardBase.module.css` → only docblock differs. Same for `setCard.module.css` vs `card.module.css`. Read `SetCard.tsx:25` → local import confirmed.

**Item 3:** Read `Deliveries/index.tsx:64` and `Approvals/index.tsx:34` → both have literal `style={{ background: '#ffffff' }}`.

**Item 4:** Read `Shell.tsx:60-99` → literal ternary at line 71.

**Item 5:** Read `approvals/service.php:239-277` → `'status' => 'launch'` at line 264 inside `$wpdb->update`. Adjacent comment confirms hand-maintained history.

**Item 6:** Read `ClientReviewPage.tsx:80-94` → line 90: `const isReadOnly = set?.status === 'completed';`. Handover cited line 89; actual is line 90. The `'completed'` enum value is no longer reachable in live data per `LEGACY_STATUS_MAP`.

**Item 7:** Grep `trpc\.articles|trpc\[.articles.\]` across entire `app/src/` → 1 match, all in a comment at `Approvals/types.ts:95`. Zero `useQuery` / `useMutation` consumers across the SPA.

**Item 8:** `wc -l useApprovalSets.ts` → 314 (not 315). Grep `useApprovalSets()` call sites → 2 (`SetsBoard.tsx:127`, `index.tsx:20`). Read hook → confirmed dialog/selection state owned internally at lines 255-279 and 282-288.

**Item 9:** Read `useDeliveries.ts:119-120` → both lines call `trpc.deliveries.update.useMutation()`.

**Item 10:** Grep `public (static )?function` on `approvals/service.php` → 10 matches, all static. Read `deliveries/service.php:51, 73` → instance methods.

**Item 11:** Grep `PCM_DB::get_by_id` in `includes/modules/approvals/` → 1 match at `service.php:83`. Read `class-pcm-db.php:426-524` → 5 typed delivery methods (per-table pattern).

**Item 12:** Read `approvals/service.php:35-52` → 4-entry LEGACY_STATUS_MAP with explicit iteration-order docblock.

**Item 13:** Read `class-pcm-activator.php:105-130` → 2 sequential `version_compare` gates at lines 113 and 122, both calling the same migration method.

**Item 14:** Grep `deliveries\.` on `app/src/lib/trpc.ts` → 5 routes total. Manual count of transforms with `(input: any)`: 3 (getById, update, delete). `list` and `create` carry no transform.

**Item 15:** Not yet run.

---

## Next steps

1. Decide remediation order (suggested priority below).
2. Execute one item at a time per `feedback_implementation_process.md` (BEFORE commit → code → AFTER-UNVERIFIED commit → manual test → AFTER-VERIFIED commit on user approval).
3. Update this doc per item — flip `[x] CONFIRMED` → `[✓] RESOLVED in <commit>` once the fix is merged and verified.

**Suggested order:**
1. Item 6 (active bug — ClientReviewPage)
2. Item 9 (1-line cleanup — useDeliveries double mutation)
3. Item 7 (dead routes deletion — quick win after final grep)
4. Item 3 + Item 4 (white-shell convention — combine, small refactor)
5. Item 14 (typed input interfaces for deliveries routes)
6. Item 2 (CSS dedup — refactor SetCard.tsx)
7. Item 1 (build-script for enum sync)
8. Item 8 (split useApprovalSets — biggest internal refactor)
9. Item 11 (PCM_DB pattern decision + docblock)
10. Item 10 (service class convention — decide + refactor Deliveries)
11. Item 5 (magic-string constant in submit_review)
12. Item 12 (LEGACY_STATUS_MAP purge — schedule for v2.0)
13. Item 13 (activator gate generic runner — schedule for v2.0)
14. Item 15 (run `npm run check` after every fix above)
