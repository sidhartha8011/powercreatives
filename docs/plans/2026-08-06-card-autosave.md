# Card autosave — approved plan

Approved 2026-08-06 02:50 (local) / 09:50 UTC.

> `task-process.md` is referenced by the implementation process but does not exist
> anywhere in this repository. Flagged once. Each task carries its own test.

## Vision

Everything you change in a card is saved while you work — not only if you happen
to close it cleanly.

## Root cause, proven

| Evidence | Fact |
| --- | --- |
| `wp_posts` 231, 232 — `annotated-*.png`, 09:42:31 / 09:42:32 UTC | The upload works. Both files are in the media library. |
| `wp_pcm_approval_sets` 25 — `updatedAt 09:42:17` UTC | The card saved **14 seconds before** the upload finished. |
| `CardDocumentView` persists in a `useEffect` cleanup | The card saves **only on unmount**. |

So: annotate → upload starts → close → save writes the OLD content → upload
finishes → the URL is handed to a destroyed editor → lost. Two orphan files sit
in the media library and the card still points at the old image.

Same flaw for text: nothing is written until the card is closed.

**Not the cause:** approval status. `isSubmitted` only disables the Approve
button (`CardDocumentView.tsx:188`); nothing gates editing.

**Not the cause:** the upload. It succeeds; it is merely slow because a request
on this machine costs up to 45 s.

## Reused, not built

`modules/Writer/hooks/useWriterPersistence.ts` already implements exactly this:
`AUTOSAVE_DEBOUNCE_MS = 2_000`, one debounce timer per document, snapshot
dirty-checking so an unchanged document is never written, and silent failure so
autosave never interrupts. The card gets the same contract, not a new one.

---

## TASKS

One at a time. Implement → run the task's own test → changelog line → next.

- [ ] **A1 — The card saves while you work.**
  A shared `useDebouncedSave` hook carrying the Writer's contract (2 s debounce,
  dirty-check, silent failure), used by the card. Extracted so there is ONE
  definition of "autosave" rather than a second copy in Approvals.
  *Test:* type in a card, wait, do NOT close it — the change is in
  `wp_pcm_approval_sets.snapshot`.

- [ ] **A2 — Async work can no longer be orphaned.**
  An image upload marks the document dirty when it lands, so its URL is saved by
  the same autosave. Closing while an upload is in flight waits for it rather
  than saving stale content over it.
  *Test:* annotate an image, close immediately, reload — the card shows the
  annotated image and no orphan file is left unreferenced.

- [ ] **A3 — The save is visible.**
  A quiet state line in the sheet's top bar: saving / saved. No permanent hint
  text; it states what is happening and then stops.
  *Test:* it appears on change and disappears when the write completes.

- [ ] **A4 — Prove a full round trip.**
  Text, checkbox, title and an annotated image in one session; close; reload;
  read the database.
  *Test:* all four present in `wp_pcm_approval_sets.snapshot`.

- [ ] **A5 — Verify ritual.**
  `php -l` → `tests/standalone/run.php` 95/95 → `npx tsc --noEmit` = 59 baseline,
  zero new → `npm run build` → changelog.

## Carried over, still open

**T4 from the previous plan — split the two oversized files.**
`CreativeAssetCard.tsx` (748) and `includes/modules/approvals/service.php`
(1 785). Held back deliberately until the card is proven working, so a split does
not confuse two failure sources. Not forgotten.

## Deferred, recorded

Open the document straight from the board and drop four of the six nesting
layers. Backlog.
