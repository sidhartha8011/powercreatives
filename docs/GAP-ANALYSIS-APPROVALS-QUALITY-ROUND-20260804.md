# GAP ANALYSIS — the quality round: inline styling debt, the share popover, the 15s share, and four UI defects
**Date:** 2026-08-04 · **Owner order + standing quality bar:** nothing inline, nothing
hand-rolled, no shortcuts; anything short of senior work goes back to the backlog.

Everything below is verified in today's tree or measured on the live DB. Where I do **not**
know the cause I say so and name the measurement instead of guessing — see §5.

**Two hypotheses of mine died during this research and are recorded so nobody re-runs them:**
- *"The seeded automation rules are duplicated."* **False.** 14 rows = 7 seeded rules × 2
  users (userId 1 and 3). Not a defect.
- *"Generate Share Link blocks on a Brevo HTTPS call."* **False.** The channel returns early
  when no API key is configured (`class-pcm-brevo-email-channel.php`, `if ($api_key === '')`),
  and this box has **no brevo integration row at all** — so no outbound call happens.

---

## 1. INLINE-STYLING DEBT — the audit, file by file

Counted mechanically across everything I created or touched this session:

| File | `style={{ }}` | raw hex | raw Tailwind palette |
|---|---|---|---|
| `components/shared/ApprovalSharePanel.tsx` | **14** | 0 | 0 |
| `modules/Approvals/kanban/SetsBoard.tsx` | 0 | 0 | **11** |
| `components/shared/SearchableSelect.tsx` | 1 | 0 | 0 |
| `components/shared/Kanban/KanbanBoard.tsx` | 1 | 0 | 0 |
| `components/shared/ProjectPicker.tsx` | 0 | 0 | 0 |
| `modules/Approvals/components/CreateCustomSetDialog.tsx` | 0 | 0 | 0 |

**G1.1 — `ApprovalSharePanel` is the worst offender: 14 inline `style={{}}` blocks.** They
pass design tokens (`colors.bgPage`, `typography.xs`), so no raw hex — but CLAUDE.md UI
RULE 1 is explicit: *"no one-off `style={{ … }}` for colors/spacing/sizing"*. I inherited
this by extracting the panel verbatim from the old dialog and then **added more of it**.
Extraction is not an excuse: the rule applies to the file as it now stands.

**G1.2 — `SetsBoard` uses 11 raw Tailwind palette classes** (`bg-blue-50`,
`border-blue-200`, `text-blue-900`, `bg-slate-50/50`, `border-slate-100`, `text-slate-500`,
`text-slate-600`, `text-slate-700/900`) instead of the semantic tokens the rule names
(`bg-background`, `bg-card`, `bg-muted`, `text-muted-foreground`, `border-border`). A
palette literal is a hardcoded colour with extra steps — it cannot follow a theme.

**G1.3 — the two remaining `style={{}}` are legitimate and stay, with a reason:**
`SearchableSelect` sizes the popover to its trigger's measured width (a runtime value, not a
design decision), and `KanbanBoard` publishes the column's accent as CSS custom properties so
the *stylesheet* can colour per lane. Both are dynamic values that cannot live in a class.

---

## 2. THE LANE "+" — still a filled chip

| # | Fact | Where |
|---|---|---|
| G2.1 | The button paints a filled background — `background: var(--pck-column-accent-bg, …)` — so it reads as a solid chip, not a glyph. | `Kanban/kanban.module.css` `.columnCreate` |
| G2.2 | Its resting opacity is `0.75`. The owner wants it quieter — the plus alone, around 70% transparent, present but not shouting. | same |

---

## 3. THE FILTER BAR CHANGES HEIGHT

| # | Fact | Where |
|---|---|---|
| G3.1 | The bar is `flex flex-wrap`. "Clear filters" is rendered **conditionally** (`{hasActiveFilter && …}`) *in the middle of the row*, between the dropdowns and the right-hand group. | `SetsBoard.tsx` |
| G3.2 | So selecting a filter inserts a new element mid-row; at narrow widths the row wraps to a second line and the board below is pushed down. Height is not reserved. | derived from G3.1 |
| **Fix** | Move it into the right-hand group on the same row, so the bar's height never depends on filter state. | — |

---

## 4. THE × IN THE DROPDOWN CANNOT BE CLICKED — root cause found

| # | Fact | Where |
|---|---|---|
| G4.1 | The clear "×" is rendered **inside** the `<Button>` that is the Radix `PopoverTrigger` (`asChild`). | `SearchableSelect.tsx` · same shape in `ui/creatable-combobox.tsx` |
| G4.2 | Radix's trigger toggles the popover on **pointerdown**. The ×'s handler is an **onClick**, which fires after — so the popover has already opened, and `e.stopPropagation()` on the click is too late to prevent it. The selection does clear, then the list opens over it, so it reads as "the × does nothing". | Radix Popover behaviour + G4.1 |
| **Fix** | Take the × **out of the trigger** — render it as a sibling control beside the trigger, or intercept `onPointerDown` and stop it there. A sibling is the honest structure: a clear button is not part of "open the list". | — |

⚠ The same defect exists in `ui/creatable-combobox.tsx` (the Project field), which was
already in the codebase — the Delivery/Brand selects are mine.

---

## 5. GENERATE SHARE LINK TAKES ~15s — **NOT root-caused yet**

What I measured and **ruled out**:

