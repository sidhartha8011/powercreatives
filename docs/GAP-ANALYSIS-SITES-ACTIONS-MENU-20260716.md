# GAP ANALYSIS — SITES ACTIONS → THREE-DOTS MENU (owner GO 2026-07-16)

Owner: the Sites table's per-site action buttons collapse into ONE
three-dots (kebab) menu holding all the site's actions.

## FACTS (live, HEAD 6e35d3c)
1. The actions column (Sites/index.tsx:378-397) renders FOUR inline
   buttons: Test (spinner while testingId matches), GSC (disabled while
   its preview is pending for that site), Auto (schedule dialog),
   Delete (ghost, destructive hover). All handlers are row-scoped.
2. THE shared menu already exists: @/components/ui/dropdown-menu
   (DropdownMenu/Trigger/Content/Item…, used by SEO bulk actions) —
   the shared-components law forbids a bespoke popover.
3. MoreHorizontal is the app's established kebab icon (lucide, used by
   ui/breadcrumb) — no new icon language.

## DESIGN
- ONE ghost icon button (MoreHorizontal, h-7) replaces the four buttons;
  DropdownMenu lists: Test connection · Verify in GSC · Auto-content
  schedule · (separator) · Delete site (destructive style).
- Same handlers, same disabled states; the TRIGGER shows the spinner
  while that row's test runs (the in-flight state stays visible with
  the menu closed). Labels become full words (menu has room — no more
  cryptic "GSC"/"Auto"); title-attr hints move to the items.
- Column narrows (~6%); zero handler/logic changes; every other column
  untouched.

## CHECKLIST
[ ] kebab + DropdownMenu items (same handlers/disabled/spinner) ·
[ ] imports (MoreHorizontal, dropdown-menu) · [ ] tsc 59/0 · [ ] build ·
[ ] changelog · [ ] AFTER commit LOCAL ONLY · [ ] owner: menu opens, all
four actions work, spinner visible while testing
