# Kie.ai Endpoint Verification (Feb 16, 2026)

## GPT-4o Image (4o Image API)
- **Generate**: `POST https://api.kie.ai/api/v1/gpt4o-image/generate`
- **Status**: `GET https://api.kie.ai/api/v1/gpt4o-image/record-info`
- The docs page title says "4o Image API" but the actual endpoint is `/gpt4o-image/generate` (NOT `/4o-image-api/generate`)
- Uses `size` param (1:1, 3:2, 2:3), NOT `aspect_ratio`
- Uses `filesUrl` array for input images, NOT `image_url`
- Uses `nVariants` for number of variants
- Response uses `successFlag` (0=generating, 1=success, 2=failed)
- Result in `response.result_urls[]`

## Flux Kontext API
- **Generate**: `POST https://api.kie.ai/api/v1/flux/kontext/generate`
- **Status**: `GET https://api.kie.ai/api/v1/flux/kontext/record-info`
- Uses `inputImage` for editing, `aspectRatio`, `model` (flux-kontext-pro/flux-kontext-max)
- Status uses `successFlag` (0=generating, 1=success, 2=create_failed, 3=generate_failed)
- Result in `response.resultImageUrl` (NOT result_urls[])

## CRITICAL FINDINGS
- GPT-4o: endpoint is `/gpt4o-image/generate` (the ORIGINAL one was correct!)
- Flux Kontext: endpoint is `/flux/kontext/generate` (the ORIGINAL one was correct!)
- Our kie-api-findings.md had WRONG info that led us to change to wrong endpoints
- We need to REVERT both endpoints back to the originals

## Flux 2 Pro/Flex
- These are marketplace models, not dedicated. TLS timeout is network issue.
