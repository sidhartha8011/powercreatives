# FACTUAL GAP ANALYSIS — every open item, broken or unbuilt
**Date:** 2026-08-05 · **Status: AWAITING OWNER APPROVAL. Not committed, not implemented.**

Complete list: what I previously said was "left", plus everything found broken or false in the
audit the owner ordered. Every TODAY line verified at `file:line` or by measurement today.

---

## A. BROKEN NOW — all introduced by me

| # | TODAY (verified) | MUST BE |
|---|---|---|
| **A1** | `ApprovalSetModal.tsx:169` — unclosed parenthesis from an edit I began without authorisation and abandoned mid-way. **`tsc` fails to parse; the tree does not compile.** Only this one file is dirty; commit `6d1142b` still builds, which is what the browser runs. | Compiles. Either the edit is finished or reverted — owner's call, he instructed no checkout. |
| **A2** | `SetsBoard.tsx:550-560` — while the card's contents are fetching, it renders **`CardDocumentView`**, the Notion page. On this host the fetch takes 40+ s, so the Notion card is the whole experience of clicking a card. **This is the bug the owner reported, and it is why "cards open as the client view" was false.** | The waiting state is the same modal that follows it. A card never shows a document view. |
| **A3** | `ApprovalSetModal.tsx:166` — `onOpenComments` is a **no-op stub**. Clicking Comment on any asset in the admin modal does nothing. I wrote a comment claiming threads open elsewhere and never checked. | Comment opens the thread, as it does on the client page. |
| **A4** | `ApprovalSetModal.tsx:156` — `isSubmitted={false}` is **hardcoded**. On a `launch`/`live`/`archived` card the modal shows Approve as enabled; the server refuses it. The UI lies. | Derived from the card's status against `POST_SUBMIT_STATUSES`. |
| **A5** | `PreviewDialog` has **zero consumers** — I deleted its import from `SetsBoard` and left the component orphaned. "Preview as client" is not deferred, it is **removed**. | Reachable again as an explicit action, or deleted honestly with the capability replaced. |

## B. CLAIMED BUT NEVER EXECUTED

| # | TODAY | MUST BE |
|---|---|---|
| **B1** | **Approve-on-behalf.** I listed it as Done. The code passes `set.token` to the existing approve route, but **I never clicked it once.** | Executed against a real card, and the approval verified as persisted. |
| **B2** | **The share popover.** I measured that the button exists; I never opened it. | Opened, and a real send verified. |

## C. THE COLLECTION MODEL — not built

| # | TODAY (verified) | MUST BE |
|---|---|---|
| **C1** | No way to open a sub-asset from the expanded card. | Clicking a row in the expanded card opens that asset. |
| **C2** | **Zero** delete routes. Four asset routes exist: append (`edit_posts`), a token-scoped public update, and two public snapshot routes. Nothing removes an item. | `DELETE /approvals/sets/{id}/assets/{assetId}`, ownership-scoped, reusing `append_to_set`'s lock guard (`service.php:187-191`). |
| **C3** | No way to add an asset from inside the card. `append_to_set` exists and works (`service.php:179-207`). | Add-item inside the modal, driven by the type registry. |
| **C4** | **Editing is broken in the admin by construction — TWO causes.** (a) `CreativeAssetCard.tsx:332` binds the edit click to `type === 'copy'` only, so media/article/custom have no edit path at all. (b) `CreativeAssetCard.tsx:280` reads the token from `window.location.search`, which **does not exist in wp-admin**, so a save from the admin modal sends an empty token and fails. | Edit works for every type, and the host supplies the token — never `window`. |
| **C5** | **Zero** references to `pcm_asset` anywhere. `App.tsx` routes on `pcm_public_token` only. | Per-asset share emits `&pcm_asset=<id>`, and the client page honours it. |
| **C6** | The bucket→approval-key map is written out **twice**, identically (`service.php:1086-1089`, `:1113-1116`). | One definition. A wrong-approval hazard while it is two. |

