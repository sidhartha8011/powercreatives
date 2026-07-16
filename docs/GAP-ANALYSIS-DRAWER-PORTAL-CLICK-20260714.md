# GAP ANALYSIS — clicking inside the drawer closes the modal — 2026-07-14

**Owner live report:** clicking "inside a keyword" in the drawer closes the
whole editor.

## Facts (verified)
| # | Fact |
|---|---|
| X1 | The editor's outside-click flow saves+closes on ANY pointerdown whose target is outside `rootRef` (the card) and `drawerRef` (the drawer) — with exactly one other exception already: sonner toasts (`[data-sonner-toaster]`), because toasts render in a PORTAL outside the card |
| X2 | The drawer V2 introduced Radix dropdown menus (the Role tag menu; the column filter funnels use them too) — and `DropdownMenuContent` renders through a PORTAL to document.body (ui/dropdown-menu.tsx:38-48). In the DOM those menus are OUTSIDE both refs: clicking a menu item is, to the handler, a click outside the editor → save + close. The toast exception (X1) is the exact precedent for the fix |
| X3 | Radix mounts every portaled popper inside `[data-radix-popper-content-wrapper]` — ONE selector exempts the role menu, every filter menu, and any future Radix popper, permanently |

## The fix (one line, the proven pattern)
The outside-click handler gains the same exception toasts already have:
`if (t.closest('[data-radix-popper-content-wrapper]')) return;` — portaled
menus are INSIDE the editor by intent, now also by law.

## CHECKLIST
- [x] This doc committed
- [ ] The one-line exemption in the pointerdown handler
- [ ] Verify: tsc 59 · build · changelog · commit LOCAL ONLY
