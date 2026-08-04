# GAP ANALYSIS — the opened card does not read as a document
**Date:** 2026-08-04 · **Owner report:** "it doesn't look like a Notion card… a small,
unworked card". Facts first, at file:line.

---

## 1. THE KEY FACT: THERE ARE TWO SURFACES FOR THE SAME DOCUMENT, AND THEY DISAGREE

| | **Viewer** (read-only, client board) | **Editor** (authoring, create dialog) |
|---|---|---|
| Component | `ArticleViewerDialog` in `CreativeAssetCard.tsx` | `CustomCardEditor.tsx` |
| Shell | `.pcm-notion-modal` — **977px** wide, 10px radius, own top bar | shadcn `Dialog`, editor inside a **bordered form box** (`rounded-lg border bg-card`) |
| Column | `.pcm-notion-col` — **max-width 708px**, centred, 24px gutters | full dialog width, `p-4`, `max-h-[72vh]` scroll |
| Title | `.pcm-notion-title` — **40px / 1.2 / 700** | no document title at all — the set's Name field stands in |
| Body | `.pcm-notion-prose` — **16px / 1.5**, h1 1.875em → h4 1.05em, Notion list/marker/quote treatment | `.pcm-card-editor` — **11px / 1.7** |
| Page | `.pcm-notion-page` — 22vh bottom breathing room | none |

**Source:** `modules/Approvals/client-review.css:838–960` · `index.css` (`.pcm-card-editor`)
· `CustomCardEditor.tsx`.

**Conclusion: the *viewer* is already a proper Notion page — 977/708/40px/16px with the full
heading scale.** The *editor* is a compact admin form field at 11px. Same document, two
identities, and the one you author in is the wrong one.

## 2. AND THE EDITOR GOT SMALLER BECAUSE OF ME

Earlier today I unified the card editor onto the Writer canvas's scale
(`--pcm-prose-*`, **11px/1.7**), taking it from 13px → 11px. That fixed the *drift* the owner
reported but bound it to the **wrong source**: the Writer canvas documents its own 11px as
*"compact fit with admin UI"*. A document surface is not admin chrome. The correct shared
source for this surface is the one that already renders these documents correctly — the
viewer's Notion scale.

⇒ **The prose scale stays ONE source, but the card editor moves onto the document scale
(16px/708px), not the admin-compact one.** The Writer keeps its own; nothing about it changes.

## 3. WHAT ELSE THE EDITOR LACKS THAT THE VIEWER HAS

| # | Fact |
|---|---|
| F1 | No centred measure — text runs the full dialog width instead of a 708px column. |
| F2 | Visible chrome — a border, a toolbar strip and a background box frame the writing surface; the viewer has none. |
| F3 | No document title — you name the *set*, never the *page*, so the authoring view has no H1 to anchor it. |
| F4 | No bottom breathing room (`22vh` in the viewer) — the caret sits against the box edge. |
| F5 | Toolbar is always-on; the Writer/viewer pattern is a selection bubble (`WriterBubbleMenu`, already used here with `selectionOnly`). |

---

## 4. CHECKLIST

### A — One document scale, correctly sourced
- [ ] A1 Card editor adopts the **document** scale (16px/1.5 + the viewer's heading ramp), not the admin-compact Writer scale.
- [ ] A2 Keep it a single source: promote the viewer's `.pcm-notion-prose` values to shared custom properties consumed by **both** viewer and editor, so authoring and reading are byte-identical by construction.
- [ ] A3 Writer canvas untouched — its 11px is deliberate and documented.

### B — The editor becomes a page, not a field
- [ ] B1 Centred **708px** measure, matching the viewer.
- [ ] B2 Remove the border/box framing; the writing surface sits on the page.
- [ ] B3 Bottom breathing room so the caret is never against an edge.
- [ ] B4 A real document **title** input styled as the 40px H1 — the page's own name.
- [ ] B5 Image/annotate/draw actions move off the always-on strip into the existing selection bubble + a quiet insert affordance.

### C — Prove it is one surface, not two lookalikes
- [ ] C1 The same document rendered in editor and viewer must resolve to the **same** computed font-size, line-height and measure.
- [ ] C2 `grep` shows no second prose scale defined for cards.

### D — Verify
- [ ] D1 tsc 59 pre-existing ZERO new · build · changelog · AFTER commit **LOCAL ONLY**. Never push.

## 5. ORDER
A (the scale, and it is my correction to make) → B (the page shell) → C (prove one surface).

## 6. CARRIED
Card-type registry + editable cards (plan `14eea01`) — this redesign lands **inside** that
registry's `custom`/`article` types, not as a separate patch on the monolith.
