# GAP ANALYSIS — the card editor's font, the opened custom card, and the checklist
**Date:** 2026-08-04 · **Owner order:** factual gap, facts only.

Three reported symptoms. Every line below is a fact verified at `file:line`, in the built
bundle, or in the installed package tree. No proposals, no options, no recommendations.

---

## 1. SYMPTOM: "the font inside the editor is leaking / it's big / not the same as the Writer's A4 pad"

### 1a. What each editor actually declares

| # | Fact | Evidence |
|---|---|---|
| F1 | The card editor's ProseMirror root carries class `pcm-card-editor outline-none max-w-none min-h-[460px] focus:outline-none` | `CustomCardEditor.tsx:101` |
| F2 | `.pcm-card-editor` declares `font-size: var(--pcm-doc-size)`, `line-height: var(--pcm-doc-leading)`, `color: var(--pcm-doc-ink)`, `font-family: 'Inter', ui-sans-serif, system-ui, sans-serif`, `max-width: var(--pcm-doc-measure)`, `margin-inline: auto`, `padding-bottom: 12vh` | `index.css:1022-1033` |
| F3 | `--pcm-doc-size: 16px` · `--pcm-doc-leading: 1.5` · `--pcm-doc-ink: #37352f` · `--pcm-doc-measure: 708px`, declared on `:root, #pcm-root` | `index.css:585-594` |
| F4 | The Writer pad's ProseMirror root carries class `writer-editor focus:outline-none` | `ReviewEditorCanvas.tsx:100` |
| F5 | `.writer-editor` declares `font-size: var(--pcm-prose-size)`, `line-height: var(--pcm-prose-leading)`, `color: var(--pcm-prose-color)`, `font-family: 'Inter', ui-sans-serif, system-ui, sans-serif`, `max-width: none`, `padding: 40px 48px`, `min-height: 500px`. It exists under **`#pcm-root` only** — there is no bare branch. | `index.css:604-614` |
| F6 | `--pcm-prose-size: 11px` · `--pcm-prose-leading: 1.7` · `--pcm-prose-color: #1a1a2e`, commented "the admin-compact scale (Writer canvas only)" | `index.css:596-599` |

### 1b. The measured difference

| Property | Writer A4 pad | Card editor | Ratio / delta |
|---|---|---|---|
| font-size | **11px** | **16px** | **1.4545×** |
| line-height | 1.7 | 1.5 | −0.2 |
| colour | `#1a1a2e` | `#37352f` | different |
| **font-family** | **Inter** | **Inter** | **identical** |
| h1 | `2em` = 22px (`index.css:617-624`) | `1.5em` = 24px (`index.css:1034`) | +2px |
| padding | `40px 48px` | `padding-bottom: 12vh` only | different |

### 1c. Whether anything is leaking

| # | Fact | Evidence |
|---|---|---|
| F7 | The SPA sets `body { font-family: 'Inter', sans-serif !important }` | `index.css:273` |
| F8 | Both editors declare `font-family: 'Inter', …` on their own root | F2, F5 |
| F9 | The font families are the same string. **No wp-admin font reaches either editor.** | F2, F5, F7 |
| F10 | The two scales are separate **by design and by dated decision**: the card editor used to read `--pcm-prose-*` (11px); §2.2 of the 2026-08-04 gap records unbinding it as "Right instinct, wrong source" and giving the card its own document scale. | `GAP-ANALYSIS-APPROVALS-COMPLETE-20260804.md` §2.1-2.2 |

**Fact: this is not a leak.** It is two declared scales, each winning correctly in its own
context. The card editor renders 45% larger than the Writer pad because it is told to.

### 1d. Two facts about the card editor that are NOT working as its own CSS intends

| # | Fact | Evidence |
|---|---|---|
| F11 | Tailwind is imported with the `important` flag, so **every utility is emitted with `!important`** | `index.css:8` (`@import "tailwindcss" important;`); rationale at `index.css:1-7` |
| F12 | `.max-w-none { max-width: none !important }` in the built bundle | `dist/index.css:4466-4468` |
| F13 | The card editor element carries `max-w-none` (F1). By F11+F12 it **beats** `.pcm-card-editor { max-width: var(--pcm-doc-measure) }`, which has no `!important`. **The intended 708px centred measure never applies**; the editor is the full width of the dialog body, and `margin-inline: auto` has nothing to centre. | F1, F2, F12 |
| F14 | `#pcm-root :where(p) { font-size: 0.7em; line-height: 1.3 }` is the admin reset | `index.css:63-69` |
| F15 | `DialogContent` renders inside `DialogPrimitive.Portal`, i.e. into `document.body`, **outside `#pcm-root`** | `dialog.tsx:62-65`, `:122` |
| F16 | By F14+F15 the `0.7em` paragraph reset does **not** reach the card editor inside the create dialog; paragraphs inherit the full 16px. The only `p` rules scoped to `.pcm-card-editor` set margins, never font-size. | `index.css:1038-1039` |
| F17 | `ReviewEditorCanvas` is rendered once, at `Writer/index.tsx:530`, on a normal module page inside `#pcm-root` — never portaled. So `#pcm-root .writer-editor` (F5) applies and the pad is 11px. | `Writer/index.tsx:530` |

