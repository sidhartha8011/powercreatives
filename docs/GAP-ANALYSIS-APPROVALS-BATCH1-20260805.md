# FACTUAL GAP + CHECKLIST — batch 1 of 3
**Date:** 2026-08-05 · Items **5, 5b, 6, 7, 8** of the owner-approved list (`dcca912`).
Every TODAY line verified at `file:line` or by measurement today.

---

## THE GAP

### G5 — Approve-on-behalf: the server is proven, the UI is not

| TODAY (verified) | MUST BE |
|---|---|
| The write is real: `reviewFeedback` went from `NULL` to `approvedCustomIds = 9e6c52ac-…` after one click, request `200`. **But I verified it by reading the Approve BUTTON, which always reads "Approve"** — the state is shown by the status pill (`CreativeAssetCard.tsx:615`, `isApproved ? 'Approved' : 'Awaiting'`) and the card's `approved` class (`:331`). So whether the card visibly reflects the approval is **unverified**. | The pill reads "Approved" and the card carries the `approved` class after the click, verified by reading **those** elements. |

### G5b — Approving the last asset silently strands the board. **My defect.**

| TODAY (verified) | MUST BE |
|---|---|
| `approve_assets` advances a fully-approved set to `launch` — observed live: set 18 moved `client → launch` on the click. The modal calls only `onChanged` → `refetchPreviewSet`, which refetches **`getPublicSet` only**. The board's lanes come from a **separate** query (`trpc.approvals.listSets`, invalidated via `LIST_QUERY_PREFIX` — `useApprovalSets.ts:154, 188, 217`). Nothing invalidates it, so **the card sits in the wrong lane until a reload**. | Approving refreshes the board list too. `useApprovalSets` already exposes `refetch` (`:33`, `:99`) — no new plumbing. |

### G6 — There is no way to remove an item from a card

| TODAY (verified) | MUST BE |
|---|---|
| **Zero** delete routes for an asset. `DELETE /approvals/sets/{id}` exists for a whole set (`controller.php:67`). The numeric-id vs token split is already the established way to keep authenticated and public asset routes from colliding (`controller.php:78-81`). | `DELETE /approvals/sets/{id}/assets/{assetId}`, ownership-scoped, mirroring `append_to_set` (`service.php:179-207`): scoped read, refuse with `locked` when the set is post-submit **or** fully approved, then write. Removal loops the **existing** `ITEM_BUCKETS` constant — no second bucket list. |

### G7 — A sub-asset in the expanded card cannot be opened

| TODAY | MUST BE |
|---|---|
| `SetCard` lists items (type label + title) with **no** per-item action — no handler exists (`onOpenItem` / `onOpenAsset`: 0 matches). | Clicking a row opens the card and focuses that asset. The card modal already renders every asset; the row needs to say *which*. |

### G8 — A sub-asset cannot be deleted from the board

| TODAY | MUST BE |
|---|---|
| No delete affordance on an item row (`onDeleteItem` / `onRemoveItem`: 0 matches), and no route to call (G6). | A delete action per row, **immediate with undo**, refused by the SERVER on a locked card rather than merely hidden. |

---

## CHECKLIST — batch 1

- [ ] **5.1** Verify approve-on-behalf against the **pill and the card class**, not the button.
      **Gate:** pill reads "Approved" AND `.pcm-card.approved` present AND the DB row shows the id.
- [ ] **5b.1** Approving refreshes the board list as well as the opened set, using the `refetch`
      `useApprovalSets` already exposes. **Gate:** approve the last asset, close the modal, and the
      card is in the Launch lane **without a reload**.
- [ ] **6.1** `PCM_Approvals_Service::remove_asset(int $set_id, int $user_id, string $asset_id)` —
      scoped read, the same `locked` guard as `append_to_set`, removal driven by `ITEM_BUCKETS`.
- [ ] **6.2** `DELETE /approvals/sets/{id}/assets/{assetId}` (`edit_posts`) + the tRPC route.
      **Gate:** returns `locked` on a post-submit card — refused by the server, proven by calling it.
- [ ] **7.1** Item rows in the expanded card open that asset.
      **Gate:** click a row, the card opens, that asset is on screen.
- [ ] **8.1** Delete action per item row, immediate with an undo toast.
      **Gate:** item disappears, DB no longer holds it; undo restores it.
- [ ] **V** `php -l` each touched PHP file · harness **88/88** · tsc **59, zero new** · build ·
      **executed in a browser, sampling the state the owner sees, including the waiting and locked
      states** · data restored to its pre-test values · changelog · AFTER commit **LOCAL ONLY**.

## NOT IN THIS BATCH
Items 9-14 (edit any type + host token, add asset, Preview-as-client + per-asset share, share
popover executed, collapse the duplicated map, Approve/Comment wrapping) are batch 2 and 3.

## STANDING CONSTRAINT
`pm.max_children = 2` makes each browser verification cost minutes and has already timed one run
out. R4 (2→6) is the owner's decision and is not taken here.
