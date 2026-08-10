# FACTUAL GAP — approval cards as collections (TRUTHFUL, CORRECTED SIZE)
**Date:** 2026-08-05 · **Supersedes the scope of `GAP-ANALYSIS-APPROVALS-COLLECTIONS-20260805.md`.**

**Correction, mine to own.** The earlier gap led with "180 hand-named type references" and framed
that inventory as prerequisite work under a three-phase programme. **That was wrong.** Those 180
references are existing, working code that is *not in the way* of anything the owner asked for.
Presenting a debt inventory as required scope made a day's work look like a quarter's. The facts
in that document are accurate; its **scope was inflated**. This document is the true one.

**Target — the owner's five bullets:** lane shows one card only · expand card, see sub-items
instantly · click sub-item, open it directly · modal is client view, editable · add or delete
items inside.

---

## 1. THE GAP, BULLET BY BULLET, WITH REAL SIZE

| Bullet | TODAY (verified) | MUST BE | Size |
|---|---|---|---|
| **1. Lane shows one card only** | Already true. The board maps over sets, never over assets — sub-items have never appeared as their own cards. | Unchanged. | **0 lines** |
| **2. Expand → sub-items instantly** | Impossible today: `list_sets_by_user` selects an explicit column list and omits `snapshot` (`service.php:72-74`), so a row carries **nothing** about its contents. `SetCard` has no disclosure control and no item count. | List query returns a per-card **summary** — `items[] { id, type, title }` + counts — extracted in SQL (MySQL 8.0.35, verified) so the longtext never crosses the wire. `SetCard` expands in place and renders it. **Zero network on expand.** | **~20 PHP + ~60 TSX** |
| **3. Click sub-item → open it** | The viewers already exist and work: `CardDocumentView` (documents, browser-verified) and `CreativeAssetCard` (media/copy/article). Nothing routes to them from the board. | Wire the expanded row's item to the existing viewer. | **~20 TSX** |
| **4. Modal = client view, editable** | The client view is not reusable. `ClientReviewPage.tsx` is a 598-line **page**: it owns the token query, hero, toolbar and submit flow. The reusable part is the asset derivation (`:337-341`, `:381`) plus the grid render (`:527-577`) — **~90 lines with ~12 inputs**. `CreativeAssetCard` already takes `isTeamMember` and gates edit affordances on it (`:69, 261, 373, 589`). | Extract those ~90 lines into one component; the public page and the admin modal both consume it, admin powers switched on by the existing `isTeamMember`. Same code, not a lookalike. | **~90 moved + ~30 wiring** |
| **5. Add / delete items inside** | **Add already works**: `append_to_set` merges, dedupes by id, and correctly refuses once the card is post-submit or fully approved (`service.php:179-207`). **Delete does not exist.** Only two asset routes are registered: `POST /sets/{id}/assets` (append, `edit_posts`) and `POST /sets/{token}/assets/{asset_id}` (update, **token-scoped, public**) — `controller.php:80-81`. There is no ownership-scoped delete, and no per-item fetch. | One new route: `DELETE /approvals/sets/{id}/assets/{assetId}`, ownership-scoped, reusing `append_to_set`'s existing lock guard. Add reuses `appendToSet` as-is. | **~35 PHP + ~15 TSX** |

**One supporting piece:** a small `assetTypes` registry — 4 entries — so the expanded rows have a
label and icon per type and new code stops hand-naming types. **~40 lines, one file.** It is a
convenience for this work, **not** a prerequisite refactor.

**TOTAL: roughly 300 lines across ~9 files, one new REST route, zero migrations, zero schema
changes, no new table, no type column.**

---

## 2. MY OWN DEFECT, STILL IN THE TREE

`SetsBoard.tsx:231` opens `fullPreviewSet?.snapshot?.custom?.[0]` — the first custom document
only. Append three copies to a card and opening it shows the document and **silently hides them**.
It also replaced `PreviewDialog`, which had shown every asset as the client sees it. **Deleted by
bullet 4** — the modal renders the card's contents, so one item shows one item and ten show ten.
No branch on kind, no `setKind` helper.

---

## 3. WHAT IS **NOT** REQUIRED — the correction to my earlier scope

| Not required | Why |
|---|---|
| Refactoring the 168 TS + ~12 PHP hand-named type references | They work and are not in the path of any of the five bullets. New code reads the registry; old sites migrate only when independently touched. |
| A `type` column, a second table, or any migration | A card with one item and a card with twenty are already the same record. |
| Rebuilding `KanbanBoard` | It hands the whole card body to the consumer via `renderCard` (`KanbanBoard.tsx:149, 260, 304`), so expansion is a card-level change only. |
| A `SET` vs `SINGLE` type split (my earlier proposal) | It creates the exact ambiguity this is meant to remove, and flips presentation under the user the moment they append. The owner's one-type model is correct. |
| Three phases | It is one change of ~300 lines. |

**The one duplication worth fixing while adjacent** (not a blocker): the bucket→approval-key map
is written out twice, `service.php:1086-1089` and `:1113-1116`. ~10 lines, and a real
wrong-approval hazard, so it goes when that file is open.

---

## 4. CHECKLIST

- [ ] **C1** `assetTypes` registry — 4 entries: id, label, icon, storageKey, approvalKey.
- [ ] **C2** `list_sets_by_user` returns `items[]` summary + counts via SQL JSON extraction.
      **Gate:** counts present, `snapshot` still absent, payload small on a card with images.
- [ ] **C3** `SetCard`: item count + expand in place, listing sub-items. **Gate:** expanding fires
      **zero** network requests.
- [ ] **C4** Per-item open from the expanded row into the existing viewers.
- [ ] **C5** `DELETE /approvals/sets/{id}/assets/{assetId}`, ownership-scoped, lock-guarded; delete
      action on the expanded row.
- [ ] **C6** Extract the card-contents renderer from `ClientReviewPage` (`:337-341`, `:381`,
      `:527-577`); public page + admin modal both consume it. **Gate:** one definition, two consumers.
- [ ] **C7** Modal = that renderer with `isTeamMember`, in the existing Notion shell at its current
      size. **Delete the `custom[0]` branch.**
- [ ] **C8** "Preview as client" restored as an explicit action.
- [ ] **C9** Collapse the duplicated bucket→approval-key map while in `service.php`.
- [ ] **V** `php -l` · harness 88/88 · tsc 59 ZERO new · build · **executed in a browser against a
      REAL multi-item card built via `appendToSet`** · changelog · AFTER commit **LOCAL ONLY**.

## 5. THE 30-SECOND OPEN IS ENVIRONMENT, NOT THIS
Measured: `/wp-json/` 44.6 s, bare front page 45.8 s, static files 0.15 s every time, approvals
endpoint alternating 38 / 0.45 / 47 / 0.67 / 45 s. Cause: `pm.max_children = 2`
(`conf/php/php-fpm.d/www.conf.hbs:5`) — one worker occupied, the rest queue. C2+C3 route **around**
it by making the common case need no request at all. Only R4 (2→6) removes it. Owner's call.
