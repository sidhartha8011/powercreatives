# Nano Banana — Fal.ai API Reference

> **Alias:** Google Imagen 4 (rebranded on Fal.ai)
> **Type:** Text-to-Image

## Models

| Model | Endpoint | Speed | Resolution |
|---|---|---|---|
| **Nano Banana Pro** | `fal-ai/nano-banana-pro` | Standard | 1K, 2K, 4K |
| **Nano Banana 2** | `fal-ai/nano-banana-2` | Fast | 0.5K, 1K, 2K, 4K |

## Input Schema

```json
{
  "prompt": "string (required)",
  "num_images": 1,
  "aspect_ratio": "1:1",
  "output_format": "png",
  "resolution": "1K",
  "safety_tolerance": "4",
  "enable_web_search": false,
  "limit_generations": true,
  "seed": null
}
```

### Fields

| Field | Type | Default | Values |
|---|---|---|---|
| `prompt` | string | — | Required |
| `num_images` | integer | `1` | — |
| `aspect_ratio` | enum | `1:1` (Pro) / `auto` (2) | `auto`, `21:9`, `16:9`, `3:2`, `4:3`, `5:4`, `1:1`, `4:5`, `3:4`, `2:3`, `9:16` |
| `output_format` | enum | `"png"` | `jpeg`, `png`, `webp` |
| `resolution` | enum | `"1K"` | Pro: `1K`, `2K`, `4K` · 2: `0.5K`, `1K`, `2K`, `4K` |
| `safety_tolerance` | enum | `"4"` | `1` (strict) – `6` (permissive) |
| `enable_web_search` | boolean | `false` | Uses latest web info |
| `limit_generations` | boolean | `false` (Pro) / `true` (2) | Limits to 1 image per round |
| `seed` | integer | random | Reproducibility |

## Output Schema

```json
{
  "images": [
    {
      "url": "https://...",
      "content_type": "image/png",
      "file_name": "nano-banana-pro-t2i-output.png",
      "width": 1024,
      "height": 1024
    }
  ],
  "description": ""
}
```

## Example Request

```bash
POST https://fal.run/fal-ai/nano-banana-pro
{
  "prompt": "A professional product photo of a luxury watch on marble surface",
  "num_images": 1,
  "aspect_ratio": "1:1",
  "output_format": "png",
  "resolution": "1K"
}
```

## Mapping to our normalized params

| Our param | Fal.ai field | Notes |
|---|---|---|
| `prompt` | `prompt` | Direct |
| `aspectRatio` | `aspect_ratio` | Direct (same format `16:9`) |
| `inputUrls` | N/A | Text-to-image only |
