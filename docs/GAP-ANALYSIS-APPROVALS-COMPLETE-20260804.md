# GAP ANALYSIS — the complete Approvals round: as-is → to-be, with the debt named
**Date:** 2026-08-04 · **Owner order.** Every "TODAY" line is verified at file:line or by
measurement. Every "MUST BE" line is the target. Nothing is left as "tidy up later".

---

## 1. THE OPENED CARD

| # | TODAY (verified) | MUST BE |
|---|---|---|
| 1.1 | Overlay is a flat scrim — `background: rgba(15,15,15,0.55)` with **no `backdrop-filter`** (`client-review.css:827-837`). The page behind stays sharp, so the document never separates from it. | A real backdrop: scrim **plus** blur, so the page recedes and the document is the subject. |
| 1.2 | Modal is `width: min(977px, 94vw)`, `align-items: flex-start`, `padding: 4vh 16px`, radius 10px (`:838-844`) — it hangs from the top edge with a hard band above it. | Page-like placement and a shell that reads as paper, not a panel. |
| 1.3 | The **card tile** carries heavy glass — `backdrop-filter: blur(30px) saturate(180%)`, translucent white, inset highlight, 16px radius (`:467-479`) — while the **opened document** carries none. The cheap-looking surface is the one you actually read in. | The document is the most considered surface in the module; the tile is quieter than it. |
| 1.4 | Content column `708px`, title `40px/1.2/700`, prose `16px/1.5` (`:878-930`) — correct numbers, but they live in `client-review.css`, which **only `ClientReviewPage` imports** (`ClientReviewPage.tsx:13`). | One document definition both surfaces reach — see §2. |

## 2. THE DOCUMENT SCALE (one definition, correctly sourced)

| # | TODAY | MUST BE |
|---|---|---|
| 2.1 | Two scales for one document: viewer **16px/1.5** (`client-review.css`), editor **11px/1.7** (`.pcm-card-editor`, `index.css`). | One definition, consumed by both. |
| 2.2 | The editor is 11px **because I bound it to the Writer's `--pcm-prose-*`**, which documents itself as *"compact fit with admin UI"*. Right instinct, wrong source. | The document scale is its own source; the Writer keeps its admin-compact one untouched. |
| 2.3 | `index.css` resets with `#pcm-root :where(p|h1…)` = **(1,0,1)**; a plain `.pcm-notion-prose p` is **(0,2,0)** and loses inside `#pcm-root`. The viewer only escapes by `createPortal` to `document.body` (`CreativeAssetCard.tsx:85`). | The shared definition ships in `index.css` using the two-branch `#pcm-root …` + bare pattern, so it survives the resets on both sides. |
| 2.4 | Editor is a bordered form box: `rounded-lg border bg-card`, `p-4`, `max-h-[72vh]`, full width, always-on toolbar, no document title, no bottom space. | Centred 708px measure, no framing, real 40px title, bottom breathing room, actions in the existing selection bubble. |

## 3. THE CARD-TYPE STRUCTURE

| # | TODAY | MUST BE |
|---|---|---|
| 3.1 | `CreativeAssetCard.tsx` is **729 lines**, four inline `type ===` branches, plus `ArticleViewerDialog` defined in the same file. | One file per type; the host owns only shared chrome. |
| 3.2 | Editability is a ternary at `:401` — bound **only** to `copy`. `article`/`custom` open a viewer built `editable: false`. | `editable` is a declared property of each type; forgetting one is impossible. |
| 3.3 | The save reads its token from `window.location.search` (`:349`) for a token-scoped route (`controller.php:81`). | Host resolves the token once; `save(patch, ctx)` — no type touches `window`. |
| 3.4 | Only consumer is `ClientReviewPage.tsx:557`. | Unchanged — blast radius is one file. |

## 4. OWNER / ASSIGNEE / TASK

| # | TODAY | MUST BE |
|---|---|---|
| 4.1 | `approval_sets.userId` exists (creator). **No assignee column.** `PCM_DB_VERSION` 1.45.0. | `assigneeId int(11) NULL`, gated migration, → 1.46.0. |
| 4.2 | `users.list` route exists (`users/controller.php:43`, `trpc-routes.ts:1169`). | Assignee picker uses it; no new endpoint. |
| 4.3 | Board filters are declarative (`setFilters.ts`). | Owner filter = one row + one dropdown. |
| 4.4 | `notifications.create` action exists, rule-driven. | Assign fires a **trigger**; no new channel. |
| 4.5 | Task-list packages **absent** from `editorExtensions.ts` and `package.json`. | ⛔ Blocked on the dependency decision. |

