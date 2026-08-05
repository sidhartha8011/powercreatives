# Opened approval card — factual gap, checklist, plan

Date: 2026-08-05
Scope: the sheet that opens when a team member clicks an approval card.

Every line below is either read from source at the cited `file:line`, or measured
against the live database. Anything not established that way is marked
**UNVERIFIED** and is not treated as a finding.

---

## PART 1 — FACTUAL GAP

### G1 — Every pointer event inside the sheet is discarded before it arrives

**Where we are.** `components/CardDocumentView.tsx:200` defines
`const stopDialogDismiss = (e: React.PointerEvent) => e.stopPropagation();`
and it is bound twice on the overlay:

```
:205      onClick={onClose}
:206      onPointerDown={stopDialogDismiss}
:207      onPointerDownCapture={stopDialogDismiss}
```

The capture phase runs document → target. `:207` therefore runs on
`.pcm-notion-overlay` (`:204`) **before** the event descends into
`.pcm-notion-modal` (`:211`) and everything below it, and `stopPropagation()`
ends the descent there. No `pointerdown` reaches the editor.

ProseMirror sets the caret from the pointer/mouse-down that starts a click, and a
task-item checkbox is an `<input type="checkbox">` whose activation begins with
the same event. Both are inside the modal.

**Consequence.** The document cannot be focused (reported as "I can't edit it")
and a checkbox cannot be ticked (reported as "the check boxes are not working").

**Where we have to be.** The event reaches the editor, and does **not** reach
`document`, where Radix's DismissableLayer listens for pointerdown-outside.

**Exact change.** Delete `CardDocumentView.tsx:207`. Line `:206` alone is
correct: the bubble phase runs after the target has handled the event, and still
stops it before `document`. `:207` was added earlier today and is the sole cause.

---

### G2 — The sheet renders the body at 11px when opened for editing, 16px when opened for reading

**Where we are.**

| Path | Class | Token | Value |
| --- | --- | --- | --- |
| editing | `CustomCardEditor.tsx:115` → `pcm-card-editor` | `index.css:1033` → `--pcm-prose-size` | `11px` (`index.css:597`) |
| reading | `CardDocumentView.tsx:131` → `pcm-notion-prose` | `client-review.css:973` → `--pcm-doc-size` | `16px` (`index.css:589`) |

`index.css:587` labels `--pcm-doc-*` as *"the document scale (card viewer + card
editor)"*, and `index.css:596` labels `--pcm-prose-*` as *"the admin-compact
scale (Writer canvas only)"*. `.pcm-card-editor` currently reads the second
group. The two comments contradict each other; the binding was changed today by
owner direction recorded at `index.css:1022`, which was about the **New approval
set** dialog.

**Consequence.** The same document is 1.45× smaller to edit than to read, on the
same sheet. This is the reported "text looks like it's broken".

**Where we have to be.** On the sheet, editing and reading are the same size —
the document scale, 16px / 1.5 / 708px, which is what the owner's reference card
shows. The 11px pad scale stays where the owner put it: the New approval set
dialog.

**Exact change.**
1. `index.css`, after the `.pcm-card-editor` block ending at `:1041` — add one
   modifier that rebinds the surface to the document scale:
   ```css
   #pcm-root .pcm-card-editor--document,
   .pcm-card-editor--document {
     font-size: var(--pcm-doc-size);
     line-height: var(--pcm-doc-leading);
     color: var(--pcm-doc-ink);
   }
   ```
   The scale lives in CSS with the other scales; no component learns a pixel.
2. `CustomCardEditor.tsx` — add `scale?: 'pad' | 'document'` (default `'pad'`,
   so the New approval set dialog is byte-identical) and use it at `:115`.
3. `CardDocumentView.tsx` — the sheet passes `scale="document"`.

---

### G3 — The sheet reserves 460px + 12vh of empty body regardless of content

**Where we are.** `CustomCardEditor.tsx:115` puts `min-h-[460px]` on the editor,
and `index.css:1041` puts `padding-bottom: 12vh` on `.pcm-card-editor`. Both
exist for the New approval set dialog, where the editor **is** the window and a
caret must not sit on the bottom edge.

On the sheet the same two rules run inside `.pcm-notion-page`
(`client-review.css:904`, `padding: 6px 0 72px`) inside `.pcm-notion-modal`
(`:864`, `max-height: 94vh`).

**Consequence.** A two-line checklist reserves ≥ 460px + 12vh of blank body —
the empty lower half of the owner's screenshot.

**Where we have to be.** The sheet grows to its content and stops.

**Exact change.** Both rules become part of the `pad` scale, absent from
`document`:
- `CustomCardEditor.tsx:115` — apply `min-h-[460px]` only when `scale === 'pad'`.
- `index.css:1041` — move `padding-bottom: 12vh` out of the base
  `.pcm-card-editor` block into a `.pcm-card-editor--pad` block, so the base
  carries only what both scales share.

---

### G4 — The document title cannot be edited

**Where we are.** `CardDocumentView.tsx` renders the title as static text:
`<h1 className="pcm-notion-title">{title}</h1>`. The backend already accepts it:
`includes/modules/approvals/service.php:1647` writes `$item['title']` for the
`custom` bucket and `:1625` for `articles`.

**Where we have to be.** The title is edited on the sheet, like the body.

**Exact change.** `CardDocumentView.tsx` — the `<h1>` becomes
`contentEditable` + `suppressContentEditableWarning`, writing into the same
`draft` ref as the body (`:168`), and `title` joins the `onSave` payload
(`:89`). `CreativeAssetCard.tsx:~310` adds `title: doc.title` to the mutation.
No new endpoint and no new route: the field is already accepted.

