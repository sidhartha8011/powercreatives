# FACTUAL CHECKLIST + PLAN — approval cards as collections
**Date:** 2026-08-05 · **Owner order:** verify every fact, including the ones I believed I
already had; build the checklist from verified facts only; commit; implement.

**Owner's requirement, verbatim in 12 points:** click any card on the board · expand it to see
sub-assets inline · open the big card → the regular client approval view · click assets · add
assets · delete assets · edit/rewrite assets · approve on the client's behalf · share button in
the header · share the whole set · share an individual asset · **reuse the existing share
popover, do not build a new one**.

**Owner decision taken:** individual-asset sharing = **deep link on the existing set token**
(`&pcm_asset=<id>`), not a new per-asset access token.

---

## 1. VERIFIED FACTS

### Server

| # | Fact | Evidence |
|---|---|---|
| S1 | The board's list query selects `id, userId, brandId, projectId, deliveryId, name, token, status, clientEmail, createdAt, updatedAt`. `snapshot` and `reviewFeedback` are excluded **on purpose** — longtext, can carry embedded images. | `service.php:72-74` |
| S2 | `format_set_row` runs `json_decode($row->snapshot)`; with `snapshot` unselected that yields `array()`, so **every board row ships `snapshot: []`** — an empty *array*, not an object. | `service.php:663-680` |
| S3 | **Consequence of S2, a live dead-code bug:** `SetCard.tsx:114` reads `set.snapshot.brandName`, which is `undefined` on every board card. The `??` fallback carries it. | `SetCard.tsx:114` + S2 |
| S4 | `POST_SUBMIT_STATUSES = ['launch','live','archived']`. | `service.php:688` |
| S5 | `append_to_set` is ownership-scoped and refuses with `'locked'` when status ∈ S4 **or** the set is fully approved. | `service.php:179-192` |
| S6 | `merge_snapshot` loops a hardcoded `('media','copy','articles','custom')`, dedupes by item `id`, appends. | `service.php:144-168` |
| S7 | The bucket→approval-key map is written out **twice**, identically. | `service.php:1086-1089` and `:1113-1116` |
| S8 | Only **two** asset routes exist: `POST /sets/{id}/assets` (append, `edit_posts`) and `POST /sets/{token}/assets/{asset_id}` (update, **public/token-scoped**). **No delete. No per-item GET. No ownership-scoped update.** | `controller.php:80-81` |
| S9 | Approve is `POST /sets/{token}/approve` — **public, token-scoped, rate-limited**. There is **no** `edit_posts` approve route. | `controller.php:74`, `:558` |
| S10 | Team reply already exists: `POST /sets/{id}/reply` (`edit_posts`). | `controller.php:76` |
| S11 | Share already exists: `POST /sets/{id}/share` (`edit_posts`). | `controller.php:77` |
| S12 | MySQL **8.0.35**; `JSON_LENGTH(JSON_EXTRACT(snapshot,'$.copy'))` verified against both live rows. | live query |

### Frontend

| # | Fact | Evidence |
|---|---|---|
| F1 | The board maps over **sets**, never assets — sub-assets have never appeared as their own cards. **Bullet 1 needs no work.** | `SetsBoard.tsx:498-508` |
| F2 | `KanbanBoard` hands the entire card body to the consumer via `renderCard(item, ctx)` — **expansion needs no change to the shared primitive.** | `KanbanBoard.tsx:149, 260, 304` |
| F3 | `SetCard`'s root is `<article role="link">` with a card-wide click; it renders checkbox, title, brand, lane and action chips. **No disclosure control, no item count.** | `SetCard.tsx:117-131`, `:158-174` |
| F4 | The reusable client-view block is: buckets `:337-341`, counts `:342-349`, `allMergedAssets` (`{id,type,data}`) `:351-373`, `activeAssetForComment` `:375-379`, `filteredAssets` `:381+`, grid render `:527-577`. **≈95 lines.** | `ClientReviewPage.tsx` |
| F5 | `CreativeAssetCard` already accepts `isTeamMember` and gates edit affordances on it. | `CreativeAssetCard.tsx:69, 261, 373, 589` |
| F6 | `ApprovalSharePanel` takes `setId, shareUrl, defaultEmail, defaultMessage, alreadySent, lanes, defaultLaneAfterSend, onSaved, onSaveAndClose, onCancel`. | `ApprovalSharePanel.tsx:74-103` |
| F7 | `buildPublicBoardUrl(token)` → `<shortcode page>?pcm_public_token=<token>`. | `approvalSets.ts:38-47` |
| F8 | `App.tsx` routes on `pcm_public_token` **only** — there is no asset parameter today, so a deep link needs a new param **and** handling on the client page. | `App.tsx:21-26` |
| F9 | **My defect, still in the tree:** `SetsBoard.tsx:231` opens `snapshot.custom[0]`. Combined with S2 this is why it needed an extra per-card fetch at all. | `SetsBoard.tsx:231` |
| F10 | `CardDocumentView` renders the document correctly — browser-verified (crumb 0, Approve in topbar, 708px, 40px title, 16px prose). | this session |