---

## 2. SYMPTOM: "opening a custom approval thing is not the Notion card, it's still the old crap"

| # | Fact | Evidence |
|---|---|---|
| F18 | `CreateCustomSetDialog`'s props are exactly `{ open, onClose, preset }`. There is **no** `set`, `setId` or `editSet` prop. | `CreateCustomSetDialog.tsx:81` |
| F19 | It has exactly **one** call site, driven by the "Add Approval Set" button. It is **create-only**. | `Approvals/index.tsx:82-85`, `:92-99` |
| F20 | Clicking a set card on the board calls `handleCardClick` → `openPreview` | `SetCard.tsx:79-82`, `SetsBoard.tsx:305` |
| F21 | `openPreview` opens `PreviewDialog` with `getPublicBoardUrl(previewSet.token)` | `SetsBoard.tsx:513-517` |
| F22 | `PreviewDialog` renders `<iframe src={url} sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox">` | `PreviewDialog.tsx:61-66` |
| F23 | Inside that iframe, `App.tsx` routes on `?pcm_public_token=` to `ClientReviewPage` | `App.tsx:21-26` |
| F24 | `ClientReviewPage` renders one `CreativeAssetCard` per asset | `ClientReviewPage.tsx:557` |
| F25 | Clicking a **custom** tile sets `showArticleViewer` | `CreativeAssetCard.tsx:562` |
| F26 | `ArticleViewerDialog` — the Notion card (`.pcm-notion-*`) — renders for `article` and `custom` | `CreativeAssetCard.tsx:714-726` |
| F27 | `ArticleViewerDialog`'s editor is constructed `editable: false` | `CreativeAssetCard.tsx:66` |
| F28 | It portals to `document.body`; inside the iframe that is the **iframe's** body | `CreativeAssetCard.tsx:85`, `:162` |
| F29 | `.pcm-notion-*` (55 rules) and `.pcm-card-editor` (19 rules) are present in the built stylesheet — **no styles are missing from the bundle** | `dist/index.css` |

**Fact: from the admin board there is no path that opens a custom set as the Notion
document.** The only route to the Notion card is board → iframe → click an asset inside it.
It is then read-only (F27) and confined to the iframe (F22, F28). There is also no path that
edits an existing custom set (F18, F19).

---

## 3. SYMPTOM: "we cannot create a checklist"

| # | Fact | Evidence |
|---|---|---|
| F30 | `@tiptap/extension-list@3.22.3` is installed, with subpath exports `./task-list` and `./task-item` | `app/node_modules/@tiptap/extension-list/package.json` |
| F31 | Its `dist/index.d.ts` declares `TaskItem`, `TaskList`, and the `toggleTaskList()` command | `app/node_modules/@tiptap/extension-list/dist/index.d.ts` |
| F32 | It is a direct dependency of `@tiptap/starter-kit@3.22.3`, which is a direct dependency of the app | `app/node_modules/@tiptap/starter-kit/package.json`, `app/package.json` |
| F33 | `TaskList` / `TaskItem` appear **0 times** in the shared extension registry | `components/shared/editorExtensions.ts` |
| F34 | Consequently no checkbox node type is registered in any editor, and `toggleTaskList()` is not callable anywhere | F33 |
| F35 | `@tiptap/extension-task-list` and `@tiptap/extension-task-item` — the TipTap **v2** package names — are absent from `package.json` and `node_modules`. This absence is what the handover cited when it recorded the feature as blocked. | `app/package.json`, `app/node_modules/@tiptap/` |

**Fact: the capability is installed and unregistered.** Nothing is missing from the dependency
tree; the extensions are simply not added in `editorExtensions.ts`.

---

## 4. STATE OF THE THREE ITEMS AS OF THIS DOCUMENT

| Item | State |
|---|---|
| Card editor font scale | Unchanged. 16px vs the Writer's 11px, by the dated decision at F10. |
| 708px measure on the card editor | Declared but inert (F13). Never rendered as intended. |
| Notion card on open from the board | Not implemented. No admin path exists (F18-F22). |
| Editing an existing custom set | Not implemented (F18, F19). |
| Checklist | Not implemented. Not blocked (F30-F35). |

## 5. NOT ESTABLISHED

- No computed-style reading was taken from a live browser for either editor. Every typography
  fact above is read from the declared CSS and the built stylesheet, plus the cascade rules
  that follow from F11-F17. A DevTools computed-value check on `.pcm-card-editor` and
  `.writer-editor` would settle 1b and F13 by measurement rather than by derivation.
