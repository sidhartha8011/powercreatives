# Approvals card typography validation — 2026-08-06

## Functional and visual checklist

| Check | Before | Cause | After implementation | Regression |
|---|---|---|---|---|
| Opened document title has clear Notion-like hierarchy | Failed: intended 40px/700 rendered at 15.75px/600 | `#pcm-root` heading reset outranked the modal class | Passed live: 40px, 48px line-height, weight 700, document ink | Passed in client portal and internal preview |
| Edit metadata is readable but quiet | Failed: intended 12px rendered at 8.05px and weight 300 | `#pcm-root` paragraph reset outranked the modal class | Passed live: 12px, 16.8px line-height, weight 400, faint document ink | Passed in client portal and internal preview |
| Property rows use one deliberate supporting scale | Failed: 14px size survived, but weight drifted to 300 | Document surface did not own its base weight | Passed live: 14px, 21px line-height, weight 400 | Passed |
| Body copy and controls use the same font family and color system as card chrome | Failed: body used Inter while chrome used the system stack; body inherited weight 300; controls were forced to Inter by an important declaration in the base cascade layer | Font and weight were not part of the shared document contract, and the sheet had no override in the owning cascade layer | Passed live: system stack throughout; body 16px/24px/400 in `#37352f`; approval action 13.5px/600 | Passed in the body-portalled client view and admin preview |
| Checklist boxes match the 16px body rhythm | Partial: geometry aligned, but the border was light and corners too rounded | Checkbox appearance used local literals instead of document tokens | Passed live: 16px square, 2px radius, tokenized warm border, centred in the 24px line box | Passed across all six persisted task items |
| Approval and save-state controls remain legible | Failed for approval size: intended 13.5px rendered at 11.5px | Admin button reset outranked the component class | Passed live: approval action 13.5px/600 on the shared document font; save state uses the 12px metadata tier | Passed |
| Selection popover and editing behavior remain unchanged | Passed | Existing Tiptap/Floating UI implementation | Passed live: selecting document text opens exactly one popover | Passed; modal width remains 820px |

## Architecture checklist

| Check | Result |
|---|---|
| One source of truth for document font, weights, ink hierarchy, semantic sizes, and checkbox geometry | Implemented in the existing `--pcm-doc-*` contract |
| Approvals-only scope; no project, delivery, brand, or persistence changes | Passed |
| Same styles work inside `#pcm-root` and in body-portalled documents | Implemented with the existing paired-selector pattern |
| No duplicated renderer, editor extension, or save path | Passed |
| No component file approaches the modularity threshold | Passed |
| No shortcuts or compensating transforms | Passed |
