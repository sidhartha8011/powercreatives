# Card editor alignment — approved plan A

Approved 2026-08-06. Solution A: render the document sheet **inside** the dialog,
plus the two corrections that belong to the same cleanup.

> `task-process.md` is referenced by the implementation process but does not exist
> anywhere in this repository (searched the whole tree). Flagged, not invented,
> not blocking. Each task below still carries its own explicit completion test.

## Goal

Open an approval card and write in it exactly like the Writer's A4 pad — same
engine, same toolbar, same size — and every change survives a reload.

## Root cause being removed

`PreviewDialog` is a modal Radix dialog. A modal dialog sets `trapFocus`,
`disableOutsidePointerEvents` and calls `hideOthers()` on everything outside its
own content. `CardDocumentView` portals to `document.body`, i.e. outside it, so
it was unclickable, unfocusable and hidden from screen readers. Known Radix
behaviour: primitives#922, #2122, #2544.

The industry answer, and the one the shadcn/Radix docs give: portal a nested
overlay into the **dialog content element**, not into `document.body`.

Three separate patches are being removed and replaced by that one change.

---

## TASKS

Each task: implement → run its own verification → only then move on.

- [ ] **T1 — Remove the three overlapping patches.**
  Revert `modal={false}` (PreviewDialog), `pointer-events: auto`
  (client-review.css), and confirm the `onPointerDownCapture` line is gone
  (CardDocumentView). One cause must have one fix.
  *Done when:* none of the three remain in the tree, `tsc` = 59.

- [ ] **T2 — Give the sheet a portal target.**
  `CardDocumentView` takes `container?: HTMLElement | null` and portals there,
  defaulting to `document.body` so the public client page is unchanged.
  *Done when:* `tsc` = 59, default path byte-identical in behaviour.

- [ ] **T3 — Host the sheet inside the dialog.**
  `PreviewDialog` holds a ref to its `DialogContent` and passes it down through
  `ClientReviewPage` → `ApprovalSetContents` → `CreativeAssetCard` →
  `CardDocumentView`. The dialog becomes full-viewport (no transform centring)
  so a `position: fixed` sheet still covers the screen.
  *Done when:* the sheet is a DOM descendant of `[data-slot="dialog-content"]`,
  measured in the browser.

- [ ] **T4 — One editor instance, not two.**
  `CustomCardEditor` gains `editable` (default `true`). `CardDocumentProse` is
  deleted; the read path becomes the same component in read-only mode, which is
  the pattern Notion-style editors use. Removes a whole duplicate Tiptap setup.
  *Done when:* `CardDocumentProse` no longer exists, `tsc` = 59, the public
  client page still renders a document read-only.

- [ ] **T5 — Type scale back to the owner's decision.**
  The card editor reads the Writer pad scale (`--pcm-prose-*`, 11px/1.7) as
  recorded at index.css:1022. My `--document` 16px override is removed.
  *Done when:* computed `font-size` inside the sheet is `11px`.

- [ ] **T6 — Prove one edit survives.**
  Tick a checkbox on set 18, close, reload, read
  `wp_pcm_approval_sets.snapshot` and see `data-checked="true"`.
  *Done when:* that string is in the database.

- [ ] **T7 — Verify ritual.**
  `php -l` each touched PHP file → `tests/standalone/run.php` 88/88 →
  `npx tsc --noEmit` = 59 baseline, zero new → `npm run build` → changelog line.

---

## Files touched, and nothing else

| File | Tasks |
| --- | --- |
| `app/src/modules/Approvals/kanban/PreviewDialog.tsx` | T1, T3 |
| `app/src/modules/Approvals/components/CardDocumentView.tsx` | T1, T2, T4 |
| `app/src/modules/Approvals/components/CustomCardEditor.tsx` | T4, T5 |
| `app/src/modules/Approvals/components/ClientReviewPage.tsx` | T3 |
| `app/src/modules/Approvals/components/ApprovalSetContents.tsx` | T3 |
| `app/src/modules/Approvals/components/CreativeAssetCard.tsx` | T3 |
| `app/src/modules/Approvals/client-review.css` | T1 |
| `app/src/index.css` | T5 |

No new component, no new route, no new endpoint. Every file stays under 400
lines; `CardDocumentView` gets smaller (T4 deletes a component from it).

## Deferred, recorded

Solution C — stop nesting entirely, open the document straight from the board.
Removes four of six layers and makes this bug class impossible. Backlog.
