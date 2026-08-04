# GAP ANALYSIS — the approval-set dialog: two-row mapping, Generate Share Link, and the multi-recipient send step
**Date:** 2026-08-04 · **Owner order + GO**, spec confirmed across four messages and
consolidated below. Facts are at file:line in today's tree; each decision names the option
that was rejected.

---

## 1. THE SPEC AS CONFIRMED

**Header:** title left, **Generate Share Link** top-right — saves the set and produces the
link **without closing anything**. Copying is a deliberate second click.

**Row 1 — Name · Recipient Email · Lane.** The email field is *renamed* from "Client email"
to "Recipient Email" and keeps working as it does; it **pre-fills the share step**. Lane =
which lane the set is created into, defaulting to the "+" that was clicked.

**Row 2 — Project · Delivery · Brand.** All three visible, Project and Delivery changeable,
Brand shows the client. Any one narrows the other two to what is actually available.

**Footer:** two main buttons — **Cancel** and **Save and Close**.

**The share step** (from Generate Share Link): the link with a copy button, plus a
Google-style recipient box where each committed address becomes a **pill** (multiple
recipients), a **pre-written editable message**, and a **"move to lane after sending"**
dropdown that must be **dynamic, never hardcoded**. Three actions:

| Button | Sends | Moves the lane | Closes the share popover | Closes the set editor |
|---|---|---|---|---|
| Cancel | no | no | yes | no |
| Save | yes | yes | yes | **no** |
| Save and Close | yes | yes | yes | **yes** |

Closing never undoes anything: the send happened and the lane already changed.

---

## 2. FACTS

| # | Fact | Where |
|---|---|---|
| F1 | **Sharing is strictly single-recipient.** `share_set(int $set_id, int $user_id, string $email, string $message='')` validates ONE address and returns false otherwise. | `approvals/service.php:1270-1275` |
| F2 | The REST layer mirrors that — one `email` param, hard-required. | `approvals/controller.php:635-647` |
| F3 | The invite email's recipient is read from **`$context['clientEmail']`** by `render_email_for_event()`; no recipient, no email. | `automations/service.php:276-280` |
| F4 | `share_set` also fires the `approvals.set_shared` **trigger**, which drives the seeded "move to Sent to Client" rule — so firing it per-recipient would run that rule (and any user webhook) N times. | `approvals/service.php` · `automations/class-pcm-automation-seeds.php:128-137` |
| F5 | `share_set` stores the address on the set (`clientEmail`) **and** remembers it on the brand for next time. Both are single-value columns. | `approvals/service.php:1280-1288` |
| F6 | The lane registry already exists and is the single source: `setColumns.ts` (id + label per lane), mirroring `PCM_Approvals_Service::STATUSES`. | `Approvals/kanban/setColumns.ts` · `approvals/service.php:31` |
| F7 | `create_set` now takes an optional validated `status` (landed this session) — Row 1's Lane needs no new backend. | `approvals/service.php` |
| F8 | The chain data needed for three-way filtering is already on the wire: `deliveries.list` returns `brandId`, `assets.getProjects` returns `deliveryId`. | `SendToApprovalSetDialog` · `assets/controller.php:376` |
| F9 | `ApprovalSharePanel` already owns link + copy + single-recipient send + editable message, and is used by all three create flows. | `components/shared/ApprovalSharePanel.tsx` |
| F10 | `useApprovalSetsCache().registerCreated()` already makes a created set appear on the board instantly. | `components/shared/approvalSets.ts` |
| F11 | `update_status()` is the ownership-scoped lane mover and already fires `set_status_changed`; the share step's "move after sending" should reuse it, not write status directly. | `approvals/service.php:274-322` |

---

## 3. ARCHITECTURAL DECISIONS

**D1 — multi-recipient is ONE share event with N emails, not N shares.**
Extend the endpoint with `emails: string[]` (keeping `email` for compatibility). `share_set`
loops the **email dispatch** per recipient (F3: swap `clientEmail` in the context each pass)
and fires the `approvals.set_shared` **trigger exactly once**, after the loop. The first
valid address is what gets stored on the set and remembered on the brand (F5 — both columns
are single-valued and that is not being changed).
*Rejected:* calling today's single-recipient endpoint once per pill from the browser. Per F4
that fires the automation N times → the lane-move rule runs N times (harmless but dishonest)
and any user webhook fires N times (not harmless). One share is one event.

