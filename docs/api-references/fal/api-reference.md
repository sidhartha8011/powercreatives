# Fal.ai — API Reference

> **Provider ID:** `fal`
> **Base URL:** `https://fal.run`
> **Auth:** `Authorization: Key {key_id}:{key_secret}`
> **Docs:** https://fal.ai/docs

## Endpoints

### Sync (direct response)

```
POST https://fal.run/{model_id}
Headers:
  Authorization: Key {key_id}:{key_secret}
  Content-Type: application/json
Body: { ...model-specific input }
→ Response: { images: [{ url, width, height, content_type }], seed, ... }
```

Returns the result directly. Best for fast models (<30s).

### Queue (async — submit → poll → result)

```
# 1. Submit
POST https://queue.fal.run/{model_id}
→ { request_id: "abc123" }

# 2. Poll status
GET https://queue.fal.run/{model_id}/requests/{request_id}/status
→ { status: "IN_QUEUE" | "IN_PROGRESS" | "COMPLETED" }

# 3. Get result
GET https://queue.fal.run/{model_id}/requests/{request_id}
→ { images: [{ url, ... }], ... }
```

Use for slow models or when you want non-blocking.

## Authentication

API key format: `{key_id}:{key_secret}` (two parts separated by colon).

```
Authorization: Key 0bb386a8-xxxx:efded510xxxx
```

## Universal Output Schema

All image models return:

```json
{
  "images": [
    {
      "url": "https://v3b.fal.media/files/...",
      "width": 1024,
      "height": 1024,
      "content_type": "image/jpeg"
    }
  ],
  "seed": 1234567890
}
```

## Aspect Ratio Modes

Models use one of two field names:

| Field | Models | Values |
|---|---|---|
| `aspect_ratio` | Nano Banana Pro/2, Flux Kontext | `1:1`, `16:9`, `9:16`, `4:3`, `3:4`, `3:2`, `2:3`, `21:9`, `auto` |
| `image_size` | Flux 2 Flex, Recraft V4, Ideogram v3 | `square_hd`, `square`, `portrait_4_3`, `portrait_16_9`, `landscape_4_3`, `landscape_16_9` or `{ width, height }` |

## File Upload

Files can be sent as:
1. **URL** — direct HTTP URL to image
2. **Data URI** — `data:image/png;base64,...`
3. **Uploaded** — POST to `https://fal.ai/api/storage/upload` first → get URL

---

## Sync vs Queue — Verified Decision

> **Faktum:** Alla image-modeller på Fal.ai svarar **synkront** (direkt svar) inom rimlig tid.
> Queue behövs bara för video-modeller eller extremt häftiga batchjobb.

| Modell | Typisk responstid | Sync-viabelt? | Källa |
|---|---|---|---|
| **Flux Dev** | **0.59s** | ✅ Ja | Live-testat 2026-03-04 |
| **Flux 2 Flex** | ~2–5s (28 inference steps) | ✅ Ja | Fal.ai benchmarks |
| **Nano Banana Pro** | ~3–8s (1K), ~10–20s (4K) | ✅ Ja | Fal.ai docs ("fast generation") |
| **Nano Banana 2** | ~2–5s (snabbare variant) | ✅ Ja | Fal.ai: "4x faster" |
| **Flux Kontext** | ~3–8s | ✅ Ja | Fal.ai: Flux Pro-class speed |
| **Recraft V4** | ~5–15s | ✅ Ja | Fal.ai pricing page (image model) |
| **Ideogram v3** | ~5–15s (BALANCED) | ✅ Ja | Rendering speed: TURBO/BALANCED/QUALITY |

**Beslut:** Implementera med **sync-endpoint** (`fal.run`) för alla modeller. Timeout: `120s`.
Queue-endpoint (`queue.fal.run`) finns som framtida fallback men behövs inte dag 1.

### Timeout-konfiguration

Fal.ai stödjer:
- `X-Fal-Request-Timeout: {seconds}` — header för att sätta server-side start-timeout
- Automatisk retry vid 503 (server error), 504 (timeout), 429 (rate limit)

---

## Pricing (verifierat från fal.ai/pricing)

Alla image-modeller: **per megapixel (MP)** eller **per bild**.
1MP = ~1024×1024 bild.

| Modell | Pris | Enhet |
|---|---|---|
| Nano Banana Pro | Se fal.ai/pricing | Per MP |
| Nano Banana 2 | Lägre än Pro ("lower cost") | Per MP |
| Flux Kontext Pro | Se fal.ai/pricing | Per MP |
| Flux 2 Flex | $0.06 | Per MP |
| Recraft V4 Pro | $0.25 | Per bild |
| Ideogram v3 | Se fal.ai/pricing | Per bild |

> **Källa:** https://fal.ai/pricing (verifierat 2026-03-04)
