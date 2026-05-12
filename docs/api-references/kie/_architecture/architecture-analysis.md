# Architecture Analysis: Model Flow

## Current Data Flow

```
1. Integration Center (UI)
   └── User adds integration with API key
   └── Validates via tRPC (integrations.validateApiKey)
   └── Returns: { valid, capabilities, models[] }
   
2. AppContext (ADD_INTEGRATION action)
   └── Saves integration to state
   └── PROBLEM: Uses PROVIDER_MODELS (hardcoded) instead of API-returned models
   └── Saves to localStorage
   
3. AppContext (LOAD_INTEGRATIONS action)
   └── Loads from localStorage
   └── PROBLEM: Prefers integration.availableModels but falls back to PROVIDER_MODELS
   └── Builds state.models array
   
4. useModelRegistry Hook
   └── Calls getAvailableImageModels() from AppContext
   └── Enhances with registry data (customName, costTier)
   └── Returns enhancedImageModels
   
5. Image Module
   └── Uses useModelRegistry().imageModels
   └── Displays in Production Engines sidebar
```

## Identified Problems

### Problem 1: Dual Model Sources (CRITICAL)
**Location:** AppContext.tsx lines 73-126 (PROVIDER_MODELS) and ADD_INTEGRATION action

The system has TWO sources of model data:
1. **PROVIDER_MODELS** - Hardcoded list in AppContext (outdated, doesn't match API)
2. **integration.availableModels** - Dynamic models from API validation

When an integration is added via ADD_INTEGRATION, it uses PROVIDER_MODELS (hardcoded) instead of the models returned from API validation.

### Problem 2: Model ID Mismatch
**Location:** AppContext.tsx ADD_INTEGRATION action

The hardcoded PROVIDER_MODELS uses IDs like:
- `gemini-2.5-flash-image`
- `imagen-4`

But the API returns IDs like:
- `gemini-2.0-flash-exp-image-generation`
- `imagen-4.0-fast-generate-001`

This causes the backend to fail because it receives wrong model IDs.

### Problem 3: availableModels Not Used in ADD_INTEGRATION
**Location:** AppContext.tsx lines 151-199

The ADD_INTEGRATION action ignores `integration.availableModels` that was set in IntegrationsModule when adding the integration. It rebuilds models from PROVIDER_MODELS instead.

### Problem 4: Model ID Prefix Confusion
**Location:** Multiple files

Models get prefixed with integration ID in some places:
- `${integration.id}-${model.id}` in LOAD_INTEGRATIONS
- But availableModels from API already have their own IDs

This creates inconsistent IDs like:
- `uuid-imagen-4.0-fast-generate-001` (prefixed)
- `imagen-4.0-fast-generate-001` (original)

## Root Cause

The architecture has evolved organically with multiple approaches:
1. Original: Hardcoded PROVIDER_MODELS
2. Added: Dynamic API validation
3. Added: availableModels on Integration
4. Added: Model Registry for customization

These were never properly unified into a single source of truth.

## Solution: Single Source of Truth

### Principle
**Integration.availableModels IS the source of truth for models.**

When an integration is added:
1. API validation returns models
2. These are stored in integration.availableModels
3. AppContext.models is built ONLY from integration.availableModels
4. PROVIDER_MODELS should be REMOVED entirely

### Changes Required

1. **Remove PROVIDER_MODELS** from AppContext.tsx
2. **Fix ADD_INTEGRATION** to use integration.availableModels directly
3. **Fix LOAD_INTEGRATIONS** to only use integration.availableModels
4. **Ensure model IDs are consistent** (no random prefixing)
5. **Backend uses original model ID** from provider (not prefixed)
