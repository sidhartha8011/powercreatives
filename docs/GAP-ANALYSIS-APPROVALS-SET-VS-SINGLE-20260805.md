# GAP ANALYSIS + PLAN — approval SET vs approval SINGLE
**Date:** 2026-08-05 · **Owner order:** "We should probably have two different types, as the
table view we have in our app is able to have subitems… build this out so we get the proper
architecture and proper look, so the user understands what they're using and what they're
opening."

Every TODAY line is verified at `file:line` or by live query. No opinion.

---

## 1. WHAT IS ALREADY TRUE

| # | Fact | Evidence |
|---|---|---|
| F1 | A set is **already** a container of many items. `snapshot` holds four arrays — `media`, `copy`, `articles`, `custom`. | `types.ts:120-130` |
| F2 | **Appending to an existing set already works.** `SendToApprovalSetDialog` has a `'create' \| 'append'` mode; "Add to an existing set" calls `approvals.appendToSet` → `POST /approvals/sets/{id}/assets`. Used from Ads, Copy and Image. | `SendToApprovalSetDialog.tsx:137-138, 207, 314`; `approvals/controller.php:80` |
| F3 | Per-item approval and per-item comment threads already exist, keyed by asset id. | `reviewFeedback.approved{Visual,Copy,Article,Custom}Ids`; `comments[assetId]` (`types.ts:131-139`) |
| F4 | **The board row cannot tell how many items a set holds.** `list_sets_by_user` selects an explicit column list and deliberately omits `snapshot` (longtext, can carry embedded images). | `approvals/service.php:72-74` |
| F5 | The expandable sub-item table pattern the owner referred to exists and is shared: TanStack `getExpandedRowModel` + `renderSubComponent`, sub-rows rendered as real table rows. | `Keywords/data-table.tsx:19, 25, 101, 150-151` |
| F6 | MySQL is **8.0.35** and counts items per type straight from SQL **without shipping the longtext**. Measured live: set 17 `custom=1` (snapshot 261 bytes), set 18 `custom=1` (748 bytes). | live query, `JSON_LENGTH(JSON_EXTRACT(snapshot,'$.copy'))` |
| F7 | Sets on this install today: **2**, both a single custom document. | live query |

## 2. THE GAP

### G1 — There is no distinction between a set and a single. Everything is a "set".

| TODAY | MUST BE |
|---|---|
| Every row is an `approval_sets` record regardless of whether it holds one item or twenty. The board card shows a name and a lane; nothing says how much is inside or what kind. | Two **presentations** of one record, derived from the data: **SINGLE** = exactly one item; **SET** = two or more. Read off the item count, never a stored flag or a maintained list of kinds. |

### G2 — My own C7 hides every item but the first document. **My defect.**

| TODAY | MUST BE |
|---|---|
| `SetsBoard.tsx:231` opens `fullPreviewSet?.snapshot?.custom?.[0]`. Append three copies (F2) and opening the card shows **only the document** — the appended items are silently invisible. It also replaced `PreviewDialog`, which showed every asset as the client sees them. | Opening a SET shows the set and everything in it. The Notion document is one **item inside** a set, never a replacement for the set view. |

### G3 — The board cannot render the distinction, because the data never reaches it.

| TODAY | MUST BE |
|---|---|
| By F4 the row has no counts, so the card cannot say "4 items" or "Document" without fetching the whole snapshot per card. | The list query returns a cheap `itemCounts { media, copy, articles, custom, total }` computed in SQL (F6). No longtext on the wire, no extra request per card. |

### G4 — Opening is ambiguous: the same click means different things.

| TODAY | MUST BE |
|---|---|
| Clicking a card opens whatever my branch decided — document or iframe — with no signal beforehand about which. | **SINGLE** opens the item itself. **SET** opens the set view: its items listed, each openable. The card says which will happen before you click. |

---

## 3. THE ARCHITECTURE

**One record, two presentations, derived — not stored.**

```
approval_sets row
└─ snapshot { media[], copy[], articles[], custom[] }
   └─ itemCounts.total == 1  →  SINGLE   → opens THE ITEM
   └─ itemCounts.total >= 2  →  SET      → opens the SET VIEW (items as sub-items)
                                            └─ click an item → opens THE ITEM
```

- **Nothing new is stored.** No `type` column, no migration, no second table. The distinction is
  a function of the data, so appending a second item turns a single into a set automatically and
  deleting back down reverses it. There is no state to keep in step and nothing to forget to set.
- **The item viewer is already built** — `CardDocumentView` renders a document; `CreativeAssetCard`
  renders media/copy/article. Both stay; only what wraps them changes.
- **Per-item approval and comments already work** (F3), so a set view does not need a new
  review mechanism — it needs to present the one that exists.

---

## 4. CHECKLIST

- [ ] **C1** `list_sets_by_user` returns `itemCounts {media,copy,articles,custom,total}` via
      `JSON_LENGTH(JSON_EXTRACT(...))`, guarded by `JSON_VALID`. Snapshot stays off the wire. (G3)
      **Gate:** the response carries counts; `snapshot` is still absent.
- [ ] **C2** One derivation helper — `setKind(counts) → 'single' | 'set' | 'empty'` — in the
      Approvals module, used by every consumer. No second definition. (G1)
- [ ] **C3** `SetCard` states what it is: a "Document"/type label for a single, or an item count
      plus composition for a set. Existing shared tokens only, no new colour literals. (G1, G4)
- [ ] **C4** Opening branches on `setKind`: SINGLE → the item viewer; SET → the set view. Replaces
      my `custom[0]` branch entirely. (G2, G4)
- [ ] **C5** The SET view lists every item across all four arrays as sub-items, each with its own
      open action, reusing the shared expandable pattern (F5) rather than a bespoke list.
- [ ] **C6** "Preview as client" restored as an explicit action, so the iframe view of the real
      client board is never lost — it is a deliberate choice, not the default.
- [ ] **V** `php -l` · harness 88/88 · tsc 59 ZERO new · build · **execute in a browser against
      real data, both a single and a multi-item set** · changelog · AFTER commit **LOCAL ONLY**.

## 5. STAGING — smallest honest increments, each verifiable

1. **C1 + C2** — server counts and the one derivation. Verifiable by reading the REST response.
2. **C3** — the card says what it is. Verifiable in the browser.
3. **C4 + C5** — the two open paths and the set view. Needs a real multi-item set to test; I will
   build one via `appendToSet` rather than claim it works on a single-item install.
4. **C6** — restore Preview as client.

## 6. NOT IN THIS ROUND
Editing a document from the admin, the card-type registry, `data-id` in saved content, the
colour-literal debt, owner/assignee.

## 7. THE 30-SECOND OPEN IS NOT THIS
Measured this session: `/wp-json/` 44.6 s, bare front page 45.8 s, static files 0.15 s every
time, and the Approvals endpoint alternating 38 s / 0.45 s / 47 s / 0.67 s / 45 s. The cause is
`pm.max_children = 2` (`conf/php/php-fpm.d/www.conf.hbs:5`) — two PHP workers, one occupied,
everything else queues. This also corrects a documented claim: the "15–30 s share click" was
recorded as *"cause is browser/transport"*; it is the server. The fix is R4 (2→6), an owner
decision on the stack, untouched here.
