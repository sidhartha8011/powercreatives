# TemplateDialog Inline-Form Refactor — Gap Analysis

## Current State
1. **Name** input at top
2. **3 dropdowns** (Module, Type, Category) in a row
3. **"+ Add Entry" button** — creates an EMPTY row with inherited category
4. **Entry rows** — each has: category badge + label input + collapsible value textarea + delete
5. User flow: click Add → empty row appears → fill in label → expand → fill in value

## Desired State
1. **3 dropdowns** at top (Module, Type, Category) — same as now
2. **Label input** — always visible below dropdowns
3. **Value textarea** — always visible below label
4. **"Add" button** — takes dropdown values + label + value → pushes complete entry to list → clears label+value → dropdowns stay
5. **Entry list** below — shows completed entries with badge + label + value (editable)
6. **Template Name** — separate field (at bottom near Create button, or at top)

## Gap (what changes)

| # | Area | Current | Desired | Change |
|---|------|---------|---------|--------|
| 1 | Input area | Empty row added on click | Always-visible label+value fields | Add inline label+value inputs above entry list |
| 2 | Add button | Creates empty row | Pushes populated entry to list | handleAddEntry reads label+value state, creates complete entry, clears inputs |
| 3 | Entry rows | Editable label+value | Still editable (for corrections) | No change needed |
| 4 | Dropdowns | Retain state | Retain state | No change needed |
| 5 | Module cascade | Resets type | Resets type+category | Minor: also reset category on module change |
| 6 | Empty state | "No entries yet" message | Same but with inline form always visible above | Adjust empty state text |

## Files to change
- `TemplateDialog.tsx` — the only file that needs changes (pure UI refactor)

## No changes needed
- Backend (tRPC, schema, db helpers)
- shared/templateTypes.ts
- Templates/index.tsx (table view)
- Tests (they test data structure, not dialog UI)
