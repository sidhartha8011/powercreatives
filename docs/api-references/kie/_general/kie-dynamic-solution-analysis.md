# Kie.ai Dynamic Solution Analysis

## Key Finding: No Model Discovery API

Kie.ai does NOT have a "list available models" endpoint. There is:
- `GET /api/v1/chat/credit` — check credits
- `POST /api/v1/jobs/createTask` — create task (returns 422 for invalid model names)
- `GET /api/v1/jobs/recordInfo` — check task status

There is NO way to dynamically fetch the list of available models from the API.

## Implication for Architecture

Since we can't dynamically discover models, the smartest approach is:

1. **One unified `createMarketplaceTask` function** — takes model name + input params, sends to `/jobs/createTask`
2. **Model registry as DATA, not CODE** — a single typed array of model configs stored in shared/
3. **All marketplace models use identical code path** — no switch/case, no if/else per model
4. **The only thing that varies per model is the INPUT SCHEMA** — which fields go in the `input` object

## The Key Insight

ALL marketplace models share:
- Same endpoint: `POST /api/v1/jobs/createTask`
- Same body shape: `{ model: string, input: { ... }, callBackUrl?: string }`
- Same status: `GET /api/v1/jobs/recordInfo?taskId=...`
- Same result format: `resultJson` with `resultUrls` array

The ONLY difference is:
- The `model` string
- Which fields go in `input` (prompt, aspect_ratio, image_url, input_urls, duration, etc.)

## Smart Solution

A single generic function that:
1. Reads model name from config
2. Builds `input` object by mapping our generation params to the model's expected field names
3. Sends to createTask
4. Polls recordInfo
5. Returns result URLs

No model-specific code needed for marketplace models.