**D2 — the two lane fields are deliberately separate, and share ONE registry.**
Row 1's Lane = where the set is *created* (F7). The share step's dropdown = where it *moves
after sending*. Both render their options from `setColumns` (F6) — the owner's "dynamic, not
hardcoded" is satisfied by reading the existing registry, so adding a lane stays a one-line
change in one file.
*Rejected:* a literal option list in either dropdown, and a second lane list on the server.

**D3 — the "move after sending" goes through `update_status()`.**
It is ownership-scoped, stamps `clientSentAt` on entry into `client`, and fires
`set_status_changed` so downstream rules chain (F11).
*Rejected:* writing `status` straight to the row — it would skip the stamp and the trigger.

**D4 — the share step is a shared component, not dialog-local markup.**
`ApprovalSharePanel` (F9) grows the recipient pills, the lane dropdown and the three
actions, so Ads / Copy / Image / the custom card all get the same send flow. The owner
explicitly asked to reuse the existing component.
*Rejected:* a bespoke popover inside `CreateCustomSetDialog` — a fourth send surface.

**D5 — Row 1 "Recipient Email" seeds the pills; it is not a second source of truth.**
Owner-confirmed. The field stays for a fast single-recipient path and is passed into the
share step as the first pill.

**D6 — three-way filtering is derived client-side from data already fetched (F8).**
Brand → its deliveries → their projects, and upward from a project via its chain. No new
endpoint, no new query.
*Rejected:* a `/hierarchy` lookup route — the two lists already on the page answer it.

**D7 — Brand stays a *narrowing* control, not a second mapping.**
Picking a brand filters deliveries/projects and pre-fills the set's `brandId`, which
`format_set_row` overwrites from the live chain the moment a project exists — so it can
never contradict the project-only mapping (ruling 30f87f1).

---

## 4. FACTUAL CHECKLIST

**Backend**
- [ ] B1 `share_set` accepts `string[] $emails` (or a single `$email`, unchanged): validate
      each, dispatch the invite per recipient, fire `set_shared` ONCE, store + remember the
      first valid address (D1).
- [ ] B2 Controller accepts `emails[]`, falls back to `email`; empty/all-invalid → named
      error, never a silent no-send.

**Shared layer**
- [ ] S1 `ApprovalSharePanel` → recipient **pills** (add on Enter/comma/blur, removable),
      seeded from the caller (D5).
- [ ] S2 Same panel: **"move to lane after sending"** dropdown, options from a lane registry
      passed in by the consumer (D2), and the **three actions** Cancel / Save / Save and
      Close with the exact matrix in §1.
- [ ] S3 On Save|Save and Close: send to every pill, then move the lane via
      `approvals.updateSetStatus` (D3), then refresh the board cache (F10). "Save and Close"
      additionally asks the host dialog to close.
- [ ] S4 `ProjectPicker` → the permanent **Project · Delivery · Brand** row with three-way
      narrowing (D6, D7); the delivery is no longer a conditional reveal.

**Approvals dialog**
- [ ] A1 Row 1 = Name · **Recipient Email** · Lane (registry-driven, defaults to the "+"
      lane); Row 2 = the mapping row.
- [ ] A2 **Generate Share Link** top-right: saves the set, shows the link, closes nothing.
- [ ] A3 Footer = **Cancel** · **Save and Close**.

**Verify**
- [ ] V1 `php -l` each touched PHP file · `tests/standalone/run.php` 88/88 ·
      `npm run check` 59 pre-existing ZERO new · `npm run build` · changelog · AFTER commit
      **LOCAL ONLY**. Never push.

## 5. PLAN (sequential, each step independently verifiable)
1. B1+B2 — multi-recipient sharing, server first, harness green.
2. S1+S2+S3 — the share step, wired to the existing single-recipient callers so nothing
   regresses before the dialog changes.
3. S4 — the mapping row with three-way narrowing.
4. A1–A3 — the dialog layout, top-right action, footer.

## 6. OUT OF SCOPE (named)
- Storing a *list* of recipients on the set: `clientEmail` and the brand's remembered
  address stay single-valued (F5). Multi-send is an action, not new state.
- The Deliveries board adopting the lane "+" (free, unrequested).
- The dead "View client feedback" chip (`approvals/service.php:69`).
