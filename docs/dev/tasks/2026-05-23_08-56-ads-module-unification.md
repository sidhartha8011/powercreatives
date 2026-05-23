# PowerCreatives — Ads Module Unification Planning

## Goal
Integrate copywriting and image generation capabilities under a unified, presentational Ads Module frontend structure, strictly confined within `app/src/modules/Ads/`, utilizing custom React hook business logic and tRPC endpoints.

---

## Requirements

### R1. Dumb UI & Hook-Based Logic Separation
- [ ] Ensure all business logic, tRPC mutations, and pipeline orchestration live strictly in `useAdsOrchestration.ts`.
- [ ] Make `AdsSidebar.tsx`, `AdsResultsGrid.tsx`, and `index.tsx` pure, dumb, presentational components/contexts that only receive state and callbacks as props.
- [ ] No direct API requests or tRPC mutations inside sidebar or grid components.

### R2. AI Enhance & Slider UI Integration
- [ ] Expose "AI Enhance" (Brief Optimization) toggle in `AdsSidebar.tsx` using the same styling, layout, Radix Switch, and Wand2/Sparkles icons/tooltips as `ImageSidebar.tsx`.
- [ ] Expose "Angles (Scenes)" slider in `AdsSidebar.tsx` (range 1-8) with same styling, input element, Dices icon, and numeric indicator as `ImageSidebar.tsx`.
- [ ] Manage `autoOptimizeBrief` and `numVersions` states in `index.tsx` and pass them and their callbacks to `AdsSidebar.tsx`.

### R3. Ads Image Phase Alignment (Hook Logic)
- [ ] Update `useAdsOrchestration.ts` to include tRPC mutations `trpc.image.optimizeBrief` and `trpc.image.generateConcepts`.
- [ ] In `useAdsOrchestration.ts`, call `optimizeBrief` mutation if "AI Enhance" is active during image generation phase.
- [ ] Call `generateConcepts` mutation if `numVersions` > 1 to generate $N-1$ creative variations/scenes.
- [ ] Run parallel image generation queries using the optimized concepts/prompts and selected image models in `runImagePhase`.

### R4. Cohesive Share & Approval Set Selection
- [ ] Retain decoupled grid display in `AdsResultsGrid.tsx` for visuals and copy cards.
- [ ] Verify that selected visuals and copy cards can be packaged cohesively using `CreateApprovalSetDialog.tsx` under a single public sharing link.

---

## Impact Analysis & Changed Files

All modifications are strictly confined to the `app/src/modules/Ads/` directory.

1. **`app/src/modules/Ads/index.tsx`**:
   - Add state declarations for `autoOptimizeBrief` and `numVersions`.
   - Pass states/callbacks as props to `AdsSidebar`.
   - Pass `autoOptimizeBrief` and `numVersions` to `orchestration.generate(...)`.
   
2. **`app/src/modules/Ads/components/AdsSidebar.tsx`**:
   - Add `autoOptimizeBrief`, `onAutoOptimizeBriefChange`, `numVersions`, `onNumVersionsChange` to props interface.
   - Implement the "AI Enhance" toggle in the Creative Brief section.
   - Implement the "Angles (Scenes)" slider below the Creative Brief section.
   
3. **`app/src/modules/Ads/hooks/useAdsOrchestration.ts`**:
   - Update `AdsGenerateParams` type signature.
   - Declare mutations for `optimizeBrief` and `generateConcepts`.
   - Implement the brief optimization and concept generation steps at the start of `runImagePhase` or before launching it.
   - Loop over the generated concepts/versions to invoke parallel image generations for all selected models and variations.

---

## Risks and Mitigation
- **Rate Limiting**: Generating multiple concepts/versions with multiple models in parallel could hit backend LLM or image provider rate limits.
  - *Mitigation*: Respect sequential variation calls per model, and execute model queries using `Promise.allSettled` to prevent catastrophic failure if one fails.
- **UI Overflow**: Adding two new settings in the sidebar could clutter the panel.
  - *Mitigation*: Match the exact space-efficient styling of `ImageSidebar.tsx` using Radix Switch and modern range inputs.

---

## Verification Plan

### Automated / Manual Verification:
1. Verify build and compilation success of the plugin React frontend.
2. Manually test that selecting "AI Enhance" calls `optimizeBrief` on the backend.
3. Manually test that setting "Angles (Scenes)" slider to >1 generates distinct concepts and schedules parallel image queries correctly.
4. Select visual assets and copy cards and verify that the "Share with Client" dialog generates a valid sharing token and public board link.

---

## Subtasks

- [ ] Add states to `AdsModule` in `app/src/modules/Ads/index.tsx`
- [ ] Integrate settings props in `AdsSidebarProps` (`AdsSidebar.tsx`)
- [ ] Render "AI Enhance" toggle and "Angles (Scenes)" slider with Tooltips/Icons in `AdsSidebar.tsx`
- [ ] Add mutations & resolve generation parameters in `useAdsOrchestration.ts`
- [ ] Update `runImagePhase` to orchestrate `optimizeBrief` and `generateConcepts` sequentially and spawn image variants
- [ ] Verify build and functionality of Ads module integration
