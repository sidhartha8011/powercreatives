# Kie.ai API Notes

## Authentication
- Header format: `Authorization: Bearer <YOUR_API_KEY>`
- Content-Type: `application/json`

## Available Models (from sidebar)

### Image Models
- Seedream
- Z-image
- Google
- Flux-2
- Grok Imagine
- GPT Image
- Topaz
- Recraft
- Ideogram
- Qwen

### Video Models
- Grok Imagine
- Kling
- Bytedance
- Hailuo
- Sora2
- Wan
- Topaz
- Infinitalk
- Elevenlabs
- Claude
- Gemini

## Key Features
- Asynchronous task model (returns task_id, need to poll or use webhook)
- Rate limits: 20 requests per 10 seconds
- Data retention: 14 days for media, 2 months for logs

## API Endpoints to check
- Veo3.1 API
- Suno API
- 4o Image API
- Flux Kontext API
- Runway API
- Luma API
