# Implementation Checklist — Video Model Fixes

## 1. Registry fixes (shared/kieMarketplaceModels.ts)

### Kling 3.0 — restore all params
- [ ] `aspectRatioMode: 'aspect_ratio'`
- [ ] `supportsDuration: true`
- [ ] `validDurations: ['5', '10']`
- [ ] `defaultDuration: '5'`
- [ ] `extraRequiredFields: { mode: 'std', sound: true, multi_shots: false }`
- [ ] `imageInputMode: 'image_urls'` (supports starting frame)
- [ ] `maxImageInputs: 1`

### Sora 2 I2V — add aspect ratio support
- [ ] `aspectRatioMode: 'aspect_ratio'`
- [ ] `aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' }`

### Sora 2 Pro I2V — add aspect ratio support
- [ ] `aspectRatioMode: 'aspect_ratio'`
- [ ] `aspectRatioMap: { portrait: 'portrait', landscape: 'landscape' }`

## 2. Dedicated model fixes (server/kieai.ts)

### Veo 3.1 — fix generationType
- [ ] Change `generationType: 'generate'` → `generationType: 'TEXT_2_VIDEO'`
- [ ] For I2V: `generationType: 'FIRST_AND_LAST_FRAMES_2_VIDEO'`

## 3. Video system prompt (server/promptDefaults.ts + server/routers/video.ts)

- [ ] Add `system_prompt_concepts` section to video module in PROMPT_SECTION_META
- [ ] Add `VideoPromptSection` type
- [ ] Replace hardcoded prompt in video router with getPromptSection() call
- [ ] Fallback to hardcoded prompt if no DB entry exists yet

## 4. Brand logo as reference image (server/routers/video.ts)

- [ ] Accept optional brandId in video.generate input
- [ ] Fetch brand logo URL from scrapedImages where category='logo'
- [ ] Append logo URL to inputUrls
- [ ] Append "Include the brand logo" instruction to prompt

## 5. Capability badges (frontend)

- [ ] Expose model capabilities (formats, durations) via tRPC query or derive from registry
- [ ] Show small badges next to each model in the Video sidebar
