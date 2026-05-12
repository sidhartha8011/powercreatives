# Custom Kie.ai Models - Architecture Analysis

## Current Architecture

### Two parallel systems for Kie.ai models:

1. **`providers/kieai.ts` → `knownModels[]`**
   - Static list of ProviderModel objects (id, name, type, description)
   - Used during validation to show available models
   - Models get synced to `models` DB table via `syncFromIntegrations`

2. **`kieai.ts` → `MODEL_CONFIG{}`**
   - Maps model IDs to runtime config (endpoint, modelName, type, inputFormat)
   - Used during actual generation to dispatch API calls
   - If a model ID is not in MODEL_CONFIG → `throw new Error("Unknown Kie.ai model")`

### Key Insight: Custom models need entries in BOTH places

For a user-added custom model to work:
- It needs to appear in the models DB table (for UI selection)
- It needs runtime config (endpoint, modelName, inputFormat) for generation

### Standard vs Special Models

~90% of Kie.ai models use:
- endpoint: `/jobs/createTask`
- inputFormat: `standard`
- modelName: the Kie.ai model identifier (e.g., `seedream/3.0`)

Only 5 models have special endpoints/formats:
- GPT-4o → `/gpt4o-image/generate` (gpt4o format)
- Flux Kontext → `/flux-kontext/generate` (flux-kontext format)
- Runway → `/runway/generate` (runway format)
- Luma → `/luma/generate` (luma format)
- Veo → `/veo/generate` (veo format)

## Solution: Minimal Changes

### What the user needs to provide:
1. **Model Name** (Kie.ai API name) - e.g., `seedream/v4.5`
2. **Display Name** - e.g., `Seedream 4.5`
3. **Type** - `image` or `video`

That's it. Default to standard endpoint + standard inputFormat.

### Implementation:
1. Store custom models in DB `models` table (already exists, provider=`kieai`)
2. At generation time, if model ID not in MODEL_CONFIG, build a standard config dynamically
3. Add "Add Custom Model" button to Kie.ai integration card in Integrations module
4. New tRPC endpoint: `integrations.addCustomKieModel`

### No new DB table needed - use existing `models` table with a convention:
- Custom Kie.ai models get `modelId` prefixed with `kie-custom-`
- The actual Kie.ai model name stored in `description` field (or a new metadata field)

Actually better: store the Kie.ai model name in the `description` field since it's already text.
Or even better: use the `tags` JSON field to store `{ kieModelName: "seedream/v4.5" }`.

Best approach: Add a `providerMetadata` JSON column to models table for provider-specific config.
This is the most extensible - any provider can store custom data there.
