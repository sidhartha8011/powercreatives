# Intent-Based Reference Image System — Design Document

## 1. Research Summary

### AI Provider Capabilities

After thorough research of the three AI providers currently integrated into Creative Machine, the following capabilities were identified for reference image handling:

| Provider | Reference Image Support | Subject Preservation | Style Transfer | Environment/Background | Editing/Inpainting | Max Ref Images |
|----------|------------------------|---------------------|---------------|----------------------|-------------------|----------------|
| **Manus Image** | Single `originalImages` array | Via prompt | Via prompt | Via prompt | Via prompt + image | 1 |
| **Gemini (Nano Banana)** | Multimodal `contents` parts | Via prompt + image | Via prompt + image | Via prompt + image | Via prompt + image | 3 (Flash) / 14 (Pro) |
| **Imagen 4** | Structured `referenceImages` array | `REFERENCE_TYPE_SUBJECT` | `REFERENCE_TYPE_STYLE` | Via `REFERENCE_TYPE_RAW` + mask | `REFERENCE_TYPE_RAW` + `REFERENCE_TYPE_MASK` | 4 |

### Key Findings

**Gemini (Nano Banana / Nano Banana Pro):** The most flexible approach. Reference images are passed as inline data parts alongside the text prompt. The model interprets the relationship between images and text naturally. No structured "intent" parameter exists — the prompt itself controls how each image is used. For example: "Put this person [image1] in this environment [image2]" or "Apply the style of [image1] to generate a new image of [prompt]". Gemini 3 Pro supports up to 14 reference images (5 humans, 6 objects).

**Imagen 4 (Customization API):** The most structured approach. Each reference image has an explicit `referenceType` enum:
- `REFERENCE_TYPE_SUBJECT` — with `subjectType` (PERSON, ANIMAL, PRODUCT, DEFAULT) and `subjectDescription`
- `REFERENCE_TYPE_STYLE` — with optional `styleDescription`
- `REFERENCE_TYPE_CONTROL` — with `controlType` (FACE_MESH, CANNY, SCRIBBLE)
- `REFERENCE_TYPE_RAW` — for editing (original image)
- `REFERENCE_TYPE_MASK` — for inpainting

**Manus Image:** Simplest approach. Accepts a single original image via `originalImages` array. The prompt controls how the image is used. No structured intent parameters.

### Practical Implications

The intent system should be **prompt-engineering driven** rather than API-parameter driven, because:

1. **Gemini and Manus** don't have structured intent parameters — they rely on prompt wording
2. **Imagen's structured API** uses a different model (`imagen-3.0-capability-001`) that requires separate routing
3. A prompt-engineering approach works universally across all providers
4. Imagen's structured intents can be used as an optimization when that specific provider is selected

## 2. Intent Definitions

Based on what's technically proven across providers, these intents are recommended:

| Intent | Label | Prompt Injection Pattern | Imagen Mapping |
|--------|-------|------------------------|----------------|
| `subject_person` | "Use this person" | "featuring the person shown in the reference image" | `REFERENCE_TYPE_SUBJECT` + `SUBJECT_TYPE_PERSON` |
| `subject_product` | "Feature this product" | "featuring the product shown in the reference image" | `REFERENCE_TYPE_SUBJECT` + `SUBJECT_TYPE_PRODUCT` |
| `style_transfer` | "Use this style" | "in the visual style of the reference image" | `REFERENCE_TYPE_STYLE` |
| `environment` | "Use this environment" | "set in the environment/location shown in the reference image" | `REFERENCE_TYPE_RAW` (prompt-driven) |
| `variation` | "Create variations" | "create a variation of the reference image" | `REFERENCE_TYPE_RAW` (prompt-driven) |

## 3. Architecture Design

### Session-Level Reference Images

The key requirement is that reference images in the Image module sidebar should be **session-level** — adding/removing should NOT affect the brand's permanent asset library.

**Design:**
- On brand selection, copy the brand's assets into a local `sessionReferenceImages` state array
- Each session image gets an `intent` field (default: none/auto)
- Users can add/remove images from the session without touching the brand DB
- Users can also add images that aren't in the brand (upload or URL) — these are session-only
- The permanent brand CRUD stays in BrandDialog only

### Data Flow

```
Brand DB Assets → [copy on brand select] → Session Reference Images (with intents)
                                                    ↓
                                           Image Generation
                                                    ↓
                                    Prompt Engineering (inject intent context)
                                                    ↓
                                    Provider-specific API call
```

### Type Definitions

```typescript
// Intent types for reference images
export type ReferenceImageIntent = 
  | 'auto'              // Let the AI decide (default)
  | 'subject_person'    // Use this person in the generated image
  | 'subject_product'   // Feature this product
  | 'style_transfer'    // Apply this visual style
  | 'environment'       // Use this setting/background
  | 'variation';        // Create variations of this image

// Session-level reference image (not persisted to brand DB)
export interface SessionReferenceImage {
  id: string;           // Unique session ID
  url: string;          // Image URL (from brand asset or session upload)
  filename: string;     // Display name
  intent: ReferenceImageIntent;
  fromBrand: boolean;   // true = copied from brand, false = session-only upload
  fileKey?: string;     // Brand asset fileKey (only if fromBrand)
}
```

### Prompt Engineering

When generating, the system builds an enhanced prompt by injecting intent context:

```typescript
function buildIntentPrompt(
  basePrompt: string, 
  sessionImages: SessionReferenceImage[]
): string {
  const intentParts: string[] = [];
  
  for (const img of sessionImages) {
    switch (img.intent) {
      case 'subject_person':
        intentParts.push('featuring the person shown in the reference image');
        break;
      case 'subject_product':
        intentParts.push('featuring the product shown in the reference image');
        break;
      case 'style_transfer':
        intentParts.push('in the visual style of the reference image');
        break;
      case 'environment':
        intentParts.push('set in the environment/location shown in the reference image');
        break;
      case 'variation':
        intentParts.push('create a variation of the reference image');
        break;
      case 'auto':
      default:
        // No injection — let the AI interpret naturally
        break;
    }
  }
  
  if (intentParts.length === 0) return basePrompt;
  return `${basePrompt}. ${intentParts.join('. ')}.`;
}
```

## 4. Implementation Plan

### Phase 1: Types & Backend
1. Add `ReferenceImageIntent` and `SessionReferenceImage` types to `shared/brandTypes.ts`
2. Update `ImageGenerationRequest` in routing to accept `referenceImageIntents`
3. Add prompt-engineering logic in routing layer (before provider dispatch)
4. Update `image.generate` tRPC endpoint to accept `referenceImages` with intents

### Phase 2: Frontend — Session Reference Images
1. Create `SessionReferenceImagePanel` component (new, separate from `ReferenceImageSelector`)
2. On brand select: copy brand assets into session state with default `auto` intent
3. Allow adding session-only images (upload/URL) that don't touch brand DB
4. Allow removing session images without affecting brand DB
5. Intent pill selector per image thumbnail

### Phase 3: Integration
1. Wire session reference images into `handleGenerate` in Image module
2. Pass intents through to backend
3. Backend builds enhanced prompt and passes images to providers
4. For Gemini: pass multiple images as content parts with intent-aware prompt
5. For Manus: pass primary image with intent-aware prompt
6. For Imagen: optionally use structured `referenceType` when available

### Phase 4: Tests
1. Unit tests for prompt engineering logic
2. Unit tests for session reference image management
3. Integration tests for generation with intents
