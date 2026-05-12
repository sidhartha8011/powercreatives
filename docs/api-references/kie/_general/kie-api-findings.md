# Kie.ai API Findings (from docs audit)

## Key Architecture

### Two API Families

1. **Market API (Unified)** — `/api/v1/jobs/createTask` + `/api/v1/jobs/recordInfo`
   - Standard request format: `{ model: "vendor/model-name", input: { prompt, ... }, callBackUrl? }`
   - Standard status: GET `/api/v1/jobs/recordInfo?taskId=xxx`
   - Used by: ALL market models (Seedream, Grok, Flux-2, Imagen4, Ideogram, Qwen, Kling, Sora2, Bytedance, Hailuo, Wan, ElevenLabs, Infinitalk, Gemini, Claude, GPT)

2. **Dedicated APIs** — Custom endpoints per product
   - 4o Image API: POST `/api/v1/4o-image-api/generate` + GET `/api/v1/gpt4o-image/record-info`
   - Flux Kontext API: POST `/api/v1/flux-kontext/generate` + GET `/api/v1/flux-kontext/record-info`
   - Runway API: POST `/api/v1/runway/generate` + GET `/api/v1/runway/record-info`
   - Luma API: POST `/api/v1/luma/generate-modify` + GET `/api/v1/luma/modify-record-info`
   - Veo3.1 API: POST `/api/v1/veo3/generate` + GET `/api/v1/veo3/record-info`
   - Suno API: Multiple music endpoints

## Model Names (from docs — these are the ACTUAL model strings)

### Image Models (Market API)
- `seedream/4.5-text-to-image`
- `seedream/4.5-edit`
- `seedream/seedream` (v3.0)
- `seedream/seedream-v4-text-to-image` (v4.0)
- `seedream/seedream-v4-edit` (v4.0 edit)
- `grok-imagine/text-to-image`
- `grok-imagine/image-to-image`
- `grok-imagine/upscale`
- `flux-2/pro-text-to-image`
- `flux-2/pro-image-to-image`
- `flux-2/flex-text-to-image`
- `flux-2/flex-image-to-image`
- `google/imagen4`
- `google/imagen4-fast`
- `google/imagen4-ultra`
- `google/nano-banana`
- `google/nano-banana-edit`
- `google/pro-image-to-image` (Nano Banana Pro)
- `gpt-image/1.5-text-to-image`
- `gpt-image/1.5-image-to-image`
- `ideogram/character`
- `ideogram/character-edit`
- `ideogram/character-remix`
- `ideogram/v3-reframe`
- `qwen/text-to-image`
- `qwen/image-to-image`
- `qwen/image-edit`
- `recraft/crisp-upscale`
- `recraft/remove-background`
- `topaz/image-upscale`
- `z-image/z-image`

### Video Models (Market API)
- `kling/text-to-video` (v2.6)
- `kling/image-to-video` (v2.6)
- `kling/kling-3.0`
- `kling/v2-1-pro`
- `kling/v2-1-standard`
- `kling/v2-1-master-text-to-video`
- `kling/v2-1-master-image-to-video`
- `kling/v2-5-turbo-text-to-video-pro`
- `kling/v2-5-turbo-image-to-video-pro`
- `kling/motion-control` (v2.6)
- `kling/ai-avatar-pro`
- `kling/ai-avatar-standard`
- `sora2/sora-2-text-to-video`
- `sora2/sora-2-image-to-video`
- `sora2/sora-2-pro-text-to-video`
- `sora2/sora-2-pro-image-to-video`
- `sora2/sora-2-characters`
- `sora2/sora-2-characters-pro`
- `sora-2-pro-storyboard`
- `sora-watermark-remover`
- `bytedance/seedance-1.5-pro`
- `bytedance/v1-pro-text-to-video`
- `bytedance/v1-pro-image-to-video`
- `bytedance/v1-pro-fast-image-to-video`
- `bytedance/v1-lite-text-to-video`
- `bytedance/v1-lite-image-to-video`
- `hailuo/02-text-to-video-pro`
- `hailuo/02-text-to-video-standard`
- `hailuo/02-image-to-video-pro`
- `hailuo/02-image-to-video-standard`
- `hailuo/2-3-image-to-video-pro`
- `hailuo/2-3-image-to-video-standard`
- `wan/2-2-a14b-text-to-video-turbo`
- `wan/2-2-a14b-image-to-video-turbo`
- `wan/2-2-a14b-speech-to-video-turbo`
- `wan/2-2-animate-move`
- `wan/2-2-animate-replace`
- `wan/2-6-text-to-video`
- `wan/2-6-image-to-video`
- `wan/2-6-video-to-video`
- `grok-imagine/text-to-video`
- `grok-imagine/image-to-video`
- `topaz/video-upscale`

### Audio Models (Market API)
- `elevenlabs/text-to-speech-turbo-2-5`
- `elevenlabs/text-to-speech-multilingual-v2`
- `elevenlabs/text-to-dialogue-v3`
- `elevenlabs/speech-to-text`
- `elevenlabs/sound-effect-v2`
- `elevenlabs/audio-isolation`
- `infinitalk/from-audio`

### Chat Models (Market API)
- `gemini/gemini-2.5-flash`
- `gemini/gemini-2.5-pro`
- `gemini/gemini-3-pro`
- `claude/claude-opus-4-5`
- `claude/claude-sonnet-4-5`
- `gpt/gpt-5-2`

### Dedicated API Models (NOT Market API)
- GPT-4o Image (gpt4o): `/api/v1/4o-image-api/generate`
- Flux Kontext: `/api/v1/flux-kontext/generate`
- Runway: `/api/v1/runway/generate`
- Luma Modify: `/api/v1/luma/generate-modify`
- Veo3.1: `/api/v1/veo3/generate`

## Standard Market API Request Format

```json
POST /api/v1/jobs/createTask
{
  "model": "seedream/4.5-text-to-image",
  "callBackUrl": "https://optional-callback.com",
  "input": {
    "prompt": "...",
    "aspect_ratio": "1:1",
    // model-specific params
  }
}
```

## Standard Market API Response

```json
{
  "code": 200,
  "msg": "success",
  "data": {
    "taskId": "task_seedream_1765166238716"
  }
}
```

## Standard Status Response (recordInfo)

States: waiting, queuing, generating, success, fail
Result in `resultJson` field (JSON string with resultUrls array)
