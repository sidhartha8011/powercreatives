# Recraft V4 Pro — Fal.ai API Reference

> **Endpoint:** `fal-ai/recraft/v4/pro/text-to-image`
> **Type:** Text-to-Image
> **Key Feature:** Design-grade images. Brand color palette control. Production-ready.
> **Price:** $0.25 per image

## Input Schema

```json
{
  "prompt": "string (required)",
  "image_size": "square_hd",
  "colors": [],
  "background_color": null,
  "enable_safety_checker": true
}
```

### Fields

| Field | Type | Default | Values |
|---|---|---|---|
| `prompt` | string | — | Required |
| `image_size` | enum or object | `"square_hd"` | `square_hd`, `square`, `portrait_4_3`, `portrait_16_9`, `landscape_4_3`, `landscape_16_9` OR `{ "width": 1280, "height": 720 }` |
| `colors` | array of RGBColor | `[]` | Preferable colors palette. Each: `{ "r": 255, "g": 0, "b": 0 }` |
| `background_color` | RGBColor | — | Preferable background color |
| `enable_safety_checker` | boolean | `true` | — |

### RGBColor Type

```json
{
  "r": 255,
  "g": 100,
  "b": 50
}
```

## Output Schema

```json
{
  "images": [
    {
      "url": "https://...",
      "file_size": 1949504,
      "file_name": "image.webp",
      "content_type": "image/webp"
    }
  ]
}
```

> Note: Recraft outputs WebP by default (not JPEG/PNG).

## Aspect Ratio Mapping

Same as Flux 2 Flex — uses `image_size` enum.

| Our aspect ratio | Fal.ai `image_size` |
|---|---|
| `1:1` | `square_hd` |
| `16:9` | `landscape_16_9` |
| `9:16` | `portrait_16_9` |
| `4:3` | `landscape_4_3` |
| `3:4` | `portrait_4_3` |

## Example Request

```bash
POST https://fal.run/fal-ai/recraft/v4/pro/text-to-image
{
  "prompt": "Clean product shot of premium skincare bottle, minimalist",
  "image_size": "portrait_4_3",
  "colors": [
    { "r": 41, "g": 128, "b": 185 },
    { "r": 255, "g": 255, "b": 255 }
  ]
}
```

## Mapping to our normalized params

| Our param | Fal.ai field | Notes |
|---|---|---|
| `prompt` | `prompt` | Direct |
| `aspectRatio` | `image_size` | Needs mapping: `16:9` → `landscape_16_9` |
| `inputUrls` | N/A | Text-to-image only |
| Brand colors | `colors` | Future: map from brand profile |
