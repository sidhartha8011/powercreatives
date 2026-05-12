# Failing Models Diagnosis — Based on Local API Docs

## 1. Seedream 4.0 T2I (`kie-seedream-4-t2i`)

**Error**: `422: The model name you specified is not supported`

**Our registry**: `modelName: 'seedream/seedream-v4-text-to-image'`, `aspectRatioMode: 'aspect_ratio'`

**Docs say**: model = `bytedance/seedream-v4-text-to-image`, uses `image_size` (enum: square, square_hd, portrait_4_3, etc.) + `image_resolution` (1K/2K/4K). Only `prompt` is required inside input.

**Root cause**: WRONG MODEL NAME. Our registry says `seedream/seedream-v4-text-to-image` but the API expects `bytedance/seedream-v4-text-to-image`. Also wrong `aspectRatioMode` — should be `image_size`, not `aspect_ratio`.

---

## 2. Seedream 4.5 T2I (`kie-seedream-4.5-t2i`)

**Error**: `500: This field is required`

**Our registry**: `modelName: 'seedream/4.5-text-to-image'`, `aspectRatioMode: 'aspect_ratio'`

**Docs say**: model = `seedream/4.5-text-to-image`, requires `prompt` AND `aspect_ratio` (both required).

**Root cause**: `aspect_ratio` is REQUIRED but we're not sending it. Our default fix should handle this, but the error at 19:10 was from BEFORE the fix was deployed. Need to verify the fix is actually running.

---

## 3. Flux 2 Pro T2I (`kie-flux2-pro-t2i`)

**Error**: `500: resolution is required`

**Our registry**: `modelName: 'flux-2/pro-text-to-image'`, `aspectRatioMode: 'aspect_ratio'`

**Docs say**: model = `flux-2/pro-text-to-image`, requires `prompt`, `aspect_ratio`, AND `resolution` (1K/2K/4K). All three are required.

**Root cause**: MISSING REQUIRED FIELD `resolution`. Our input mapper sends `aspect_ratio` (with the default fix) but never sends `resolution`. The registry has no concept of a `resolution` field.

---

## 4. Flux 2 Flex T2I (`kie-flux2-flex-t2i`)

**Error**: `500: resolution is required`

**Our registry**: `modelName: 'flux-2/flex-text-to-image'`, `aspectRatioMode: 'aspect_ratio'`

**Docs say**: Same as Flux 2 Pro — requires `prompt`, `aspect_ratio`, AND `resolution` (1K/2K/4K).

**Root cause**: Same as Flux 2 Pro — missing `resolution` field.

---

## 5. GPT Image 1.5 T2I (`kie-gpt-image-1.5-t2i`)

**Error**: `500: This field is required`

**Our registry**: `modelName: 'gpt-image/1.5-text-to-image'`, `aspectRatioMode: 'aspect_ratio'`

**Docs say**: model = `gpt-image/1.5-text-to-image`, requires `prompt`, `aspect_ratio`, AND `quality` (medium/high). All three are required.

**Root cause**: MISSING REQUIRED FIELD `quality`. Even with the aspect_ratio default fix, the API still requires `quality` which we never send.

---

## 6. Ideogram Character (`kie-ideogram-character`)

**Error**: `500: reference_image_urls is required`

**Our registry**: `modelName: 'ideogram/character'`, `capability: 'text-to-image'`, `imageInputMode: null`, `maxImageInputs: 0`

**Docs**: Not in our local docs folder. But the error is clear — the API requires `reference_image_urls`.

**Root cause**: WRONG CAPABILITY. This model requires reference images for character consistency. It should be `image-to-image` or have `imageInputMode` set. It's categorized as T2I with no image input, but the API requires images.

---

## 7. GPT-4o Image (`kie-gpt-4o-image`) — DEDICATED MODEL

**Error**: `No image URL in Kie.ai result`

**Our code**: Uses dedicated endpoint `/gpt4o-image/generate`, polls `/gpt4o-image/record-info`. Parses `data.response.result_urls[0]`.

**Root cause**: The task creates successfully (no 4xx/5xx on create), but the polling response doesn't contain `result_urls` where we expect it. Either: (a) the response format changed, (b) the generation actually fails silently, or (c) the result is in a different field. Need to log the raw polling response to diagnose.

---

## 8. Flux Kontext (`kie-flux-kontext`) — DEDICATED MODEL

**Error**: `404 Not Found` (Request failed with status code 404)

**Our code**: Uses dedicated endpoint `/flux/kontext/generate`, polls `/flux-kontext/record-info`.

**Docs (from llms.txt)**: The Flux Kontext API docs are at `https://docs.kie.ai/flux-kontext-api/generate-or-edit-image`. The endpoint is likely `/api/v1/flux-kontext/generate-or-edit-image`, NOT `/flux/kontext/generate`.

**Root cause**: WRONG ENDPOINT. The API path changed or was never correct. Our code uses `/flux/kontext/generate` but the actual endpoint is different.

---

# Summary of Fixes Needed

| Model | Fix Type | What to Change |
|-------|----------|---------------|
| Seedream 4.0 | Wrong model name + wrong aspectRatioMode | Change modelName to `bytedance/seedream-v4-text-to-image`, aspectRatioMode to `image_size` |
| Seedream 4.5 | Missing required field (may be fixed already) | Verify aspect_ratio default is being sent |
| Flux 2 Pro | Missing `resolution` field | Add `resolution` support to registry + input mapper |
| Flux 2 Flex | Missing `resolution` field | Same as Flux 2 Pro |
| GPT Image 1.5 | Missing `quality` field | Add `quality` support to registry + input mapper |
| Ideogram Character | Wrong capability + missing image input | Recategorize or add required image input |
| GPT-4o Image | Response parsing issue | Log raw response, fix field extraction |
| Flux Kontext | Wrong API endpoint | Update endpoint path |
