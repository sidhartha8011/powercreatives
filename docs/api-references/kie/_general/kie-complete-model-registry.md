# Kie.ai Complete Model Registry (from official docs)

This file is the single source of truth for all Kie.ai model configurations.
Updated: 2026-02-16 from docs.kie.ai

## API Architecture

Kie.ai has TWO distinct API families:

### 1. Market API (Unified)
- Create: `POST /api/v1/jobs/createTask`
- Status: `GET /api/v1/jobs/recordInfo?taskId={taskId}`
- Body: `{ "model": "<model-name>", "input": { ... }, "callBackUrl": "..." }`

### 2. Dedicated APIs (Custom endpoints per product)
- Each has its own create + status endpoint pair
- Different body formats (no `model` field, no `input` wrapper)

---

## IMAGE MODELS

### Market API Image Models

| Model Name (API) | Type | Key Input Params | Notes |
|---|---|---|---|
| `bytedance/seedream` | text-to-image | prompt, image_size, guidance_scale, seed | Seedream 3.0 |
| `seedream/seedream-v4-text-to-image` | text-to-image | prompt, aspect_ratio, quality | Seedream 4.0 |
| `seedream/seedream-v4-edit` | image-edit | prompt, image_url, aspect_ratio | Seedream 4.0 Edit |
| `seedream/4.5-text-to-image` | text-to-image | prompt, aspect_ratio, quality | Seedream 4.5 |
| `seedream/4.5-edit` | image-edit | prompt, image_url, aspect_ratio | Seedream 4.5 Edit |
| `z-image` | text-to-image | prompt, aspect_ratio | Z-Image (note: no vendor prefix) |
| `google/imagen4` | text-to-image | prompt, negative_prompt, aspect_ratio, num_images | Imagen 4 |
| `google/imagen4-fast` | text-to-image | prompt, negative_prompt, aspect_ratio, num_images | Imagen 4 Fast |
| `google/imagen4-ultra` | text-to-image | prompt, negative_prompt, aspect_ratio, num_images | Imagen 4 Ultra |
| `google/nano-banana` | text-to-image | prompt, aspect_ratio | Nano Banana |
| `google/nano-banana-edit` | image-edit | prompt, image_url | Nano Banana Edit |
| `google/pro-image-to-image` | image-to-image | prompt, image_url | Nano Banana Pro I2I |
| `flux-2/pro-text-to-image` | text-to-image | prompt, aspect_ratio, resolution | Flux 2 Pro T2I |
| `flux-2/pro-image-to-image` | image-to-image | input_urls (1-8), prompt, aspect_ratio, resolution | Flux 2 Pro I2I |
| `flux-2/flex-text-to-image` | text-to-image | prompt, aspect_ratio | Flux 2 Flex T2I |
| `flux-2/flex-image-to-image` | image-to-image | input_urls, prompt, aspect_ratio | Flux 2 Flex I2I |
| `grok-imagine/text-to-image` | text-to-image | prompt, aspect_ratio | Grok Imagine T2I |
| `grok-imagine/image-to-image` | image-to-image | prompt, input_urls, aspect_ratio | Grok Imagine I2I |
| `grok-imagine/upscale` | upscale | input_urls | Grok Imagine Upscale |
| `gpt-image/1.5-text-to-image` | text-to-image | prompt, aspect_ratio, quality | GPT Image 1.5 T2I |
| `gpt-image/1.5-image-to-image` | image-to-image | prompt, input_urls, aspect_ratio, quality | GPT Image 1.5 I2I |
| `ideogram/character` | text-to-image | prompt, image_size, style, rendering_speed | Ideogram Character |
| `ideogram/character-edit` | image-edit | prompt, image_url, image_size | Ideogram Character Edit |
| `ideogram/character-remix` | image-remix | prompt, image_url, image_size | Ideogram Character Remix |
| `ideogram/v3-reframe` | image-reframe | image_url, image_size, rendering_speed, style | Ideogram V3 Reframe |
| `qwen/text-to-image` | text-to-image | prompt, image_size, num_inference_steps, guidance_scale | Qwen T2I |
| `qwen/image-to-image` | image-to-image | prompt, image_url | Qwen I2I |
| `qwen/image-edit` | image-edit | prompt, image_url, mask_url | Qwen Image Edit |
| `recraft/crisp-upscale` | upscale | image | Recraft Crisp Upscale |
| `recraft/remove-background` | utility | image | Recraft Remove BG |
| `topaz/image-upscale` | upscale | image_url, scale_factor | Topaz Upscale |

### Dedicated API Image Models

| Product | Create Endpoint | Status Endpoint | Body Format |
|---|---|---|---|
| GPT-4o Image | `POST /api/v1/gpt4o-image/generate` | `GET /api/v1/gpt4o-image/record-info` | `{ prompt, filesUrl[], size, maskUrl, callBackUrl, isEnhance, uploadCn, enableFallback, fallbackModel }` |
| Flux Kontext | `POST /api/v1/flux/kontext/generate` | `GET /api/v1/flux-kontext/record-info` | `{ prompt, inputImage, aspectRatio, outputFormat, model: "flux-kontext-pro"|"flux-kontext-max", callBackUrl }` |

---

## VIDEO MODELS

### Market API Video Models

