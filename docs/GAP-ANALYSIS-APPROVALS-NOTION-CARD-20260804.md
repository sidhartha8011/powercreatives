# GAP ANALYSIS + PLAN — the proper Notion card
**Date:** 2026-08-04 · **Owner order:** factual gap checklist, then the factual plan, commit,
then implement.

**Owner's requirements, verbatim:** see the proper Notion card · it should be the height and
the size of a Notion card · it should look like a Notion card · it should use our information
simply · it should not have a small icon in the top left which looks like a crappy document.

Every TODAY line is verified at `file:line`. Nothing below is opinion.

---

## 1. THE GAP

### G1 — You cannot reach the Notion card from the board at all

| TODAY (verified) | MUST BE |
|---|---|
| `CreateCustomSetDialog`'s props are exactly `{ open, onClose, preset }` — no `set`, no `setId`, no `editSet` (`CreateCustomSetDialog.tsx:81`) — and it has **one** call site, behind the "Add Approval Set" button (`Approvals/index.tsx:82-85`, `:92-99`). It is **create-only**. Clicking a set on the board runs `handleCardClick` → `openPreview` (`SetCard.tsx:79-82`, `SetsBoard.tsx:305`) → `PreviewDialog` with the public URL (`SetsBoard.tsx:513-517`), which is an `<iframe>` (`PreviewDialog.tsx:61-66`). The Notion card only exists **inside** that iframe: `App.tsx:21-26` → `ClientReviewPage.tsx:557` → `CreativeAssetCard.tsx:562` → `ArticleViewerDialog` (`:714-726`). | Clicking a set that holds a custom document opens the Notion card **directly in the admin**, not an iframe of the client board. |

### G2 — The crappy document icon in the top left

| TODAY | MUST BE |
|---|---|
| `CreativeAssetCard.tsx:95-98` renders `<span class="pcm-notion-crumb">` containing a `<FileText>` icon (`style={{ opacity: 0.55, flexShrink: 0 }}`) plus the title, in a 45px sticky topbar (`client-review.css:861-876`). | Gone — icon and duplicated title both. The title already renders at 40px directly below it (`client-review.css:914-921`), so the crumb repeats it. |

### G3 — It is a floating card, not a page, in height and placement

| TODAY | MUST BE |
|---|---|
| `.pcm-notion-overlay` is `display:flex; align-items:flex-start; padding: 4vh 16px` (`client-review.css:841-842`), and `.pcm-notion-modal` is `width: min(977px, 94vw); max-height: 92vh; border-radius: 10px; overflow-y: auto` (`:846-855`). The sheet therefore hangs 4vh from the top with a visible band above **and** below, and never fills the available height. | Notion peek proportions: the sheet occupies the height it is given, with the scrim reading as page-behind rather than a border around a card. |

### G4 — The property area is **always empty** on a custom card

| TODAY | MUST BE |
|---|---|
| The property block is gated on `metaTitle \|\| metaDescription` (`CreativeAssetCard.tsx:115`). `CustomAsset` declares **no such fields** — it is `{ id, type, title, content, images, annotation, createdAt, updatedAt }` (`types.ts:69-86`). Those two are **article** fields. So for a custom document the gate is always false and **no properties ever render**. `ArticleViewerDialog` is handed only `content, title, metaTitle, metaDescription, overlay, isApproved, isSubmitted, onApprove, onClose` (`CreativeAssetCard.tsx:715-725`) — brand and project are never passed to it, even though `CreativeAssetCard` already receives `brandName` and `brandLogoUrl` (`ClientReviewPage.tsx:565-566`) and the set carries `snapshot.projectName` (`types.ts:123`). | Real Notion property rows built from our own data — Brand, Project, Created — passed in explicitly. No component reaching for a global. |

### G5 — Approve is at the bottom of the document

| TODAY | MUST BE |
|---|---|
| `.pcm-notion-actions` renders after the prose, inside the scrolling column (`CreativeAssetCard.tsx:147-157`; `client-review.css:895-899`). On a long document it scrolls out of reach. This is the owner's standing request from the 2026-08-04 handover §7. | In the topbar, which is already `position: sticky; top: 0` (`client-review.css:861-862`) — the same bar the crumb vacates in G2. |

### G6 — The typography is ALREADY correct. It must not be touched.

| TODAY (verified) | MUST BE |
|---|---|
| Column `max-width: var(--pcm-doc-measure)` = **708px** (`client-review.css:887`, `index.css:588`); title **40px / 1.2 / 700** (`client-review.css:916`, `index.css:592-594`); prose **16px / 1.5** (`client-review.css:938`, `index.css:589-590`). The file documents these as the Notion reference it was built to (`CreativeAssetCard.tsx:83-84`). | Unchanged. These are the numbers that make it read as Notion; the gap is chrome and placement, not type. |

### G7 — One definition, one owner

| TODAY | MUST BE |
|---|---|
| `ArticleViewerDialog` is a private function inside `CreativeAssetCard.tsx` (`:48-164`) with exactly one consumer (`:714-726`). G1 requires a **second** consumer on the admin board. | Extracted to its own file, consumed by both surfaces. Rendering the same document twice from two copies is how the two surfaces drift. |

