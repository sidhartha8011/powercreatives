# GAP ANALYSIS — the lane "+", select-on-hover, empty dropdowns, and the load time
**Date:** 2026-08-04 · **Owner report + GO** (screenshot: Delivery dropdown open showing
"No deliveries match"; only the hovered lane shows a "+").

Four defects. Three are mine, from this session. Each is proven below at file:line or with
a live probe — none is inferred.

---

## A. The "+" — only one lane appears to have it, and it is not our design

| # | Fact | Where |
|---|---|---|
| A1 | `.columnCreate { opacity: 0 }` with `.column:hover .columnCreate { opacity: 1 }` — the button is **invisible except on the hovered lane**. The screenshot is the CSS working as written: Draft is hovered, so only Draft shows it. | `Kanban/kanban.module.css:92-112` |
| A2 | The icon is a **hand-rolled inline `<svg>`** with a literal path, not the lucide `Plus` every other control in this codebase uses. This is the "inline coding crap". | `Kanban/KanbanBoard.tsx:224-231` |
| A3 | Its colour is `var(--pck-pill-text)` (#4a4a45) on transparent — a single neutral for all six lanes, ignoring each lane's own `accentColor`/`accentText`, so it reads as foreign to the lane it sits in. | `kanban.module.css:92-116` · `setColumns.ts` |

**Verdict:** the "+" is on every lane; it is hidden by my own hover rule and drawn with a
one-off SVG in a one-off colour. All three are my defects.

---

## B. Selection is offered on hover, before the user asked for it

| # | Fact | Where |
|---|---|---|
| B1 | Hovering any card reveals the multi-select checkbox and shifts the title 22px to make room. | `Approvals/kanban/setCard.module.css:47-53, 61+` |
| B2 | There is **no Select-mode control anywhere**. Select-mode is entered *implicitly* — the board derives it from "is anything selected" (`selectMode={hasSelection}`), so the only way in is to click a checkbox that only exists on hover. | `kanban/SetsBoard.tsx` · `hooks/useApprovalSets.ts` |

**Owner's ask:** selection must be a deliberate mode entered from a control in the toolbar,
not the first thing hovering a card offers.

---

## C. The dropdowns find nothing — PROVEN, and it is my design decision that causes it

| # | Fact | Where |
|---|---|---|
| C1 | `linkOptions()` builds each dropdown from **only the ids present on the board**, deliberately ("no dead options"). | `kanban/SetsBoard.tsx` |
| C2 | Live probe — the board's only set resolves to nothing: `set #11 "test" → projectId 2 ("Donkey") → project.deliveryId = NULL → delivery.brandId = NULL`. Stored brand/delivery are NULL too. | hub DB 10017 |
| C3 | So Delivery = **0 options** (the screenshot's "No deliveries match"), Brand = 0, Project = 1. | derived from C1+C2 |
| C4 | The registries actually contain **2 brands, 3 deliveries, 6 projects**. The data exists; the narrowing hides it. | hub DB 10017 |

**Verdict:** not a search bug — the search box has nothing to search. My "narrow to what is
on the board" rule is correct for a full board and useless for a sparse one, and it
contradicts what a filter is for. **Offer the full registry**; a choice that matches nothing
already has an honest answer — the board's existing "No sets match your filters" notice.

---

## D. Slow to load — measured, and the dominant cause is one line

| # | Fact | Where |
|---|---|---|
| D1 | **`ver = time()` on a 5,280,335-byte script.** The version changes on every request, so the browser can never cache it: **5.28 MB is re-downloaded on every single admin page load.** The comment says "Cache-bust on every page load for dev testing". The stylesheet immediately above it correctly uses `filemtime`. | `includes/class-pcm-admin.php:125-131` |
| D2 | The board fires **four** queries on mount — `listSets`, `deliveries.list`, `brands.list`, `assets.getProjects` — and the first paint waits on them. Opening the card dialog re-requests the same three registries (deduped by TanStack within the 30s staleTime, so cached, but only after the board has already paid for them). | `SetsBoard.tsx` · `ProjectPicker.useProjectPickerData` |
| D3 | `assets.getProjects` is the heaviest and the board needs **names only**: it runs `SET SESSION group_concat_max_len`, a `GROUP_CONCAT` of every asset URL (190 rows / 19,264 bytes today) plus a second COUNT over `copy_results` — **ungated**. An `includeThumbnails` param exists but the aggregation runs regardless of it. | `assets/controller.php:384-420` |

**Verdict:** D1 alone accounts for a multi-second load on every visit and is a one-word fix
already flagged as a perf item in HANDOVER-20260717 §4. D3 makes the board pay for asset
aggregation it never reads.

---

## E. FACTUAL CHECKLIST

**A — the "+"**
- [ ] A-1 Replace the inline `<svg>` with the shared lucide `Plus`.
- [ ] A-2 Always visible on every lane (drop the hover gate); hover/focus changes emphasis only.
- [ ] A-3 Colour from the lane's own `accentText`/`accentColor`, so it belongs to its lane.
      No inline styles for colour or size.

**B — Select mode**
- [ ] B-1 Remove the hover reveal of the checkbox and the hover title shift.
- [ ] B-2 Add a **Select** toggle to the board toolbar (right side, beside Sort).
- [ ] B-3 Checkboxes render only in select mode; leaving select mode clears the selection.

**C — the dropdowns**
- [ ] C-1 Options come from the full registries (brands / deliveries / projects), not from
      ids present on the board.
- [ ] C-2 A choice that matches nothing falls through to the existing "No sets match your
      filters" state — no silent empty board.

**D — load time**
- [ ] D-1 `time()` → `filemtime()` on the script enqueue (matching the CSS beside it).
- [ ] D-2 `get_projects` gains a light mode that returns id/name/deliveryId and **skips** the
      asset + copy aggregation; the board and the pickers use it.
- [ ] D-3 Re-measure after D-1/D-2 and record the numbers, not an impression.

**Verify**
- [ ] V1 `php -l` each touched PHP file · harness 88/88 · tsc 59 pre-existing ZERO new ·
      build · changelog · AFTER commit **LOCAL ONLY**. Never push.

## F. PLAN
1. D-1 first — one line, largest win, independently testable.
2. C-1/C-2 — the dropdowns become useful.
3. A-1..A-3 — the "+" as a proper shared control.
4. B-1..B-3 — Select mode.
5. D-2 — the light projects payload, then re-measure.

## G. OUT OF SCOPE (named)
- Code-splitting the 5.28 MB bundle (real, separate, and much larger than this round).
- Persisting document edits after save (still open from gap 270356c).
- The dead "View client feedback" chip (`approvals/service.php:69`).
