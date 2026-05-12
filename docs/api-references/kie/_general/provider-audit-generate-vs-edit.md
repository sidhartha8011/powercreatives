# Provider Audit: Generate vs Edit Code Paths

## Complete Trace of Every Code Path

### Entry Points (2 separate tRPC endpoints)

1. **`image.generate`** → calls `generateImageRouted()` — for new image creation
2. **`image.edit`** → calls `editImageRouted()` — for refining/editing existing images

These are correctly separated at the tRPC level. The problem is INSIDE `generateImageRouted()`.

---

## Provider-by-Provider Analysis

### 1. Google — Gemini (Nano Banana, Gemini Flash Image)

**Current behavior when reference images exist:**
- `generateImageRouted()` passes `refUrls[0]` as `originalImageUrl` to `generateGoogleImage()`
- `generateGoogleImage()` routes to `generateWithGemini(apiKey, model, prompt, originalImageUrl)`
- `generateWithGemini()` line 268: `if (originalImageUrl)` → wraps prompt as **`"Edit this image: ${prompt}"`**
- Result: **WRONG** — treats reference image as edit request

**What should happen:**
- Reference images should be included as `inlineData` parts WITHOUT the "Edit this image:" prefix
- The prompt should remain unchanged (already enhanced by `buildIntentPrompt`)
- Multiple reference images should be supported (Gemini supports up to 14)

**Fix needed:** `generateGoogleImage()` needs a new parameter to distinguish `mode: 'reference' | 'edit'`

---

### 2. Google — Imagen 4

**Current behavior when reference images exist:**
- `generateImageRouted()` passes `refUrls[0]` as `originalImageUrl` to `generateGoogleImage()`
- `generateGoogleImage()` line 79-81: `if (modelLower.includes('imagen') && originalImageUrl)` → calls **`editWithImagen()`**
- `editWithImagen()` uses `editMode: 'EDIT_MODE_INPAINT_INSERTION'`
- Result: **WRONG** — treats reference image as inpaint edit

**What should happen:**
- For reference images: call `generateWithImagen()` (text-to-image) — the prompt already contains intent context
- Imagen 4 also supports `referenceImages` with `referenceType` — could be used for even better results
- Only call `editWithImagen()` from the explicit `editImageRouted()` path

**Fix needed:** Same — `mode: 'reference' | 'edit'` parameter

---

### 3. Manus Image

**Current behavior when reference images exist:**
- `generateImageRouted()` passes `refUrls[0]` as `originalImages: [{ url, mimeType }]`
- Manus API receives it as `original_images` array
- Result: **AMBIGUOUS** — Manus API docs say this is "for editing" but it works as reference too
- The prompt is already enhanced by `buildIntentPrompt` so it gives context

**Assessment:** Works acceptably — Manus treats `original_images` as context, not strict edit. No change needed.

---

### 4. Kie.ai

**Current behavior when reference images exist:**
- `generateImageRouted()` passes `refUrls` as `inputUrls` to `generateKieImage()`
- `createKieTask()` passes them as `input_urls` (standard format) or `filesUrl` (GPT-4o format)
- Result: **CORRECT** — Kie.ai's `input_urls` is a reference parameter, not an edit command

**Assessment:** Works correctly. No change needed.

---

### 5. Edit Flow (editImageRouted)

**Current behavior:**
- Only called from `image.edit` tRPC endpoint (explicit refine/edit action)
- Google: calls `generateGoogleImage()` with `originalImageUrl` → triggers edit mode → CORRECT here
- Manus: falls back to generation with original image → acceptable

**Assessment:** This path is correct and should NOT be changed.

---

## Summary of Issues

| Provider | Generate with Reference | Edit (Refine) | Status |
|----------|----------------------|---------------|--------|
| Gemini (Nano Banana) | ❌ Wraps prompt as "Edit this image:" | ✅ Correct | NEEDS FIX |
| Imagen 4 | ❌ Calls editWithImagen() with inpaint | ✅ Correct | NEEDS FIX |
| Manus Image | ⚠️ Ambiguous but works | ✅ Correct | OK |
| Kie.ai | ✅ Uses input_urls correctly | N/A | OK |

## Fix Plan

**Single change point:** `generateGoogleImage()` needs a `mode` parameter.

```typescript
export interface GoogleImageGenOptions {
  apiKey: string;
  model: string;
  prompt: string;
  aspectRatio?: string;
  numberOfImages?: number;
  originalImageUrl?: string;
  /** Additional reference image URLs (for multi-reference) */
  referenceImageUrls?: string[];
  /** 'reference' = generate new image using images as visual context
   *  'edit' = modify the original image based on instruction */
  mode?: 'reference' | 'edit';
}
```

**Behavior with mode:**
- `mode: 'reference'` (default from generateImageRouted):
  - Gemini: Include images as inlineData parts, prompt stays unchanged
  - Imagen: Call generateWithImagen (text-to-image), ignore images (prompt has intent context)
- `mode: 'edit'` (from editImageRouted):
  - Gemini: Include image + "Edit this image:" prefix (current behavior)
  - Imagen: Call editWithImagen with inpaint (current behavior)

**Routing changes:**
- `generateImageRouted()` → pass `mode: 'reference'` + all refUrls
- `editImageRouted()` → pass `mode: 'edit'` (current behavior, no change)
