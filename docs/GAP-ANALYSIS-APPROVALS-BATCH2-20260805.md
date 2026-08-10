# FACTUAL GAP + CHECKLIST — batch 2 of 3
**Date:** 2026-08-05 · Items **9, 10, 11, 12, 13** of the approved list (`dcca912`).
Verified at `file:line` today.

---

## G9 — Editing. **The item as written cannot be finished this round, and here is why.**

| TODAY (verified) | MUST BE |
|---|---|
| **9a — the token.** `CreativeAssetCard.tsx:280` reads the client token from `window.location.search`. In wp-admin that parameter does not exist, so a save from the admin modal sends `token: ''` and fails. The host already holds the token (`row.token`). | The host passes the token in. No component reaches for `window`. |
| **9b — every type.** Only `copy` has an editor: `isEditingText` state (`:153`), the edit click bound to `type === 'copy'` (`:332`), and the edit UI at `:386-405`. `media`, `article` and `custom` have **no editing path at all** — not a disabled one, none. Building them means a per-type editor and a `save(patch, ctx)` contract, which are items **D1 (card-type registry)** and **D7**, both explicitly deferred out of this round by the approved plan. | 9b is honestly **blocked on D1/D7**. Doing it properly means pulling them in; faking it means a fourth bespoke editor. Named, not silently skipped. |

## G10 — Add an asset inside the card

| TODAY | MUST BE |
|---|---|
| No add affordance in the modal. `append_to_set` exists, is ownership-scoped and lock-guarded (`service.php:179-207`), and `approvals.appendToSet` is already wired. | An add control in the card, offering the registered types, calling the route that already works. |

## G11 — "Preview as client" and the per-asset share link

| TODAY (verified) | MUST BE |
|---|---|
| `PreviewDialog` has **zero** consumers — I removed its import and orphaned it. The capability is gone, not deferred. | Reachable again as an explicit action from the card modal header. |
| **Zero** `pcm_asset` references in `app/src` (the one hit is an unrelated comment in the Video module). `App.tsx:21-26` routes on `pcm_public_token` only. | Per-asset share emits `&pcm_asset=<id>`; the client page opens that asset on load. |

## G12 — The share popover has never been opened

| TODAY | MUST BE |
|---|---|
| I measured that the button exists. The popover has never been opened and no send has ever been executed from it. | Opened, and a real send executed against a real address. |

## G13 — One map, written twice

| TODAY (verified) | MUST BE |
|---|---|
| The bucket→approval-key map appears **twice**, identically: `service.php:1086-1089` and `:1113-1116`. Two definitions of which approval list belongs to which bucket is a wrong-approval hazard. | One definition. `ITEM_BUCKETS` already exists as the single list of buckets; the approval key joins it. |

---

## CHECKLIST — batch 2

- [ ] **9a** Token supplied by the host, never `window`. **Gate:** edit a copy asset from the ADMIN
      modal and confirm the change in the database.
- [ ] **9b** **BLOCKED on D1/D7 — not attempted.** Recorded here so it is not mistaken for done.
- [ ] **13** Collapse the duplicated map into one definition. **Gate:** the literal
      `'media' => 'approvedVisualIds'` appears exactly once.
- [ ] **11a** "Preview as client" reachable from the card header. **Gate:** `PreviewDialog` has one
      consumer again.
- [ ] **11b** Per-asset share link emits and is honoured. **Gate:** open the URL, that asset opens.
- [ ] **10** Add an asset inside the card, types from the registry. **Gate:** added item appears in
      the card and in the database.
- [ ] **12** Share popover opened and a send executed.
- [ ] **V** `php -l` · harness **88/88** · tsc **59, zero new** · build · executed in a browser ·
      data restored · changelog · AFTER commit **LOCAL ONLY**.

## ORDER — correctness first, then capability
13 (a real hazard, ~10 lines) → 9a (editing is broken in admin without it) → 11a (restores a
capability I removed) → 11b → 10 → 12.

## STANDING
`pm.max_children = 2` — every browser verification costs minutes. R4 is the owner's call.
