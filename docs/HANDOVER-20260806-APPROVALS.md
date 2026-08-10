# HANDOVER — Approvals card — 2026-08-06

Written by the outgoing developer. Read this before touching the Approvals
module. Everything below is either a fact with its source, or is labelled
UNVERIFIED. Nothing here is a conclusion dressed as a measurement.

---

## 1. State of the tree, right now

**Last commit:** `8024974` — `2026-08-06 02:50 BEFORE IMPLEMENTATION OF card autosave`

**Uncommitted, and NONE of it observed working in a browser:**

| File | What changed | Risk |
| --- | --- | --- |
| `kanban/PreviewDialog.tsx` | Reverted my full-screen regression back to the `95vw × 90vh` popup | Low — restores prior behaviour |
| `components/CardDocumentView.tsx` | Autosave wired in; `onSave` now returns a promise | **Unverified** |
| `components/CreativeAssetCard.tsx` | `handleSaveDocument` uses `mutateAsync` and returns the promise | **Unverified** |
| `modules/Approvals/client-review.css` | Save-state line style | Low |
| `hooks/useDebouncedSave.ts` (new, 138 lines) | Shared autosave contract | **Unverified** |
| `docs/CHANGELOG-20260806-0225.md` | Changelog entries | None |

`git reset --hard 8024974` returns to the last committed state. That state has
the data-loss guard and the image-upload work in it, but **not** the autosave and
**not** the full-screen revert — so resetting reintroduces the full-screen
preview regression. Read section 3 before deciding.

**Green at that commit:** `php -l` clean · `tests/standalone/run.php` 95/95 ·
`npx tsc --noEmit` 59 (the long-standing baseline, zero new) · `npm run build` OK.

---

## 2. What actually got fixed, with evidence

### 2.1 A save could empty a card — FIXED, covered by tests

**Proven in the live database:**

| | set 25 (intact) | set 26 (emptied) |
| --- | --- | --- |
| content | `<img src="data:image/png;base64,…">` 489,939 B | `<p data-id="…"></p>` 54 B |
| updatedAt | `2026-08-06T08:02:42.950Z` ISO — the CREATE path | `2026-08-06 09:17:15` MySQL — `update_snapshot_asset` |

The chain: images lived as base64 inside the document → `wp_kses_post()` strips
any `src` whose scheme is missing from `wp_allowed_protocols()`
(`wp-includes/functions.php:7245`, no `data:`) → the next fetch handed Tiptap an
`<img>` with no `src` → the node was dropped → the next close saved the emptied
document over the real one.

Fixed by two guards in `includes/modules/approvals/service.php`:
- `sanitize_document_html()` — permits `data:` for the duration of that one call
  via `kses_allowed_protocols`, then removes the filter. Full tag/attribute
  filtering still runs.
- `would_erase_document()` — an empty document is never stored over content.

**7 tests in `tests/standalone/run.php`**, extracted from the real service file.
The first is the set 26 regression. Scope is stated honestly in the test file:
only the erase guard is provable standalone; the kses widening depends on
WordPress's own protocol list and is **not** proven by the harness.

### 2.2 Images no longer embed themselves — DONE, UNVERIFIED end-to-end

Three producers used to write base64 into the document: paste/drop
(`CustomCardEditor`), annotation (`ImageAnnotator`), draw layer
(`CardDrawLayer`). All three now upload through `useImageUpload` — the hook the
Writer canvas already used — which gained `uploadDataUrl()` for canvas output.

The draw layer emits after every stroke, so its output is buffered in
`pendingOverlay` and uploaded once on done/cancel/toggle-off.

**Proven working:** attachments `wp_posts` 231 and 232 (`annotated-*.png`,
09:42 UTC) reached the media library. **Not proven:** that the resulting URL ends
up in the card — see 3.2.

### 2.3 One editor instead of two

`CardDocumentProse` (a second Tiptap instance I had added) is deleted. The card
now renders `CustomCardEditor` in both modes via `editable`. That component is
the Writer's editor: shared `getEditorExtensions` + `WriterBubbleMenu`.

`.pcm-notion-prose` (27 CSS rules) and `.pcm-notion-draw` were orphaned by that
and removed. Zero TSX consumers, verified before deletion.

---

## 3. What is broken or unproven — start here

### 3.1 The preview opens full-screen instead of as a popup — MY REGRESSION
I set `DialogContent` to `w-screen h-screen` so a `position: fixed` child would
resolve against the viewport. It turned the preview into a full-screen takeover.
The revert is in the working tree, **uncommitted and untested**. If you
`git reset --hard`, this regression comes back.

### 3.2 Saving the card — the owner reports it still does not save
At `8024974` the card persisted **only in an unmount cleanup**. Evidence: set 25
saved at 09:42:17 UTC, fourteen seconds *before* its annotation upload finished
at 09:42:31 — so the upload landed in a destroyed editor and the card wrote its
old content back over itself. Two orphan attachments (231, 232) are the residue.

Also at that commit, `save: async (v) => { onSave?.(v); }` where `onSave`
returned `void` — the promise resolved instantly, so the UI reported "Saved"
before anything left the browser and `flush()` awaited nothing. The uncommitted
change fixes both, and **has not been run**.

### 3.3 30–50 second page loads — UNVERIFIED, best evidence collected
Measured: the same 1 KB endpoint five times — 0.6s, 38s, 0.8s, 44s, 0.7s.
`admin-ajax.php?action=nopriv_noop`, which does nothing at all — 45s. A static
file — 0.16s. The approvals queries themselves — 1.1 ms and 2.3 ms.

