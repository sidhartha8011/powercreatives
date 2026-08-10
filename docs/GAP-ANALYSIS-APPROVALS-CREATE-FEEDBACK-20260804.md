# GAP ANALYSIS — creating an approval card: the "double pop" + the card not appearing
**Date:** 2026-08-04 · **Owner report:** "when you create a card, it pops up as a double
pop … it pops up and then disappears again … there should probably be something else
coming up, right?" + "I want the card to be showing immediately. I don't want to need to
update the page just to see the card."

Data-first: the DB was probed BEFORE reading any code for a cause. No fix applied.

---

## 0. What the live data says (hub DB, mysqli 127.0.0.1:10017)

```
=== wp_pcm_approval_sets, newest first ===
id=11  test   status=draft  uid=1  brandId=-  projectId=2  deliveryId=-  created=2026-08-04 04:53:59  snapshot=262 bytes

=== wp_pcm_projects ===
id=2   Donkey   deliveryId=NULL   2026-05-13 10:50:39
```

Three facts fall straight out of that:

1. **Exactly ONE row was written.** The "double" is **not** a double submit — no
   duplicate set, no duplicate project. Whatever appeared twice was UI, not data.
2. The set is **`status='draft'`** — so the card lands in the **Draft** column, not in a
   client lane. `share_set()` (which moves it to `client`) only runs when a client email
   was entered on create (`approvals/controller.php:145-149`). None was.
3. Its project is **#2 "Donkey", `deliveryId = NULL`** → the live chain resolves delivery
   AND brand to null, so this card correctly shows **no brand prefix** and contributes
   nothing to the Brand/Delivery dropdowns. Expected under the project-only mapping
   (gap 30f87f1), not a defect — but it does mean the board looks emptier than expected.
   (The four older sets are gone from the table — deleted during testing — so the board
   currently holds this one card only.)

---

## 1. GAP A — nothing ever tells the board to refetch. **Confirmed.**

The card exists server-side the moment Create returns, but the board cannot know:

- The tRPC adapter's `useMutation` does **zero cache work** — it builds a fetch and
  returns; no invalidation, no `onSuccess` hook of its own (`lib/trpc.ts:176-208`).
- `CreateCustomSetDialog`'s `onSuccess` only fires a toast and calls `onClose()`
  (`CreateCustomSetDialog.tsx:70-78`). It never touches the query cache.
- QueryClient defaults: **`staleTime: 30_000`**, **`refetchOnWindowFocus: false`**
  (`main.tsx:17-26`) — so nothing re-fetches on its own for at least 30s, and switching
  windows won't do it either.
- Grep across the whole app: **no call site anywhere invalidates `approvals.listSets`.**
  Not one. The only writers to that cache are the optimistic status/delete updates in
  `useApprovalSets` (`:161-253`).

⇒ A page reload is currently the ONLY way a newly created set reaches the board. Exactly
what was reported.

**The pattern already exists in this codebase** — `ClientReviewPage` calls
`utils.approvals.getPublicSet.invalidate({ token })` after an approval
(`ClientReviewPage.tsx:186-196`), and `useApprovalSets` writes the cache optimistically
for moves and deletes. Create is simply the one path that never adopted it. Same hole in
`SendToApprovalSetDialog` and `Ads/CreateApprovalSetDialog`, which is invisible there only
because those dialogs stay open on a share-link screen.

## 2. GAP B — the custom flow ends in a flash of nothing. **Confirmed, and asymmetric.**

| Flow | What happens when you click the create button |
|---|---|
| Copy / Image (`SendToApprovalSetDialog:452-529`) | Dialog **stays open** and swaps to a "Generated Shareable Client Board Link" panel: the link + copy button + "email the link to your client" + editable message |
| Ads (`Ads/CreateApprovalSetDialog:397+`) | Same share-link panel |
| **Add Approval Set** (`CreateCustomSetDialog:70-78`) | Dialog **closes**; a sonner toast appears bottom-right and auto-dismisses. Nothing else. |

So on the custom flow the only post-create feedback is a toast that appears and vanishes —
matching "it pops up and then disappears again" — while the board behind it is unchanged
because of GAP A. The instinct that "there should probably be something else coming up" is
correct: every sibling create flow shows the client link at this exact moment, and this one
silently drops it, even though the set has a `token` and the link is what the whole feature
is for.

**Not reproducible from code:** a *second* popup. One row, one toast, one `onClose()`, no
second dialog mounts in `Approvals/index.tsx` (only `CreateCustomSetDialog`), and no
`location.reload()` anywhere in the create path. If something genuinely renders twice, it
needs a screen recording or a Network-tab look to pin — it is NOT in the data.

---

## 3. Checklist (proposed — NOT applied, awaiting GO)

- [ ] C1 Invalidate `approvals.listSets` on create success in **all three** create dialogs
      (`utils.approvals.listSets.invalidate()`), so the board is correct everywhere, not
      just on the path that was reported.
- [ ] C2 Make the new card appear **instantly**, not after a round trip: insert the created
      set into the list cache directly (the server already returns the full row — 
      `create_set` → `get_set_by_id`, `controller.php:151-152`), mirroring the optimistic
      writes `useApprovalSets` already does for move/delete. C1 then only reconciles.
- [ ] C3 Decide what follows Create on the custom flow. **Recommended:** keep the dialog
      open and show the same share-link panel the other three flows show (one shared
      surface, no fourth behaviour) — the link is the point of the feature.
      Alternative: close as today, now that C2 makes the card appear behind it.
- [ ] C4 Only if C3 = share panel: the panel is currently inline in
      `SendToApprovalSetDialog`; extracting it to a shared component is the honest way to
      reuse it rather than copying the markup a third time.

## 4. Verify plan
`php -l` (only if a PHP file is touched — C1/C2/C3 are frontend-only) · `cd app && npm run
check` (tsc 59 pre-existing, zero new) · `npm run build` · browser: create a card and watch
it land in **Draft** with no reload · changelog line · AFTER commit **LOCAL ONLY**. Never push.

## 5. Out of scope (named)
- The Draft-vs-client lane behaviour is correct as designed (no client email → no share →
  no lane move). Changing it would be a product decision, not a fix.
- The dead "View client feedback" chip (`reviewFeedback` also unselected at
  `approvals/service.php:69`) — still open from the 2026-08-04 module review.
