# GAP ANALYSIS — the lane "+" (create into a lane, pre-filled from the filters) + optional delivery link on an existing project
**Date:** 2026-08-04 · **Owner order + GO.** Confirmed in the same thread: the second
dropdown is NOT a bug — it is the project→delivery link, and it correctly shows only when
creating a new project. New scope: the same link must be offered for an EXISTING project
that has none, and it must never be mandatory ("it can continue without it").

Every fact is verified at file:line in today's tree. Decisions are listed with the option
that was rejected, so a later reader sees why.

---

## 1. FACTS — the lane "+"

| # | Fact | Where |
|---|---|---|
| F1 | `KanbanBoardProps` has **no per-column action slot**. It exposes `columns`, `items`, `getColumnId`, `renderCard`, `onItemMove`, `onColumnReorder` — nothing for a header control. | `components/shared/Kanban/types.ts:75-103` |
| F2 | The column header is a single `<header>` holding one pill with the label. There is a natural, empty right-hand slot inside it. | `Kanban/KanbanBoard.tsx:186-209` |
| F3 | `KanbanColumn` is `{id,label,accentColor,accentText,dotColor,emptyHint}` — the lane ids ARE the statuses (`setColumns.ts`), so a lane's id is directly the status a new set needs. | `Kanban/types.ts:15-31` |
| F4 | **`create_set` hardcodes `'status' => 'draft'`.** There is no way to create a set into any other lane in one call. | `approvals/service.php:262` |
| F5 | The existing "create into the client lane" flows do it in TWO calls — create, then `updateSetStatus({status:'client'})`. | `SendToApprovalSetDialog` createMutation.onSuccess · `Ads/CreateApprovalSetDialog` |
| F6 | `update_status()` fires `approvals.set_status_changed`, and stamps `clientSentAt` on entry into `client` — the anchor the pending-client reminder scanner reads. | `approvals/service.php:296-321` |
| F7 | The seeded rule **"Notify team on Launch (webhook)"** listens on `approvals.set_status_changed` with `conditions {status: launch}`. | `automations/class-pcm-automation-seeds.php:177-185` |
| F8 | `SetsBoard` owns the filter state; `ApprovalsModule` owns the create dialog and the "Add Approval Set" button. They are separate files and the board takes no props today. | `Approvals/index.tsx:61-78` · `kanban/SetsBoard.tsx:109+` |
| F9 | Filter state is read with `readSelect(listState.filterState, id)` → the selected **id as a string**, or null. Filter ids are `brand` / `delivery` / `project`. | `kanban/SetsBoard.tsx` · `kanban/setFilters.ts` |
| F10 | `CreateCustomSetDialog` already accepts a `preset` of `{brandId, projectId, deliveryId}` (create-from-delivery "+"), but has **no** concept of a target lane. | `Approvals/components/CreateCustomSetDialog.tsx:49` |

## 2. FACTS — the delivery link on an existing project

| # | Fact | Where |
|---|---|---|
| F11 | `ProjectPicker` renders the delivery row **only** when `value.newProjectName !== null`. Selecting an existing project shows nothing. | `components/shared/ProjectPicker.tsx` |
| F12 | **`GET /assets/projects` already returns `deliveryId`** for every project — so "does this project have a delivery?" is answerable client-side with no backend change. | `assets/controller.php:376` |
| F13 | `PATCH /assets/projects/{id}/delivery` exists and is wired as **`assets.setProjectDelivery`**. | `assets/controller.php:64` · `lib/trpc-routes.ts:934-937` |
| F14 | `projects.deliveryId` and `deliveries.brandId` are both **nullable** — "no delivery" and "no brand" are legal states, not workarounds. | `core/db/class-pcm-schema.php:126,554` |
| F15 | `PCM_Hierarchy::for_project()` derives delivery + brand LIVE and its docblock forbids storing them on attached entities. `format_set_row` now honours that including nulls. | `core/class-pcm-hierarchy.php:38-62` · `approvals/service.php:637-648` |
| F16 | `resolveProjectId()` is already the single choke-point that turns a picker value into a stored project id (it creates the project when the user typed a new name). | `components/shared/ProjectPicker.tsx` |

---

## 3. ARCHITECTURAL DECISIONS (with the rejected option)

**D1 — the "+" belongs to the shared Kanban primitive, as an additive prop.**
Add `onColumnCreate?: (columnId: string) => void` to `KanbanBoardProps`; when present the
column header renders one "+" button on its right. Any future board gets it free.
*Rejected:* a `renderColumnAction` render-prop (every consumer would hand-roll a different
button — the shared-components law says one control, extended additively), and putting the
"+" in Approvals' own markup (it cannot reach inside the lane header).

