# Card integrity — approved plan

Approved 2026-08-06 02:25.

> `task-process.md` is referenced by the implementation process but does not exist
> anywhere in this repository (whole tree searched). Flagged once, not invented.
> Every task below carries its own explicit completion test instead.

## Vision

Write, paste images and tick lists in an approval card exactly as in the Writer —
and trust that nothing ever disappears.

## Root cause, proven in the live database

| | set 25 (intact) | set 26 (emptied) |
| --- | --- | --- |
| content | `<img src="data:image/png;base64,…">` 489 939 B | `<p data-id="…"></p>` 54 B |
| updatedAt | `2026-08-06T08:02:42.950Z` — ISO, written by the CREATE path | `2026-08-06 09:17:15` — MySQL, written by `update_snapshot_asset` |

The destroying chain:

1. Images live as base64 **inside the document**, so a card weighs half a megabyte.
2. `service.php:1651` runs `wp_kses_post()` on the content. WordPress's allowed
   protocols (`functions.php:7245`) are `http, https, ftp, mailto, …` — **`data:`
   is not among them**, so `src="data:image/png;base64,…"` is stripped.
3. The next fetch hands Tiptap an `<img>` with no `src`; the node is dropped.
4. The next close saves that empty document over the real one. Content gone.

Three places produce those data URLs:
`CustomCardEditor.tsx:102` (paste/drop), `ImageAnnotator.tsx:120` (annotated
image), `CardDrawLayer.tsx:106` (draw overlay).

## Reused, not built

- `writer.uploadImage` → `articles/upload-image` → `PCM_Writer_Service::upload_image()`
  → `PCM_Storage::save_data()`. Takes base64, returns a media-library URL. Already
  generic; only its folder tag names the Writer.
- `getEditorExtensions`, `WriterBubbleMenu`, `CustomCardEditor` — already shared.
- `update_snapshot_asset` — already accepts title, content, images, overlay.
- Type tokens `--pcm-doc-*` (16px) and `--pcm-prose-*` (11px) — both already exist.

Nothing new is invented. Only wiring, one guard, and two file splits.

---

## TASKS

One at a time. Implement → run the task's own test → changelog line → next.

- [ ] **T1 — The save can never destroy.**
  (a) A document save must not strip embedded images: allow `data:image/*` for
  this one call via the `kses_allowed_protocols` filter, scoped and released
  immediately. (b) Content is never written EMPTY over non-empty content — an
  empty document arriving for a card that has content is refused, not stored.
  *Test:* a unit case in `tests/standalone/run.php` proving both; harness green.

- [ ] **T2 — Images leave the document.**
  All three producers upload through the existing route and store a URL.
  A shared `useCardImageUpload` hook so the three call sites share one
  implementation, not three.
  *Test:* paste an image, save, and read the snapshot — `src="http…"`, and the
  snapshot is kilobytes, not megabytes.

- [ ] **T3 — One type scale, one place.**
  The card reads the document scale (`--pcm-doc-*`, 16px/1.5). The Writer pad
  keeps `--pcm-prose-*` (11px) untouched. No component carries a pixel value.
  *Test:* computed `font-size` in the sheet is 16px; in the Writer pad, 11px.

- [ ] **T4 — Split the two oversized files.**
  `CreativeAssetCard.tsx` (748) and `includes/modules/approvals/service.php`
  (1 724). Split along what they already do, not invented seams.
  *Test:* every touched file under 400 lines; `tsc` 59; harness green.

- [ ] **T5 — Prove a round trip.**
  Tick a checkbox, close, reload, read the database.
  *Test:* `data-checked="true"` present in `wp_pcm_approval_sets.snapshot`.

- [ ] **T6 — Verify ritual.**
  `php -l` each touched PHP file → `tests/standalone/run.php` green →
  `npx tsc --noEmit` = 59 baseline, zero new → `npm run build` → changelog.

## Order

T1 first: until the save is safe, every other task risks more of your content.

## Deferred, recorded

Solution C from the previous plan — open the document straight from the board and
drop four of the six nesting layers. Backlog.