| Model Name (API) | Type | Key Input Params |
|---|---|---|
| `kling-2.6/text-to-video` | text-to-video | prompt, aspect_ratio, duration | Kling 2.6 T2V |
| `kling-2.6/image-to-video` | image-to-video | prompt, image_urls, duration | Kling 2.6 I2V |
| `kling-2.6/motion-control` | motion-control | prompt, input_urls, trajectory_type | Kling 2.6 Motion Control |
| `kling-3.0/video` | text-to-video | prompt, aspect_ratio, duration, resolution, camera_control | Kling 3.0 |
| `kling/v2-1-pro` | text-to-video | prompt, aspect_ratio, duration, cfg_scale | Kling V2.1 Pro |
| `kling/v2-1-standard` | text-to-video | prompt, aspect_ratio, duration, cfg_scale | Kling V2.1 Standard |
| `kling/v2-1-master-text-to-video` | text-to-video | prompt, aspect_ratio, duration, cfg_scale | Kling V2.1 Master T2V |
| `kling/v2-1-master-image-to-video` | image-to-video | prompt, image_urls, duration, cfg_scale | Kling V2.1 Master I2V |
| `kling/v2-5-turbo-image-to-video-pro` | image-to-video | prompt, image_urls, duration, cfg_scale | Kling V2.5 Turbo I2V Pro |
| `kling/ai-avatar-standard` | avatar | prompt, image_urls, audio_url | Kling AI Avatar |
| `sora2/sora-2-text-to-video` | text-to-video | prompt, aspect_ratio, duration |
| `sora2/sora-2-image-to-video` | image-to-video | prompt, input_urls, duration |
| `sora2/sora-2-pro-text-to-video` | text-to-video | prompt, aspect_ratio, duration |
| `sora2/sora-2-pro-image-to-video` | image-to-video | prompt, input_urls, duration |
| `bytedance/seedance-1.5-pro` | text/image-to-video | prompt, input_urls (0-2), aspect_ratio, resolution (480p/720p/1080p), duration (4/8/12), fixed_lens, generate_audio |
| `bytedance/v1-pro-text-to-video` | text-to-video | prompt, aspect_ratio, resolution (480p/720p/1080p), duration (5/10), camera_fixed, seed, enable_safety_checker |
| `bytedance/v1-pro-image-to-video` | image-to-video | prompt, image_url (required), resolution (720p/1080p), duration (5/10), camera_fixed, seed, enable_safety_checker |
| `bytedance/v1-pro-fast-image-to-video` | image-to-video | prompt, image_url (required), resolution (720p/1080p), duration (5/10) |
| `bytedance/v1-lite-text-to-video` | text-to-video | prompt, aspect_ratio, resolution (480p/720p/1080p), duration (5/10), camera_fixed, seed, enable_safety_checker |
| `bytedance/v1-lite-image-to-video` | image-to-video | prompt, image_url (required), resolution (480p/720p/1080p), duration (5/10), camera_fixed, seed, end_image_url |
| `hailuo/02-text-to-video-pro` | text-to-video | prompt, aspect_ratio |
| `hailuo/02-image-to-video-pro` | image-to-video | prompt, input_urls |
| `hailuo/2-3-image-to-video-pro` | image-to-video | prompt, input_urls |
| `wan/2-6-text-to-video` | text-to-video | prompt, aspect_ratio |
| `wan/2-6-image-to-video` | image-to-video | prompt, input_urls |
| `grok-imagine/text-to-video` | text-to-video | prompt, aspect_ratio, mode, duration, resolution | Grok Imagine T2V |
| `grok-imagine/image-to-video` | image-to-video | prompt, image_urls/task_id, mode, duration | Grok Imagine I2V |

### Dedicated API Video Models

| Product | Create Endpoint | Status Endpoint | Body Format |
|---|---|---|---|
| Runway | `POST /api/v1/runway/generate` | `GET /api/v1/runway/record-info` | `{ prompt, imageUrl, model: "runway-duration-5-generate", duration, quality, aspectRatio, waterMark, callBackUrl }` |
| Luma Modify | `POST /api/v1/modify/generate` | `GET /api/v1/luma/modify-record-info` | `{ prompt, videoUrl, callBackUrl, watermark }` |
| Veo 3.1 | `POST /api/v1/veo/generate` | `GET /api/v1/veo3/record-info` | `{ prompt, imageUrls[], model: "veo3_fast"|"veo3_quality", generationType, aspect_ratio, callBackUrl }` |

---

## AUDIO MODELS (Market API)

| Model Name (API) | Type |
|---|---|
| `elevenlabs/text-to-speech-turbo-2-5` | TTS |
| `elevenlabs/text-to-speech-multilingual-v2` | TTS |
| `elevenlabs/text-to-dialogue-v3` | TTS |
| `elevenlabs/speech-to-text` | STT |
| `elevenlabs/sound-effect-v2` | SFX |
| `elevenlabs/audio-isolation` | Audio |
| `infinitalk/from-audio` | Audio |

---

## KEY ARCHITECTURAL NOTES

1. **Market API models** all share the same `createTask` + `recordInfo` pattern
2. **Dedicated API models** each have unique endpoints and body formats
3. **Status polling** for Market API uses `recordInfo`, dedicated APIs use their own `record-info` endpoints
4. **Result format**: Market API returns `resultJson` (JSON string with `resultUrls` array), dedicated APIs vary
5. **Model names are case-sensitive** and must match exactly
6. **Some models use `image_size` enum** (square, square_hd, portrait_4_3, etc.) instead of `aspect_ratio` string
