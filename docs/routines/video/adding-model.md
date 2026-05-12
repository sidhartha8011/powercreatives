# Rutin: Lägga till ny video-modell

> **Förutsättning:** Providern för modellen finns redan registrerad (se `adding-provider.md`).
> **Resultat:** Modellen visas i Video-modulens modellväljare och kan generera video.

## Steg

### 1. Bestäm hur modellen upptäcks

| Typ | Hur modellen hamnar i DB | Åtgärd |
|-----|--------------------------|--------|
| **Kie.ai marketplace** | Auto-synk via `/models/sync` | Lägg till i `models.json` (se steg 2a) |
| **Google/OpenAI native** | Auto-synk via provider API | Inget — synkas automatiskt vid integration setup |
| **Manuell** | Admin lägger till i Model Registry UI | Inget — admin fyller i alla fält |

### 2a. Kie.ai marketplace-modell (JSON-registry)

Lägg till modellen i `includes/core/kie/models.json`:

```json
{
  "kie-{model-id}": {
    "name": "Modellnamn",
    "type": "video",
    "defaultDuration": "5",
    "validDurations": ["5", "10"],
    "defaultAspectRatio": "16:9",
    "supportedAspectRatios": ["16:9", "9:16", "1:1"],
    "inputFields": ["prompt"],
    "endpoint": "marketplace"
  }
}
```

### 2b. Native Google/OpenAI-modell

Inga kodändringar behövs. Modellen synkas automatiskt vid integration setup och sparas i `wp_pcm_models` med korrekt `provider`-fält.

### 3. Sätt capabilities i Model Registry

Via UI eller direkt i DB, sätt rätt capabilities:
- `canGenerateVideo: true` — krävs för att modellen ska visas i Video-modulen
- `isEnabled: true` — krävs för att modellen ska vara valbar

### 4. Verifiera

- [ ] Modellen syns i Model Registry med rätt provider
- [ ] `canGenerateVideo` är ikryssad
- [ ] Modellen visas i Video-modulens modellväljare
- [ ] `enabledModules` inkluderar `video` i Model Registry expanded row
- [ ] Generering skickar `provider: '{korrekt-provider}'` (inte hårdkodat)

### Hur provider-routing fungerar (sammanfattning)

```
wp_pcm_models (provider-fält)
    ↓
Frontend: tRPC models.list → model.provider
    ↓
Backend: $params['provider'] → Registry::get($provider) → provider-klass
```

Ingen heuristik. Ingen gissning. Datan bestämmer.

> **Arkitekturreferens:** Se `docs/architecture-vision.md`, sektion "Provider Routing — Data-Driven"
