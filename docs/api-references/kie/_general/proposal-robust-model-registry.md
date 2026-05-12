# Proposal: Robust Model Registry with Extra Required Fields

## Problem Statement

Our current `KieMarketplaceModel` interface only tracks a few behavioral flags (`aspectRatioMode`, `supportsDuration`, `supportsNegativePrompt`), but Kie.ai models have **model-specific required fields** that vary per model — `resolution`, `quality`, `image_resolution`, `reference_image_urls`, `style`, `rendering_speed`, etc. When the API requires a field we don't send, we get 500/422 errors. Adding a new flag for every possible field creates an ever-growing interface that's hard to maintain.

## Proposed Solution: `extraRequiredFields` Map

Add a single new field to `KieMarketplaceModel` that captures **any additional required input fields** with their default values. The input mapper reads this map and injects the defaults automatically. This is:

- **Extensible**: Any new required field Kie.ai adds just needs one line in the model entry
- **Zero code changes**: The input mapper loop handles all fields generically
- **Self-documenting**: Each model entry shows exactly what it sends to the API
- **Backwards compatible**: Existing models without extra fields don't need changes

### Interface Change

```typescript
export interface KieMarketplaceModel {
  // ... existing fields unchanged ...

  /**
   * Additional required input fields with their default values.
   * The input mapper sends these automatically when the caller doesn't provide them.
   * Key = API field name, Value = default value to send.
   *
   * Examples:
   *   { resolution: '1K' }              → Flux 2 Pro/Flex
   *   { quality: 'medium' }             → GPT Image 1.5
   *   { quality: 'basic' }              → Seedream 4.5
   *   { resolution: '1K', quality: 'medium' } → hypothetical future model
   */
  readonly extraRequiredFields?: Readonly<Record<string, string | number | boolean>>;
}
```

### Registry Changes (examples)

```typescript
// Flux 2 Pro T2I — needs resolution
{
  id: 'kie-flux2-pro-t2i',
  modelName: 'flux-2/pro-text-to-image',
  // ... existing fields ...
  extraRequiredFields: { resolution: '1K' },
}

// GPT Image 1.5 T2I — needs quality
{
  id: 'kie-gpt-image-1.5-t2i',
  modelName: 'gpt-image/1.5-text-to-image',
  // ... existing fields ...
  extraRequiredFields: { quality: 'medium' },
}

// Seedream 4.5 T2I — needs quality
{
  id: 'kie-seedream-4.5-t2i',
  modelName: 'seedream/4.5-text-to-image',
  // ... existing fields ...
  extraRequiredFields: { quality: 'basic' },
}

// Seedream 4.0 T2I — fix model name + aspectRatioMode
{
  id: 'kie-seedream-4-t2i',
  modelName: 'bytedance/seedream-v4-text-to-image',  // FIXED: was seedream/
  aspectRatioMode: 'image_size',                       // FIXED: was aspect_ratio
  // ... existing fields ...
}
```

### Input Mapper Change (3 lines)

```typescript
// In buildMarketplaceInput(), after existing field handling:

// --- Extra required fields (model-specific defaults) ---
if (model.extraRequiredFields) {
  for (const [key, defaultValue] of Object.entries(model.extraRequiredFields)) {
    if (!(key in input)) {  // Don't override if already set by caller
      input[key] = defaultValue;
    }
  }
}
```

### Why NOT individual flags?

Adding `supportsResolution`, `supportsQuality`, `defaultResolution`, `defaultQuality`, etc. would:
- Bloat the interface with every new Kie.ai field
- Require code changes in the mapper for each new field
- Create a maintenance burden when Kie.ai adds new required fields

The `extraRequiredFields` map handles ALL of these with zero code changes.

## Additional Fixes Required

### 1. Seedream 4.0: Wrong model name
Change `modelName` from `seedream/seedream-v4-text-to-image` to `bytedance/seedream-v4-text-to-image`.
Same for edit: `seedream/seedream-v4-edit` → `bytedance/seedream-v4-edit`.

### 2. Seedream 4.0: Wrong aspectRatioMode
Change from `aspect_ratio` to `image_size`. The API uses `image_size` with enum values (square, square_hd, portrait_4_3, etc.).

### 3. Ideogram Character: Requires reference images
The API requires `reference_image_urls` even though it's labeled as text-to-image. Two options:
- **Option A**: Recategorize to `image-to-image` with `imageInputMode: 'reference_image_urls'` (new mode)
- **Option B**: Keep as T2I but add `extraRequiredFields: { reference_image_urls: [] }` — but this doesn't make sense since an empty array won't work

**Recommendation**: Option A — add `reference_image_urls` as a new `imageInputMode` value and recategorize as image-to-image. This correctly reflects that the model CANNOT work without reference images.

### 4. GPT-4o Image (Dedicated): Wrong endpoint or response parsing
Our docs show the endpoint is `POST /api/v1/gpt4o-image/generate` — which matches our code (`/gpt4o-image/generate`). The issue is likely in response parsing. The `record-info` response may have changed format. Need to add debug logging to capture the raw response.

### 5. Flux Kontext (Dedicated): Wrong endpoint
Our docs show `POST /api/v1/flux/kontext/generate` — which matches our code. But we're getting 404. Either:
- The endpoint was removed/renamed on Kie.ai's side
- The base URL path is wrong (should be `/api/v1/flux-kontext/generate-or-edit-image` based on the newer docs)

**Recommendation**: Update to the new endpoint from the official docs page.

## Implementation Order

1. Add `extraRequiredFields` to the interface (non-breaking, optional field)
2. Add 3-line loop to input mapper
3. Fix Seedream 4.0 model names and aspectRatioMode
4. Add `extraRequiredFields` to Flux 2 Pro/Flex, GPT Image 1.5, Seedream 4.5
5. Recategorize Ideogram Character
6. Fix Flux Kontext endpoint
7. Add debug logging for GPT-4o Image response
8. Update tests
9. Checkpoint

## Future-Proofing

When Kie.ai adds a new model with a new required field (e.g., `style`, `rendering_speed`, `num_images`), the fix is ONE line in the registry entry:

```typescript
extraRequiredFields: { style: 'realistic', rendering_speed: 'fast' }
```

No code changes. No new types. No mapper updates. Just data.
