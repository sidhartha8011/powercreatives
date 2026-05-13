
# Rutin: Lägga till ny modell (Image / Video)

> **Förutsättning:** Providern för modellen finns redan registrerad (se `adding-provider.md`).
> **Resultat:** Modellen visas i modulens modellväljare och kan generera bilder/video.

## Steg

### 1. Bestäm hur modellen registreras

| Typ | Hur modellen hamnar i DB | Åtgärd |
|-----|--------------------------|--------|
| **Marketplace (Kie/Fal)** | JSON-seeding eller API-synk | Lägg till i providerns `models.json` |
| **Native (Google/OpenAI)** | Auto-synk vid integration setup | Inget — synkas automatiskt |
| **Manuell** | Admin lägger till via UI | Via Model Registry (admin) |

### 2. Registrera modellen

#### 2a. Via JSON-seeding (marketplace-providers)

Lägg till i `includes/core/{provider}/models.json`:

```json
{
  "{provider}-{model-id}": {
    "name": "Modellnamn",
    "type": "image",
    "provider": "{provider}",
    "costTier": "standard",
    "defaultAspectRatio": "1:1",
    "supportedAspectRatios": ["1:1", "16:9", "9:16"],
    "inputFields": ["prompt"],
    "canGenerateImage": true,
    "isEnabled": true
  }
}
```

#### 2b. Via Model Registry UI

Admin → Model Registry → Add Model → fyll i alla fält manually.

### 3. Sätt capabilities

Varje modell behöver rätt capabilities i DB:

| Capability | Modul | Krävs för |
|---|---|---|
| `canGenerateImage: true` | Image | Visas i Image-modulens modellväljare |
| `canGenerateVideo: true` | Video | Visas i Video-modulens modellväljare |
| `isEnabled: true` | Alla | Krävs för att modellen ska vara valbar |

### 4. Input-mapping (om modellen har speciella krav)

Om modellen kräver annorlunda fältnamn/format:
- Lägg till case i providerns input-mapper (t.ex. `PCM_Kie_Input_Mapper`)
- **Mappa INTE i controller/service** — input-mapping ägs av providerns API-lager

### 5. Verifiera

- [ ] Modellen syns i Model Registry med rätt provider
- [ ] Rätt capability (-er) ikryssade
- [ ] Modellen visas i modulens modellväljare (Image/Video)
- [ ] `enabledModules` inkluderar rätt modul(er)
- [ ] Generering skickar `provider: '{korrekt-provider}'` (inte hårdkodat)
- [ ] Resultat-URL returneras och visas korrekt

### Routing-sammanfattning

```
wp_pcm_models (provider-fält)
    ↓
Frontend: tRPC models.list → model.provider
    ↓
Backend: $params['provider'] → Registry::get($provider) → provider-klass → API-klass
```

Ingen heuristik. Ingen gissning. Datan bestämmer.

> **Referens:** `docs/architecture-vision.md` → "Provider Routing — Data-Driven"
