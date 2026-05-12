# Kie.ai API - Model Documentation

## Common API Structure

All models use the same endpoint and authentication:
- **Endpoint:** `POST https://api.kie.ai/api/v1/jobs/createTask`
- **Auth:** `Authorization: Bearer <YOUR_API_KEY>`
- **Content-Type:** `application/json`

All tasks are **asynchronous** - returns `task_id`, then poll or use webhook.

## Response Codes
- 200: Success
- 401: Unauthorized (invalid API key)
- 402: Insufficient Credits
- 404: Not Found
- 422: Validation Error
- 429: Rate Limited
- 455: Service Unavailable
- 500: Server Error
- 501: Generation Failed
- 505: Feature Disabled

## Get Task Details
`GET https://api.kie.ai/api/v1/jobs/getTaskDetails?taskId={taskId}`

---

## IMAGE MODELS

### Flux-2 Models
All Flux models use model name format: `flux-2/{variant}`

| Model | model value | Input |
|-------|-------------|-------|
| Flux-2 Pro Image to Image | `flux-2/pro-image-to-image` | input_urls, prompt, aspect_ratio, resolution |
| Flux-2 Pro Text to Image | `flux-2/pro-text-to-image` | prompt, aspect_ratio, resolution |
| Flux-2 Image to Image | `flux-2/image-to-image` | input_urls, prompt |
| Flux-2 Text to Image | `flux-2/text-to-image` | prompt |

### Flux Kontext (separate API)
- **Endpoint:** `POST https://api.kie.ai/api/v1/flux-kontext/generate`
- Model: `flux-kontext/pro`
- Input: prompt, input_urls (optional)

### GPT-4o Image
- Model: `gpt-4o-image`
- Input: prompt, size (1024x1024, 1792x1024, 1024x1792)

### Ideogram V2
- Model: `ideogram/v2`
- Input: prompt, aspect_ratio, style_type

### Recraft V3
- Model: `recraft/v3`
- Input: prompt, style, size

### Google Imagen 3
- Model: `google/imagen-3`
- Input: prompt, aspect_ratio

### Grok Imagine
- Model: `grok/imagine`
- Input: prompt

### Seedream
- Model: `seedream/3.0`
- Input: prompt, aspect_ratio

---

## VIDEO MODELS

### Kling Models
All Kling models use model name format: `kling/{version}/{type}`

| Model | model value | Input |
|-------|-------------|-------|
| Kling 2.6 Text to Video | `kling/2.6/text-to-video` | prompt, duration, aspect_ratio |
| Kling 2.6 Image to Video | `kling/2.6/image-to-video` | input_urls, prompt, duration |
| Kling 2.5 Turbo | `kling/2.5-turbo/text-to-video` | prompt, duration |
| Kling 2.1 Master | `kling/2.1-master/text-to-video` | prompt, duration |

### Runway Gen-3 (separate API)
- **Endpoint:** `POST https://api.kie.ai/api/v1/runway/generate`
- Model: `runway/gen3-turbo`
- Input: prompt, input_urls (optional), duration

### Luma Dream Machine (separate API)
- **Endpoint:** `POST https://api.kie.ai/api/v1/luma/generate`
- Model: `luma/dream-machine`
- Input: prompt, input_urls (optional)

### Sora 2
- Model: `sora/2.0`
- Input: prompt, duration, aspect_ratio

### Hailuo AI
- Model: `hailuo/minimax`
- Input: prompt, duration

### Veo 3.1
- **Endpoint:** `POST https://api.kie.ai/api/v1/veo/generate`
- Model: `veo/3.1`
- Input: prompt, duration

### Wan Video
- Model: `wan/video`
- Input: prompt, input_urls (optional)

### Bytedance Video
- Model: `bytedance/video`
- Input: prompt

---

## Key Differences

1. **Standard models** use: `POST https://api.kie.ai/api/v1/jobs/createTask`
2. **Special models** have dedicated endpoints:
   - Flux Kontext: `/api/v1/flux-kontext/generate`
   - Runway: `/api/v1/runway/generate`
   - Luma: `/api/v1/luma/generate`
   - Veo: `/api/v1/veo/generate`

3. **Same API key** works for all endpoints
4. **Same async pattern** - all return task_id
