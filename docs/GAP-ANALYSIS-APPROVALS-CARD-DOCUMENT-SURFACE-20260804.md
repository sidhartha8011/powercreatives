# GAP ANALYSIS — the opened card does not read as a document
**Date:** 2026-08-04 · **Owner report:** "it doesn't look like a Notion card… a small,
unworked card". **Rewritten after the owner called out an assumption — see §0.**

---

## 0. WHAT I ASSUMED, AND WHAT IS ACTUALLY TRUE

**The assumption:** the first version of this document assumed "opening a card" meant the
authoring editor, and proposed pointing it at the viewer's `.pcm-notion-*` styles.

**Verified since — there are THREE distinct open-a-card surfaces, not one:**

| # | Surface | Where it is rendered | DOM context |
|---|---|---|---|
| S1 | `ArticleViewerDialog` — read-only document | `CreativeAssetCard.tsx:715`; that card's **only** consumer is `ClientReviewPage` | `createPortal(…, document.body)` (`:85`) — **outside `#pcm-root`** |
| S2 | `PreviewDialog` — the admin board's "open" | `SetsBoard.tsx:518` | an **`<iframe>` of the public URL** — i.e. it renders S1 inside a frame |
| S3 | `CustomCardEditor` — authoring | `CreateCustomSetDialog.tsx:346` | **inside `#pcm-root`** |

**And the fact that breaks the original proposal:** `client-review.css` is imported by
**`ClientReviewPage` only** (`ClientReviewPage.tsx:13`). The `.pcm-notion-*` rules therefore
**do not exist on the admin side at all**. The authoring editor cannot "just use them".

**A second constraint the original missed:** `index.css` resets with
`#pcm-root :where(p|h1|h2|h3…)` — specificity **(1,0,1)**. A plain `.pcm-notion-prose p`
is **(0,2,0)** and would **lose** inside `#pcm-root`. S1 only escapes this because it is
portalled out of the root. Any document styling used by S3 must clear that bar — which is
precisely why `.pcm-card-editor` already ships as a two-branch selector
(`#pcm-root .pcm-card-editor` **and** bare `.pcm-card-editor`), documented in `index.css`.

⇒ The fix is **not** "point S3 at S1's classes". It is: define the document scale **once, at
a specificity that survives `#pcm-root`, in a stylesheet both surfaces load** — and have S1
and S3 consume that one definition.

---

## 1. HOW THE THREE SURFACES ACTUALLY DIFFER TODAY

| | S1 / S2 (viewer) | S3 (editor) |
|---|---|---|
| Shell | `.pcm-notion-modal` — 977px, own top bar | shadcn `Dialog`; editor in a **bordered box** (`rounded-lg border bg-card`) |
| Measure | `.pcm-notion-col` — **708px** centred, 24px gutters | full dialog width, `p-4`, `max-h-[72vh]` |
| Title | `.pcm-notion-title` — **40px / 1.2 / 700** | none — you name the *set*, never the *page* |
| Body | `.pcm-notion-prose` — **16px / 1.5**, h1 1.875em → h4 1.05em | `.pcm-card-editor` — **11px / 1.7** |
| Bottom space | `.pcm-notion-page` — 22vh | none |
| Toolbar | none | always-on strip |

**Source:** `client-review.css:838–960` · `index.css` (`.pcm-card-editor`, `#pcm-root :where`)
· `CustomCardEditor.tsx`.

## 2. AND THE EDITOR GOT SMALLER BECAUSE OF ME

Earlier today I unified the card editor onto the Writer canvas's `--pcm-prose-*` scale,
taking it 13px → **11px**. That fixed the drift the owner reported but bound it to the
**wrong source**: the Writer documents its own 11px as *"compact fit with admin UI"*, and a
document surface is not admin chrome. One shared source was right; that source was not.

---

## 3. CHECKLIST

### A — Define the document scale once, where both surfaces can reach it
- [ ] A1 Move the document scale into **`index.css`** (loaded by both admin and client), as
      custom properties, using the **two-branch** `#pcm-root …` + bare selector pattern so it
      survives the `:where()` resets (§0).
- [ ] A2 `.pcm-notion-prose` (S1) and the card editor (S3) both consume **that one
      definition** — neither keeps its own numbers.
- [ ] A3 Writer canvas untouched: its 11px is deliberate and documented.
- [ ] A4 Delete the card editor's binding to `--pcm-prose-*` (my mis-sourced change).

### B — The editor becomes a page, not a form field
- [ ] B1 Centred **708px** measure, matching S1.
- [ ] B2 No border/box framing around the writing surface.
- [ ] B3 Bottom breathing room so the caret is never against an edge.
- [ ] B4 A real document **title**, styled as the 40px H1.
- [ ] B5 Image/annotate/draw move off the always-on strip into the selection bubble already
      imported in that file (`WriterBubbleMenu … selectionOnly`).

### C — Prove it is ONE surface, not two lookalikes
- [ ] C1 The same document must resolve to the **same computed** font-size, line-height and
      measure in S1 and S3.
- [ ] C2 `grep` shows exactly **one** document-scale definition, in `index.css`.
- [ ] C3 S2 needs no work — it is an iframe of S1 and inherits whatever S1 becomes.

### D — Verify
- [ ] D1 tsc 59 pre-existing ZERO new · build · changelog · AFTER commit **LOCAL ONLY**.

## 4. ORDER
A (the scale — my correction to make, and the constraint the first draft missed) → B (the
page shell) → C (prove one surface).

## 5. CARRIED
This lands **inside** the card-type registry's `custom`/`article` types (plan `14eea01`), not
as a separate patch on the 729-line monolith.
