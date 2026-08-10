# LOG — in-flight state, open items, and the regressions I caused
**Date:** 2026-08-04 · Written at the owner's instruction to stop and log, so nothing in
flight is lost.

---

## 1. WHERE I WAS WHEN STOPPED — and the finding already in hand

I was mid-investigation of the owner's seven points about the opened card. **One question is
already answered factually and must not be re-investigated:**

### "Where is that weird text coming from? Is that hard-coded?" — ANSWERED: it is not.

Probed set **#12 "Campaign novemnebr 2026"** in the hub DB. Its stored `custom[0].content` is:

```html
<p data-id="7867e1f5-…"></p>
<p data-id="7e19e5bd-…">Images </p>
<img data-id="8d98ff41-…" src="…/pcm-gen-674c9de5-….jpg" alt="">
<p data-id="5ef0…">…
```

**"Images" is a paragraph in the owner's own document** — text typed into the card editor and
saved as content. It is **not** a hardcoded label: `grep` for `>Images<` / `'Images'` /
`"Images"` across `modules/Approvals/components/` returns **nothing**. The viewer is
faithfully rendering what was authored.

⚠ The genuinely wrong thing nearby: every node carries a `data-id` attribute
(`data-id="7867e1f5-…"`) — editor-internal identity anchors are being **persisted into the
saved document**. That is state leaking into content and belongs in the regression list (§3).

### Also established this turn, not yet acted on
- The top-left "crap icon" is `CreativeAssetCard.tsx:95-98` — a `FileText` icon plus the title
  inside `.pcm-notion-crumb`.
- Approve sits at the **bottom**, `CreativeAssetCard.tsx:147-155` (`.pcm-notion-actions`), so
  it scrolls away. Owner wants it in the header, sticky.
- **The blur is trapped.** `.pcm-notion-overlay` has the backdrop-filter, but the whole viewer
  renders inside `PreviewDialog`'s **iframe** — an iframe cannot blur the page hosting it. The
  blur can therefore never cover the admin screen from where it is. This is architectural, not
  a CSS value.

---

## 2. THE OWNER'S OPEN ITEMS — carried verbatim, none dropped

1. Blur must cover the whole screen behind, not just inside the frame.
2. White corners around the modal must go.
3. Remove the top-left icon/crumb — it carries no value.
4. The card must be **editable** when opened while logged in.
5. **Approve moves to the header** so it follows on scroll.
6. The document must use **the Writer's editor**, nothing bespoke.
7. A **checklist that actually works** inside the card.
8. Every regression found must be fixed, and not repeated.

---

## 3. REGRESSIONS — cause, and whether it was deliberate

### R1 — The create dialog now has THREE rows of fields. **NOT deliberate. My defect.**

**Observed:** Row 1 `Name | Lane`, Row 2 `Project | Delivery`, Row 3 `Brand` alone.
**Specified:** Row 1 `Name · Lane`; Row 2 `Project · Delivery · Brand`.

**Cause, exactly:** removing the Recipient Email field, Row 1 had to go from a 3-column grid
to a 2-column grid. I applied it with
`sed -i '0,/sm:grid-cols-3/s//sm:grid-cols-2/'` — a "first occurrence only" replacement — and
**ran that same command in two separate steps**. The first run changed Row 1 (correct); the
second run then changed what had become the first remaining match, which was **Row 2**. Row 2
holds three controls, so Brand wrapped onto a third line.

**Why it happened:** I used a positional text substitution on a file where two lines were
identical, then re-ran it without re-reading the file. A positional `sed` is not a safe edit
on non-unique text — the correct tool was a targeted edit anchored on surrounding content.

**Fix:** Row 2 returns to `sm:grid-cols-3`. Row 1 stays `sm:grid-cols-2`.

### R2 — `data-id` attributes persisted into saved document content. **Pre-existing, not mine — but it is real.**
Editor identity anchors are being written into the stored HTML (§1). Content should be
content; identity belongs to the editor session.

### R3 — To be confirmed, not yet verified
The editor area in the current screenshot renders as a tall empty box. I added
`max-width: var(--pcm-doc-measure); margin-inline: auto; padding-bottom: 12vh` to
`.pcm-card-editor` in commit `88f3cf4`. That interacts with the existing
`min-h-[460px]` on the editor. **Must be checked before claiming it is or is not a
regression** — I will not guess.

---

## 4. THE RULE THIS BREAKS, AND THE STANDING FIX

Rule 1 says *one definition, one owner*. The deeper failure here is different and needs
naming as its own habit:

> **Never edit code by positional text substitution on non-unique text, and never re-run an
> edit without re-reading the file.** Anchor every edit on surrounding content that is unique,
> and verify the result by reading it back — not by assuming the command did what it said.

R1 is exactly what that prevents: two identical lines, one blind positional command, run twice.

---

## 5. NEXT ACTIONS, IN ORDER
1. Fix **R1** (Row 2 back to three columns) and read the file back to confirm.
2. Verify or clear **R3**.
3. Then resume §2 items 1–7, starting with the blur, which needs the iframe decision.