### Environment

| # | Fact | Evidence |
|---|---|---|
| E1 | `pm.max_children = 2`. Measured site-wide: `/wp-json/` 44.6 s, front page 45.8 s, static 0.15 s, approvals endpoint alternating 38 / 0.45 / 47 / 0.67 / 45 s. Not this module. Fix is R4 (2→6), owner's call. | `www.conf.hbs:5` + measurements |

### What these facts CHANGE about the plan

- **S9:** approve-on-behalf needs **no new route** — the admin holds the token and can call the
  existing public approve route. It is rate-limited, so the UI must not fire it per keystroke.
- **S2/S3:** board rows carry `snapshot: []`, so **any** contents work must come from a new
  summary; and `SetCard:114`'s snapshot read is already dead and gets removed.
- **S8:** exactly **one** new route is required (delete). Not three.
- **F8:** the asset deep link is **two** changes — emit `&pcm_asset=` and honour it on open.

---

## 2. CHECKLIST

**C1 — Item summary on the board row (S1, S2, S12)**
- [ ] `list_sets_by_user` additionally returns `itemCount` and `items[] { id, type, title }`,
      extracted in SQL. `snapshot` stays unselected.
- [ ] `format_set_row` stops emitting the misleading `snapshot: []` on list rows.
- [ ] **Gate:** list response carries `items`; `snapshot` absent; payload stays small on a card
      holding an embedded image.

**C2 — The card expands (F2, F3)**
- [ ] `SetCard` shows the item count and a disclosure control; expanding lists the sub-assets
      from `items[]` already on the row. No `KanbanBoard` change.
- [ ] Remove the dead `set.snapshot.brandName` read (S3).
- [ ] **Gate:** expanding fires **zero** network requests (verified in a browser Network trace).

**C3 — Per-item actions on the expanded card (S5, S8)**
- [ ] Open a sub-asset from the expanded row into the existing viewer.
- [ ] **New route** `DELETE /approvals/sets/{id}/assets/{assetId}` — ownership-scoped, reusing
      the S5 lock guard verbatim; delete action on the row, immediate with undo.
- [ ] **Gate:** deleting on a `launch/live/archived` card is refused by the server, not just hidden.

**C4 — The modal is the client view (F4, F5)**
- [ ] Extract F4's ≈95 lines into one `ApprovalSetContents` component.
- [ ] `ClientReviewPage` consumes it. The admin modal consumes it with `isTeamMember`.
- [ ] Delete the `custom[0]` branch (F9).
- [ ] **Gate:** one definition, two consumers; `grep` finds the grid render once.

**C5 — Admin powers inside the modal (S5, S9, S10)**
- [ ] Add assets — reuses `appendToSet` as-is.
- [ ] Edit assets — via the existing `isTeamMember` affordances.
- [ ] **Approve on the client's behalf** — calls the existing token approve route (S9). Debounced,
      because that route is rate-limited.

**C6 — Share, reusing the existing popover (F6, F7, F8, S11)**
- [ ] Share button in the modal header → **`ApprovalSharePanel` unchanged**, set-level.
- [ ] Share on an individual asset → same panel, `shareUrl` carrying `&pcm_asset=<id>`.
- [ ] `App.tsx` honours `pcm_asset` by opening that asset on load.
- [ ] **Gate:** zero new share UI; `ApprovalSharePanel` has exactly one implementation.

**C7 — Adjacent correctness (S7)**
- [ ] Collapse the twice-written bucket→approval-key map into one definition.

**V — Verify**
- [ ] `php -l` each touched PHP file · `tests/standalone/run.php` **88/88** · `npm run check` =
      **59 pre-existing, ZERO new** · `npm run build`
- [ ] **Executed in a browser against a REAL multi-item card built via `appendToSet`** — not
      claimed on a single-item install
- [ ] Changelog line · AFTER commit ending **LOCAL ONLY** · never push

---

## 3. ORDER
C1 → C2 (expansion is the daily win and routes around E1) → C3 → C4 → C5 → C6 → C7 → V.

## 4. NOT IN THIS ROUND
Editing a document through the `save(patch, ctx)` contract, the card-type registry's 11
`type ===` lines, `data-id` in saved content, the colour-literal debt, owner/assignee, the dead
feedback chip, and R4 itself.
