# GAP ANALYSIS — self-audit: my own session output against my own three rules
**Date:** 2026-08-04 · **Owner order:** "they're your rules for yourself — fix it now, leave
nothing behind."

I wrote three rules from the failures found this session. Then I audited what **I** shipped
this session against them. Three violations, all mine, all verified at file:line.

**The rules**
1. One definition, one owner.
2. Never hand-build an allow-list.
3. Run the path once with real data before calling it done.

---

## V1 — RULE 2, violated in the very fix that produced the rule ⚠ worst of the three

`lib/trpc-routes.ts:1157-1160`

```ts
transform: (input: any) => ({
  url: `approvals/sets/${input.id}/share`,
  body: { email: input.email, emails: input.emails, message: input.message },
}),
```

I diagnosed that a hand-built body is an allow-list, wrote a nine-line comment above this
saying exactly that — **and then left it a hand-built body.** I added the missing key instead
of removing the failure mode. The next field added at both ends disappears in precisely the
same way, silently, with the same class of bug report.

**Must be:** `body: input`. The handler already reads only what it needs and sanitises it;
the client has no business deciding which of its own fields survive.
**Check:** the transform names no field.

## V2 — RULE 1, two normalisers for the same three registries

| Where | What it does |
|---|---|
| `modules/Approvals/kanban/SetsBoard.tsx` | `toNamedRows()` — normalises brands, deliveries, projects (**4** call sites) |
| `components/shared/ProjectPicker.tsx` | `useProjectPickerData()` — normalises the same three (**3** coercions) |

Two definitions of "what a registry row is", built by me in the same session, for the same
three registries. This is the exact duplication that lost `deliveryId` from the picker
options the first time — and I wrote the second one while fixing the first.

**Must be:** one normaliser, one owner; the board consumes the shared hook.
**Check:** `toNamedRows` exists nowhere; `grep -c "Number(p.id)"` across the module = 1.

## V3 — RULE 1, one registry mapped into two shapes at the call site

`modules/Approvals/components/CreateCustomSetDialog.tsx:61` builds
`{ value, label }`; `:282` re-maps the same array inline to `{ id, label }` because the panel
wants a different shape. One registry, two vocabularies, converted at a call site.

**Must be:** one lane-option shape shared by both consumers.
**Check:** no inline `.map` re-shaping a registry at a call site.

**Also noted, not yet a violation:** `ApprovalSharePanel.looksLikeEmail` is a second
definition of email validity beside the server's `is_email`. Defensible as fast client
feedback, but the two can disagree — record it so a later reader knows it is deliberate.

## V4 — RULE 3, and this one covers everything I shipped today

**Not one line of this session's work has been executed once.** The send fix, the share
popover, the backdrop, the document scale, the lane "+", the select mode, the English client
surface, the idempotency guard — all of it is compiled, typechecked, built, and **never run**.

By my own rule that is not done, it is drafted. The rule exists precisely because this
session's worst bug — sending that never once worked — would have been caught by running the
path a single time.

**Must be:** each path exercised against real data, with the result recorded.
**Check:** a named observation per path, not "tsc passed".

---

## CHECKLIST

- [ ] C1 `shareSet` transform → `body: input` (V1)
- [ ] C2 Audit every transform I touched this session for the same shape (V1)
- [ ] C3 Delete `toNamedRows`; the board consumes `useProjectPickerData` (V2)
- [ ] C4 One shared lane-option shape; no inline re-mapping (V3)
- [ ] C5 Document `looksLikeEmail` as a deliberate client-side pre-check
- [ ] C6 Execute and record: create a set · send to a real address · open a card · filter the
      board · clear a filter (V4)
- [ ] V `php -l` where touched · harness 88/88 · tsc 59 ZERO new · build · changelog ·
      AFTER commit **LOCAL ONLY**

## ORDER
C1 (the rule I broke while writing it) → C3 → C4 → C2/C5 → C6.
