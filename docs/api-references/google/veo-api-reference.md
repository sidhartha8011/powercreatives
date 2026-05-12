# Google Veo REST API Reference (from ai.google.dev/gemini-api/docs/video)

## Base URL
```
https://generativelanguage.googleapis.com/v1beta
```

## Text-to-Video Generation (VERIFIED from official docs)

### Step 1: Submit generation request
```bash
curl -s "${BASE_URL}/models/veo-3.1-generate-preview:predictLongRunning" \
  -H "x-goog-api-key: $GEMINI_API_KEY" \
  -H "Content-Type: application/json" \
  -X "POST" \
  -d '{
    "instances": [{
        "prompt": "Your prompt here"
    }],
    "parameters": {
      "aspectRatio": "9:16"
    }
  }'
```

Returns JSON with `.name` field = operation name for polling.

### Step 2: Poll for completion
```bash
curl -s -H "x-goog-api-key: $GEMINI_API_KEY" "${BASE_URL}/${operation_name}"
```

Check `.done` field. When `true`, extract video URI from:
```
.response.generateVideoResponse.generatedSamples[0].video.uri
```

### Step 3: Download video
```bash
curl -L -o output.mp4 -H "x-goog-api-key: $GEMINI_API_KEY" "${video_uri}"
```

Note: The video URI requires the API key header and follows redirects (-L).

## Parameters

### aspect_ratio
- `16:9` (default, landscape)
- `9:16` (portrait)

### resolution (Veo 3.1 only)
- `720p` (default)
- `1080p`
- `4k`

### personGeneration
- `allow_adult` (default)
- `dont_allow`

### numberOfVideos
- 1-4 (default: 1)

### durationSeconds
- 5-8 seconds (default: 8)

### negativePrompt
- Text describing what to avoid

## Model Versions
- `veo-3.1-generate-preview` — Latest, 720p/1080p/4k, audio, portrait
- `veo-3.0-generate-preview` — 720p, audio
- `veo-3.0-fast-generate-preview` — Fast variant, 720p, audio
- `veo-2.0-generate-001` — 720p, no audio

## Image-to-Video
Same endpoint but add `image` field to instances:
```json
{
  "instances": [{
    "prompt": "...",
    "image": {
      "bytesBase64Encoded": "<base64>",
      "mimeType": "image/png"
    }
  }]
}
```

## Reference Images (Veo 3.1 only)
Add `referenceImages` array to instances with up to 3 images.

## Video Extension (Veo 3.1 only)
Add `video` field with URI from a previously generated video.