Circumstantial evidence, **not proven**:
- Two real-time antivirus products run together: Avast (`AvastSvc`, `afwServ`
  firewall, `aswidsagent` behaviour shield) **and** Windows Defender with
  real-time on.
- A loopback TCP connect to PHP takes 300–357 ms. It should be sub-millisecond.
- Avast flagged `php.exe` (IDP.Generic) on 2026-08-06.
- This site loads **3.9 MB of PHP across 224 files on every request**.
- Exactly **two** `php-cgi.exe` processes serve the site (ports 10014, 10015).
  On Windows each handles one request at a time; the Approvals module fires five
  queries at once.

**The decisive test nobody has run:** exclude `php-cgi.exe` and the site folder
from both antivirus products, then re-time. Under a second = proven.

I gave the owner two confident wrong answers here first (`pm.max_children = 2`
from a config file whose first line says it does not apply on Windows; and a
WP-Cron loopback theory I changed `wp-config.php` for). `wp-config.php` was
restored byte-for-byte from a backup. **Do not repeat those two.**

### 3.4 Existing cards still carry embedded base64
Sets 22, 23, 24, 25, 27 hold ~490 KB of base64 in `content`. New images upload,
but old ones were never migrated. Every autosave on those cards ships half a
megabyte. **Until they are migrated, saving on those cards will feel slow no
matter what the front end does.**

### 3.5 Two files are over the limit
`CreativeAssetCard.tsx` 752 lines (13 branches on asset type) ·
`includes/modules/approvals/service.php` 1,785 lines (sets + snapshot + comments
+ approvals + sharing in one class). The split was planned and deliberately
deferred so it would not confuse two failure sources. It was never done.

### 3.6 Not the cause — checked, so you don't recheck
- **Approval status does not block editing.** `isSubmitted` only disables the
  Approve button (`CardDocumentView.tsx`). Nothing gates editing by status.
- **The upload endpoint works.** Attachments 231/232 prove it.
- **The Approvals queries are not slow.** 1.1 ms and 2.3 ms, measured directly.

---

## 4. Environment facts worth keeping

- **The database stores UTC; the machine's clock is local, seven hours apart.**
  I misread this once and reported five-minute-old uploads as "yesterday".
- MySQL for this site moved from port **10017** to **10060**. An orphaned
  `mysqld` (PID 13760) was holding the data directory and stopping the site from
  starting; killing it fixed it. It can recur.
- Chrome here binds the **IPv6 loopback** for remote debugging. Poll `[::1]`,
  not `127.0.0.1`, or you get "no debug target".
- The board card is an `<article>` styled by `setCard.module.css`, so its class
  is hashed. **`.pcm-set-card` does not exist** — a probe using it matches zero
  cards and silently produces no evidence.
- To drive the UI as an admin without installing anything on the server: mint a
  cookie with `wp_generate_auth_cookie()` from CLI (define `DB_HOST` as
  `127.0.0.1:10060` *before* requiring `wp-load.php`) and set it via CDP. Do
  **not** install a login mu-plugin; I did twice and left it in place once.
- `mu-plugins/` is empty and `wp-config.php` matches its backup byte-for-byte.

---

## 5. What I would do next, in order

1. **Test the uncommitted work in a browser.** It is the popup revert plus a real
   save. Either commit it or reset — but know that resetting brings the
   full-screen regression back.
2. **Migrate the embedded base64 out of sets 22–25 and 27.** Until that is done,
   those cards are half a megabyte per save and nothing on the front end can make
   them feel fast.
3. **Run the antivirus exclusion test** (3.3). It is two minutes and it either
   removes the 45-second wall or eliminates the theory.
4. **Split `service.php` into three** — sets / contents / review — and
   `CreativeAssetCard.tsx` by asset type.
5. **Then, and only then, the structural work:** open a document straight from
   the board instead of through six nested layers, and give the team its own page
   rather than the client's page with `isTeamMember` flags inside it.

---

## 6. How this went wrong, so it doesn't repeat

I could not see the product. Three attempts at driving a browser failed on my
own tooling. The correct response was to stop feature work and fix that first.
Instead I kept writing code blind and shipped it to the owner to find out whether
it worked — which made him the test environment, at twenty minutes and one
regression per cycle.

I also repeatedly treated a correct *mechanism* as a proven *cause*. Capture-phase
events, Radix modality, kses protocols, PHP worker counts — each was real, and
each time I asserted it was the explanation here without measuring. Three of the
four were wrong.

**Concretely, for whoever is next:** do not change a line in this module until
you can open a card in a browser and watch it. On this machine that means
budgeting for 45-second page loads, or fixing 3.3 first. Everything else in this
handover is downstream of that.

---

## 7. Where the detail lives

- `docs/plans/2026-08-05-opened-card-gap.md` — the first gap analysis
- `docs/plans/2026-08-06-card-integrity.md` — the data-loss root cause
- `docs/plans/2026-08-06-card-autosave.md` — the save-on-close root cause
- `docs/CHANGELOG-20260806-0225.md` — one line per change, with the reasoning
- Commit messages on `7c01a40`, `b6d9a68`, `50e4fa1`, `8024974` carry the
  evidence for each decision, including the ones that turned out wrong.
