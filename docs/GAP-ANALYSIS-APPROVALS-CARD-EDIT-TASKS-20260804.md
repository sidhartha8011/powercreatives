# GAP ANALYSIS — editable cards, the card-type structure, and the card as an internal task
**Date:** 2026-08-04 · **Owner order + GO.** Covers everything discussed in this thread:
the card that cannot be edited, whether the card types are senior code, and turning the
approval card into an internal task with an owner and an assignee.

Every fact is at file:line in today's tree.

---

## 1. WHY THE CARD CANNOT BE EDITED — not a regression, a hole that only just became visible

| # | Fact | Where |
|---|---|---|
| F1 | Click-to-edit is bound **only for ad copy**: `onClick={type === 'copy' ? handleCardClick : undefined}`. | `CreativeAssetCard.tsx:401` |
| F2 | `article` and `custom` cards instead open `ArticleViewerDialog`. | `:517`, `:562` |
| F3 | That viewer is constructed **`editable: false`** — it is a reader by design, with no save path. | `CreativeAssetCard.tsx` (`ArticleViewerDialog`) |
| F4 | ⇒ **custom cards have never been editable**, and `custom` is exactly what the new "Add Approval Set" flow produces. Nothing regressed; the capability was only ever built for one of four types. | derived F1–F3 |
| F5 | The **server already supports it**: `update_snapshot_asset` handles the `custom` bucket (`title`, `content` via `wp_kses_post`, `images`, `annotation`) and the `articles` bucket (`title`, `content`, `metaTitle`, `metaDescription`). The UI simply never calls it for those types. | `approvals/service.php` (`update_snapshot_asset`) |
| F6 | The permission gate is **fine** — `pcmConfig.user.isLoggedIn` is `true` in admin and `$is_team_member` under the shortcode. Being logged in is already detectable. | `class-pcm-admin.php:203-209` · `class-pcm-shortcode.php:476-503` |

**Conclusion:** one line decides editability, per type, and three of the four types were
never wired. The backend and the auth signal both already exist.

---

## 2. THE CARD STRUCTURE IS NOT SENIOR — and that is *why* §1 happened

| # | Fact | Where |
|---|---|---|
| F7 | `CreativeAssetCard.tsx` is **729 lines**, one component, with four inline `type === …` branches each carrying its own JSX, plus a second component (`ArticleViewerDialog`) defined in the same file. | `CreativeAssetCard.tsx` |
| F8 | Behaviour is decided by scattered ternaries on `type` (`:401`, `:517`, `:562`, and the lightbox/viewer blocks), not by any declaration of what a type *is*. | same |
| F9 | ⇒ "Is this type editable?" is not answerable from one place, so it was answered for `copy` and forgotten for the rest. Adding a fifth type means editing the monolith again and re-deciding every branch by hand. | derived |
| F10 | This is the exact shape the owner already ruled against for `SectionModal` (structural-quality mandate 2026-07-19: one concern → one file with an explicit contract). | `docs/BLUEPRINT-MASTER-OPTIMIZER-20260715.md` §10 |

**The fix is a card-type registry:** one file per type declaring `{ id, label, render,
editable, save, preview }`. Editability becomes a property of the type, impossible to forget,
and a new type is a new file plus one registry line — never a branch in a 729-line component.

---

## 3. THE CARD AS AN INTERNAL TASK — what exists, what is missing

**The design (owner-approved, deliberately minimal):** the document's **checkboxes are the
tasks**. No new entity, no new screen. The lane says who holds it. Two people are named on
the card. Progress is derived and internal-only.