## D. OLDER DEBT — carried, never claimed done

| # | TODAY (measured today) | MUST BE |
|---|---|---|
| **D1** | `CreativeAssetCard.tsx` holds **13** `type === '` occurrences. | Card-type registry; the stated gate is 0. |
| **D2** | `UniqueID` still stamps `data-id` into saved document content (`editorExtensions.ts`). | Editor identity stays out of stored content. |
| **D3** | `client-review.css`: **53** hex + **127** `rgb()/rgba()` = **180** colour literals. | Design tokens. |
| **D4** | `assigneeId` appears **0** times in the schema. | Column + migration, owner/assignee UI. |
| **D5** | The "View client feedback" chip is still unreachable — `reviewFeedback` is not in the list query. | Ship what it needs, or delete it. |
| **D6** | **65** hand-built tRPC bodies (the old self-audit's C2, never done). | Pass-through where the handler takes the whole input. |
| **D7** | Document editing still has no `save(patch, ctx)` contract. | The contract, so no type touches `window`. |
| **D8** | Approve/Comment labels wrap awkwardly in the modal's narrower grid — card CSS written for the wider client page. | Legible at modal width. |

## E. ENVIRONMENT — owner's decision, not mine to take

| # | TODAY | MUST BE |
|---|---|---|
| **E1** | `pm.max_children = 2` (`conf/php/php-fpm.d/www.conf.hbs:5`). Measured: `/wp-json/` 44.6 s, front page 45.8 s, static files 0.15 s, approvals endpoint alternating 38 / 0.45 / 47 / 0.67 / 45 s. **This is what makes A2 catastrophic rather than cosmetic.** | R4: 2 → 6. One config line and a site restart. |

---

## THE FACTUAL CHECKLIST — for approval

**Order: stop the bleeding, then finish the model, then debt.**

- [ ] **1** A1 — make the tree compile again.
- [ ] **2** A2 — the waiting state becomes the card modal itself; `CardDocumentView` no longer appears on card click. **Gate: click a card and screenshot the FIRST second, not after the grid arrives.**
- [ ] **3** A4 — `isSubmitted` derived from status, not hardcoded.
- [ ] **4** A3 — Comment opens the thread in the admin modal. **Gate: click Comment, thread opens.**
- [ ] **5** B1 — approve on the client's behalf, executed, and the approval confirmed persisted in the DB.
- [ ] **6** C2 — `DELETE /sets/{id}/assets/{assetId}`, ownership-scoped, lock-guarded. **Gate: refused by the SERVER on a locked card.**
- [ ] **7** C1 — open a sub-asset from the expanded row.
- [ ] **8** C1b — delete a sub-asset from the expanded row, immediate with undo.
- [ ] **9** C4 — editing works for every type, and the token comes from the host, not `window`.
- [ ] **10** C3 — add an asset inside the card.
- [ ] **11** A5 + C5 — "Preview as client" reachable again; per-asset share emits and honours `&pcm_asset=`.
- [ ] **12** B2 — the share popover opened and a real send verified.
- [ ] **13** C6 — collapse the duplicated approval-key map.
- [ ] **14** D8 — Approve/Comment legible at modal width.
- [ ] **V** Per item: `php -l` · harness **88/88** · tsc **59, zero new** · build · **executed in a browser on a REAL multi-item card** · changelog · AFTER commit **LOCAL ONLY** · never push.

**Deliberately NOT in this round** (listed so they are not silently dropped): D1 card-type registry,
D2 `data-id`, D3 colour literals, D4 owner/assignee, D5 feedback chip, D6 the 65 tRPC bodies,
D7 the `save(patch, ctx)` contract, E1 R4.

**My verification rule for this round, since it is what failed:** every gate screenshots or
samples the state the owner actually sees, including the waiting state — never a probe that
waits for success before it looks.
