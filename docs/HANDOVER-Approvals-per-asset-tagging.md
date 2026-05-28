# HANDOVER — Approvals per-asset brand/project/delivery tagging

**Date:** 2026-05-27
**Plugin version at handover:** 1.5.0
**Status:** Spec'd, not implemented. Two tasks, in order. ~1-2 days of work.
**Branch:** `image-features`

---

## TL;DR

Inside the Approvals module, every media and copy item in a snapshot must
be assignable to a `brand`, a `project`, AND a `delivery`. All three
independently. Today no per-item tagging exists; only the set-level has
`brandId` + `projectId`.

This handover is for the developer picking it up after v1.5.0 ships.
**Read the architectural principle in §1 first — the PO has explicitly
ruled out cross-cutting refactors. Stay strictly inside the Approvals
module.** The earlier session lost the PO's trust by proposing a
sprawling, multi-module plan. Don't repeat that.

---

## 1. Architectural principle (from PO, non-negotiable)

> "EN uppsättning i vår modulära lösning som hanterar approvalsets.
> INGET KOMPLEXT nätverk. ALLT som har med APPROVALSETS skall hanteras
> i APPROVALS. Den ska vara en fristående modul precis som alla andra
> och den ska ha kopplingar till dem som behöver ha kopplingar."

Translation in operational terms:

1. **Approvals owns the approval-set domain end-to-end.** Per-item
   metadata lives inside the approval-set's snapshot JSON, not as new
   columns on the generic `pcm_assets` table.
2. **Approvals references other modules via FK.** Brand, Project,
   Delivery are referenced by id. Approvals never modifies those
   modules' schemas or data.
3. **The generic `pcm_assets` table is OFF-LIMITS.** It belongs to the
   assets/image/video module domain. Adding brandId/deliveryId to it
   was the sprawling-plan we rejected.
4. **Deliveries-the-module must exist before Approvals can reference
   it.** Today it's a 116-line UI stub with no backend. That's Task 1.

If you find yourself wanting to touch tables outside Approvals to make
something work — stop, re-read this section, and find the in-module
path.

---

## 2. Current state — facts (verified 2026-05-27)

### Tables that exist

| Table | Relevant columns | Status |
|---|---|---|
| `pcm_approval_sets` | `id, userId, brandId (nullable), projectId (nullable), name, token, status, snapshot (longtext JSON), reviewFeedback (longtext JSON), createdAt, updatedAt` | **Has set-level brand/project. No deliveryId. Per-item metadata lives in the snapshot JSON.** |
| `pcm_assets` | `id, projectId (nullable), userId, type, url, prompt, provider, modelId, metadata, versionName, createdAt` | OFF-LIMITS. No brandId, no deliveryId. Will stay that way. |
| `pcm_projects` | `id, userId, name, description, status, settings` | OFF-LIMITS. |
| `pcm_brands` | full brand profile | OFF-LIMITS. |
| `pcm_brand_assets` | brand-owned logos | OFF-LIMITS. |
| `pcm_deliveries` | — | **DOES NOT EXIST.** Task 1 creates it. |

### Snapshot JSON shape inside `pcm_approval_sets.snapshot`

