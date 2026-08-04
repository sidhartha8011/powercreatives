# PLAN — Approvals quality round (execution checklist)
**Source gap:** `GAP-ANALYSIS-APPROVALS-QUALITY-ROUND-20260804.md` (commit `bebda90`).
**Rule for this list:** a box is ticked only when the change is written AND its verify line
passed. Nothing is ticked on intent.

Ordered so the unknown is measured first and the user-facing breakage is fixed before polish.

---

## STEP 1 — Measure the share-link time (C10) · the only item whose fix is unknown
- [ ] 1.1 Instrument the real path in **web** context and record elapsed ms per stage:
      `create_set` · `share_set` · `dispatch` · `fire_trigger` · `update_status`.
- [ ] 1.2 Record the numbers in this file. No fix is designed before they exist.
- [ ] 1.3 Delete the probe; verify it no longer answers.
- **Verify:** per-stage ms written below; probe gone.

**MEASURED RESULT:** _(filled in by 1.2)_

---

## STEP 2 — The × that cannot be clicked (C6) · a control the user hits constantly
- [ ] 2.1 `SearchableSelect`: move the clear × **out of** the `PopoverTrigger` (sibling
      control), so a Radix pointerdown can no longer open the list before the click lands.
- [ ] 2.2 Same in `ui/creatable-combobox.tsx` (pre-existing defect, same root cause).
- [ ] 2.3 Keyboard: the clear must be reachable and labelled.
- **Verify:** tsc zero new; clicking × clears without opening the list.

---

## STEP 3 — The share step becomes a real popover, token-styled (C7 + C8 + C1)
- [ ] 3.1 Rebuild `ApprovalSharePanel` as content of a shared Radix `Popover`, anchored to
      the Generate Share Link button. It must float — never expand the dialog body.
- [ ] 3.2 Replace all **14** inline `style={{}}` blocks with classes/semantic tokens.
- [ ] 3.3 "Message to client" gets an explicit `bg-card` surface (C8).
- [ ] 3.4 Recipient pills, message, lane dropdown and the three actions all keep working.
- **Verify:** tsc zero new; build; grep the file for `style={{` → only documented dynamic ones.

---

## STEP 4 — Board chrome (C4 + C5 + C2)
- [ ] 4.1 Lane "+": **no background**, glyph only, lane-coloured, ~70% transparent.
- [ ] 4.2 Filter bar: clear-control into the right-hand group; bar height constant whether or
      not a filter is active.
- [ ] 4.3 `SetsBoard`: 11 raw Tailwind palette classes → semantic tokens.
- **Verify:** tsc zero new; grep `SetsBoard` for palette literals → 0.

---

## STEP 5 — Card editor typography (C9)
- [ ] 5.1 Read the Writer canvas's actual paragraph rule first.
- [ ] 5.2 Make the card editor consume the SAME source — no second size invented.
- **Verify:** the two selectors resolve to one declaration; tsc zero new; build.

---

## CLOSING VERIFY (every step)
- [ ] V1 `php -l` each touched PHP file
- [ ] V2 `tests/standalone/run.php` 88/88
- [ ] V3 `npm run check` — 59 pre-existing, ZERO new
- [ ] V4 `npm run build` ("built in" prints)
- [ ] V5 changelog line · AFTER commit ending **LOCAL ONLY** · never push

## CARRIED (not in this round, not forgotten)
- Document edits not persisted after save (gap `270356c`).
- The dead "View client feedback" chip (`approvals/service.php:69`).
- Code-splitting the 5.28 MB bundle.
