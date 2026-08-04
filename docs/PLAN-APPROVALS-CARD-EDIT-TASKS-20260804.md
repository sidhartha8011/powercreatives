# PLAN — editable cards, card-type registry, owner/assignee (execution checklist)
**Source gap:** `GAP-ANALYSIS-APPROVALS-CARD-EDIT-TASKS-20260804.md` (commit `4d879ba`,
corrected in this commit — see the F16 correction).
**Rule:** a box is ticked only when the change is written AND its verify line passed. Never
on intent.

**Factual status of the source gap:** 17 of its 18 facts were verified at file:line or by
probe. **One (F16) was an assumption and it was wrong** — Tiptap task lists are NOT already
available; `TaskList`/`TaskItem` are absent from `editorExtensions.ts` and the packages are
absent from `package.json`. That correction is folded into the gap and into STEP 4 below.
No other claim in the gap rests on an unchecked premise.

---

## ⚠ SEQUENCING CORRECTED — STEPS 1 AND 2 MERGE INTO ONE PASS

The original order was A (make it editable) → B (registry, so A cannot regress). That order
**creates technical debt on purpose**: it adds a fifth `type ===` branch to a 729-line
component and then rewrites that same code in the next step. Two writes, two reviews, and a
window where the file is worse than it is today.

Since the standing bar is *no technical debt*, editability lands **as a property of the
registry**, not as another branch. Structure first, behaviour inside it. Same total work,
written once.

## STEP 1+2 (MERGED) — Card-type registry, with editability as one of its properties

- [ ] 1.1 `cardTypes/types.ts` — the contract every type implements:
      `{ id, label, render(ctx), editable: boolean, editFields, save(patch) }`.
- [ ] 1.2 One file per type, moved **verbatim** out of the host so behaviour cannot drift in
      the same commit that moves it: `media.tsx`, `copy.tsx`, `article.tsx`, `custom.tsx`.
- [ ] 1.3 `copy` declares `editable: true` with its existing inline-edit behaviour intact
      (it is the one type that already worked — it must come through unchanged).
- [ ] 1.4 `custom` declares `editable: true` — title + document — saving through
      `updateSnapshotAsset`, which already accepts that bucket server-side.
- [ ] 1.5 `article` declares `editable: true` — title, content, metaTitle, metaDescription —
      the exact fields that endpoint already accepts.
- [ ] 1.6 `media` declares `editable: false` (its `name` is the only writable field; not in
      scope, and saying so in the registry is the point).
- [ ] 1.7 Editing is gated on `pcmConfig.user.isLoggedIn` **only** — never on lane. A client
      without a login keeps the read-only viewer.
- [ ] 1.8 `CreativeAssetCard` becomes a thin host: look up the type, render it, and own only
      the shared chrome (approve / comment / status). The nested `ArticleViewerDialog` moves
      into the types that use it.
- [ ] 1.9 Saving updates the card in place — cache write, no reload.
- **Verify:** `grep -c "type === '" CreativeAssetCard.tsx` → **0** · tsc 59 pre-existing ZERO
  new · build · all four types render, approve and comment · a custom card and a copy card
  both edit and persist.

## STEP 3 — Owner and assignee (gap C)
- [ ] 3.1 Schema: `approval_sets.assigneeId int(11) NULL`, `dbDelta` + `version_compare`
      gate in `maybe_upgrade()`, `PCM_DB_VERSION` 1.45.0 → **1.46.0**.
- [ ] 3.2 Owner = the existing `userId`, shown by name. No new column.
- [ ] 3.3 Assignee = optional and **clearable** (unassigned is a real state), options from
      the existing `users.list` route.
- [ ] 3.4 REST: accept `assigneeId` on create + a PATCH to change it; validate the user
      exists; add it to `list_sets_by_user`'s column list or the board cannot read it.
- [ ] 3.5 Both shown on internal surfaces only.
- **Verify:** `php -l` · harness 88/88 · migration runs once on a real page load · tsc ZERO new.

## STEP 4 — Progress from the document (gap D) · **BLOCKED ON A DECISION**
- [ ] 4.0 **Owner decision:** add `@tiptap/extension-task-list` + `-task-item` (3.x, matching
      the tree), or defer checkboxes and ship STEP 5/6 first. Not started until answered.
- [ ] 4.1 Checkboxes in the card editor.
- [ ] 4.2 "N of M done" **derived** from the document, never stored, so it cannot drift.
- [ ] 4.3 Rendered on the board card and the dialog. **Never** on `ClientReviewPage`.

## STEP 5 — Filter by owner (gap E)
- [ ] 5.1 One row in `setFilters.ts` + one `SearchableSelect` in the bar, options from
      `users.list` — the same shape as Brand / Delivery / Project.
- [ ] 5.2 Same for assignee if wanted; identical one-line pattern.

## STEP 6 — Notify on assign (gap F)
- [ ] 6.1 A trigger fired on assignment so the existing `notifications.create` rule can run.
      No new channel.
- [ ] 6.2 Editable/disable-able like every other automation rule.

## CLOSING VERIFY (every step)
- [ ] V1 `php -l` each touched PHP file
- [ ] V2 `tests/standalone/run.php` 88/88
- [ ] V3 tsc — 59 pre-existing, ZERO new
- [ ] V4 `npm run build` ("built in" prints)
- [ ] V5 changelog line · AFTER commit ending **LOCAL ONLY** · never push

## CARRIED (not this round, not forgotten)
- The 15–30 s share click — server measured ~120 ms; browser/transport (plan `19ff403`).
- Document edits not persisted after save (gap `270356c`).
- The dead "View client feedback" chip (`approvals/service.php:69`).
- 66 hand-built tRPC bodies carrying the allow-list trap that broke sending.
- Bundle code-splitting (5.28 MB).
