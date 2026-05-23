# Handoff Report — explorer_ads_exploration

## 1. Observation
We successfully examined all key files in both the Ads Module and the Image Module:
* `app/src/modules/Ads/index.tsx` (317 lines)
* `app/src/modules/Ads/components/AdsSidebar.tsx` (328 lines)
* `app/src/modules/Ads/components/AdsResultsGrid.tsx` (392 lines)
* `app/src/modules/Ads/hooks/useAdsOrchestration.ts` (434 lines)
* `app/src/modules/Ads/components/CreateApprovalSetDialog.tsx` (265 lines)
* `app/src/modules/Image/components/ImageSidebar.tsx` (447 lines)
* `app/src/modules/Image/hooks/useImageGeneration.ts` (559 lines)
* `app/src/lib/trpc.ts` (737 lines)
* `includes/modules/image/controller.php` (481 lines)
* `includes/modules/copy/controller.php` (740 lines)

Key structural patterns observed in `ImageSidebar.tsx`:
* Line 225: Sparkles icon label next to custom tooltips and Radix Switch:
  ```tsx
  <Switch id="auto-optimize" checked={autoOptimizeBrief} onCheckedChange={(checked) => gen.setAutoOptimizeBrief(checked)} />
  ```
* Line 412: Range slider for Angles (Scenes):
  ```tsx
  <input type="range" min="1" max="8" value={numVersions} onChange={(e) => gen.setNumVersions(parseInt(e.target.value) || PRODUCTION_DEFAULTS.numVersions)} ... />
  ```

Key paths verified in `trpc.ts`:
* Line 429: `"image.suggestConcepts": { endpoint: "image/concepts", method: "POST" }`
* Line 439: `"image.optimizeBrief": { endpoint: "image/optimize-brief", method: "POST" }`
* Line 539: `"approvals.createSet": { endpoint: "approvals/sets", method: "POST" }`

In `useAdsOrchestration.ts`:
* The generation hook `useAdsOrchestration` is responsible for conducting copy generation via a raw SSE stream from `/copy/generate` (REST endpoint) and concurrent visual generations per image model using tRPC calls:
  ```typescript
  const result = await generateImageMutation.mutateAsync({
    prompt: brief,
    model: modelId,
    provider: resolvedProvider,
    brandContext: brandCtx,
    ...(refUrls.length > 0 ? { inputUrls: refUrls, referenceImageIntents: refIntents } : {}),
  });
  ```

In `CreateApprovalSetDialog.tsx`:
* The component triggers `trpc.approvals.createSet.useMutation()` with the selected media snapshot, copy snapshot, and basic brand information to build a client-review board.

## 2. Logic Chain
1. *Observation*: The `ImageSidebar.tsx` visual patterns for "AI Enhance" and "Angles (Scenes)" rely on standard UI components (`Switch` and `input range`) tied to states (`autoOptimizeBrief`, `numVersions`) from `useImageGeneration.ts`.
2. *Observation*: During generation in `useImageGeneration.ts`, these parameters trigger tRPC async mutations (`optimizeBriefMutation` and `generateConceptsMutation`) dynamically.
3. *Observation*: The tRPC calls map directly via `trpc.ts` proxy mapping definitions to standard WordPress REST endpoints (`POST /image/optimize-brief` and `POST /image/concepts`) processed by the backend PHP controllers (`PCM_REST_Image::optimize_brief` and `suggest_concepts`).
4. *Conclusion*: By implementing identical React states and Radix/HTML slider controls in `AdsSidebar.tsx` and updating the pipeline in `useAdsOrchestration.ts` to call `image.optimizeBrief` and `image.generateConcepts` before starting visual/copy generation, we can achieve identical features parity for the Ads Module while maintaining branding and design consistency.

## 3. Caveats
* **No Code Changes**: This was a read-only investigation. No source files were updated; only the metadata progress tracking, original prompt, handoff, and exploration reports were created in the metadata directory.
* **WordPress REST Nonce**: Ensure that a valid X-WP-Nonce header is passed dynamically by the frontend application configurations to prevent unauthorized endpoint access errors.

## 4. Conclusion
Integrating "AI Enhance" and "Angles (Scenes)" in the Ads module is fully supported by the codebase. By extending `useAdsOrchestration.ts` to execute `image.optimizeBrief` (if AI Enhance is selected) and `image.generateConcepts` (if multiple Angles are requested) prior to calling copy/visual generation, the Ads Module will have full feature parity with the Image Module.

## 5. Verification Method
* **Static Inspection**: Read the created detailed exploration report located at `c:\Users\dataadmin546\Desktop\PROJECTS\PowerCreatives\app\public\wp-content\plugins\power-creatives\.agents\explorer_ads_exploration\exploration_report.md` to review the precise signatures, payloads, and component prop structures.
* **Component Testing**: Run npm build/lint checks once the implementation plan is coded to ensure no compiler/TypeScript errors.