| # | Fact | Where |
|---|---|---|
| F11 | `approval_sets.userId` already exists and is the creator — that is the **owner** with no schema change. | `class-pcm-schema.php:631` |
| F12 | There is **no assignee column**. `approval_sets` has `userId, brandId, projectId, deliveryId, name, token, status, clientEmail, clientSentAt, snapshot, reviewFeedback, createdAt, updatedAt`. | `class-pcm-schema.php` approval_sets DDL |
| F13 | A platform-user registry exists and is listable: `GET /users` (`manage_options:coadmin`), wired as **`users.list`**. So an assignee picker needs no new endpoint. | `users/controller.php:43` · `trpc-routes.ts:1169` |
| F14 | `PCM_DB_VERSION` is **1.45.0**; adding a column means a `dbDelta` entry plus a `version_compare` gate in `maybe_upgrade()` and a bump. | `power-creatives.php:39` |
| F15 | An in-app notification action already exists (`notifications.create`) and is driven by editable automation rules — so "notify the owner" needs a **trigger**, not a new channel. | `automations/class-pcm-automation-seeds.php` |
| F16 | Tiptap task lists are supported by the shared extension set the card editor already uses, so checkboxes need no new dependency. | `components/shared/editorExtensions.ts` |
| F17 | The client surface is a **separate component** (`ClientReviewPage`), so hiding progress/owner/assignee from clients is structural, not a conditional — the client page simply never renders them. | `ClientReviewPage.tsx` |
| F18 | Board filters are declarative — a new filter is one row in `setFilters.ts` plus one dropdown, exactly like Brand/Delivery/Project. | `kanban/setFilters.ts` · `kanban/SetsBoard.tsx` |

---

## 4. FULL CHECKLIST

### A — Make the card editable (the blocking bug)
- [ ] A1 Custom cards editable in place for logged-in team members: title + document, saving through the existing `updateSnapshotAsset` (F5).
- [ ] A2 Article cards editable the same way (title, content, meta) — same endpoint, already supported.
- [ ] A3 The client (not logged in) keeps the read-only viewer. Editing is gated on `isLoggedIn` (F6), never on lane.
- [ ] A4 Saving refreshes the card in place; no reload.

### B — The card-type registry (so A can never regress)
- [ ] B1 `cardTypes/` — one file per type (`media`, `copy`, `article`, `custom`) declaring id, label, render, **editable**, save.
- [ ] B2 `CreativeAssetCard` becomes a thin host that looks the type up; the four `type ===` branches and the inline viewer move out.
- [ ] B3 Adding a type = new file + one registry line. No monolith edit.

### C — Owner and assignee
- [ ] C1 Schema: `approval_sets.assigneeId int(11) NULL` + `dbDelta` + `version_compare` gate in `maybe_upgrade()` + `PCM_DB_VERSION` → 1.46.0 (F14).
- [ ] C2 **Owner** = existing `userId` (F11), displayed with its name; no new column.
- [ ] C3 **Assignee** = optional, chosen from `users.list` (F13); clearable, because unassigned is a real state.
- [ ] C4 Both on the card dialog's Row 1/2 area and on the board card, internal surfaces only.
- [ ] C5 REST: accept `assigneeId` on create + a PATCH to change it; validate the user exists; return it in `list_sets_by_user`'s column list.

### D — Progress from the document, internal only
- [ ] D1 Enable Tiptap task-list checkboxes in the card editor (F16).
- [ ] D2 Derive "N of M done" from the document — never stored, so it cannot drift.
- [ ] D3 Show it on the board card and in the dialog. **Never** on `ClientReviewPage` (F17).

### E — Filter by owner
- [ ] E1 One row in `setFilters.ts` for owner, one `SearchableSelect` in the bar, options from `users.list` (F18).
- [ ] E2 Optionally the same for assignee — same one-line pattern.

### F — Notify
- [ ] F1 A trigger when a set is assigned, so the existing `notifications.create` rule can fire (F15). No new channel.
- [ ] F2 The owner can opt out; it is an editable automation rule like every other.

### G — Verify (every step)
- [ ] G1 `php -l` each touched file · harness 88/88 · tsc 59 pre-existing ZERO new · build · changelog · AFTER commit **LOCAL ONLY**. Never push.

## 5. ORDER
A (unblocks the user) → B (stops A regressing) → C → D → E → F.

## 6. STILL CARRIED, NOT FORGOTTEN
- The 15–30 s share click: server measured at ~120 ms; cause is browser/transport (plan `19ff403`).
- Document edits not persisted after save (gap `270356c`).
- The dead "View client feedback" chip (`approvals/service.php:69`).
- 66 hand-built tRPC bodies app-wide carrying the allow-list trap that broke sending.
- Bundle code-splitting (5.28 MB).