---

### G5 — The editor's toolbar renders inside the document column

**Where we are.** `CustomCardEditor.tsx:200` renders four icon buttons (insert
image, annotate, draw, checklist) as the first thing inside the editor, so on
the sheet they land between the property rows and the first line of text.

The owner's reference puts actions in the sheet's top bar and starts the body at
the text.

**This is a design gap, not a defect** — the buttons work. Recorded so it is a
decision and not drift. **Not scheduled below**; see "Deferred".

---

### G6 — Brand and Project property rows: NOT A DEFECT

Measured against the live database (all three approval sets that exist):

```
set 17: brandId=NULL projectId=NULL snapshot.brandName=NULL snapshot.projectName=NULL
set 18: brandId=NULL projectId=NULL snapshot.brandName=NULL snapshot.projectName=NULL
set 20: brandId=NULL projectId=NULL snapshot.brandName=NULL snapshot.projectName=NULL
```

`ClientReviewPage.tsx:533-534` passes `set.snapshot?.brandName` /
`?.projectName`; `CreativeAssetCard.tsx:147-148` emits a row only when the value
is a non-empty string. No set carries either value, so rendering only *Created*
is correct. **No change.** Rendering "Brand —" would be inventing data.

---

### G7 — Click-outside does not close the sheet: UNVERIFIED

`CardDocumentView.tsx:205` binds `onClick={onClose}` on the overlay and `:211`
stops click propagation on the modal, which should close on a backdrop click.
The owner reports it does not.

**I have not observed this behaviour.** The browser probe run for it matched zero
board cards (`article` is styled by a CSS module, so the `.pcm-set-card`
selector it used does not exist) and produced no evidence. No cause is claimed.

**Where we have to be.** Cause identified from a measurement, then fixed.
Scheduled below as a measurement step, not as a fix.

---

## PART 2 — FACTUAL CHECKLIST

Ordered so each item is verifiable before the next begins.

- [ ] **C1** Delete `CardDocumentView.tsx:207`. *Done when:* a checkbox in the
      open sheet flips `data-checked` on its `<li>`, observed in the browser.
- [ ] **C2** Measure the backdrop click (G7): what element is hit, whether
      `onClose` fires, whether the Radix dialog also closes. *Done when:* the
      cause is stated with the measurement that shows it.
- [ ] **C3** Fix the cause found in C2. *Done when:* a backdrop click closes the
      document and leaves the card open, observed in the browser.
- [ ] **C4** Add the `--document` scale modifier (`index.css`) and the
      `scale` prop (`CustomCardEditor.tsx`, `CardDocumentView.tsx`). *Done when:*
      computed `font-size` on `.pcm-card-editor` inside the sheet is `16px`, and
      inside the New approval set dialog is still `11px`.
- [ ] **C5** Move `min-h-[460px]` and `padding-bottom: 12vh` onto the `pad`
      scale. *Done when:* the sheet's measured height for set 18's two-line
      checklist is below the `94vh` cap with no blank lower half.
- [ ] **C6** Make the title editable and include it in the save payload.
      *Done when:* an edited title survives a reload, read back from the database.
- [ ] **C7** Full round trip on set 18: tick a box, close, reload, confirm
      `data-checked="true"` in `wp_pcm_approval_sets.snapshot`.
- [ ] **C8** Verify ritual: `php -l` on every touched PHP file →
      `tests/standalone/run.php` 88/88 → `npx tsc --noEmit` = 59 (baseline, zero
      new) → `npm run build` → changelog line → commit ending **LOCAL ONLY**.
- [ ] **C9** Delete `wp-content/mu-plugins/pcm-probe-login-TEMP.php` and confirm
      `?pcm_probe_key=…` no longer logs anyone in.

## Deferred — recorded, not scheduled

- **G5** toolbar placement. Moving it into the sheet's top bar means the editor
  renders its toolbar through a slot supplied by the host. That is a real
  refactor of a shared component and needs the owner's decision first.

---

## PART 3 — PLAN

**Order:** C1 → C2 → C3 → C4 → C5 → C6 → C7 → C8 → C9.

C1 first because until pointer events arrive nothing else can be observed at
all — every later check depends on being able to click inside the sheet.

C2 before C3 because the cause of G7 is not known, and no code changes for it
until a measurement names it.

C4 and C5 are one commit: both are the same distinction (pad scale vs document
scale) expressed once.

**Files touched, and nothing else:**

| File | Items |
| --- | --- |
| `app/src/modules/Approvals/components/CardDocumentView.tsx` | C1, C3, C4, C6 |
| `app/src/modules/Approvals/components/CustomCardEditor.tsx` | C4, C5 |
| `app/src/modules/Approvals/components/CreativeAssetCard.tsx` | C6 |
| `app/src/index.css` | C4, C5 |

No new component, no new route, no new endpoint, no new CSS file. The two scales
already exist as tokens; this adds one modifier and one prop that names which of
them a surface uses.

**Standing constraints applied:** no inline colour/spacing literals; the scale
lives in CSS, not in a component; `scale` defaults to `pad` so the existing
consumer is unchanged; no fallback invents a value that is not in the data (G6).

**Probe correction for the verification steps.** The board card is an `<article>`
styled by `setCard.module.css` (`kanban/SetCard.tsx:26,144`), so its class name is
hashed. Probes must select `article[data-expanded], .pcm-board article` or the
document tile `.pcm-copy-body` — never `.pcm-set-card`, which does not exist.
