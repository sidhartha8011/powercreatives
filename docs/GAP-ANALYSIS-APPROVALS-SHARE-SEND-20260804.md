# GAP ANALYSIS — sharing cannot send, wrong verbs, uncleared lane, duplicate email field
**Date:** 2026-08-04 · **Owner report** (screenshots: the share popover with a valid
recipient pill, and the toast "A valid email is required."). **Facts only — no fix applied.**

---

## A. "A valid email is required." with a valid email — ROOT CAUSE FOUND, and it is mine

The send is **broken 100% of the time** from the new share popover. Three files, one
mismatch in the middle:

| Layer | What it does | Where |
|---|---|---|
| 1. The panel sends | `mutateAsync({ id, **emails**: list, message })` — the plural array | `ApprovalSharePanel.tsx:202-206` |
| 2. The tRPC transform rebuilds the body | ``body: { **email**: input.email, message: input.message }`` — it hard-picks the **singular** key and drops everything else | `lib/trpc-routes.ts:1151` |
| 3. The server reads | `emails[]`, falling back to `email`; if neither yields a valid address → `error('A valid email is required.')` | `approvals/controller.php:644-663` |

**The chain:** the panel sends `emails`. The transform does not copy `emails` — it reads
`input.email`, which is `undefined`. `JSON.stringify` omits `undefined` keys, so the request
body contains **only `message`**. The server finds neither `emails` nor `email`, `$emails`
is empty, and it returns the named error. The pill on screen is real and valid; it simply
never reaches PHP.

**This is my defect.** I extended the server to accept `emails[]` (commit `e58f1e0`) and
extended the panel to send `emails[]` in the same round, and never updated the transform
sitting between them. The transform is an explicit allow-list, so adding a field at both
ends silently drops it in the middle — nothing errors, the data just disappears.

**Also proven by this:** no send has ever succeeded from the new popover, so the
"move to lane after sending" step has never run either — it is sequenced *after* the send
(`ApprovalSharePanel.tsx`, `send()`: `await shareMutation` → then `statusMutation`).

---

## B. The buttons say "Save", but they send

| # | Fact | Where |
|---|---|---|
| B1 | The two primary actions are labelled **"Save"** and **"Save and Close"**. | `ApprovalSharePanel.tsx` |
| B2 | What they actually do is send an invite email to every recipient, then move the lane. Nothing about the set is "saved" at that point — it was already saved by Generate Share Link. | same, `send()` |
| **Owner's ask** | **"Send"** and **"Send and Close"**. | — |

---

## C. The "move to lane after sending" choice cannot be removed

| # | Fact | Where |
|---|---|---|
| C1 | The control is a plain shadcn `Select`, which has **no clear affordance** — once a value is chosen it can only be swapped for another. | `ApprovalSharePanel.tsx` |
| C2 | It opens pre-selected on `client` (`defaultLaneAfterSend="client"`, passed by the dialog), so the user starts with a choice already made and no way to un-make it. | `CreateCustomSetDialog.tsx` |
| C3 | A "Leave where it is" sentinel row does exist (`NO_MOVE`), so the *behaviour* is reachable — but only by picking another row, not by clearing. | `ApprovalSharePanel.tsx` |
| C4 | Every other dropdown on this surface is the shared `SearchableSelect`, which already has a working clear × (fixed this session). This one is the odd control out. | `SearchableSelect.tsx` |

---

## D. The recipient email exists in two places

| # | Fact | Where |
|---|---|---|
| D1 | Row 1 of the card dialog is **Name · Recipient Email · Lane** — the email field the owner now wants gone. | `CreateCustomSetDialog.tsx` |
| D2 | The same address is entered again as a pill in the share popover; Row 1's value only *seeds* the first pill. | `ApprovalSharePanel.tsx` `defaultEmail` |
| D3 | Row 1's email is also sent as `clientEmail` on **create**, which makes the SERVER share-and-email during `create_set` (`controller.php:145-149` → `share_set`). Removing the field removes that path — after which the popover is the **only** way anything is sent. | `controller.php:145-149` |
| D4 | `alreadySent={clientEmail.trim() !== ''}` exists purely to stop the popover offering a duplicate resend of that server-side send. It becomes dead once D3 is gone. | `CreateCustomSetDialog.tsx` |

**Consequence to decide, not assume:** with Row 1's email removed, Row 1 becomes
**Name · Lane** (two columns), and creating a set never emails anyone — sending is an
explicit act inside the popover. That matches "All sharing should be inside the sharing
link", and it is a behaviour change worth stating out loud.

---

## E. FACTUAL CHECKLIST

- [ ] E1 `trpc-routes.ts` shareSet transform must carry **`emails`** (and keep `email` for
      the legacy single-recipient callers). This alone unbreaks sending.
- [ ] E2 Audit the other transforms for the same allow-list trap — any route whose body is
      hand-rebuilt can silently drop a field added at both ends.
- [ ] E3 Relabel the two actions **"Send"** / **"Send and Close"**.
- [ ] E4 Replace the lane `Select` with the shared `SearchableSelect` so the choice can be
      cleared, and let cleared mean "don't move".
- [ ] E5 Delete Row 1's Recipient Email; Row 1 becomes Name · Lane.
- [ ] E6 Stop sending `clientEmail` on create from this dialog; remove the now-dead
      `alreadySent` seeding (D4).
- [ ] E7 Re-verify a real send end-to-end **after E1** — the send path has never once
      succeeded, so nothing downstream of it (the lane move) has been exercised either.

## F. VERIFY
`php -l` if PHP is touched · harness 88/88 · tsc 59 pre-existing ZERO new · build ·
changelog · AFTER commit **LOCAL ONLY**. Never push.

## G. STILL OPEN (unchanged, not forgotten)
- The 15–30 s share click: server measured at ~120 ms, cause is browser/transport, needs a
  Network trace (plan `19ff403` STEP 1).
- Document edits are not persisted after save (gap `270356c`).
- The dead "View client feedback" chip (`approvals/service.php:69`).
- Bundle code-splitting (5.28 MB).