## 5. TECHNICAL DEBT FOUND IN THE SWEEP — all of it fixed in this round

| # | TODAY (measured) | MUST BE |
|---|---|---|
| 5.1 | **53 raw hex colours** in `client-review.css`. | Design tokens / CSS custom properties. No literal in a component surface. |
| 5.2 | **8 hardcoded `z-index` literals** in the same file (incl. `9999`, and `100000` in the inspector lightbox). | A named layer scale; no magic numbers competing. |
| 5.3 | **1 `!important`.** | Removed by fixing the specificity that made it necessary. |
| 5.4 | **17 hardcoded Swedish strings** across `ClientReviewPage` (3), `ClientStatusToolbar` (11), `CreativeAssetCard` (3) — sitting beside English "Approve/Comment/Awaiting" on the same screen. | English only, per the standing UI law. |
| 5.5 | Hardcoded **4-day client deadline** (`REVIEW_PERIOD_MS`, `ClientStatusToolbar.tsx:478`) shown to clients as real. | Hub-controlled data, or removed — never a literal. |
| 5.6 | `submit_review` is **not idempotent** — re-submitting re-fires the webhook; only the client UI guards it. | Server-side guard, like `approve_assets` already has. |
| 5.7 | `reviewFeedback` isn't in the list query, so the **"View client feedback" chip can never appear**. | Ship what the chip needs, or delete the chip. |
| 5.8 | **66 hand-built tRPC bodies** app-wide carry the allow-list trap that silently broke sending. | Audited; pass-through where the handler takes the whole input. |
| 5.9 | Document edits are **not persisted** after save — "Save and Close" only closes. | Edits persist through the registry's `save`. |
| 5.10 | Share click 15–30 s; server measured **~120 ms**, payload <1 KB. Cause is browser/transport. | Instrumented in the browser and driven under 3 s. |

---

## 6. CHECKLIST (execution order)

**STEP 1 — the document definition (unblocks everything visual)**
- [ ] 1.1 Document scale + measure as custom properties in `index.css`, two-branch selectors (§2.3)
- [ ] 1.2 Viewer and editor both consume it; delete both local copies (§2.1, 2.2)
- [ ] 1.3 Gate: identical computed font-size / line-height / measure on both; exactly one definition in the tree

**STEP 2 — the opened card**
- [ ] 2.1 Backdrop scrim **+ blur** (§1.1)
- [ ] 2.2 Shell placement and radius that read as paper (§1.2)
- [ ] 2.3 Document surface out-classes the tile, not the reverse (§1.3)
- [ ] 2.4 Editor becomes a page: measure, no framing, 40px title, bottom space, bubble actions (§2.4)

**STEP 3 — the card-type registry**
- [ ] 3.1 `cardTypes/types.ts` — `{ id, label, render, editable, editFields, save(patch, ctx) }`
- [ ] 3.2 Four verbatim extractions; `ArticleViewerDialog` moves with its types
- [ ] 3.3 `custom` + `article` editable; `media` explicitly not
- [ ] 3.4 Gate: `grep -c "type === '" CreativeAssetCard.tsx` → **0**

**STEP 4 — debt, fixed not deferred**
- [ ] 4.1 53 hex → tokens · 4.2 8 z-index → named scale · 4.3 drop the `!important`
- [ ] 4.4 17 Swedish strings → English · 4.5 the 4-day literal → data or gone
- [ ] 4.6 `submit_review` idempotency · 4.7 the feedback chip: ship it or delete it
- [ ] 4.8 tRPC body audit · 4.9 edits persist on save

**STEP 5 — owner / assignee / task**
- [ ] 5.1 `assigneeId` + migration → 1.46.0 · 5.2 owner + assignee UI · 5.3 owner filter
- [ ] 5.4 notify-on-assign trigger · 5.5 ⛔ checkboxes — blocked on the dependency decision
- [ ] 5.6 progress + assignee never render on the client page

**STEP 6 — the share click**
- [ ] 6.1 Instrument in the browser · 6.2 drive under 3 s · 6.3 report the numbers

**VERIFY (each step)** — `php -l` · harness 88/88 · tsc 59 pre-existing ZERO new · build ·
changelog · AFTER commit **LOCAL ONLY** · never push.

## 7. REPORTED AT THE END
Every bug, hack or bad practice met on the way gets listed in the closing report — §5 is the
opening balance, not the final one.