Verified at
[`CreateApprovalSetDialog.tsx:88-110`](../app/src/modules/Ads/components/CreateApprovalSetDialog.tsx#L88-L110):

```ts
snapshot = {
  brandName: string,           // set-level, denormalized for client view
  brandLogoUrl: string | null, // set-level
  media: Array<{
    id: string,
    type: string,              // e.g. "image"
    url: string,
    prompt?: string,
    provider?: string,
    modelId?: string,
    // ← Task 2 adds: brandId, projectId, deliveryId (all nullable)
  }>,
  copy: Array<{
    id: string,
    headline: string,
    body: string,
    cta?: string,
    description?: string,
    hashtags?: string[],
    audienceName?: string,
    angleName?: string,
    modelUsed?: string,
    // ← Task 2 adds: brandId, projectId, deliveryId (all nullable)
  }>,
}
```

### PHP modules that exist

`includes/modules/`: approvals, assets, brands, copy, image, integrations,
keywords, models, prompts, scraper, settings, sites, strategy, templates,
video, writer.

**Missing:** `deliveries` (Task 1 creates it).

### Frontend modules that exist

`app/src/modules/`: 16 modules including Deliveries, Ads, Approvals,
Assets, Brands, Projects, Copy, Image, Video, etc.

**Status of relevant modules:**
- `Deliveries/index.tsx` — **116-line UI stub.** `useState<Delivery[]>([])`.
  No tRPC. "+ New Delivery" button has no handler. Task 1 replaces this.
- `Approvals/` — fully functional. Delete + bulk-select shipped in v1.5.0.
- `Ads/` — generation flow. Generated assets live in
  `orchestration.mediaSlots` / `textSlots` in memory; persisted to DB
  ONLY when user opens `CreateApprovalSetDialog` and clicks Share.

### tRPC ROUTE_MAP in [`lib/trpc.ts`](../app/src/lib/trpc.ts)

For approvals: `listSets, createSet, getPublicSet, submitReview,
saveReviewDraft, updateSnapshotAsset, updateSetStatus, deleteSet,
bulkDeleteSets`.

**For deliveries: nothing.** Task 1 adds them.

`updateSnapshotAsset` exists at
[`controller.php:70`](../includes/modules/approvals/controller.php#L70)
already — Task 2 extends its accepted body shape, doesn't add a new route.

---

## 3. Task 1 — Deliveries module (standalone, prerequisite)

Mirror the Brands module pattern exactly. The deliverable is a working
modul on par with Brands. Approvals integration is NOT in this task.

### Scope

**Schema** (in `includes/core/db/class-pcm-schema.php`):

```sql
CREATE TABLE {prefix}deliveries (
  id int(11) NOT NULL AUTO_INCREMENT,
  userId int(11) NOT NULL,
  name varchar(256) NOT NULL,
  clientName varchar(256) DEFAULT NULL,
  status varchar(50) DEFAULT 'active' NOT NULL,
  createdAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
  updatedAt datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
  PRIMARY KEY (id),
  KEY idx_userId (userId)
)
```

Bump DB version. Verify dbDelta runs the migration on activation.

**PHP module** at `includes/modules/deliveries/`:
- `config.php` — module metadata (copy structure from
  `includes/modules/brands/config.php`)
- `service.php` — static methods: `list_by_user`, `get_by_id`, `create`,
  `update`, `delete`. Ownership-scoped (`WHERE userId = %d` on every
  query/update/delete).
- `controller.php` — extends `PCM_REST_Base`. Routes:
  ```
  GET    /deliveries           list
  GET    /deliveries/(?P<id>\d+) get
  POST   /deliveries           create
  PATCH  /deliveries/(?P<id>\d+) update
  DELETE /deliveries/(?P<id>\d+) delete
  ```
  All with `edit_posts` permission.

Register the module by adding the include in `includes/module-loader.php`.

**tRPC ROUTE_MAP** in `app/src/lib/trpc.ts`:

```ts
"deliveries.list":    { endpoint: "deliveries", method: "GET" },
"deliveries.create":  { endpoint: "deliveries", method: "POST" },
"deliveries.get":     { endpoint: "deliveries", method: "GET",
                        transform: (i) => ({ url: `deliveries/${i.id}` }) },
"deliveries.update":  { endpoint: "deliveries", method: "PATCH",
                        transform: (i) => ({ url: `deliveries/${i.id}`, body: i }) },
"deliveries.delete":  { endpoint: "deliveries", method: "DELETE",
                        transform: (i) => ({ url: `deliveries/${i.id}` }) },
```

**Frontend** — replace [`Deliveries/index.tsx`](../app/src/modules/Deliveries/index.tsx)
with a real CRUD. Mirror the Brands module's card-grid pattern. Use:
- `trpc.deliveries.list.useQuery()` for data
- shadcn `Dialog` for create/edit
- shadcn `AlertDialog` for delete confirmation
- Boundary normalization (see Gotcha §5.1)

**Out of scope for Task 1:**
- Don't touch Approvals
- Don't add deliveryId to assets table (that table is off-limits)
- Don't try to link deliveries to anything yet — projects/assets stay
  unaware

### Done criteria for Task 1
- New delivery can be created, listed, edited, deleted in the UI
- Bumped schema version migrates correctly on a fresh install AND on
  an existing install (test both)
- `npm run build` clean, `npm run check` no new errors
- Version bump to v1.6.0 (minor — new feature module)
- Pre + post implementation commits per the workflow in §6

---

## 4. Task 2 — Approvals per-item tagging (after Task 1)

Per-asset metadata fields added inside the snapshot JSON. No new tables.
No FK enforcement at DB level (JSON doesn't support that); validation
done at the controller layer.

### Scope

**PHP** — extend the existing `updateSnapshotAsset` handler in
[`controller.php`](../includes/modules/approvals/controller.php).
Today it accepts arbitrary snapshot-asset updates. Make it explicitly
accept `brandId`, `projectId`, `deliveryId` as `int | null`. Validate
ownership of each referenced id (the brand/project/delivery must belong
to the same user) before writing.

The snapshot JSON is a string column — read, mutate, write back. Use
`PCM_Approvals_Service::format_set_row` to ensure round-trip parsing.

**Frontend — Approvals module** (`app/src/modules/Approvals/`):

1. **Per-asset metadata UI.** When a card's PreviewDialog opens, render
   three dropdowns near the top: Brand / Project / Delivery. Each
   dropdown is populated from `trpc.brands.list`, `trpc.assets.getProjects`
   (existing route), and the NEW `trpc.deliveries.list`. Selection
   change calls `updateSnapshotAsset` for the per-item record.

   Or: a separate per-item editor view if PreviewDialog gets too busy.
   Implementer's call.

2. **Bulk-assign action.** In v1.5.0 the bulk-action bar has Delete +
   Cancel. Add a "Tag" action that opens a dropdown with the three
   selectors. Applies to all selected snapshot items across all cards.

3. **Display tags on cards.** Small chips on `SetCard` showing the
   assigned brand/project/delivery, if any are non-null. Keep cards
   single-line — chips go in the actions area or as a footer row.

**Frontend — Ads module CreateApprovalSetDialog**: add three optional
selectors at create-time. Their value pre-fills every item in the new
snapshot's media+copy arrays with those ids.

**Schema migration** — none needed at the table level. Existing
approval_sets keep working: items without the new fields just read as
null on the client side. Make sure the UI handles null gracefully.

### Done criteria for Task 2
- Per-item brand/project/delivery can be set from Approvals UI
- Bulk-tag works across selected cards
- New approval-sets created from Ads can be pre-tagged
- Existing approval-sets continue to render (null per-item metadata)
- v1.6.x patch bump
- Pre + post commits

---

## 5. Critical gotchas — learned from prior failed sessions

### 5.1 MySQL BIGINT serialized as JSON strings (THE bug that ate 4 attempts)

WordPress `wpdb->get_results()` returns ALL column values as PHP
strings. `wp_send_json` serializes them as strings too. So a row with
`id = 6` arrives on the client as `{"id": "6"}` even though TypeScript
declares it as `number`.

**Every strict-equality comparison `s.id === id` between cache-data
'6' and parameter Number(itemId) === 6 returns false.**

This caused four straight DnD jump-back fix attempts to fail silently.
See [HANDOVER-DnD-jump-back-bug.md](HANDOVER-DnD-jump-back-bug.md) for
the autopsy.

**Defensive pattern (used in v1.4.11 and v1.5.0):**

```ts
// In every consumer hook that reads from the React Query cache:
const sets = useMemo(() => {
  return (query.data as Foo[]).map((raw) => ({
    ...raw,
    id: Number(raw.id),
    userId: Number(raw.userId),
    brandId: raw.brandId != null ? Number(raw.brandId) : null,
    // … etc for every numeric FK
  }));
}, [query.data]);
```

And in predicates that read directly from cache (not via the
normalized memo): use `Number(s.id) === id`, not `s.id === id`.

**For Task 1**: do this in the new `useDeliveries` hook from the
start. Don't ship the bug into a new module.

### 5.2 Don't theorize — instrument

Earlier sessions wasted hours guessing at runtime behavior without
ever opening DevTools. The memory entry
[`feedback_debug_runtime_bugs_data_first`](../../../.claude/projects/.../memory/feedback_debug_runtime_bugs_data_first.md)
codifies the rule: for any runtime UI bug, instrument (console.log at
boundaries + Network tab + TanStack Query DevTools) BEFORE writing a
single line of fix code. The Number(id) bug was found in literally one
minute of console output once instrumentation was added.

### 5.3 React Query cache holds raw server data, normalized memos do not

`setQueriesData` updaters receive raw cache data. If you only
normalize ids in the `useMemo` (per §5.1), the cache itself still has
string ids. Predicates inside `setQueriesData` updaters must coerce.

### 5.4 `flushSync` is required for DnD optimistic updates

`@hello-pangea/dnd` paints IDLE state when `onDragEnd` returns. If
React Query's render commit is still pending at that moment, the card
appears to "snap back". Wrap the optimistic `setQueriesData` call in
`flushSync(() => { ... })` so the render commits before onDragEnd
returns. Already done in `useApprovalSets.updateStatus` — follow that
pattern if you add new DnD interactions.

### 5.5 The duplicate `useApprovalSets()` call in `Approvals/index.tsx`

`index.tsx` calls `useApprovalSets()` for `sets.length` (splash gate),
and so does `SetsBoard.tsx`. Two hook instances, two `useMutation`s
created (only one used). Code smell, no UX impact, never fixed
because the implementation_process memory says "don't refactor beyond
what the task requires." If Task 2 brings you into either file for
real reasons, consider splitting the hook into `useApprovalSetsData`
+ `useApprovalSetActions`. Optional.

### 5.6 Generated assets are NOT persisted to `pcm_assets`

The Ads module generates copy + media into `orchestration.mediaSlots` /
`textSlots`. Those live in memory until the user shares to an approval
set. There is no asset-table row to tag. The per-item tagging Task 2
implements lives in the snapshot JSON, which IS the persistence layer
for these generated items. Do not "fix" this by persisting to
pcm_assets — that's not the modular boundary.

---

## 6. Workflow conventions to follow

From `feedback_implementation_process` memory + this branch's history:

### Pre-implementation
```bash
git commit --allow-empty -m "YYYY-MM-DD HH:MM BEFORE IMPLEMENTATION OF <feature>"
```

### During implementation
- One task at a time. Don't bundle Task 1 + Task 2 into a single commit.
- Append one line per substantive change to
  `/docs/CHANGELOG-YYYYMMDD-HHmm.md`.
- File > 400 lines → consider refactoring into modular pieces.
- No silent fallbacks. If the server returns something unexpected, fail
  loud, don't band-aid.

### Post-implementation
```bash
git commit -m "YYYY-MM-DD HH:MM AFTER IMPLEMENTATION OF <feature> - UNVERIFIED"
# Wait for user to test.
git commit -m "YYYY-MM-DD HH:MM AFTER IMPLEMENTATION OF <feature> - VERIFIED"
```

Bump plugin version in `power-creatives.php` (both `Version:` header
and `PCM_VERSION` constant). Patch bumps for fixes, minor for new
feature modules.

Build via `cd app && npm run build` (or run `npm run build:watch` in a
side terminal). `index-writer.js` ends up at
`app/dist/index-writer.js` — that's what WP enqueues.

---

## 7. Files to read first

In this order:

1. This document.
2. [`HANDOVER-DnD-jump-back-bug.md`](HANDOVER-DnD-jump-back-bug.md) —
   the prior autopsy. Read §11 (honest assessment) and §7 (instrumentation
   approach).
3. [`CHANGELOG-20260527-0425.md`](CHANGELOG-20260527-0425.md) — the
   ID-as-string root-cause writeup. Embeds the defensive pattern.
4. [`CHANGELOG-20260527-0550.md`](CHANGELOG-20260527-0550.md) — the
   v1.5.0 delete + bulk-select feature. The pattern Task 2's bulk-tag
   action should follow.
5. [`includes/modules/brands/`](../includes/modules/brands/) — the
   exact PHP module template Task 1 should mirror.
6. [`app/src/modules/Approvals/hooks/useApprovalSets.ts`](../app/src/modules/Approvals/hooks/useApprovalSets.ts)
   — Task 2 extends this. Note the boundary normalization on lines
   ~98-114 and the flushSync wrappers.

---

## 8. What is explicitly NOT in scope

- Touching `pcm_assets` schema or its module's controller
- Touching `pcm_projects` schema (extending it to know about delivery
  would be a cross-cutting change; PO ruled out)
- Auto-persisting generated Ads assets to `pcm_assets` on generation
  completion (still feels like a useful feature; still out of scope
  here)
- Long-press for touch select-mode (polish, separate task)
- Move-to bulk action in the Approvals kanban (separate task — bar
  currently only has Delete)
- Undo-toast pattern for delete (separate task — modal confirm is the
  shipped UX)
- Splitting `useApprovalSets` into data + actions hooks (optional, see
  §5.5)

---

## 9. Recent commit history (for context)

```
ae1b86e 2026-05-27 05:50 AFTER IMPLEMENTATION OF approvals delete + bulk-select UX - UNVERIFIED
99547c3 2026-05-27 05:50 BEFORE IMPLEMENTATION OF approvals delete + bulk-select UX
de80ab8 2026-05-27 04:25 AFTER IMPLEMENTATION OF DnD jump-back ROOT FIX - UNVERIFIED  ← THE BIG ONE
7514af3 2026-05-27 04:25 BEFORE IMPLEMENTATION OF DnD jump-back ROOT FIX (string/number ID coercion)
a290ae3 2026-05-27 04:10 AFTER IMPLEMENTATION OF DnD jump-back DIAGNOSTIC instrumentation
a4ed953 2026-05-27 03:35 AFTER IMPLEMENTATION OF DnD jump-back fix via flushSync - UNVERIFIED
68d545e 2026-05-26 13:00 handover doc: DnD jump-back bug not fixed after 3 attempts
```

The lesson buried in this chronology: 4 attempts failed before
instrumentation revealed the actual cause (Number-vs-string id). Don't
repeat it.

---

## 10. Honest note for the next dev

The previous session was repeatedly overconfident and underinstrumented.
The same trap is waiting for Task 1 and Task 2. Specifically:

- **Task 1 (Deliveries):** mirror Brands. Don't invent. The Number(id)
  normalization is non-optional.
- **Task 2 (Approvals tagging):** the simplest version is correct.
  Don't add cross-module dependencies. Don't propose schema changes
  outside Approvals. The PO has stated this explicitly and it remains
  the architectural rule.

If you find yourself wanting to do "one more clever thing" while in
either task — stop. Ship the clean modular version. Add cleverness in a
separate commit if it survives a day of reflection.

Good luck.
