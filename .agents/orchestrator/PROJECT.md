# Project: PowerCreatives Unified Ads Module Integration

## Architecture
Integrate copywriting and image generation capabilities under a unified, presentational Ads Module frontend structure, strictly confined within `app/src/modules/Ads/`, utilizing custom React hook business logic and tRPC endpoints.

- Backend modules root: `includes/modules/`
- Frontend code root: `app/src/`
- Agent metadata: `.agents/`

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|---|---|---|---|
| 1 | Exploration & Planning | Discover files, check Image/Copy module patterns, write comprehensive plan | None | DONE |
| 2 | Presentational Sidebar Controls | Expose "AI Enhance" toggle and "Angles (Scenes)" slider in AdsSidebar.tsx, declare states in index.tsx | M1 | DONE |
| 3 | Generation Pipeline Integration | Update useAdsOrchestration.ts with optimizeBrief, generateConcepts mutations and concurrent generation logic | M2 | DONE |
| 4 | Grid & Share Dialog Verification | Ensure visual and copy cards can be packaged cohesively via CreateApprovalSetDialog.tsx under a single shareable link | M3 | DONE |
| 5 | Validation & Review | Perform a full build, run reviews, challenger testing, and forensic audit checks | M4 | IN_PROGRESS |

## Interface Contracts
- **`AdsSidebarProps`**:
  - `autoOptimizeBrief: boolean`
  - `onAutoOptimizeBriefChange: (v: boolean) => void`
  - `numVersions: number`
  - `onNumVersionsChange: (n: number) => void`
- **`AdsGenerateParams`**:
  - `autoOptimizeBrief: boolean`
  - `numVersions: number`
