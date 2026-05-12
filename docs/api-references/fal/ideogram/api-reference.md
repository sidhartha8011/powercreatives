# Ideogram v3 — Fal.ai API Reference

> **Endpoint:** `fal-ai/ideogram/v3`
> **Type:** Text-to-Image
> **Key Feature:** Best text rendering in images. Style presets. Color palettes. Negative prompts.

## Input Schema

```json
{
  "prompt": "string (required)",
  "image_size": "square_hd",
  "num_images": 1,
  "rendering_speed": "BALANCED",
  "style": "AUTO",
  "expand_prompt": true,
  "negative_prompt": "",
  "image_urls": [],
  "color_palette": null,
  "style_preset": null,
  "style_codes": [],
  "seed": null
}
```

### Fields

| Field | Type | Default | Values |
|---|---|---|---|
| `prompt` | string | — | Required |
| `image_size` | enum or object | `"square_hd"` | `square_hd`, `square`, `portrait_4_3`, `portrait_16_9`, `landscape_4_3`, `landscape_16_9` OR `{ "width": 1280, "height": 720 }` |
| `num_images` | integer | `1` | — |
| `rendering_speed` | enum | `"BALANCED"` | `TURBO`, `BALANCED`, `QUALITY` |
| `style` | enum | `"AUTO"` | `AUTO`, `GENERAL`, `REALISTIC`, `DESIGN` |
| `expand_prompt` | boolean | `true` | MagicPrompt auto-enhancement |
| `negative_prompt` | string | `""` | What to exclude |
| `image_urls` | string[] | `[]` | Style reference images (max 10MB total) |
| `color_palette` | object | — | `{ "name": "preset" }` or `{ "members": [{ "hex": "#FF0000", "weight": 1.0 }] }` |
| `style_preset` | enum | — | 60+ presets: `MAGAZINE_EDITORIAL`, `POP_ART`, `WATERCOLOR`, `FLAT_VECTOR`, `DRAMATIC_CINEMA`, etc. |
| `style_codes` | string[] | `[]` | 8-char hex style codes |
| `seed` | integer | random | — |

### Notable Style Presets (for advertising)

- `MAGAZINE_EDITORIAL` — editorial magazine look
- `DRAMATIC_CINEMA` — cinematic lighting
- `FLAT_VECTOR` — flat illustrations
- `POP_ART` — vibrant pop art
- `ART_POSTER` — poster design
- `TRAVEL_POSTER` — travel poster style
- `VINTAGE_POSTER` — retro poster

## Output Schema

```json
{
  "images": [
    {
      "url": "https://..."
    }
  ],
  "seed": 123456
}
```

## Aspect Ratio Mapping

Same as Flux 2 Flex and Recraft — uses `image_size` enum.

| Our aspect ratio | Fal.ai `image_size` |
|---|---|
| `1:1` | `square_hd` |
| `16:9` | `landscape_16_9` |
| `9:16` | `portrait_16_9` |
| `4:3` | `landscape_4_3` |
| `3:4` | `portrait_4_3` |

## Example Request

```bash
POST https://fal.run/fal-ai/ideogram/v3
{
  "prompt": "A coffee bag label design with text 'NORDIC ROAST' in elegant serif",
  "image_size": "portrait_4_3",
  "rendering_speed": "BALANCED",
  "style": "DESIGN",
  "expand_prompt": true,
  "negative_prompt": "blurry, low quality"
}
```

## Mapping to our normalized params

| Our param | Fal.ai field | Notes |
|---|---|---|
| `prompt` | `prompt` | Direct |
| `aspectRatio` | `image_size` | Needs mapping: `16:9` → `landscape_16_9` |
| `inputUrls` | `image_urls` | Array of style reference URLs |
| `negativePrompt` | `negative_prompt` | Direct |
