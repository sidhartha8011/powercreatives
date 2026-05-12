# Flux Kontext — Fal.ai API Reference

> **Endpoint:** `fal-ai/flux-pro/kontext`
> **Type:** Image-to-Image (editing/variations)
> **Key Feature:** Takes text + reference image → targeted edits and scene transformations

## Input Schema

```json
{
  "prompt": "string (required)",
  "image_url": "https://... (reference image)",
  "guidance_scale": 3.5,
  "num_images": 1,
  "output_format": "jpeg",
  "safety_tolerance": "2",
  "enhance_prompt": false,
  "aspect_ratio": null,
  "seed": null
}
```

### Fields

| Field | Type | Default | Values |
|---|---|---|---|
| `prompt` | string | — | Required. Edit instruction. |
| `image_url` | string | — | Reference image URL (HTTP or data URI) |
| `guidance_scale` | float | `3.5` | CFG scale — how closely to follow prompt |
| `num_images` | integer | `1` | — |
| `output_format` | enum | `"jpeg"` | `jpeg`, `png` |
| `safety_tolerance` | enum | `"2"` | `1` (strict) – `6` (permissive) |
| `enhance_prompt` | boolean | `false` | Auto-enhance prompt |
| `aspect_ratio` | enum | — | `21:9`, `16:9`, `4:3`, `3:2`, `1:1`, `2:3`, `3:4`, `9:16`, `9:21` |
| `seed` | integer | random | — |

## Output Schema

```json
{
  "images": [
    {
      "url": "https://...",
      "width": 1024,
      "height": 1024
    }
  ],
  "timings": { "inference": 2.34 },
  "seed": 1234567890,
  "has_nsfw_concepts": [false],
  "prompt": "..."
}
```

## Example Request

```bash
POST https://fal.run/fal-ai/flux-pro/kontext
{
  "prompt": "Change the background to a tropical beach",
  "image_url": "https://example.com/product-photo.jpg",
  "guidance_scale": 3.5,
  "num_images": 1,
  "output_format": "jpeg"
}
```

## Mapping to our normalized params

| Our param | Fal.ai field | Notes |
|---|---|---|
| `prompt` | `prompt` | Direct |
| `aspectRatio` | `aspect_ratio` | Direct (same format `16:9`) |
| `inputUrls[0]` | `image_url` | Single image only (string, not array) |
