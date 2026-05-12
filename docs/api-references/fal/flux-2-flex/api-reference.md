# Flux 2 Flex — Fal.ai API Reference

> **Endpoint:** `fal-ai/flux-2-flex`
> **Type:** Text-to-Image
> **Key Feature:** Best typography/text rendering. Adjustable inference steps and guidance scale.
> **Price:** $0.06 per megapixel

## Input Schema

```json
{
  "prompt": "string (required)",
  "image_size": "landscape_4_3",
  "guidance_scale": 3.5,
  "num_inference_steps": 28,
  "output_format": "jpeg",
  "safety_tolerance": "2",
  "enable_safety_checker": true,
  "seed": null
}
```

### Fields

| Field | Type | Default | Values |
|---|---|---|---|
| `prompt` | string | — | Required |
| `image_size` | enum or object | `"landscape_4_3"` | `square_hd`, `square`, `portrait_4_3`, `portrait_16_9`, `landscape_4_3`, `landscape_16_9` OR `{ "width": 1280, "height": 720 }` |
| `guidance_scale` | float | `3.5` | Controls creativity vs prompt adherence |
| `num_inference_steps` | integer | `28` | 10–50. Higher = better quality, slower |
| `output_format` | enum | `"jpeg"` | `jpeg`, `png` |
| `safety_tolerance` | enum | `"2"` | `1` (strict) – `5` (permissive) |
| `enable_safety_checker` | boolean | `true` | — |
| `seed` | integer | random | — |

## Output Schema

```json
{
  "images": [
    {
      "url": "https://..."
    }
  ],
  "seed": 1234567890
}
```

## Aspect Ratio Mapping

This model uses `image_size` (named enum), not `aspect_ratio` (ratio format).

| Our aspect ratio | Fal.ai `image_size` |
|---|---|
| `1:1` | `square_hd` |
| `16:9` | `landscape_16_9` |
| `9:16` | `portrait_16_9` |
| `4:3` | `landscape_4_3` |
| `3:4` | `portrait_4_3` |

## Example Request

```bash
POST https://fal.run/fal-ai/flux-2-flex
{
  "prompt": "Marketing banner with headline 'SUMMER SALE' in bold sans-serif",
  "image_size": "landscape_16_9",
  "guidance_scale": 3.5,
  "num_inference_steps": 28,
  "output_format": "jpeg"
}
```

## Mapping to our normalized params

| Our param | Fal.ai field | Notes |
|---|---|---|
| `prompt` | `prompt` | Direct |
| `aspectRatio` | `image_size` | Needs mapping: `16:9` → `landscape_16_9` |
| `inputUrls` | N/A | Text-to-image only |