| Ruled out | Evidence |
|---|---|
| The Brevo email blocking the request | Channel returns early with no API key; **no brevo integration row exists** on this box. `timeout` would have been 20s, which is why it was the leading suspect — it simply never runs. |
| Webhook rules blocking | The two seeded webhook rules have an **empty URL** → the channel reports `skipped` without a call. |
| Duplicated automation rules | 7 rules × 2 users, not duplicates. |
| `build_share_url`'s `LIKE '%[power_creatives]%'` full scan | Real (leading wildcard, no index possible) and called up to 8× per share — but **1.40 ms/call** on 217 posts. ~11 ms total. Not it. |
| The projects query | 0.78–1.14 ms/call. Not it. |

**Still open, and each is measurable, not guessable:**
- **W1** `pm.max_children = 2` on this box (HANDOVER-20260717 §4, the standing bottleneck).
  "Generate Share Link" can issue **two sequential requests** (create the project, then the
  set) while the SPA also refetches `listSets` + three registries — with two workers those
  queue behind each other.
- **W2** `PCM_Automation_Engine::fire_trigger` selects rules with
  `a.userId = %d OR u.role = 'admin'` (`automations/service.php:446-451`) — an admin's rules
  run for **other users'** triggers too, so the fan-out is larger than "my rules".
- **W3** `update_status()` (fired by the move-to-lane rule) re-runs `enrich_context()`, which
  itself re-runs `build_share_url()` and several joins — the whole chain repeats per rule.

**Measurement plan (the project's own protocol, not a guess):** a temporary secret-keyed
mu-plugin that `rest_do_request`s the real create route in **web** context and logs elapsed
ms per stage (create_set / share_set / dispatch / fire_trigger / update_status), then delete
the probe and verify it 400s. **No fix is proposed until that output exists.**

---

## 6. THE SHARE STEP IS INLINE, NOT A POPOVER — my defect, named

| # | Fact | Where |
|---|---|---|
| G6.1 | `ApprovalSharePanel` renders in the dialog's scrolling body (`{created && shareOpen && <ApprovalSharePanel …/>}`) — it expands the document flow instead of floating. | `CreateCustomSetDialog.tsx` |
| G6.2 | The spec was a Google-style share **window** anchored to the button. I flagged it as inline in my own summary and shipped it anyway. | owner spec, four messages |
| **Fix** | Rebuild on the shared Radix `Popover` (already a dependency, used by `SearchableSelect` and `CreatableCombobox`), anchored to the Generate Share Link button, with the panel as its content. | — |

---

## 7. "MESSAGE TO CLIENT" IS UNREADABLE

| # | Fact | Where |
|---|---|---|
| G7.1 | The `Textarea` gets `style={{ background: colors.bgSurface }}` while sitting on a panel already tinted `colors.bgPage` — two near-identical greys, so the field has no edge and the text has almost no contrast against it. | `ApprovalSharePanel.tsx` |
| G7.2 | This is also a RULE 1 violation (no transparent/implicit surfaces — a control must never inherit whatever is behind it). | CLAUDE.md UI RULE 1 |
| **Fix** | An explicit card surface (`bg-card`) via class, like every other input, not an inline token. | — |

---

## 8. THE CARD EDITOR'S TEXT IS TOO BIG

| # | Fact | Where |
|---|---|---|
| G8.1 | The editor's typography comes from a `pcm-card-editor` class rather than the Writer's paragraph scale; the comment in `CustomCardEditor` records that Tailwind `prose` was inert here (the typography plugin is not loaded) and a bespoke class was used instead. | `CustomCardEditor.tsx` `editorProps.attributes.class` |
| **To verify before changing** | Read the Writer canvas's actual paragraph rule and make the card editor consume the SAME source, rather than inventing a second size. | `modules/Writer/**` + `index.css` |

---

## 9. FACTUAL CHECKLIST

**Styling debt (the standing bar)**
- [ ] C1 `ApprovalSharePanel`: all 14 `style={{}}` → classes/tokens. No inline colour, spacing or size.
- [ ] C2 `SetsBoard`: 11 palette literals → semantic tokens (`bg-card`, `bg-muted`, `text-muted-foreground`, `border-border`).
- [ ] C3 Leave the 2 dynamic `style={{}}` (measured width, per-lane CSS vars) and document why, so a later reader doesn't "fix" them.

**Defects**
- [ ] C4 Lane "+": glyph only, no filled background, ~70% transparent, lane-coloured.
- [ ] C5 Filter bar: clear-control into the right-hand group; height constant regardless of filter state.
- [ ] C6 The × leaves the trigger (sibling control or `onPointerDown` interception) in **both** `SearchableSelect` and `ui/creatable-combobox`.
- [ ] C7 The share step becomes a real Radix `Popover` anchored to its button.
- [ ] C8 "Message to client" gets an explicit `bg-card` surface.
- [ ] C9 Card editor adopts the Writer's paragraph scale from one shared source.

**The 15s**
- [ ] C10 Instrument first (mu-plugin, web context, per-stage ms), report the numbers, **then** propose the fix. Delete the probe and verify it 400s.

**Verify**
- [ ] V1 `php -l` each touched PHP file · harness 88/88 · tsc 59 pre-existing ZERO new · build · changelog · AFTER commit **LOCAL ONLY**. Never push.

## 10. PLAN
1. C10 — measure the 15s; it is the only item whose fix is unknown.
2. C6 — the × (a broken control the user hits constantly).
3. C7 + C8 + C1 — rebuild the share step as a popover, styled from tokens, in one pass.
4. C4 + C5 + C2 — board chrome.
5. C9 — editor typography, after reading the Writer's rule.

## 11. CARRIED TO BACKLOG (open, not silently dropped)
- Document edits are not persisted after save (gap 270356c) — still open.
- The dead "View client feedback" chip (`approvals/service.php:69`).
- Code-splitting the 5.28 MB bundle.
- `ui/creatable-combobox`'s × shares defect G4.1 — pre-existing, fixed here because the
  Project field is part of this surface.