---

## 2. CHECKLIST

- [ ] **C1** Extract the document view verbatim from `CreativeAssetCard.tsx:48-164` into
      `modules/Approvals/components/CardDocumentView.tsx`. Same markup, same `.pcm-notion-*`
      classes, same portal. `CreativeAssetCard` imports it. (G7)
      **Gate:** `ArticleViewerDialog` appears 0 times in `CreativeAssetCard.tsx`.
- [ ] **C2** Delete the crumb — the `<FileText>` icon and the duplicated title. The topbar
      keeps close. (G2) **Gate:** `pcm-notion-crumb` appears 0 times in `app/src`.
- [ ] **C3** Approve moves into the sticky topbar, left of close, keeping its existing
      toggle + locked behaviour verbatim. (G5)
- [ ] **C4** Notion page proportions: the sheet fills the height it is given; the scrim reads
      as the page behind. Type untouched. (G3, G6)
- [ ] **C5** A typed `properties?: ReadonlyArray<{ label: string; value: string }>` prop on
      `CardDocumentView`, rendered through the existing `.pcm-notion-props` markup. Empty
      array renders nothing. No component reads `window`. (G4)
- [ ] **C6** `ClientReviewPage` passes Brand / Project / Created from data it already holds
      (`snapshot.brandName`, `snapshot.projectName`, `asset.createdAt`). (G4)
- [ ] **C7** Board wiring: a set whose `snapshot.custom` is non-empty opens `CardDocumentView`
      in the admin instead of the iframe. Every other set keeps `PreviewDialog` unchanged.
      The rule is read off the data, not a hand-maintained list of set kinds. (G1)
- [ ] **V** `php -l` where PHP is touched (expected: none) · `tests/standalone/run.php` 88/88 ·
      `cd app && npm run check` = 59 pre-existing, ZERO new · `npm run build` · changelog line ·
      AFTER commit ending **LOCAL ONLY** · never push.

---

## 3. PLAN

**Order matters: C1 first, because C2–C6 all edit the extracted file, and C7 needs it to exist.**

1. **C1 — extract.** Move `ArticleViewerDialog` (`CreativeAssetCard.tsx:48-164`) into
   `CardDocumentView.tsx` unchanged: same props, same `createPortal(…, document.body)`, same
   `getEditorExtensions` read-only editor, same classes. Export it. `CreativeAssetCard` imports
   and renders it at `:714-726` with the identical prop set. Nothing else changes in this step,
   so any visual difference afterwards is attributable to C2–C4 alone.
2. **C2 + C3 — the topbar.** Remove the crumb span entirely. Move the Approve button out of
   `.pcm-notion-actions` into the topbar, before the close button, preserving
   `disabled={isSubmitted}`, the `is-approved` class and `onApprove`. Delete
   `.pcm-notion-actions` and its CSS once nothing renders it.
3. **C4 — proportions.** `.pcm-notion-overlay`: `align-items: stretch` with a symmetric vertical
   padding so the sheet is a page in a window, not a card on a tray. `.pcm-notion-modal`: height
   driven by that stretch rather than `max-height: 92vh`. Keep `border-radius`, keep the shadow,
   keep `overflow-y: auto` so only the document scrolls under the sticky bar. **Do not touch**
   `--pcm-doc-*`, `.pcm-notion-col`, `.pcm-notion-title` or `.pcm-notion-prose` (G6).
4. **C5 + C6 — properties.** Add the `properties` prop and render it through the existing
   `.pcm-notion-props` / `-prop` / `-prop-label` / `-prop-value` markup, replacing the
   `metaTitle || metaDescription` gate with `properties.length > 0`. `CreativeAssetCard` builds
   the array for `custom` from `brandName`, a new `projectName` prop, and `asset.createdAt`;
   for `article` it keeps Meta title / Meta description so that surface is unchanged.
   `ClientReviewPage` passes `projectName={set.snapshot.projectName}`.
5. **C7 — board wiring.** In `SetsBoard`, `openPreview` branches on the set's own data: if
   `snapshot.custom?.length`, open `CardDocumentView` for the first custom document; otherwise
   open `PreviewDialog` exactly as today. The admin has no public token, so the view is opened
   read-only — editing is out of scope here and stays open work.
6. **V — verify ritual**, then the AFTER commit.

### Explicitly NOT in this round
- Making the document **editable** from the admin (handover §7, needs the `save(patch, ctx)`
  card-type contract).
- The card-type registry / `type === '` removal (11 lines, tracked separately).
- The checklist / task-list extensions (separate, unblocked, tracked in the fact-check D1).
- Any change to `--pcm-doc-*` or the Writer's `--pcm-prose-*` scales.

### Risk named up front
`.pcm-notion-*` is defined in `client-review.css`, which **only `ClientReviewPage` imports**
(`ClientReviewPage.tsx:13`). That import is static and `ClientReviewPage` is statically imported
by `App.tsx:12`, so the CSS is in the bundle on every surface — confirmed: 55 `.pcm-notion-*`
rules are present in `dist/index.css`. The admin board therefore inherits the styles without a
new import. This is stated so the next reader does not "fix" it by adding a duplicate import.
