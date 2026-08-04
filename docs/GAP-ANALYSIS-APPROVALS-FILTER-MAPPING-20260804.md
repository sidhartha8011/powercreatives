# GAP ANALYSIS — Approvals: searchable filter dropdowns + project-only mapping
**Date:** 2026-08-04 · **Owner order + GO:** same message ("check the factual gap …
then you commit and execute"). **Owner decision (asked, answered):** three dropdowns
only; the notification jump opens that set's preview card instead of filtering.

Every fact below is verified at file:line or by live probe. Nothing inferred.

---

## PART A — the filter bar

### A.1 What is there today
`app/src/modules/Approvals/kanban/SetsBoard.tsx:341-464` renders **five** controls,
hand-rolled inline, none of them searchable:

| # | Control | Source of its options | Line |
|---|---|---|---|
| 1 | Search text input | free text over brand+project+set name+delivery | :342-354 |
| 2 | Brand `<Select>` | `uniqueValues(sets, s => s.snapshot.brandName)` | :356-371 |
| 3 | Project `<Select>` | `uniqueValues(sets, s => s.snapshot.projectName)` | :373-391 |
| 4 | Delivery `<Select>` | `deliveryNameById.get(s.deliveryId)` | :393-411 |
| 5 | Approval set `<Select>` | `uniqueValues(sets, s => s.name)` | :413-431 |

Declarations live in `kanban/setFilters.ts` (5 definitions) and are bound through
`useListState` (`components/shared/Kanban/filters/useListState.ts`).

### A.2 The searchable control does NOT exist as a filter control — FACT
- The Kanban barrel states filter UI is **out of scope** on purpose:
  `components/shared/Kanban/index.ts:12-18` — "Toolbar / filter UI controls. Each
  consumer renders its own bar with the design-system primitives".
- `searchableSelect` (`filters/factories.ts`) is a **data-side filter definition
  factory only** — it renders nothing.
- Both boards that consume it (`Approvals/kanban/SetsBoard.tsx`,
  `Deliveries/kanban/DeliveriesBoard.tsx:482-500`) hand-roll a plain shadcn
  `<Select>` — a plain list, no search box.
- What DOES exist: **`components/ui/creatable-combobox.tsx`** — Popover + Command
  (cmdk), searchable, clearable, used by Brands / SEO / Templates (4 modules). It
  always offers "Create «typed text»" on a non-match (`:78`, `:178-197`), which is
  correct for authoring and **wrong for a filter**.
- ⇒ **The gap:** a searchable *filter* dropdown has no shared implementation.
  UI RULE 1 (CLAUDE.md) applies verbatim: *"If the shared layer lacks the thing you
  need, CREATE IT THERE … and use it from that one place."*

### A.3 The Brand and Project filters are dead today — FACT (live-probed)
`PCM_Approvals_Service::list_sets_by_user` selects ten columns and **neither
`snapshot` nor `reviewFeedback`** (`includes/modules/approvals/service.php:69`),
in place since `eafdb1e` (2026-06-13). But filters 2 and 3 read
`s.snapshot.brandName` / `s.snapshot.projectName`, so both dropdowns are always
empty and `SetCard`'s brand prefix never renders.

Live probe, hub DB (mysqli 127.0.0.1:10017, `wp_pcm_approval_sets`):

```
id  name                      status  bId  pId  dId  snapshot.brandName  fbLen
10  Ad Campaign Set — Profit   live    13   -    -    Profit Media        520
 8  Ad Campaign Set — Bright   launch  11   -    -    Bright Tandhälsa    177
 3  Ad Campaign Set — Bright   client   9   -    -    Bright Tandhälsa    177
 2  Ad Campaign Set — Bright   launch   9   -    -    Bright Tandhälsa     59
```

The data is there; the endpoint never ships it. `deliveryId` is likewise **not
selected**, so filter 4 only works for sets whose delivery is derived from a
project chain (`format_set_row` :637-645) — and every row above has `projectId`
NULL, so it resolves to nothing.

**Fix direction (chosen):** do NOT add `snapshot` back to the list query — it is
`longtext` and can carry base64 images. Filter by **ID**, resolve **names from the
registries the board already loads** (`brands.list`, `deliveries.list`,
`assets.getProjects` — all present in `lib/trpc-routes.ts:138,197,917`). Backend
change is one word: add `deliveryId` to `$cols`.

### A.4 Stale persisted filter state — FACT
`useListState` persists per `persistKey` (`'pcm.approvals.sets'`, SetsBoard:153).
- `applyFilters` maps over the **definitions** (`applyFilters.ts:22`), so a removed
  filter's leftover state is never applied. Safe.
- `activeFilterCount` counts the **state values** (`useListState.ts:114-117`), so a
  leftover `search` / `set` key WOULD keep "Clear filters" visible forever while
  filtering nothing.
- ⇒ bump the key to `pcm.approvals.sets.v2`. No migration code, no dead state.

### A.5 The notification jump depends on filter 5 — FACT
`NotificationsPanel.tsx:144` → `navigateToApprovalsWithSet(setId)` →
`AppContext.tsx:300-303` sets `pendingApprovalSetId` → `SetsBoard.tsx:164-177`
consumes it and sets the **`set` filter** to that set's name. Removing filter 5
without re-homing this silently kills the jump.
⇒ **Owner decision:** the jump now calls `openPreview(target)` — the preview
dialog already exists (`useApprovalSets.openPreview`, `kanban/PreviewDialog.tsx`).

### A.6 Checklist — PART A
- [ ] A1 New shared control `components/shared/SearchableSelect.tsx` (Popover +
      Command, no create option, clearable), exported from the `@/components/shared`
      barrel.
- [ ] A2 `setFilters.ts` → three ID-based definitions (brand / delivery / project);
      `search` + `set` definitions deleted.
- [ ] A3 `SetsBoard.tsx` bar → three `<SearchableSelect>`; search input + four
      `<Select>` blocks deleted; `persistKey` bumped to `.v2`.
- [ ] A4 Options + labels resolved from `brands.list` / `deliveries.list` /
      `assets.getProjects`, narrowed to values actually present on the board (no
      dead choices).
- [ ] A5 `SetCard` brand prefix fed from the resolved brand name (it reads dead
      `snapshot.brandName` today).
- [ ] A6 Backend: `deliveryId` added to `$cols` (`approvals/service.php:69`).
- [ ] A7 Notification jump → `openPreview(target)`.

---

## PART B — one mapping: the set belongs to a PROJECT

### B.1 Owner's model
> "The approval set should only be mapped to a project, and if there is no project,
> it will allow the user to create a project. You're not mapping it to delivery;
> the project should be connected to a delivery, but there should be an option to
> have a no-delivery option as well. Same thing with a no-brand option."

### B.2 What the chain already supports — FACT
- `projects.deliveryId int(11) DEFAULT NULL` (`class-pcm-schema.php:126`) →
  **"no delivery" already legal.**
- `deliveries.brandId int(11) DEFAULT NULL` (`:554`) → **"no brand" already legal.**
- `PCM_Hierarchy::for_project()` (`includes/core/class-pcm-hierarchy.php:38-62`)
  already derives delivery + brand LIVE from the project, and its docblock states
  the law: anything attached to a project "must NOT store/hardcode
  brandId/deliveryId".
- `format_set_row` (`approvals/service.php:637-645`) and `enrich_context`
  (`:873-875`) already prefer that live chain over the stored columns.
- ⇒ The model the owner describes is **already the backend's law**. The gap is that
  the UI still writes a second, competing mapping.

### B.3 Where the competing delivery mapping is written — FACT
| Where | Line | What it does |
|---|---|---|
| `components/shared/SendToApprovalSetDialog.tsx` | :414-428 | "Delivery (optional)" `<Select>` |
| same | :283 | sends `deliveryId` on create |
| same | :176-183 | guesses a delivery from the brand |
| `modules/Ads/components/CreateApprovalSetDialog.tsx` | :358-362, :220 | same picker, same send |
| `approvals/controller.php` | :120-130 | accepts + stores `deliveryId` |
| `approvals/service.php` | :254 | INSERTs it |

### B.4 Inline project creation — FACT
- `POST /assets/projects` exists (`assets/controller.php:466-496`, trpc
  `assets.createProject`) but accepts **only** name/description/type/externalId —
  **no `deliveryId`**.
- `PATCH /assets/projects/{id}/delivery` exists (trpc `trpc-routes.ts:937`).
- ⇒ Creating a project + attaching a delivery is two calls today, which can half-fail
  (project created, delivery unattached). Add an **optional `deliveryId` to
  `create_project`** — additive, inside the module that owns `projects.deliveryId`,
  one atomic INSERT.

### B.5 Checklist — PART B
- [ ] B1 `create_project` accepts optional `deliveryId` (nullable, additive).
- [ ] B2 Shared `SendToApprovalSetDialog`: Delivery picker → **Project** picker
      (searchable + create-on-type via the existing `CreatableCombobox`), with an
      optional Delivery shown **only** while creating a new project (that maps the
      PROJECT, not the set), plus "No delivery".
- [ ] B3 Same in `Ads/CreateApprovalSetDialog`.
- [ ] B4 Stop sending `deliveryId` on set create from every caller. `brandId` keeps
      being sent (it feeds `snapshot.brandName` + the brand's remembered
      `clientEmail`, `service.php:1286-1288`) and is overridden by the live chain
      whenever a project exists.
- [ ] B5 `approval_sets.deliveryId` column + its legacy read fallback
      (`service.php:875`) are KEPT for existing rows — no migration, no removal
      overreach. New rows simply stop writing it.

---

## Plan (sequential, verify + commit per part)

**PART A** — A1 → A2/A3/A4/A5 → A6 → A7. Deliverable is testable in the browser at
the end of A: three searchable dropdowns, no search box.
**PART B** — B1 → B2/B3 → B4. Deliverable: creating/sending a set asks for a project
(creatable), never a delivery.

## Verify (per part)
`php -l` each touched PHP file · `tests/standalone/run.php` · `cd app && npm run
check` (tsc: **no new errors** vs the pre-existing baseline) · `npm run build`
("built in" must print) · changelog line · AFTER commit ending **LOCAL ONLY**.
**NEVER push.**

## Out of scope (named, not silently dropped)
- The dead "View client feedback" chip (`reviewFeedback` also unselected at
  `service.php:69`) — same root as A.3 but a different surface; not requested.
- Swedish strings + the hardcoded 4-day client deadline on the public review page.
- `submit_review` server-side idempotency.
- The Deliveries board's identical hand-rolled bar — the new shared control makes
  it a one-line swap later; not touched now (another board, not requested).
