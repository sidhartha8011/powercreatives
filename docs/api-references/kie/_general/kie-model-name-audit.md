# Kie.ai Model Name Audit

## Hardcoded MODEL_CONFIG vs Actual Kie.ai API Model Names

| Our Model ID | Hardcoded modelName | Correct API modelName | Status |
|---|---|---|---|
| kie-flux-1.1-pro | `flux-2/pro-text-to-image` | `flux-2/pro-text-to-image` | OK |
| kie-flux-kontext | `flux-kontext/pro` | Dedicated API `/flux-kontext/generate` | NEEDS VERIFY |
| kie-gpt-4o-image | `gpt-4o` | Dedicated API `/gpt4o-image/generate` | NEEDS VERIFY |
| kie-ideogram-v2 | `ideogram/v2` | `ideogram/character` or `ideogram/v3-reframe` | WRONG - v2 no longer exists |
| kie-recraft-v3 | `recraft/v3` | `recraft/crisp-upscale` or `recraft/remove-background` | WRONG - v3 no longer exists |
| kie-imagen-3 | `google/imagen-3` | `google/imagen4`, `google/imagen4-fast`, `google/imagen4-ultra` | WRONG - imagen-3 no longer exists |
| kie-grok-imagine | `grok/imagine` | `grok-imagine/text-to-image` | WRONG - wrong format |
| kie-seedream | `seedream/3.0` | `seedream/seedream` | WRONG - wrong format |
| kie-kling-2.6-t2v | `kling-2.6/text-to-video` | `kling/text-to-video` | WRONG - wrong prefix |
| kie-kling-2.6-i2v | `kling-2.6/image-to-video` | `kling/image-to-video` | WRONG - wrong prefix |
| kie-kling-2.5-turbo | `kling/v2-5-turbo-text-to-video-pro` | `kling/v2-5-turbo-text-to-video-pro` | OK (probably) |
| kie-runway-gen3 | `runway/gen3-turbo` | Dedicated API `/runway/generate` | NEEDS VERIFY |
| kie-luma-dream | `luma/dream-machine` | Dedicated API `/luma/generate-modify` | NEEDS VERIFY endpoint |
| kie-sora2 | `sora/2.0` | `sora2/sora-2-text-to-video` or `sora2/sora-2-pro-text-to-video` | WRONG |
| kie-hailuo | `hailuo/minimax` | `hailuo/02-text-to-video-pro` or similar | WRONG |
| kie-veo-3.1 | `veo/3.1` | Dedicated API `/veo3/generate` | NEEDS VERIFY endpoint |
| kie-wan | `wan/video` | `wan/2-6-text-to-video` or similar | WRONG |
| kie-bytedance | `bytedance/video` | `bytedance/v1-pro-text-to-video` or similar | WRONG |

## Summary
- **2 OK**: flux-1.1-pro, kling-2.5-turbo
- **4 NEEDS VERIFY**: flux-kontext, gpt-4o-image, runway-gen3, luma-dream (dedicated APIs)
- **12 WRONG**: All the rest have stale/incorrect model names

## Dedicated API Endpoints (from docs)
- 4o Image: POST `/api/v1/4o-image-api/generate`, GET `/api/v1/gpt4o-image/record-info`
- Flux Kontext: POST `/api/v1/flux-kontext/generate`, GET `/api/v1/flux-kontext/record-info`
- Runway: POST `/api/v1/runway/generate`, GET `/api/v1/runway/record-info`
- Luma Modify: POST `/api/v1/luma/generate-modify`, GET `/api/v1/luma/modify-record-info`
- Veo3.1: POST `/api/v1/veo3/generate`, GET `/api/v1/veo3/record-info`
