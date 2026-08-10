# GAP ANALYSIS — approval sets as COLLECTIONS of typed asset items
**Date:** 2026-08-05 · **Owner order:** "Treat the approval sets as collections, and the cards
themselves are small assets inside. They can have different types."

**The goal, in the owner's five bullets:** lane shows one card only · expand card, see sub-items
instantly · click sub-item, open it directly · modal is client view, editable · add or delete
items inside.

Every TODAY line is verified at `file:line`, by live query, or by count. No opinion.

---

## 1. WHAT A CARD IS TODAY

| # | Fact | Evidence |
|---|---|---|
| F1 | One `approval_sets` row = one card. Its contents live in a `snapshot` JSON column as **four parallel arrays**: `media`, `copy`, `articles`, `custom`. | `types.ts:120-130`, `class-pcm-schema.php` |
| F2 | Appending already works and is correctly guarded — it refuses once the card is post-submit or fully approved. Dedupes by item `id`. | `service.php:179-207` (`append_to_set`), `:144-168` (`merge_snapshot`) |
| F3 | Per-item approval and per-item comment threads already exist, keyed by item id. | `types.ts:131-139`; `service.php:518` |
| F4 | `CreativeAssetCard` already takes `isTeamMember` and gates edit affordances on it. | `CreativeAssetCard.tsx:69, 261, 373, 589` |
| F5 | `KanbanBoard` hands the whole card body to the consumer via `renderCard(item, ctx)`. **A card can own its own expanded state without changing the shared primitive.** | `KanbanBoard.tsx:149, 260, 304` |
| F6 | MySQL 8.0.35 — item counts and summaries can be extracted in SQL without shipping the longtext. Measured live. | live query |

## 2. THE GAP

### G1 — "Asset type" is not a concept anywhere. It is spelled out by hand, ~180 times, in two different vocabularies. ⚠ THE ROOT

| TODAY (measured) | MUST BE |
|---|---|
| **168 lines** in `app/src` name an asset type by hand (`ClientReviewPage.tsx` 68, `Approvals/index.tsx` 23, `CreativeAssetCard.tsx` 17, `types.ts` 9, …). In PHP, ~12 more sites. Worse, there are **two parallel vocabularies for the same four things** — storage buckets `media / copy / articles / custom`, and approval lists `approvedVisualIds / approvedCopyIds / approvedArticleIds / approvedCustomIds` (note `media`→`Visual`) — with the mapping table between them **written out twice** (`service.php:1086-1089` and `:1113-1116`). | **One registry** owns every asset type: `{ id, label, icon, storageKey, approvalKey, render, editable, canDelete }`. Every consumer reads it. Adding "ad card" or "creative" is **one registry entry**, not an edit in ~180 places. **Gate:** a new type can be added touching exactly one file. |

### G2 — A card has no single list of its contents

| TODAY | MUST BE |
|---|---|
| Contents are four separate arrays. There is **no ordering across types** — a card holding two copies and an image has no defined sequence, so "the items in this card" cannot be rendered, reordered or counted without four hand-written passes. `merge_snapshot` loops a hardcoded bucket list (`service.php:146`). | One canonical, ordered `items[]` of `{ id, type, …payload }`, derived through the registry. The four buckets stay on disk for back-compat, read and written through **one** mapping defined once. |

### G3 — The board row carries nothing about its contents, so a card cannot expand

| TODAY | MUST BE |
|---|---|
| `list_sets_by_user` selects an explicit column list and omits `snapshot` on purpose — it is longtext and can carry embedded base64 images (`service.php:72-74`). So the board literally cannot say how many items a card holds, let alone list them. | The list ships a **summary** per card: `items[] { id, type, title, thumbUrl? }` plus counts, extracted in SQL (F6) with content/base64 left behind. **Expansion then costs zero network** — which is the entire point of the owner's model. |

### G4 — Nothing can expand, and nothing says a card has contents

| TODAY | MUST BE |
|---|---|
| `SetCard` renders a title, brand, lane and action chips. No disclosure control, no item count, no sub-items. | Collapsed: one card that always looks the same, stating how many items it holds. Expanded: its sub-items listed beneath it, each openable and deletable — rendered from the summary already on the row (G3), never a fetch. `KanbanBoard` needs **no change** (F5). |

### G5 — The client view cannot be reused as the modal

| TODAY | MUST BE |
|---|---|
| `ClientReviewPage` is a 598-line **page** — it owns the token query, hero, toolbar, submit flow and the asset grid, and carries 68 of the hand-written type references (G1). There is no component that renders "the contents of a card" for reuse. | The contents renderer is extracted so **one component** serves the public page and the admin modal, with admin powers switched on by the existing `isTeamMember` flag (F4). Same view the client gets — literally the same code — not a lookalike. |

