# Approval editor validation — 2026-08-06

## Functional and UX checklist

| Check | Result | Evidence |
|---|---|---|
| Checklist text uses the document scale | Pass | Live computed style: 16px font, 24px line-height. |
| Checkbox aligns to the first text line | Pass | Live geometry: 16px control centered in the same 24px label/text line box. |
| WordPress does not draw a second checkmark | Pass | Live `::before`: `content: none`, `display: none`. |
| Selection opens formatting controls | Pass | Live text selection produced one Tiptap BubbleMenu with the full existing command set. |
| Popover remains usable in the bounded card | Pass | 420px maximum width, two-row wrapping, centered with 86px side clearance in the 606px test viewport. |
| Popover is not clipped by document scrolling | Pass | Floating wrapper is hosted by `.pcm-notion-overlay`, outside `.pcm-notion-modal`'s scroll box. |
| Formatting command changes selected text | Pass | Bold command produced three `<strong>` nodes for the selected document. |
| Undo restores the document | Pass | Immediate undo removed all three nodes; reopen confirmed zero `<strong>` nodes. |
| Restored content finishes saving | Pass | Saving indicator returned to idle before reopen. |
| Existing empty checklist rows | Existing data | Three empty persisted task-item nodes remain intentionally untouched by this presentation change. |

## Architecture checklist

| Check | Result | Evidence |
|---|---|---|
| Tiptap schema and saved-content format unchanged | Pass | Existing `TaskList`/`TaskItem` extensions and HTML remain unchanged. |
| One formatting implementation | Pass | Approval reuses `WriterBubbleMenu`; no duplicate formatting commands were introduced. |
| Approval-specific modal knowledge stays in Approvals | Pass | `ApprovalSelectionMenu` owns overlay and scroll-target resolution. |
| Writer behavior unchanged | Pass | Compact mode and positioning props are opt-in; Writer continues using defaults. |
| Checklist CSS is isolated | Pass | Every task-list rule is scoped to `.pcm-card-editor`. |
| Host CSS leakage neutralized locally | Pass | Only card-editor checkboxes suppress the WordPress pseudo-element. |
| Build | Pass | Production Vite build completed successfully. |
| Repository-wide TypeScript baseline | Pre-existing failures | `npm run check` still fails in unrelated modules and missing dependencies; no reported error references a changed file. |

No API, database, approval workflow, public read-only behavior, card dimensions, or save contract was changed.