**D2 — creating into a lane is ONE insert, not create-then-move.**
Add an optional, validated `status` to `create_set`; absent → `'draft'` exactly as today.
*Rejected:* reusing the create-then-`updateSetStatus` pattern of F5. It would (a) write a
phantom Draft row, and (b) fire `approvals.set_status_changed`, which per F7 would fire the
seeded Launch webhook for a set that was merely *created* in Launch. **A creation is not a
lane change.** The share dialogs keep their two-call flow untouched — there the move is a
real transition (shared → client) and *should* fire.
*Consequence handled:* `update_status` stamps `clientSentAt` on entry into `client` (F6).
Creating straight into `client` must stamp it too, or the pending-client reminder would
never fire for those sets — a silent hole. The create path stamps it for that status only.
*No silent fallback:* an unrecognised status is a named 400, never a quiet demotion to draft.

**D3 — the filters pre-fill, but they must not re-create a second mapping.**
The set's ONE mapping is the project (ruling 30f87f1). Therefore:
- **Project filter active** → pre-select that project. Exact, no inference.
- **Delivery filter active** → seeds the delivery for a project being *created* in the
  dialog (and, per D5, for an existing project that has none). It is **never** written onto
  the set.
- **Brand filter active** → pre-fills the set's `brandId` (still a real column feeding
  `snapshot.brandName` and the brand's remembered client email) **and** narrows the delivery
  choices to that brand's deliveries. The moment a project is chosen the live chain wins —
  `format_set_row` overwrites `brandId` from the chain — so the pre-fill can never contradict
  the mapping; it only survives while there is no project.
*Rejected:* writing `deliveryId` onto the set from the Delivery filter. That is exactly the
second mapping deleted in 30f87f1.

**D4 — the board raises the intent; the module keeps owning the dialog.**
`SetsBoard` gains one prop, `onCreateInLane(ctx)`, and hands up `{status, brandId,
deliveryId, projectId}` read from its own lane + filter state. `ApprovalsModule` keeps the
single dialog mount it already owns.
*Rejected:* moving the dialog into `SetsBoard` — the module would then have two create
entry points in two files, and the header button and the lane "+" would drift.

**D5 — the delivery link on an existing project is OFFERED, never required.**
`ProjectPicker` shows its delivery row when the picked project **has no delivery**
(`deliveryId == null`, known from F12), defaulted to "No delivery". Submitting with it left
alone is a first-class outcome — the owner was explicit. If the project **already has** a
delivery, the row is not shown: re-pointing an existing project belongs to the Projects
registry, not to a create dialog (asset-registry boundary).
*Rejected:* always showing the row (invites accidental re-pointing of a shared project from
an unrelated dialog).

**D6 — one choke-point keeps all three dialogs identical.**
`resolveProjectId()` (F16) grows the "attach this delivery to this existing project" step,
so Ads / Copy+Image / custom-card behave identically and none of them learns the rule.

---

## 4. FACTUAL CHECKLIST

**Backend**
- [ ] B1 `create_set` accepts optional `status`, validated against `STATUSES`; absent →
      `'draft'`; invalid → named 400. Stamps `clientSentAt` when the created status is
      `client` (D2).
- [ ] B2 `PCM_REST_Approvals::create_set` passes it through, sanitized.

**Shared layer**
- [ ] S1 `KanbanBoardProps.onColumnCreate?` + a "+" button in the column header, keyboard
      reachable and labelled per lane (D1).
- [ ] S2 `ProjectPicker` shows the delivery row for an existing project that has none;
      needs `deliveryId` on its `ProjectOption` (D5).
- [ ] S3 `resolveProjectId()` attaches the chosen delivery to an existing project via
      `assets.setProjectDelivery`, and only when one was actually chosen (D6).

**Approvals**
- [ ] A1 `SetsBoard` renders the "+" and raises `onCreateInLane({status, brandId,
      deliveryId, projectId})` from lane id + live filter state (D3, D4).
- [ ] A2 `ApprovalsModule` opens its existing dialog with that preset.
- [ ] A3 `CreateCustomSetDialog.preset` gains `status`, and its fields (project, delivery
      seed, brand) pre-fill from it; it sends `status` on create.
- [ ] A4 All three project consumers pass `deliveryId` through to `ProjectPicker` options.

**Verify**
- [ ] V1 `php -l` on every touched PHP file · `tests/standalone/run.php` 88/88 ·
      `cd app && npm run check` = 59 pre-existing, ZERO new · `npm run build` ("built in"
      must print) · changelog line · AFTER commit **LOCAL ONLY**. Never push.

## 5. OUT OF SCOPE (named, not silently dropped)
- Re-pointing a project that already HAS a delivery (Projects registry owns that — D5).
- The Deliveries board adopting the same lane "+" — free once S1 lands, but not requested.
- The dead "View client feedback" chip (`reviewFeedback` unselected, `approvals/service.php:69`).