### G6 — Missing server capabilities

| TODAY | MUST BE |
|---|---|
| There is **no per-item delete** route. There is **no per-item fetch** route — opening one sub-asset requires pulling the whole snapshot. `update_snapshot_asset` exists but is **token-scoped** (`service.php:1386`), i.e. built for the public client, not the admin. | `DELETE /approvals/sets/{id}/assets/{assetId}` — ownership-scoped, same lock guard as append. `GET /approvals/sets/{id}/assets/{assetId}` — returns one item via `JSON_EXTRACT`, so opening a sub-asset never pulls a heavy card. An **ownership-scoped** item update to sit beside the token-scoped one. |

### G7 — My own C7 contradicts the model. My defect.

| TODAY | MUST BE |
|---|---|
| `SetsBoard.tsx:231` opens `snapshot.custom[0]` — the first custom document only. Append three copies and they are silently invisible. It also replaced `PreviewDialog`, which had shown every asset as the client sees it. | Deleted. The modal renders the card's contents through the shared renderer (G5). No branch on kind: one item shows one item, ten show ten. |

---

## 3. TARGET ARCHITECTURE

```
APPROVAL CARD  (approval_sets row — the ONLY thing in a lane)
├─ collapsed → always the same card + "N items"
├─ expanded  → sub-items from the row summary   [ZERO network]
│    └─ per item: open · delete
└─ click     → modal = the CLIENT VIEW, same component, admin powers on
     ├─ edit the card
     ├─ edit / remove any item
     └─ add an item of ANY registered type
```

**One registry is the spine.** Type identity, storage key, approval key, icon, label, renderer and
permissions live in one place. Every other file asks the registry. That single change is what
turns "add an ad card / creative / custom text" from a ~180-site edit into one entry — and it is
the same rule that has been broken repeatedly in this module.

**Nothing new is stored.** No `type` column, no migration, no second table. A card with one item
and a card with twenty are the same record shape; the presentation follows the contents.

---

## 4. CHECKLIST

- [ ] **C1** `assetTypes.ts` registry — one entry per type, owning id/label/icon/storageKey/
      approvalKey/editable/canDelete. **Gate:** adding a type touches one file.
- [ ] **C2** PHP reads the bucket↔approvalKey mapping from **one** definition; delete the two
      duplicates at `service.php:1086-1089` and `:1113-1116`; `merge_snapshot` loops the registry.
- [ ] **C3** `list_sets_by_user` returns an item **summary** + counts via SQL JSON extraction.
      **Gate:** response carries items; `snapshot` still absent; payload stays small on a card
      with embedded images.
- [ ] **C4** `SetCard` states item count and expands in place to list sub-items. No `KanbanBoard`
      change. **Gate:** expanding fires zero network requests.
- [ ] **C5** Extract the card-contents renderer from `ClientReviewPage`; the public page and the
      admin modal both consume it. **Gate:** one definition, two consumers.
- [ ] **C6** Modal = that renderer with `isTeamMember`, in the existing Notion shell at its
      current size. Delete the `custom[0]` branch (G7).
- [ ] **C7** `DELETE` + `GET` per-item routes, ownership-scoped, lock-guarded (G6).
- [ ] **C8** Add-item inside the modal, options driven by the registry (C1).
- [ ] **C9** "Preview as client" restored as an explicit action.
- [ ] **V** `php -l` · harness 88/88 · tsc 59 ZERO new · build · **executed in a browser against a
      REAL multi-item card built via `appendToSet`** · changelog · AFTER commit **LOCAL ONLY**.

## 5. STAGING — each step verifiable alone
1. **C1 + C2** the registry and the server mapping — provable by adding a throwaway type.
2. **C3 + C4** summary on the row and expansion — provable as zero-network expand.
3. **C5 + C6** shared renderer and the modal — provable on a real multi-item card.
4. **C7 + C8 + C9** delete/fetch routes, add-item, preview-as-client.

## 6. NOT IN THIS ROUND
Editing a document from the admin (needs the `save(patch, ctx)` contract), `data-id` leaking into
saved content, the colour-literal debt, owner/assignee, the dead feedback chip.

## 7. THE 30-SECOND OPEN IS ENVIRONMENT, NOT ARCHITECTURE
Measured: `/wp-json/` 44.6 s, bare front page 45.8 s, static files 0.15 s every time, approvals
endpoint alternating 38 / 0.45 / 47 / 0.67 / 45 s. Cause is `pm.max_children = 2`
(`conf/php/php-fpm.d/www.conf.hbs:5`) — one worker occupied, everything else queues. C3+C4 route
**around** it by making the common case need no request at all; only R4 (2→6) removes it.
