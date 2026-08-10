# PLAN — Approvals quality round (execution checklist)
**Source gap:** `GAP-ANALYSIS-APPROVALS-QUALITY-ROUND-20260804.md` (commit `bebda90`).
**Rule for this list:** a box is ticked only when the change is written AND its verify line
passed. Nothing is ticked on intent.

Ordered so the unknown is measured first and the user-facing breakage is fixed before polish.

---

## STEP 1 — Measure the share-link time (C10) · the only item whose fix is unknown
- [x] 1.1 Instrumented the real path in **web** context (secret-keyed probe over HTTP; the
      CLI route was abandoned — `wp-load` there cannot reach the DB, which is also what hung
      the first attempt for 3 minutes).
- [x] 1.2 Numbers recorded below.
- [x] 1.3 Probe deleted; re-requested and confirmed **HTTP 404**.

**MEASURED RESULT — the server is not the problem.**

```
wp-load boot                  436.8 ms
create_set                     17.9 ms
build_share_url x1              3.4 ms
get_set_by_id + chain           2.2 ms
share_set FULL                 81.8 ms   <== the whole server-side share chain
update_status                  18.8 ms
```

**Total server work for the button: ~120 ms** (plus one ~440 ms WP boot per REST request).
Not 15 s, not 30 s — off by more than two orders of magnitude.

**Payload also ruled out.** The stored snapshots for every existing set are **262–885 bytes**
with **no base64 images**, so the request body is under 1 KB. The "the card embeds
multi-megabyte data-URL images" theory is dead for these cards.

**Therefore the time is spent in the browser or in transport, not in PHP.** What remains,
and none of it is guessable from here:
- The button issues up to **two sequential REST calls** (create the project, then the set),
  each paying its own ~440 ms WP boot.
- `pm.max_children = 2` on this box — concurrent SPA requests (listSets + three registries +
  the create + the post-create invalidate refetch) queue behind two workers.
- Avast is documented in HANDOVER-20260717 §4 as interfering with local HTTP on this machine.

**BLOCKED — needs one artifact I cannot produce:** the **DevTools → Network** timing for the
Generate Share Link click (which request, and its Waiting vs Content-Download split). That
single screenshot decides between "queued behind workers", "one slow request", and "the
browser was still fetching the bundle". No fix is designed before it exists.

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
